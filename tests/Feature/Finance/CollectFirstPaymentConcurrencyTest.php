<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
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
| Two panel sessions, one bill: installments and "collectable" under the lock
|--------------------------------------------------------------------------
|
| The panel's own pre-check runs before RecordPaymentAction takes the charge
| lock, so two sessions with different idempotency keys can both pass it. Since
| P4-T36 a bill takes as many installments as it has money outstanding, so the
| question under the lock is no longer "has it been paid" but "is there room":
|
|   - two installments whose sum fits the outstanding amount both succeed;
|   - two whose sum exceeds it: one succeeds, the other is refused by the
|     overpayment guard (PaymentInvariantService), never recorded;
|   - a bill the first till settles leaves nothing for the second, which the
|     collectable-only rule refuses with its own typed exception.
|
| This file keeps its P4-T32 name; the harness is unchanged in kind.
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
function installmentWorkerEnvironment(): array
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
 * One till collecting `$amount` in cash against the bill, with or without the
 * collectable-only rule.
 */
function installmentWorker(
    int $actorId,
    int $chargeId,
    string $key,
    string $amount,
    bool $collectableOnly,
    string $readyPath,
    string $resultPath,
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $chargeId, $key, $amount, $collectableOnly, $readyPath, $resultPath] = $_SERVER['argv'];

        $collect = static function () use ($actorId, $chargeId, $key, $amount, $collectableOnly, $readyPath): array {
            file_put_contents($readyPath, 'ready');

            $payment = app(App\Domain\Finance\Actions\RecordPaymentAction::class)->execute(
                App\Models\User::query()->findOrFail((int) $actorId),
                new App\Domain\Finance\Data\RecordPaymentData(
                    chargeId: (int) $chargeId,
                    allocation: $amount,
                    tenders: [new App\Domain\Finance\Data\TenderData(App\Domain\Finance\Enums\TenderMethod::Cash, $amount)],
                    idempotencyKey: $key,
                    collectableOnly: $collectableOnly === '1',
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
        } catch (App\Domain\Finance\Exceptions\ChargeNotCollectableException) {
            $result = ['outcome' => 'not_collectable'];
        } catch (App\Domain\Finance\Exceptions\PaymentExceedsOutstandingException) {
            $result = ['outcome' => 'exceeds_outstanding'];
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
            $amount,
            $collectableOnly ? '1' : '0',
            $readyPath,
            $resultPath,
        ],
        base_path(),
        installmentWorkerEnvironment(),
    );
}

/**
 * Race two tills on one fresh 1,000.000 bill, releasing them together.
 *
 * `$alreadyPaid` is an earlier, COMMITTED live payment on the bill (null for none), so a race
 * can start from "300 of 1,000 already paid" the way a later installment does.
 *
 * @param  array{0: string, 1: string}  $keys
 * @param  array{0: string, 1: string}  $amounts  What each till collects.
 * @return array{outcomes: array<int, array<string, mixed>>, chargeId: int}
 */
function raceInstallmentTills(array $keys, array $amounts, bool $collectableOnly, ?string $alreadyPaid = null): array
{
    test()->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $chargeId = (int) $charge->getKey();

    if ($alreadyPaid !== null) {
        PaymentAllocation::factory()->create([
            'payment_id' => Payment::factory()->create()->getKey(),
            'charge_id' => $chargeId,
            'amount' => $alreadyPaid,
        ]);
    }

    $token = (string) Str::uuid();
    $paths = collect(['a-ready', 'a-result', 'b-ready', 'b-result'])
        ->mapWithKeys(fn (string $name): array => [
            $name => storage_path("framework/testing/first-payment-{$token}-{$name}.json"),
        ]);

    $workers = [
        installmentWorker((int) $actor->getKey(), $chargeId, $keys[0], $amounts[0], $collectableOnly, $paths['a-ready'], $paths['a-result']),
        installmentWorker((int) $actor->getKey(), $chargeId, $keys[1], $amounts[1], $collectableOnly, $paths['b-ready'], $paths['b-result']),
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

it('records both of two concurrent installments on a part-paid bill when their sum fits what is owed', function () {
    // 300 already paid, 700 owed; 250 + 300 = 550 fits.
    ['outcomes' => $outcomes, 'chargeId' => $chargeId] = raceInstallmentTills(
        [(string) Str::uuid(), (string) Str::uuid()],
        ['250.000', '300.000'],
        collectableOnly: true,
        alreadyPaid: '300.000',
    );

    expect(collect($outcomes)->pluck('outcome')->all())->toBe(['recorded', 'recorded'], json_encode($outcomes))
        ->and(DB::table('payments')->count())->toBe(3)
        ->and(DB::table('payment_allocations')->where('charge_id', $chargeId)->orderBy('amount')->pluck('amount')->all())->toBe(['250.000', '300.000', '300.000'])
        ->and(ChargeBalance::outstandingFor($chargeId)->toDecimal())->toBe('150.000');
})->group('finance');

it('accepts one of two concurrent installments and refuses the other when their sum exceeds what is owed', function () {
    // 300 already paid, 700 owed; 400 + 400 = 800 does not fit, so exactly one may go through.
    ['outcomes' => $outcomes, 'chargeId' => $chargeId] = raceInstallmentTills(
        [(string) Str::uuid(), (string) Str::uuid()],
        ['400.000', '400.000'],
        collectableOnly: true,
        alreadyPaid: '300.000',
    );

    expect(collect($outcomes)->pluck('outcome')->sort()->values()->all())->toBe(
        ['exceeds_outstanding', 'recorded'],
        'Two installments summing past the outstanding amount did not produce one success and one overpayment refusal: '.json_encode($outcomes),
    );

    // The loser left no payment, tender or allocation behind, and the bill is not overpaid.
    expect(DB::table('payments')->count())->toBe(2)
        ->and(DB::table('payment_tenders')->count())->toBe(1)
        ->and(DB::table('payment_allocations')->where('charge_id', $chargeId)->orderBy('amount')->pluck('amount')->all())->toBe(['300.000', '400.000'])
        ->and(ChargeBalance::outstandingFor($chargeId)->toDecimal())->toBe('300.000');
})->group('finance');

it('refuses the second of two concurrent installments with the collectable refusal once the first has settled the bill', function () {
    // 700 already paid, 300 owed; the first till takes all 300, leaving nothing for the second.
    ['outcomes' => $outcomes, 'chargeId' => $chargeId] = raceInstallmentTills(
        [(string) Str::uuid(), (string) Str::uuid()],
        ['300.000', '300.000'],
        collectableOnly: true,
        alreadyPaid: '700.000',
    );

    expect(collect($outcomes)->pluck('outcome')->sort()->values()->all())->toBe(
        ['not_collectable', 'recorded'],
        'The second installment on a settled bill was not refused by the collectable rule under the lock: '.json_encode($outcomes),
    );

    expect(DB::table('payments')->count())->toBe(2)
        ->and(DB::table('payment_allocations')->where('charge_id', $chargeId)->orderBy('amount')->pluck('amount')->all())->toBe(['300.000', '700.000'])
        ->and(ChargeBalance::outstandingFor($chargeId)->toDecimal())->toBe('0.000');
})->group('finance');

it('still refuses an overpaying installment when collectable-only is off, because the overpayment guard belongs to the Action', function () {
    ['outcomes' => $outcomes, 'chargeId' => $chargeId] = raceInstallmentTills(
        [(string) Str::uuid(), (string) Str::uuid()],
        ['400.000', '400.000'],
        collectableOnly: false,
        alreadyPaid: '300.000',
    );

    expect(collect($outcomes)->pluck('outcome')->sort()->values()->all())->toBe(['exceeds_outstanding', 'recorded'], json_encode($outcomes))
        ->and(ChargeBalance::outstandingFor($chargeId)->toDecimal())->toBe('300.000');
})->group('finance');

it('returns one payment to both tills when the same key is submitted twice at once with collectable-only on', function () {
    $key = (string) Str::uuid();

    ['outcomes' => $outcomes] = raceInstallmentTills([$key, $key], ['300.000', '300.000'], collectableOnly: true);

    expect(collect($outcomes)->pluck('outcome')->all())->toBe(['recorded', 'recorded'])
        ->and(collect($outcomes)->pluck('id')->unique()->count())->toBe(1)
        ->and(DB::table('payments')->count())->toBe(1)
        ->and(DB::table('payment_allocations')->count())->toBe(1);
})->group('finance');

it('returns the original payment to a replay of an installment key after the bill is settled', function () {
    // 700 already paid; the same till submits 300 twice at once, the second being a double-click that
    // arrives after the first settled the bill. It must replay, not be refused as not collectable.
    $key = (string) Str::uuid();

    ['outcomes' => $outcomes, 'chargeId' => $chargeId] = raceInstallmentTills([$key, $key], ['300.000', '300.000'], collectableOnly: true, alreadyPaid: '700.000');

    expect(collect($outcomes)->pluck('outcome')->all())->toBe(['recorded', 'recorded'])
        ->and(collect($outcomes)->pluck('id')->unique()->count())->toBe(1)
        ->and(DB::table('payments')->count())->toBe(2)
        ->and(ChargeBalance::outstandingFor($chargeId)->toDecimal())->toBe('0.000');
})->group('finance');
