<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Two people open the same month at the same moment (P4-T40)
|--------------------------------------------------------------------------
|
| Real subprocesses against committed rows, on the harness of
| PayrollSalaryDraftConcurrencyTest and PayrollFinalizationTest. Each worker
| pauses on the `creating` event of the run, which is AFTER the Action's own look
| for an existing run found none and BEFORE its insert, so both are certain to
| have passed that check. The parent then releases them together. The unique
| index on `payroll_runs.salary_month` lets one insert through and holds the
| other until the first commits; the Action must turn that duplicate-key error
| into the same typed refusal as the check, never let a raw SQL error out.
|
| Helper names carry the prefix `salaryMonth` because Pest functions are global.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/** @return array<string, string> */
function salaryMonthWorkerEnvironment(): array
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

/** One process opening October 2026, held on the run's `creating` event until the go file exists. */
function salaryMonthWorker(int $actorId, string $readyPath, string $goPath, string $resultPath): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $readyPath, $goPath, $resultPath] = $_SERVER['argv'];

        App\Domain\Finance\Models\PayrollRun::creating(function () use ($readyPath, $goPath): void {
            file_put_contents($readyPath, 'ready');

            $deadline = microtime(true) + 60;

            while (! file_exists($goPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }
        });

        try {
            $actor = App\Models\User::query()->findOrFail((int) $actorId);
            $run = app(App\Domain\Finance\Actions\CreatePayrollRunAction::class)->execute(
                $actor,
                App\Domain\Finance\Enums\PayrollRunType::MonthlySalary,
                '2026-10-01',
                '2026-10-31',
            );
            $result = ['outcome' => 'created', 'run' => $run->getKey()];
        } catch (App\Domain\Finance\Exceptions\SalaryMonthAlreadyHasRunException $exception) {
            $result = ['outcome' => 'month_taken', 'run' => $exception->existingRunId, 'message' => $exception->getMessage()];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, $readyPath, $goPath, $resultPath],
        base_path(),
        salaryMonthWorkerEnvironment(),
    );
}

it('gives exactly one run to two people opening one month at once, and the other a typed refusal', function () {
    $this->seed(RolePermissionSeeder::class);

    $creator = User::factory()->create(['is_active' => true]);
    $creator->givePermissionTo(Permission::findByName('run_payroll'));
    $employee = User::factory()->create();
    StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '3100.000',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $token = (string) Str::uuid();
    $path = fn (string $name): string => storage_path("framework/testing/salary-month-{$token}-{$name}.json");
    $go = $path('go');
    $workers = [];
    $files = [];

    foreach (['first', 'second'] as $name) {
        $files[$name] = ['ready' => $path("{$name}-ready"), 'result' => $path("{$name}-result")];
        $workers[$name] = salaryMonthWorker((int) $creator->getKey(), $files[$name]['ready'], $go, $files[$name]['result']);
    }

    try {
        foreach ($workers as $worker) {
            $worker->start();
        }

        // 60 seconds of headroom for slow CI nodes; the poll exits early on success.
        $deadline = hrtime(true) + 60_000_000_000;

        foreach ($workers as $name => $worker) {
            while (! File::exists($files[$name]['ready']) && hrtime(true) < $deadline) {
                if (! $worker->isRunning()) {
                    Assert::fail("Worker {$name} died unexpectedly: ".$worker->getOutput().$worker->getErrorOutput());
                }
                usleep(25_000);
            }

            Assert::assertTrue(File::exists($files[$name]['ready']), "Worker {$name} never reached its insert.");
        }

        // Both have passed the Action's check and neither has inserted: release them together.
        File::put($go, 'go');

        $outcomes = [];

        foreach ($workers as $name => $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $outcomes[$name] = json_decode((string) File::get($files[$name]['result']), true, flags: JSON_THROW_ON_ERROR);
        }
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        File::delete([$go, ...collect($files)->flatten()->all()]);
    }

    $kinds = collect($outcomes)->pluck('outcome')->sort()->values()->all();
    $run = PayrollRun::query()->sole();
    $winner = collect($outcomes)->firstWhere('outcome', 'created');
    $loser = collect($outcomes)->firstWhere('outcome', 'month_taken');

    expect($kinds)->toBe(['created', 'month_taken'])
        ->and($run->period_start->toDateString())->toBe('2026-10-01')
        ->and($run->period_end->toDateString())->toBe('2026-10-31')
        ->and($winner['run'])->toBe($run->getKey())
        ->and($loser['run'])->toBe($run->getKey())
        ->and($loser['message'])->toBe("October 2026 already has a salary run (#{$run->getKey()}). Open it instead.")
        // The refused creation left no lines behind: the one employee's one October line.
        ->and(PayrollLine::query()->count())->toBe(1);
});
