<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\AddPayrollLineAdjustmentAction;
use App\Domain\Finance\Actions\AdjustPayrollLineAction;
use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Actions\DeletePayrollRunAction;
use App\Domain\Finance\Actions\FinalizePayrollRunAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\CreatePayrollRun;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ReviewPayrollRun;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Finance\Policies\PayrollRunPolicy;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    $this->adjustmentActor = function (string $role = 'super_admin'): User {
        $actor = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($actor, $role);

        return $actor->refresh();
    };

    $this->finalizedMarchLine = function (User $actor): PayrollLine {
        $employee = User::factory()->create();
        StaffCompensation::factory()->create([
            'user_id' => $employee->getKey(),
            'type' => CompensationType::Salary,
            'amount' => '3100.000',
            'effective_from' => '2026-01-01',
        ]);
        $run = app(CreatePayrollRunAction::class)->execute(
            $actor,
            PayrollRunType::MonthlySalary,
            '2026-03-01',
            '2026-03-31',
        );
        app(FinalizePayrollRunAction::class)->execute($actor, $run);

        return $run->lines()->sole()->refresh();
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('creates a flat correction for the locked target employee and carries March into June finalization', function () {
    $actor = ($this->adjustmentActor)();
    $target = ($this->finalizedMarchLine)($actor);
    $run = app(CreatePayrollRunAction::class)->execute($actor, PayrollRunType::Adjustment);

    $correction = app(AdjustPayrollLineAction::class)->execute(
        $actor,
        $run,
        $target,
        '-125.375',
        'March absence was missed',
    );

    expect($correction->user_id)->toBe($target->user_id)
        ->and($correction->corrects_payroll_line_id)->toBe($target->getKey())
        ->and($correction->computed_amount)->toBe('-125.375')
        ->and($correction->posting_period_start)->toBeNull()
        ->and($correction->reason)->toBe('March absence was missed');

    app(FinalizePayrollRunAction::class)->execute($actor, $run);

    expect($correction->fresh()?->posting_period_start?->toDateString())->toBe('2026-03-01')
        ->and($correction->fresh()?->finalized_at)->not->toBeNull();
});

it('refuses to correct a draft line', function () {
    $actor = ($this->adjustmentActor)();
    $target = PayrollLine::factory()->create();
    $run = app(CreatePayrollRunAction::class)->execute($actor, PayrollRunType::Adjustment);

    expect(fn () => app(AdjustPayrollLineAction::class)->execute(
        $actor,
        $run,
        $target,
        '10.000',
        'Premature correction',
    ))->toThrow(ValidationException::class);

    expect($run->lines()->count())->toBe(0);
});

it('refuses a zero correction and an empty reason', function (string $amount, string $reason) {
    $actor = ($this->adjustmentActor)();
    $target = ($this->finalizedMarchLine)($actor);
    $run = app(CreatePayrollRunAction::class)->execute($actor, PayrollRunType::Adjustment);

    expect(fn () => app(AdjustPayrollLineAction::class)->execute(
        $actor,
        $run,
        $target,
        $amount,
        $reason,
    ))->toThrow(ValidationException::class);

    expect($run->lines()->count())->toBe(0);
})->with([
    'zero amount' => ['0.000', 'Explained but empty'],
    'blank reason' => ['10.000', '   '],
]);

it('refuses a correction that targets another correction', function () {
    $actor = ($this->adjustmentActor)();
    $original = ($this->finalizedMarchLine)($actor);
    $firstRun = app(CreatePayrollRunAction::class)->execute($actor, PayrollRunType::Adjustment);
    $firstCorrection = app(AdjustPayrollLineAction::class)->execute(
        $actor,
        $firstRun,
        $original,
        '10.000',
        'First correction',
    );
    app(FinalizePayrollRunAction::class)->execute($actor, $firstRun);
    $secondRun = app(CreatePayrollRunAction::class)->execute($actor, PayrollRunType::Adjustment);

    expect(fn () => app(AdjustPayrollLineAction::class)->execute(
        $actor,
        $secondRun,
        $firstCorrection->refresh(),
        '-10.000',
        'Attempted chain',
    ))->toThrow(ValidationException::class);

    expect($secondRun->lines()->count())->toBe(0);
});

it('refuses to add a correction to a finalized adjustment run', function () {
    $actor = ($this->adjustmentActor)();
    $target = ($this->finalizedMarchLine)($actor);
    $run = app(CreatePayrollRunAction::class)->execute($actor, PayrollRunType::Adjustment);
    app(AdjustPayrollLineAction::class)->execute($actor, $run, $target, '10.000', 'First correction');
    app(FinalizePayrollRunAction::class)->execute($actor, $run);

    expect(fn () => app(AdjustPayrollLineAction::class)->execute(
        $actor,
        $run->refresh(),
        $target,
        '-5.000',
        'Too late',
    ))->toThrow(ValidationException::class);

    expect($run->lines()->count())->toBe(1);
});

it('adds signed draft bonuses and deductions with a mandatory reason', function (string $amount) {
    $actor = ($this->adjustmentActor)();
    $employee = User::factory()->create();
    StaffCompensation::factory()->create(['user_id' => $employee->getKey()]);
    $run = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::MonthlySalary,
        '2026-06-01',
        '2026-06-30',
    );
    $line = $run->lines()->sole();

    $adjustment = app(AddPayrollLineAdjustmentAction::class)->execute(
        $actor,
        $line,
        $amount,
        'Approved before posting',
    );

    expect($adjustment->amount)->toBe($amount)
        ->and($adjustment->created_by)->toBe((int) $actor->getKey())
        ->and($adjustment->reason)->toBe('Approved before posting');
})->with([
    'bonus' => '75.125',
    'deduction' => '-20.001',
]);

it('refuses a draft adjustment after the run is finalized', function () {
    $actor = ($this->adjustmentActor)();
    $target = ($this->finalizedMarchLine)($actor);

    expect(fn () => app(AddPayrollLineAdjustmentAction::class)->execute(
        $actor,
        $target,
        '10.000',
        'Too late',
    ))->toThrow(ValidationException::class);

    expect(PayrollLineAdjustment::query()->count())->toBe(0);
});

it('refuses a zero draft adjustment and a blank reason', function (string $amount, string $reason) {
    $actor = ($this->adjustmentActor)();
    $line = PayrollLine::factory()->create();

    expect(fn () => app(AddPayrollLineAdjustmentAction::class)->execute(
        $actor,
        $line,
        $amount,
        $reason,
    ))->toThrow(ValidationException::class);

    expect(PayrollLineAdjustment::query()->count())->toBe(0);
})->with([
    'zero amount' => ['0.000', 'Nothing to apply'],
    'blank reason' => ['10.000', '   '],
]);

it('deletes only a draft run and relies on cascades for its lines and adjustments', function () {
    $actor = ($this->adjustmentActor)();
    $employee = User::factory()->create();
    StaffCompensation::factory()->create(['user_id' => $employee->getKey()]);
    $run = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::MonthlySalary,
        '2026-06-01',
        '2026-06-30',
    );
    $line = $run->lines()->sole();
    $adjustment = app(AddPayrollLineAdjustmentAction::class)->execute(
        $actor,
        $line,
        '10.000',
        'Draft-only bonus',
    );

    app(DeletePayrollRunAction::class)->execute($actor, $run);

    expect($run->fresh())->toBeNull()
        ->and($line->fresh())->toBeNull()
        ->and($adjustment->fresh())->toBeNull();
});

it('keeps finalized runs immutable even when the actor holds every write-shaped permission', function () {
    Permission::findOrCreate('update_payroll_run');
    $actor = ($this->adjustmentActor)();
    $actor->givePermissionTo('update_payroll_run');
    $target = ($this->finalizedMarchLine)($actor);
    $run = $target->run;

    expect(app(PayrollRunPolicy::class)->update($actor, $run))->toBeFalse()
        ->and(app(PayrollRunPolicy::class)->delete($actor, $run))->toBeFalse()
        ->and(fn () => app(DeletePayrollRunAction::class)->execute($actor, $run))
        ->toThrow(AuthorizationException::class);

    expect($run->fresh())->not->toBeNull();
});

it('does not move a finalized amount when its source rate changes underneath it', function () {
    $actor = ($this->adjustmentActor)();
    $line = ($this->finalizedMarchLine)($actor);

    StaffCompensation::query()->whereKey($line->staff_compensation_id)->update(['amount' => '9999.999']);

    expect($line->fresh()?->frozen_rate)->toBe('3100.000')
        ->and($line->fresh()?->computed_amount)->toBe('3100.000');
});

it('authorizes all payroll writers before validating or reloading caller input', function (string $action) {
    $admin = ($this->adjustmentActor)('admin');
    $run = PayrollRun::factory()->adjustment()->create();
    $line = PayrollLine::factory()->create();
    $run->setAttribute('id', PHP_INT_MAX);
    $line->setAttribute('id', PHP_INT_MAX);

    $call = match ($action) {
        'correct' => fn () => app(AdjustPayrollLineAction::class)->execute($admin, $run, $line, 'bad', ''),
        'adjust' => fn () => app(AddPayrollLineAdjustmentAction::class)->execute($admin, $line, 'bad', ''),
        'delete' => fn () => app(DeletePayrollRunAction::class)->execute($admin, $run),
    };

    expect($call)->toThrow(AuthorizationException::class);
})->with(['correct', 'adjust', 'delete']);

it('exposes payroll as an Action-backed create and draft-review workflow', function () {
    $admin = ($this->adjustmentActor)('admin');
    $staff = ($this->adjustmentActor)('staff');
    $superAdmin = ($this->adjustmentActor)();
    $employee = User::factory()->create();
    StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($staff)->get('/admin/payroll-runs')->assertForbidden();
    $this->actingAs($admin)->get('/admin/payroll-runs')->assertSuccessful();
    $this->actingAs($admin)->get('/admin/payroll-runs/create')->assertForbidden();

    $createAction = Livewire::actingAs($superAdmin)
        ->test(ListPayrollRuns::class)
        ->instance()
        ->getAction('create');
    expect($createAction)->not->toBeInstanceOf(CreateAction::class);

    Livewire::actingAs($superAdmin)
        ->test(CreatePayrollRun::class)
        ->fillForm([
            'type' => PayrollRunType::MonthlySalary->value,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'notes' => 'June payroll',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $run = PayrollRun::query()->latest('id')->firstOrFail();
    expect($run->type)->toBe(PayrollRunType::MonthlySalary)
        ->and($run->lines()->count())->toBe(1)
        ->and(PayrollRunResource::getPages())->not->toHaveKey('edit');

    $this->actingAs($admin)->get("/admin/payroll-runs/{$run->getKey()}/review")->assertSuccessful();

    Livewire::actingAs($superAdmin)
        ->test(ReviewPayrollRun::class, ['record' => $run->getKey()])
        ->callAction('finalize');

    expect($run->fresh()?->finalized_at)->not->toBeNull()
        ->and($run->lines()->sole()->fresh()?->posting_period_start?->toDateString())->toBe('2026-06-01');
});

it('routes draft-review bonuses and deletion through their named Actions', function () {
    $actor = ($this->adjustmentActor)();
    $employee = User::factory()->create();
    StaffCompensation::factory()->create(['user_id' => $employee->getKey()]);
    $run = app(CreatePayrollRunAction::class)->execute(
        $actor,
        PayrollRunType::MonthlySalary,
        '2026-06-01',
        '2026-06-30',
    );
    $line = $run->lines()->sole();

    $page = Livewire::actingAs($actor)
        ->test(ReviewPayrollRun::class, ['record' => $run->getKey()]);
    $page->callAction('add_adjustment', data: [
        'line_id' => $line->getKey(),
        'amount' => '-10.125',
        'reason' => 'Approved deduction',
    ]);

    expect(PayrollLineAdjustment::query()->sole()->amount)->toBe('-10.125');

    $page->callAction('delete');

    expect($run->fresh())->toBeNull();
});
