<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ChangeCompensationAction;
use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| A salary draft follows the rates, under the locks (P4-T38)
|--------------------------------------------------------------------------
|
| Real subprocesses against committed rows, on the harness of
| PayrollFinalizationTest and CollectFirstPaymentConcurrencyTest: the parent
| holds one row lock until the workers are queued behind it, then releases
| them. The finalizing and recalculating workers open a REPEATABLE READ
| snapshot BEFORE they queue, which is what makes a plain read after the wait
| answer from the past.
|
|   - Two recalculations of one stale draft queue on the RUN row; the second
|     finds nothing left to change, so no line is duplicated.
|   - A rate change and a finalize queue on the PERSON's users row, which
|     ChangeCompensationAction also takes. Whichever gets it first, the result
|     is whole: finalize behind the raise posts 1500.000 + 1800.000, finalize
|     ahead of it posts 3000.000, and neither ever posts a mixture.
|
| Helper names carry the prefix `salaryDraft` because Pest functions are global.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/** @return array<string, string> */
function salaryDraftWorkerEnvironment(): array
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
 * One process doing one of: 'recalculate' | 'finalize' | 'raise'.
 */
function salaryDraftWorker(
    string $operation,
    int $actorId,
    int $runId,
    int $userId,
    string $readyPath,
    string $resultPath,
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $operation, $actorId, $runId, $userId, $readyPath, $resultPath] = $_SERVER['argv'];

        $work = static function () use ($operation, $actorId, $runId, $userId, $readyPath): string {
            $actor = App\Models\User::query()->findOrFail((int) $actorId);

            // The snapshot is opened before the queue is joined (not for a rate change,
            // which reads its own timeline under the lock it takes first).
            if ($operation !== 'raise') {
                App\Domain\Finance\Models\StaffCompensation::query()->count();
                App\Domain\Finance\Models\PayrollLine::query()->count();
            }

            file_put_contents($readyPath, 'ready');

            return match ($operation) {
                'recalculate' => app(App\Domain\Finance\Actions\RecalculateSalaryDraftAction::class)->execute(
                    $actor,
                    App\Domain\Finance\Models\PayrollRun::query()->findOrFail((int) $runId),
                ) ? 'changed' : 'unchanged',
                'finalize' => (function () use ($actor, $runId): string {
                    app(App\Domain\Finance\Actions\FinalizePayrollRunAction::class)->execute(
                        $actor,
                        App\Domain\Finance\Models\PayrollRun::query()->findOrFail((int) $runId),
                    );

                    return 'finalized';
                })(),
                'raise' => (function () use ($actor, $userId): string {
                    app(App\Domain\Finance\Actions\ChangeCompensationAction::class)->execute(
                        $actor,
                        (int) $userId,
                        App\Domain\Finance\Enums\CompensationType::Salary,
                        '3600.000',
                        '2026-04-16',
                    );

                    return 'raised';
                })(),
            };
        };

        try {
            $outcome = $operation === 'raise'
                ? $work()
                : Illuminate\Support\Facades\DB::transaction($work);
            $result = ['outcome' => $outcome];
        } catch (Illuminate\Validation\ValidationException) {
            $result = ['outcome' => 'refused'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, $operation, (string) $actorId, (string) $runId, (string) $userId, $readyPath, $resultPath],
        base_path(),
        salaryDraftWorkerEnvironment(),
    );
}

/**
 * Give a worker that has reported ready time to reach the held lock and queue on it,
 * then require that it is still running: a worker that finished did not wait.
 * (information_schema.innodb_trx needs the PROCESS privilege, which the suite's
 * database user does not hold, so waiting is shown by the absence of a result.)
 */
function salaryDraftWaitUntilQueued(Process $worker, string $name): void
{
    usleep(1_200_000);

    Assert::assertTrue($worker->isRunning(), "{$name} finished instead of waiting on the lock: ".$worker->getOutput().$worker->getErrorOutput());
}

function salaryDraftWaitForFile(string $path, Process $worker): void
{
    $deadline = hrtime(true) + 60_000_000_000;

    while (! File::exists($path) && hrtime(true) < $deadline) {
        if (! $worker->isRunning()) {
            Assert::fail('Worker died unexpectedly: '.$worker->getOutput().$worker->getErrorOutput());
        }
        usleep(25_000);
    }

    Assert::assertTrue(File::exists($path), 'A worker never reported ready.');
}

/** @return string the outcome the worker wrote */
function salaryDraftOutcome(string $resultPath): string
{
    $result = json_decode((string) File::get($resultPath), true, flags: JSON_THROW_ON_ERROR);

    return $result['outcome'].(isset($result['message']) ? ': '.$result['message'] : '');
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $holding = function (string ...$permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(...array_map(fn (string $name): Permission => Permission::findByName($name), $permissions));

        return $actor;
    };

    $this->creator = $holding('run_payroll');
    $this->finalizer = $holding('finalize_payroll');
    $this->rateSetter = $holding('create_staff_compensation');

    // Rate 3000.000 from January, an April draft with its one stale-able line.
    $this->employee = User::factory()->create();
    StaffCompensation::factory()->create([
        'user_id' => $this->employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '3000.000',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);
    $this->run = app(CreatePayrollRunAction::class)->execute(
        $this->creator,
        PayrollRunType::MonthlySalary,
        '2026-04-01',
        '2026-04-30',
    );

    $token = (string) Str::uuid();
    $this->path = fn (string $name): string => storage_path("framework/testing/salary-draft-{$token}-{$name}.json");
});

/**
 * Hold a lock in the parent while the workers queue, then release and collect.
 *
 * @param  Closure(): void  $lock  takes the row lock on the open transaction
 * @param  list<array{0: string, 1: User, 2: string}>  $steps  operation, actor, file name; started in order
 * @return array<string, string> outcome per file name
 */
function salaryDraftRace(Closure $lock, array $steps, PayrollRun $run, User $employee, Closure $path): array
{
    $workers = [];
    $paths = [];

    foreach ($steps as [$operation, $actor, $name]) {
        $paths[$name] = ['ready' => $path("{$name}-ready"), 'result' => $path("{$name}-result")];
        $workers[$name] = salaryDraftWorker(
            $operation,
            (int) $actor->getKey(),
            (int) $run->getKey(),
            (int) $employee->getKey(),
            $paths[$name]['ready'],
            $paths[$name]['result'],
        );
    }

    $connection = DB::connection();
    $connection->beginTransaction();
    $released = false;

    try {
        $lock();

        foreach ($workers as $name => $worker) {
            $worker->start();
            salaryDraftWaitForFile($paths[$name]['ready'], $worker);
            // Each worker is confirmed to be waiting on the lock, in the order it was started.
            salaryDraftWaitUntilQueued($worker, $name);
        }

        foreach ($workers as $name => $worker) {
            expect(File::exists($paths[$name]['result']))->toBeFalse("{$name} finished instead of waiting on the lock.");
        }

        $connection->commit();
        $released = true;

        $outcomes = [];

        foreach ($workers as $name => $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $outcomes[$name] = salaryDraftOutcome($paths[$name]['result']);
        }

        return $outcomes;
    } finally {
        if (! $released && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        foreach ($paths as $pair) {
            File::delete(array_values($pair));
        }
    }
}

it('lets two page openings recalculate one stale draft once, with no duplicate lines', function () {
    // The raise lands before anyone opens the page, so the draft is stale for both.
    app(ChangeCompensationAction::class)->execute(
        $this->rateSetter,
        (int) $this->employee->getKey(),
        CompensationType::Salary,
        '3600.000',
        '2026-04-16',
    );

    $outcomes = salaryDraftRace(
        fn () => PayrollRun::query()->whereKey($this->run->getKey())->lockForUpdate()->firstOrFail(),
        [['recalculate', $this->creator, 'first'], ['recalculate', $this->creator, 'second']],
        $this->run,
        $this->employee,
        $this->path,
    );

    $lines = $this->run->lines()->orderBy('segment_start')->get();

    expect(collect($outcomes)->sort()->values()->all())->toBe(['changed', 'unchanged'])
        ->and($lines->map(fn (PayrollLine $line): array => [
            $line->segment_start->toDateString(), $line->segment_end->toDateString(), $line->computed_amount,
        ])->all())->toBe([
            ['2026-04-01', '2026-04-15', '1500.000'],
            ['2026-04-16', '2026-04-30', '1800.000'],
        ])
        ->and(Activity::query()
            ->where('subject_type', $this->run->getMorphClass())
            ->where('subject_id', $this->run->getKey())
            ->where('properties->recalculation', 'salary_lines_recalculated_from_current_rates')
            ->count())->toBe(1);
});

it('picks up a rate change that commits just before a finalize, never half of it', function () {
    $outcomes = salaryDraftRace(
        fn () => User::query()->whereKey($this->employee->getKey())->lockForUpdate()->firstOrFail(),
        [['raise', $this->rateSetter, 'raise'], ['finalize', $this->finalizer, 'finalize']],
        $this->run,
        $this->employee,
        $this->path,
    );

    $lines = $this->run->lines()->orderBy('segment_start')->get();

    expect($outcomes)->toBe(['raise' => 'raised', 'finalize' => 'finalized'])
        ->and($lines->map(fn (PayrollLine $line): array => [
            $line->segment_start->toDateString(), $line->segment_end->toDateString(), $line->computed_amount,
        ])->all())->toBe([
            ['2026-04-01', '2026-04-15', '1500.000'],
            ['2026-04-16', '2026-04-30', '1800.000'],
        ])
        ->and($lines->every(fn (PayrollLine $line): bool => $line->finalized_at !== null))->toBeTrue();
});

it('does not pick up a rate change that commits just after a finalize', function () {
    $outcomes = salaryDraftRace(
        fn () => User::query()->whereKey($this->employee->getKey())->lockForUpdate()->firstOrFail(),
        [['finalize', $this->finalizer, 'finalize'], ['raise', $this->rateSetter, 'raise']],
        $this->run,
        $this->employee,
        $this->path,
    );

    $line = $this->run->lines()->sole();

    expect($outcomes)->toBe(['finalize' => 'finalized', 'raise' => 'raised'])
        ->and($line->segment_start->toDateString())->toBe('2026-04-01')
        ->and($line->segment_end->toDateString())->toBe('2026-04-30')
        ->and($line->computed_amount)->toBe('3000.000')
        ->and($line->finalized_at)->not->toBeNull()
        ->and(StaffCompensation::query()->where('user_id', $this->employee->getKey())->count())->toBe(2);
});
