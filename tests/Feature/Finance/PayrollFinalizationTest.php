<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Finance\Actions\ChangeCompensationAction;
use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Actions\FinalizePayrollRunAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    $this->finalizationActor = function (string $role = 'super_admin'): User {
        $actor = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($actor, $role);

        return $actor->refresh();
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, string> */
function payrollWorkerEnvironment(): array
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

function payrollFinalizationWorker(
    int $actorId,
    int $runId,
    string $readyPath,
    string $resultPath,
    ?string $goPath = null,
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $runId, $readyPath, $resultPath, $goPath] = $_SERVER['argv'];
        file_put_contents($readyPath, 'ready');

        while ($goPath !== '' && ! file_exists($goPath)) {
            usleep(25000);
        }

        try {
            app(App\Domain\Finance\Actions\FinalizePayrollRunAction::class)->execute(
                App\Models\User::query()->findOrFail((int) $actorId),
                App\Domain\Finance\Models\PayrollRun::query()->findOrFail((int) $runId),
            );
            $result = ['outcome' => 'finalized'];
        } catch (Illuminate\Validation\ValidationException) {
            $result = ['outcome' => 'refused'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, (string) $runId, $readyPath, $resultPath, $goPath ?? ''],
        base_path(),
        payrollWorkerEnvironment(),
    );
}

function payrollAssignment(User $instructor, int $hours): int
{
    $batch = Batch::factory()->create();
    $batch->instructors()->attach($instructor->getKey(), ['assigned_hours' => $hours]);

    return (int) DB::table('batch_instructor')
        ->where('batch_id', $batch->getKey())
        ->where('user_id', $instructor->getKey())
        ->value('id');
}

it('freezes instructor hours and rate and assigns the finalization month as posting period', function () {
    $actor = ($this->finalizationActor)();
    $instructor = User::factory()->create();
    $rate = StaffCompensation::factory()->hourly()->create([
        'user_id' => $instructor->getKey(),
        'amount' => '40.125',
        'effective_from' => '2026-01-01',
    ]);
    $assignmentId = payrollAssignment($instructor, 12);

    $run = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::InstructorBatch,
        assignmentIds: [$assignmentId],
    );

    $draft = $run->lines()->sole();
    expect($draft->posting_period_start)->toBeNull();

    app(FinalizePayrollRunAction::class)->execute($actor, $run);

    DB::table('batch_instructor')->where('id', $assignmentId)->update(['assigned_hours' => 99]);
    $line = $draft->fresh();

    expect($line->staff_compensation_id)->toBe($rate->getKey())
        ->and($line->frozen_rate)->toBe('40.125')
        ->and($line->frozen_hours)->toBe(12)
        ->and($line->computed_amount)->toBe('481.500')
        ->and($line->posting_period_start->toDateString())->toBe('2026-06-01')
        ->and($line->finalized_at)->not->toBeNull()
        ->and($run->fresh()?->finalized_by)->toBe((int) $actor->getKey());
});

it('freezes the instructor hours and effective rate that exist at finalization', function () {
    $actor = ($this->finalizationActor)();
    $instructor = User::factory()->create();
    StaffCompensation::factory()->hourly()->create([
        'user_id' => $instructor->getKey(),
        'amount' => '35.000',
        'effective_from' => '2026-01-01',
    ]);
    $assignmentId = payrollAssignment($instructor, 10);
    $run = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::InstructorBatch,
        assignmentIds: [$assignmentId],
    );

    DB::table('batch_instructor')->where('id', $assignmentId)->update(['assigned_hours' => 20]);
    app(ChangeCompensationAction::class)->execute(
        $actor,
        (int) $instructor->getKey(),
        CompensationType::Hourly,
        '50.000',
        '2026-06-15',
    );
    app(FinalizePayrollRunAction::class)->execute($actor, $run);

    $line = $run->lines()->sole()->refresh();
    expect($line->frozen_hours)->toBe(20)
        ->and($line->frozen_rate)->toBe('50.000')
        ->and($line->computed_amount)->toBe('1000.000');
});

it('refuses finalization when a line shape does not match its run type', function () {
    $actor = ($this->finalizationActor)();
    $instructor = User::factory()->create();
    $rate = StaffCompensation::factory()->hourly()->create(['user_id' => $instructor->getKey()]);
    $assignmentId = payrollAssignment($instructor, 10);
    $run = PayrollRun::factory()->create(['created_by' => $actor->getKey()]);

    PayrollLine::factory()->instructor()->create([
        'payroll_run_id' => $run->getKey(),
        'user_id' => $instructor->getKey(),
        'staff_compensation_id' => $rate->getKey(),
        'batch_instructor_id' => $assignmentId,
        'frozen_rate' => '35.000',
        'frozen_hours' => 10,
        'computed_amount' => '350.000',
    ]);

    expect(fn () => app(FinalizePayrollRunAction::class)->execute($actor, $run))
        ->toThrow(ValidationException::class);

    expect($run->fresh()?->finalized_at)->toBeNull()
        ->and($run->lines()->sole()->finalized_at)->toBeNull();
});

it('has the database refuse a second finalized line for one instructor assignment', function () {
    $actor = ($this->finalizationActor)();
    $instructor = User::factory()->create();
    $rate = StaffCompensation::factory()->hourly()->create(['user_id' => $instructor->getKey()]);
    $assignmentId = payrollAssignment($instructor, 10);
    $firstRun = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::InstructorBatch,
        assignmentIds: [$assignmentId],
    );
    app(FinalizePayrollRunAction::class)->execute($actor, $firstRun);
    $secondRun = PayrollRun::factory()->instructorBatch()->finalized($actor)->create();

    expect(fn () => DB::table('payroll_lines')->insert([
        'payroll_run_id' => $secondRun->getKey(),
        'user_id' => $instructor->getKey(),
        'staff_compensation_id' => $rate->getKey(),
        'batch_instructor_id' => $assignmentId,
        'corrects_payroll_line_id' => null,
        'segment_start' => null,
        'segment_end' => null,
        'frozen_rate' => '35.000',
        'frozen_hours' => 10,
        'frozen_days' => null,
        'frozen_days_in_month' => null,
        'computed_amount' => '350.000',
        'posting_period_start' => '2026-06-01',
        'reason' => null,
        'finalized_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('derives paid instructor assignments from finalized lines and refuses them in a later draft', function () {
    $actor = ($this->finalizationActor)();
    $instructor = User::factory()->create();
    StaffCompensation::factory()->hourly()->create(['user_id' => $instructor->getKey()]);
    $assignmentId = payrollAssignment($instructor, 10);
    $first = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::InstructorBatch,
        assignmentIds: [$assignmentId],
    );
    app(FinalizePayrollRunAction::class)->execute($actor, $first);

    expect(fn () => app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::InstructorBatch,
        assignmentIds: [$assignmentId],
    ))->toThrow(ValidationException::class);

    expect(PayrollRun::query()->where('type', PayrollRunType::InstructorBatch->value)->count())->toBe(1);
});

it('waits on the employee lock before refusing an overlapping salary segment', function () {
    $actor = ($this->finalizationActor)();
    $employee = User::factory()->create();
    StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'effective_from' => '2026-01-01',
    ]);
    $first = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::MonthlySalary,
        '2026-01-01',
        '2026-01-15',
    );
    app(FinalizePayrollRunAction::class)->execute($actor, $first);
    $overlap = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::MonthlySalary,
        '2026-01-10',
        '2026-01-31',
    );

    $token = (string) Str::uuid();
    $ready = storage_path("framework/testing/payroll-{$token}-ready.json");
    $result = storage_path("framework/testing/payroll-{$token}-result.json");
    $worker = payrollFinalizationWorker((int) $actor->getKey(), (int) $overlap->getKey(), $ready, $result);
    $connection = DB::connection();
    $connection->beginTransaction();
    $released = false;

    try {
        User::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
        $worker->start();
        $deadline = microtime(true) + 10;

        while (! File::exists($ready) && microtime(true) < $deadline) {
            usleep(25_000);
        }

        expect(File::exists($ready))->toBeTrue('The finalization worker did not reach the Action.');
        usleep(400_000);
        expect($worker->isRunning())->toBeTrue('Finalization did not wait on the employee-row lock.')
            ->and(File::exists($result))->toBeFalse();

        $connection->commit();
        $released = true;
        $worker->wait();

        $outcome = json_decode((string) File::get($result), true, flags: JSON_THROW_ON_ERROR);
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput())
            ->and($outcome['outcome'])->toBe('refused')
            ->and($overlap->fresh()?->finalized_at)->toBeNull();
    } finally {
        if (! $released && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if ($worker->isRunning()) {
            $worker->stop();
        }

        File::delete([$ready, $result]);
    }
});

it('authorizes finalization before reloading the run', function () {
    $admin = ($this->finalizationActor)('admin');
    $run = PayrollRun::factory()->create();
    $run->setAttribute('id', PHP_INT_MAX);

    expect(fn () => app(FinalizePayrollRunAction::class)->execute($admin, $run))
        ->toThrow(AuthorizationException::class);
});

it('finalizes concurrent runs over oppositely ordered staff without deadlock', function () {
    $actor = ($this->finalizationActor)();
    $employees = User::factory()->count(2)->create()->sortBy('id')->values();
    $rates = $employees->mapWithKeys(function (User $employee): array {
        $rate = StaffCompensation::factory()->create([
            'user_id' => $employee->getKey(),
            'effective_from' => '2026-01-01',
        ]);

        return [$employee->getKey() => $rate];
    });
    $runs = PayrollRun::factory()->count(2)->create([
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'created_by' => $actor->getKey(),
    ]);

    foreach ([$employees, $employees->reverse()->values()] as $index => $orderedEmployees) {
        foreach ($orderedEmployees as $employee) {
            PayrollLine::create([
                'payroll_run_id' => $runs[$index]->getKey(),
                'user_id' => $employee->getKey(),
                'staff_compensation_id' => $rates[$employee->getKey()]->getKey(),
                'batch_instructor_id' => null,
                'corrects_payroll_line_id' => null,
                'segment_start' => '2026-05-01',
                'segment_end' => '2026-05-31',
                'frozen_rate' => '2500.000',
                'frozen_hours' => null,
                'frozen_days' => 31,
                'frozen_days_in_month' => 31,
                'computed_amount' => '2500.000',
                'posting_period_start' => null,
                'reason' => null,
                'finalized_at' => null,
            ]);
        }
    }

    $token = (string) Str::uuid();
    $go = storage_path("framework/testing/payroll-{$token}-go");
    $paths = collect(['a-ready', 'a-result', 'b-ready', 'b-result'])
        ->mapWithKeys(fn (string $name): array => [
            $name => storage_path("framework/testing/payroll-{$token}-{$name}.json"),
        ]);
    $workers = [
        payrollFinalizationWorker((int) $actor->getKey(), (int) $runs[0]->getKey(), $paths['a-ready'], $paths['a-result'], $go),
        payrollFinalizationWorker((int) $actor->getKey(), (int) $runs[1]->getKey(), $paths['b-ready'], $paths['b-result'], $go),
    ];

    try {
        foreach ($workers as $worker) {
            $worker->start();
        }

        $deadline = microtime(true) + 10;
        while ((! File::exists($paths['a-ready']) || ! File::exists($paths['b-ready'])) && microtime(true) < $deadline) {
            usleep(25_000);
        }

        expect(File::exists($paths['a-ready']))->toBeTrue()
            ->and(File::exists($paths['b-ready']))->toBeTrue();
        File::put($go, 'go');

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

        expect($outcomes)->toBe(['finalized', 'refused'])
            ->and(PayrollRun::query()->whereNotNull('finalized_at')->count())->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        File::delete([...$paths->all(), $go]);
    }
});
