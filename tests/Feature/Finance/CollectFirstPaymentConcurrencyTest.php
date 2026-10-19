<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Two panel sessions, one unpaid bill: "first payment only" under the lock
|--------------------------------------------------------------------------
|
| The panel's own pre-check runs before RecordPaymentAction takes the charge
| lock, so two sessions with different idempotency keys can both pass it. Each
| collects 300.000 against a 1,000.000 bill, so their sum is under the
| outstanding balance and the overpayment guard accepts both. Only the rule
| inside the Action, evaluated under the lock, can refuse the second.
|
| A single connection cannot show that: RefreshDatabase holds one transaction
| for the whole test, so every lock is already the test's own. These run in real
| subprocesses against committed rows, on the same harness as
| PaymentConcurrencyTest (see its header): the parent holds the charge row until
| both workers are queued behind it, then releases them together. Each worker
| takes a stale snapshot first, which is what makes a plain, unlocked read of
| "has this bill been paid" answer wrongly for the loser.
|
| The helpers carry their own prefix because Pest function names are global and
| PaymentConcurrencyTest declares the unprefixed ones.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function firstPaymentWorkerEnvironment(): array
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
 * One till collecting 300.000 in cash against the bill, with or without the
 * first-payment-only rule.
 */
function firstPaymentWorker(
    int $actorId,
    int $chargeId,
    string $key,
    bool $firstPaymentOnly,
    string $readyPath,
    string $resultPath,
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $chargeId, $key, $firstPaymentOnly, $readyPath, $resultPath] = $_SERVER['argv'];

        $collect = static function () use ($actorId, $chargeId, $key, $firstPaymentOnly, $readyPath): array {
            file_put_contents($readyPath, 'ready');

            $payment = app(App\Domain\Finance\Actions\RecordPaymentAction::class)->execute(
                App\Models\User::query()->findOrFail((int) $actorId),
                new App\Domain\Finance\Data\RecordPaymentData(
                    chargeId: (int) $chargeId,
                    allocation: '300.000',
                    tenders: [new App\Domain\Finance\Data\TenderData(App\Domain\Finance\Enums\TenderMethod::Cash, '300.000')],
                    idempotencyKey: $key,
                    firstPaymentOnly: $firstPaymentOnly === '1',
                ),
            );

            return ['outcome' => 'recorded', 'id' => (int) $payment->getKey()];
        };

        /*
         * A STALE SNAPSHOT BEFORE THE ACTION RUNS, for the reason PaymentConcurrencyTest
         * gives: both tills must hold a view of the world from before either committed,
         * which is the state a non-locking "is this bill still unpaid" read gets wrong.
         */
        try {
            $result = Illuminate\Support\Facades\DB::transaction(static function () use ($collect): array {
                App\Domain\Finance\Models\Payment::query()->count();

                return $collect();
            });
        } catch (App\Domain\Finance\Exceptions\ChargeNotAwaitingFirstPaymentException) {
            $result = ['outcome' => 'not_first_payment'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [
            PHP_BINARY,
            '-r',
            $script,
            (string) $actorId,
            (string) $chargeId,
            $key,
            $firstPaymentOnly ? '1' : '0',
            $readyPath,
            $resultPath,
        ],
        base_path(),
        firstPaymentWorkerEnvironment(),
    );
}

/**
 * Race two tills on one fresh 1,000.000 bill, releasing them together.
 *
 * @param  array{0: string, 1: string}  $keys
 * @return array{outcomes: array<int, array<string, mixed>>, chargeId: int}
 */
function raceFirstPaymentTills(array $keys, bool $firstPaymentOnly): array
{
    test()->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $chargeId = (int) $charge->getKey();

    $token = (string) Str::uuid();
    $paths = collect(['a-ready', 'a-result', 'b-ready', 'b-result'])
        ->mapWithKeys(fn (string $name): array => [
            $name => storage_path("framework/testing/first-payment-{$token}-{$name}.json"),
        ]);

    $workers = [
        firstPaymentWorker((int) $actor->getKey(), $chargeId, $keys[0], $firstPaymentOnly, $paths['a-ready'], $paths['a-result']),
        firstPaymentWorker((int) $actor->getKey(), $chargeId, $keys[1], $firstPaymentOnly, $paths['b-ready'], $paths['b-result']),
    ];

    $connection = DB::connection();
    $connection->beginTransaction();
    $chargeLockReleased = false;

    try {
        Charge::query()->whereKey($chargeId)->lockForUpdate()->firstOrFail();

        foreach ($workers as $worker) {
            $worker->start();
        }

        $deadline = hrtime(true) + 60_000_000_000;

        while ((! File::exists($paths['a-ready']) || ! File::exists($paths['b-ready'])) && hrtime(true) < $deadline) {
            foreach ($workers as $worker) {
                if ($worker->isStarted() && ! $worker->isRunning()) {
                    Assert::fail('Worker died unexpectedly: '.$worker->getErrorOutput());
                }
            }

            usleep(25_000);
        }

        expect(File::exists($paths['a-ready']))->toBeTrue('Till A never reached the Action.')
            ->and(File::exists($paths['b-ready']))->toBeTrue('Till B never reached the Action.');

        usleep(400_000);

        // Neither may have finished while the parent holds the bill: both are queued on its lock.
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

        return [
            'outcomes' => collect([$paths['a-result'], $paths['b-result']])
                ->map(fn (string $path): array => json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR))
                ->all(),
            'chargeId' => $chargeId,
        ];
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

it('accepts one of two concurrent first-payment collections and refuses the other, though their sum fits the bill', function () {
    ['outcomes' => $outcomes, 'chargeId' => $chargeId] = raceFirstPaymentTills(
        [(string) Str::uuid(), (string) Str::uuid()],
        firstPaymentOnly: true,
    );

    expect(collect($outcomes)->pluck('outcome')->sort()->values()->all())->toBe(
        ['not_first_payment', 'recorded'],
        'Two sessions each collecting a first payment on one unpaid bill did not produce one success and one typed refusal: '.json_encode($outcomes),
    );

    // 300.000 on a 1,000.000 bill, written once: the loser left no payment, tender or allocation behind.
    expect(DB::table('payments')->count())->toBe(1)
        ->and(DB::table('payment_tenders')->count())->toBe(1)
        ->and(DB::table('payment_allocations')->where('charge_id', $chargeId)->pluck('amount')->all())->toBe(['300.000'])
        ->and(ChargeBalance::outstandingFor($chargeId)->toDecimal())->toBe('700.000');
})->group('finance');

it('still lets both of two concurrent collections through when first-payment-only is off, which is what makes the race above real', function () {
    ['outcomes' => $outcomes, 'chargeId' => $chargeId] = raceFirstPaymentTills(
        [(string) Str::uuid(), (string) Str::uuid()],
        firstPaymentOnly: false,
    );

    expect(collect($outcomes)->pluck('outcome')->all())->toBe(['recorded', 'recorded'])
        ->and(DB::table('payment_allocations')->where('charge_id', $chargeId)->count())->toBe(2)
        ->and(ChargeBalance::outstandingFor($chargeId)->toDecimal())->toBe('400.000');
})->group('finance');

it('returns one payment to both tills when the same key is submitted twice at once with first-payment-only on', function () {
    $key = (string) Str::uuid();

    ['outcomes' => $outcomes] = raceFirstPaymentTills([$key, $key], firstPaymentOnly: true);

    expect(collect($outcomes)->pluck('outcome')->all())->toBe(['recorded', 'recorded'])
        ->and(collect($outcomes)->pluck('id')->unique()->count())->toBe(1)
        ->and(DB::table('payments')->count())->toBe(1)
        ->and(DB::table('payment_allocations')->count())->toBe(1);
})->group('finance');
