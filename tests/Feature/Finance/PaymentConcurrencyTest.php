<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Two tills, one bill — the proofs a single connection cannot give
|--------------------------------------------------------------------------
|
| RefreshDatabase holds a transaction open for the whole of every test, so
| `lockForUpdate()` emits `for update` and blocks nobody: there is only ever
| one connection, and every lock is already held by the test itself. A locking
| test written that way passes against an Action that opens no transaction at
| all. Design section 5 asks for the concurrent behaviour specifically, so
| these run in real subprocesses against committed rows.
|
| This file is the payments twin of CompensationConcurrencyTest, and follows
| it deliberately rather than inventing a second harness: DatabaseTruncation
| instead of RefreshDatabase, an afterAll resetting RefreshDatabaseState so the
| next database test rebuilds, one Symfony Process per till, ready/result files
| to synchronise, and a lock the parent holds until both workers are queued
| behind it.
|
| It is a separate file because a Pest file's `uses()` applies to the whole
| file, and RefreshDatabase and DatabaseTruncation cannot both govern one.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function paymentWorkerEnvironment(): array
{
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");

    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) ($database['host'] ?? ''),
        'DB_PORT' => (string) ($database['port'] ?? ''),
        'DB_DATABASE' => (string) ($database['database'] ?? ''),
        'DB_USERNAME' => (string) ($database['username'] ?? ''),
        'DB_PASSWORD' => (string) ($database['password'] ?? ''),
    ];
}

/**
 * One till, recording 300 on card and 700 in cash against the given bill.
 *
 * The tender split is fixed in the script and the KEY is the only thing that
 * varies, because the two tests differ in exactly that: the same key is a
 * replay, two keys are two people paying the same bill at once.
 */
function paymentWorker(int $actorId, int $chargeId, string $key, string $readyPath, string $resultPath): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $chargeId, $key, $readyPath, $resultPath] = $_SERVER['argv'];
        file_put_contents($readyPath, 'ready');

        try {
            $payment = app(App\Domain\Finance\Actions\RecordPaymentAction::class)->execute(
                App\Models\User::query()->findOrFail((int) $actorId),
                new App\Domain\Finance\Data\RecordPaymentData(
                    chargeId: (int) $chargeId,
                    allocation: '1000.000',
                    tenders: [
                        new App\Domain\Finance\Data\TenderData(
                            App\Domain\Finance\Enums\TenderMethod::Card,
                            '300.000',
                            'AUTH-1234567890',
                        ),
                        new App\Domain\Finance\Data\TenderData(
                            App\Domain\Finance\Enums\TenderMethod::Cash,
                            '700.000',
                        ),
                    ],
                    idempotencyKey: $key,
                ),
            );
            $result = ['outcome' => 'recorded', 'id' => (int) $payment->getKey()];
        } catch (App\Domain\Finance\Exceptions\PaymentExceedsOutstandingException) {
            $result = ['outcome' => 'exceeds_outstanding'];
        } catch (App\Domain\Finance\Exceptions\IdempotencyConflictException) {
            $result = ['outcome' => 'conflict'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, (string) $chargeId, $key, $readyPath, $resultPath],
        base_path(),
        paymentWorkerEnvironment(),
    );
}

/**
 * Run two tills against one bill, releasing them together.
 *
 * The parent holds the charge row so both workers block inside
 * `PaymentInvariantService::lockCharge()` — proving on the way past that the
 * lock is real — and then commits, so the two race from the same instant.
 *
 * @param  array{0: string, 1: string}  $keys
 * @return array{outcomes: array<int, array<string, mixed>>, chargeId: int}
 */
function raceTwoTills(array $keys): array
{
    test()->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $charge = Charge::factory()->create(['amount' => '1000.000']);

    $token = (string) Str::uuid();
    $paths = collect(['a-ready', 'a-result', 'b-ready', 'b-result'])
        ->mapWithKeys(fn (string $name): array => [
            $name => storage_path("framework/testing/payment-{$token}-{$name}.json"),
        ]);

    $workers = [
        paymentWorker((int) $actor->getKey(), (int) $charge->getKey(), $keys[0], $paths['a-ready'], $paths['a-result']),
        paymentWorker((int) $actor->getKey(), (int) $charge->getKey(), $keys[1], $paths['b-ready'], $paths['b-result']),
    ];

    $connection = DB::connection();
    $connection->beginTransaction();
    $chargeLockReleased = false;

    try {
        Charge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

        foreach ($workers as $worker) {
            $worker->start();
        }

        $deadline = microtime(true) + 10;

        while ((! File::exists($paths['a-ready']) || ! File::exists($paths['b-ready'])) && microtime(true) < $deadline) {
            usleep(25_000);
        }

        expect(File::exists($paths['a-ready']))->toBeTrue('Till A never reached the Action.')
            ->and(File::exists($paths['b-ready']))->toBeTrue('Till B never reached the Action.');

        usleep(400_000);

        /*
         * Neither may have finished. If one has, RecordPaymentAction read the
         * charge without a lock and the whole overpayment guard is decorative:
         * outstanding would have been derived from a row another transaction
         * was free to change underneath it.
         */
        expect($workers[0]->isRunning())->toBeTrue('Till A was not blocked by the charge lock.')
            ->and($workers[1]->isRunning())->toBeTrue('Till B was not blocked by the charge lock.')
            ->and(File::exists($paths['a-result']))->toBeFalse()
            ->and(File::exists($paths['b-result']))->toBeFalse();

        $connection->commit();
        $chargeLockReleased = true;

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }

        $outcomes = collect([$paths['a-result'], $paths['b-result']])
            ->map(fn (string $path): array => json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR))
            ->all();

        return ['outcomes' => $outcomes, 'chargeId' => (int) $charge->getKey()];
    } finally {
        if (! $chargeLockReleased && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        foreach ($paths as $path) {
            File::delete($path);
        }
    }
}

/*
|--------------------------------------------------------------------------
| The same key, submitted twice at once
|--------------------------------------------------------------------------
*/

it('records one payment when two tills submit the same key simultaneously', function () {
    $key = (string) Str::uuid();

    ['outcomes' => $outcomes] = raceTwoTills([$key, $key]);

    $failures = collect($outcomes)->where('outcome', '!=', 'recorded');

    expect($failures->all())->toBe(
        [],
        'A concurrent replay did not return the existing payment: '.json_encode($outcomes),
    );

    /*
     * BOTH TILLS GOT THE SAME PAYMENT. This is the assertion that depends on
     * the replay lookup being a LOCKING read. The loser's transaction took its
     * snapshot during the authorization check, before the winner committed, so
     * a plain SELECT on `idempotency_key` reads a snapshot in which that row
     * does not exist — and returns null for a row the database has just
     * refused as a duplicate. Only `lockForUpdate()` reads the latest
     * committed version. Remove it and this test reports an `error` outcome.
     */
    $ids = collect($outcomes)->pluck('id')->unique()->values();

    expect($ids)->toHaveCount(1, 'The two tills recorded different payments for one handover of cash.');

    expect(DB::table('payments')->count())->toBe(1)
        ->and(DB::table('payment_tenders')->count())->toBe(2)
        ->and(DB::table('payment_allocations')->count())->toBe(1);
})->group('finance');

/*
|--------------------------------------------------------------------------
| Two different keys, each for the whole bill
|--------------------------------------------------------------------------
*/

it('lets one of two concurrent payments settle the bill and refuses the other', function () {
    ['outcomes' => $outcomes, 'chargeId' => $chargeId] = raceTwoTills([
        (string) Str::uuid(),
        (string) Str::uuid(),
    ]);

    $sorted = collect($outcomes)->pluck('outcome')->sort()->values()->all();

    expect($sorted)->toBe(
        ['exceeds_outstanding', 'recorded'],
        'Two tills paying one bill in full did not produce one success and one typed refusal: '.json_encode($outcomes),
    );

    /*
     * The bill is settled EXACTLY, never over-settled. This is the property
     * `PaymentInvariantService` exists for: the loser derived outstanding
     * under the charge lock the winner had already released, so it saw 0.000
     * rather than the 1,000.000 its own form was rendered against.
     */
    expect(ChargeBalance::outstandingFor($chargeId)->equals(Money::zero()))->toBeTrue(
        'The bill did not end exactly settled: '.ChargeBalance::outstandingFor($chargeId)->toDecimal(),
    );

    expect(DB::table('payments')->count())->toBe(1);
})->group('finance');
