<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function compensationWorkerEnvironment(): array
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

function compensationWorker(int $actorId, int $employeeId, string $readyPath, string $resultPath): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $employeeId, $readyPath, $resultPath] = $_SERVER['argv'];
        file_put_contents($readyPath, 'ready');

        try {
            $rate = app(App\Domain\Finance\Actions\ChangeCompensationAction::class)->execute(
                App\Models\User::query()->findOrFail((int) $actorId),
                (int) $employeeId,
                App\Domain\Finance\Enums\CompensationType::Salary,
                '2500.000',
                '2026-01-01',
            );
            $result = ['outcome' => 'created', 'id' => $rate->getKey()];
        } catch (Illuminate\Validation\ValidationException) {
            $result = ['outcome' => 'refused'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, (string) $employeeId, $readyPath, $resultPath],
        base_path(),
        compensationWorkerEnvironment(),
    );
}

it('serializes two concurrent first rates by locking the employee row', function () {
    $this->seed(RolePermissionSeeder::class);
    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'super_admin');
    $employee = User::factory()->create();

    $token = (string) Str::uuid();
    $paths = collect(['a-ready', 'a-result', 'b-ready', 'b-result'])
        ->mapWithKeys(fn (string $name): array => [
            $name => storage_path("framework/testing/compensation-{$token}-{$name}.json"),
        ]);

    $workers = [
        compensationWorker((int) $actor->getKey(), (int) $employee->getKey(), $paths['a-ready'], $paths['a-result']),
        compensationWorker((int) $actor->getKey(), (int) $employee->getKey(), $paths['b-ready'], $paths['b-result']),
    ];

    $connection = DB::connection();
    $connection->beginTransaction();
    $employeeLockReleased = false;

    try {
        User::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

        foreach ($workers as $worker) {
            $worker->start();
        }

        $deadline = microtime(true) + 10;

        while ((! File::exists($paths['a-ready']) || ! File::exists($paths['b-ready'])) && microtime(true) < $deadline) {
            usleep(25_000);
        }

        expect(File::exists($paths['a-ready']))->toBeTrue('Worker A did not reach the Action.')
            ->and(File::exists($paths['b-ready']))->toBeTrue('Worker B did not reach the Action.');

        usleep(400_000);

        expect($workers[0]->isRunning())->toBeTrue('Worker A was not blocked by the employee-row lock.')
            ->and($workers[1]->isRunning())->toBeTrue('Worker B was not blocked by the employee-row lock.')
            ->and(File::exists($paths['a-result']))->toBeFalse()
            ->and(File::exists($paths['b-result']))->toBeFalse();

        $connection->commit();
        $employeeLockReleased = true;

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }

        $outcomes = collect([$paths['a-result'], $paths['b-result']])
            ->map(fn (string $path): array => json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR))
            ->pluck('outcome')
            ->sort()
            ->values()
            ->all();

        expect($outcomes)->toBe(['created', 'refused'])
            ->and(DB::table('staff_compensation')->where('user_id', $employee->getKey())->count())->toBe(1);
    } finally {
        if (! $employeeLockReleased && $connection->transactionLevel() > 0) {
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
})->group('finance');
