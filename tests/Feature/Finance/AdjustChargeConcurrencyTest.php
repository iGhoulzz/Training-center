<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
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
| A stale snapshot after waiting for the bill
|--------------------------------------------------------------------------
|
| A single connection cannot prove this guard. Under InnoDB REPEATABLE READ,
| an ordinary read keeps using the transaction snapshot established before a
| competing payment committed, even after the transaction waits for and then
| acquires the charge row lock. The parent and subprocess below force that
| sequence rather than depending on timing.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function adjustChargeWorkerEnvironment(): array
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

function adjustChargeWorker(
    int $actorId,
    int $chargeId,
    string $readyPath,
    string $resultPath,
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $chargeId, $readyPath, $resultPath] = $_SERVER['argv'];

        try {
            $result = Illuminate\Support\Facades\DB::transaction(
                static function () use ($actorId, $chargeId, $readyPath): array {
                    /* Establish the old consistent-read snapshot deliberately. */
                    App\Domain\Finance\Models\PaymentAllocation::query()->count();
                    file_put_contents($readyPath, 'ready');

                    app(App\Domain\Finance\Actions\AdjustChargeAction::class)->execute(
                        App\Models\User::query()->findOrFail((int) $actorId),
                        new App\Domain\Finance\Data\AdjustChargeData(
                            chargeId: (int) $chargeId,
                            amount: '100.000',
                            reason: 'Correct the entered charge amount.',
                        ),
                    );

                    return ['outcome' => 'adjusted'];
                },
            );
        } catch (App\Domain\Finance\Exceptions\ChargeAmountBelowAllocatedException) {
            $result = ['outcome' => 'refused'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, (string) $chargeId, $readyPath, $resultPath],
        base_path(),
        adjustChargeWorkerEnvironment(),
    );
}

it('refuses an adjustment below an allocation committed while it waited for the charge lock', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'super_admin');

    $charge = Charge::factory()->create([
        'list_price' => '1000.000',
        'amount' => '1000.000',
    ]);

    $token = (string) Str::uuid();
    $readyPath = storage_path("framework/testing/adjust-charge-{$token}-ready");
    $resultPath = storage_path("framework/testing/adjust-charge-{$token}-result.json");
    $worker = adjustChargeWorker(
        (int) $actor->getKey(),
        (int) $charge->getKey(),
        $readyPath,
        $resultPath,
    );

    $connection = DB::connection();
    $connection->beginTransaction();
    $chargeLockReleased = false;

    try {
        Charge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

        $worker->start();

        // 60 seconds of headroom for slow CI nodes; the poll exits early on success.
        $deadline = hrtime(true) + 60_000_000_000;

        while (! File::exists($readyPath) && hrtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                Assert::fail('Worker died unexpectedly: '.$worker->getErrorOutput());
            }
            usleep(25_000);
        }

        expect(File::exists($readyPath))->toBeTrue('The adjustment worker never established its old snapshot.');

        usleep(400_000);

        expect($worker->isRunning())->toBeTrue('The adjustment worker was not blocked by the charge lock.')
            ->and(File::exists($resultPath))->toBeFalse();

        $payment = Payment::factory()->create([
            'student_id' => $charge->enrollment()->value('student_id'),
            'recorded_by' => $actor->getKey(),
        ]);

        PaymentTender::factory()->create([
            'payment_id' => $payment->getKey(),
            'amount' => '500.000',
        ]);

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => '500.000',
        ]);

        $connection->commit();
        $chargeLockReleased = true;

        $worker->wait();

        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

        $result = json_decode((string) File::get($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($result)->toBe(['outcome' => 'refused'])
            ->and($charge->fresh()->amount)->toBe('1000.000')
            ->and(ChargeBalance::allocatedFor((int) $charge->getKey())->toDecimal())->toBe('500.000');
    } finally {
        if (! $chargeLockReleased && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if ($worker->isRunning()) {
            $worker->stop();
        }

        File::delete([$readyPath, $resultPath]);
    }
})->group('finance');
