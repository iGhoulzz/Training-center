<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->payrollActor = function (string $role = 'super_admin'): User {
        $actor = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($actor, $role);

        return $actor->refresh();
    };
});

it('splits a partial previous month and a full current month with each calendar denominator', function () {
    $actor = ($this->payrollActor)();
    $employee = User::factory()->create();

    StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '3100.000',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $run = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::MonthlySalary,
        '2026-03-20',
        '2026-04-30',
    );

    $lines = PayrollLine::query()
        ->where('payroll_run_id', $run->getKey())
        ->orderBy('segment_start')
        ->get();

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->segment_start->toDateString())->toBe('2026-03-20')
        ->and($lines[0]->segment_end->toDateString())->toBe('2026-03-31')
        ->and($lines[0]->frozen_days)->toBe(12)
        ->and($lines[0]->frozen_days_in_month)->toBe(31)
        ->and($lines[0]->frozen_rate)->toBe('3100.000')
        ->and($lines[0]->computed_amount)->toBe('1200.000')
        ->and($lines[0]->posting_period_start)->toBeNull()
        ->and($lines[0]->finalized_at)->toBeNull()
        ->and($lines[1]->segment_start->toDateString())->toBe('2026-04-01')
        ->and($lines[1]->segment_end->toDateString())->toBe('2026-04-30')
        ->and($lines[1]->frozen_days)->toBe(30)
        ->and($lines[1]->frozen_days_in_month)->toBe(30)
        ->and($lines[1]->computed_amount)->toBe('3100.000');
});

it('splits a month at a mid-period raise and prices both effective-dated segments', function () {
    $actor = ($this->payrollActor)();
    $employee = User::factory()->create();

    $oldRate = StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '3000.000',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-04-15',
    ]);
    $newRate = StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '3600.000',
        'effective_from' => '2026-04-16',
        'effective_to' => null,
    ]);

    $run = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::MonthlySalary,
        '2026-04-01',
        '2026-04-30',
    );

    $lines = $run->lines()->orderBy('segment_start')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('staff_compensation_id')->all())->toBe([$oldRate->getKey(), $newRate->getKey()])
        ->and($lines->pluck('frozen_days')->all())->toBe([15, 15])
        ->and($lines->pluck('frozen_days_in_month')->all())->toBe([30, 30])
        ->and($lines->pluck('computed_amount')->all())->toBe(['1500.000', '1800.000']);
});

it('rounds salary segments in integer dirham rather than through floats', function () {
    $actor = ($this->payrollActor)();
    $employee = User::factory()->create();

    StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '216.350',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $run = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::MonthlySalary,
        '2026-04-01',
        '2026-04-15',
    );

    expect($run->lines()->sole()->computed_amount)->toBe('108.175');
});

it('authorizes creating a payroll run before validating its period', function () {
    $admin = ($this->payrollActor)('admin');

    expect(fn () => app(CreatePayrollRunAction::class)->execute(
        $admin,
        PayrollRunType::MonthlySalary,
        'not-a-date',
        'also-not-a-date',
    ))->toThrow(AuthorizationException::class);
});
