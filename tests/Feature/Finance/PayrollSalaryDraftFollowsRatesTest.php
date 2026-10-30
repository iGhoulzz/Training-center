<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ChangeCompensationAction;
use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Actions\FinalizePayrollRunAction;
use App\Domain\Finance\Actions\RecalculateSalaryDraftAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Exceptions\SalaryAdjustmentsHaveNoLineException;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ReviewPayrollRun;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Finance\Reports\WageCostReport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/PayrollLegacySalaryRun.php';

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A salary draft follows the compensation rates on file (P4-T38)
|--------------------------------------------------------------------------
|
| The reproduced defect: rate 3000 -> an April draft with one line 1-30 April
| at 3000.000 -> a raise to 3600 from 16 April -> finalize posted that one
| stale line. Every figure below is worked by hand from the rates:
|
|   3000.000 x 15 / 30 = 1500.000   (1-15 April)
|   3600.000 x 15 / 30 = 1800.000   (16-30 April)
|   1500.000 + 1800.000 = 3300.000
*/

/**
 * Every notification sent so far. Reading them takes them out of the session, so
 * this is called once per assertion block.
 *
 * @return list<array{title: string, status: string, duration: int|string}>
 */
function salaryDraftNotifications(): array
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (FilamentNotification $notification): array => [
            'title' => (string) $notification->getTitle(),
            'status' => (string) $notification->getStatus(),
            'duration' => $notification->getDuration(),
        ])
        ->values()
        ->all();
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    /** An actor holding exactly the named permissions and nothing else. */
    $this->holding = function (string ...$permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(...array_map(
            fn (string $name): Permission => Permission::findByName($name),
            $permissions,
        ));

        return $actor;
    };

    $this->creator = ($this->holding)('run_payroll');
    $this->finalizer = ($this->holding)('finalize_payroll');
    $this->rateSetter = ($this->holding)('create_staff_compensation');

    /** Salary 3000.000 from 1 January, then an April draft with its single line. */
    $this->aprilDraft = function (string $rate = '3000.000'): array {
        $employee = User::factory()->create(['name' => 'Salary Employee']);
        StaffCompensation::factory()->create([
            'user_id' => $employee->getKey(),
            'type' => CompensationType::Salary,
            'amount' => $rate,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $run = app(CreatePayrollRunAction::class)->execute(
            $this->creator,
            PayrollRunType::MonthlySalary,
            '2026-04-01',
            '2026-04-30',
        );

        return [$run, $employee];
    };

    $this->raiseTo = function (User $employee, string $amount, string $from): void {
        app(ChangeCompensationAction::class)->execute(
            $this->rateSetter,
            (int) $employee->getKey(),
            CompensationType::Salary,
            $amount,
            $from,
        );
    };

    /** @return list<array{0: string, 1: string, 2: string}> start, end, amount of every line */
    $this->shape = fn (PayrollRun $run): array => $run->lines()->orderBy('segment_start')->get()
        ->map(fn (PayrollLine $line): array => [
            $line->segment_start->toDateString(),
            $line->segment_end->toDateString(),
            $line->computed_amount,
        ])->all();

    $this->reviewPage = fn (User $viewer, PayrollRun $run) => Livewire::actingAs($viewer)
        ->test(ReviewPayrollRun::class, ['record' => $run->getKey()]);
});

it('splits the stale line at the raise and finalize posts exactly 1500.000 + 1800.000', function () {
    [$run, $employee] = ($this->aprilDraft)();

    expect(($this->shape)($run))->toBe([['2026-04-01', '2026-04-30', '3000.000']]);

    ($this->raiseTo)($employee, '3600.000', '2026-04-16');

    // No page was opened: finalize alone recalculates under its lock.
    app(FinalizePayrollRunAction::class)->execute($this->finalizer, $run);

    $lines = $run->lines()->orderBy('segment_start')->get();

    expect(($this->shape)($run))->toBe([
        ['2026-04-01', '2026-04-15', '1500.000'],
        ['2026-04-16', '2026-04-30', '1800.000'],
    ])
        ->and($lines->pluck('frozen_rate')->all())->toBe(['3000.000', '3600.000'])
        ->and($lines->pluck('posting_period_start')->map->toDateString()->all())->toBe(['2026-04-01', '2026-04-01'])
        ->and($lines->every(fn (PayrollLine $line): bool => $line->finalized_at !== null))->toBeTrue()
        ->and(app(WageCostReport::class)->forMonth(2026, 4)->sole()['total']->toDecimal())->toBe('3300.000');
});

it('recalculates when the review page is opened and says so with an info toast', function () {
    [$run, $employee] = ($this->aprilDraft)();
    ($this->raiseTo)($employee, '3600.000', '2026-04-16');

    $viewer = ($this->holding)('run_payroll', 'view_payroll_run', 'view_any_payroll_run');

    $page = ($this->reviewPage)($viewer, $run);

    expect(salaryDraftNotifications())->toBe([
        ['title' => 'Lines were recalculated from the current pay rates.', 'status' => 'info', 'duration' => 6000],
    ])
        ->and(($this->shape)($run))->toBe([
            ['2026-04-01', '2026-04-15', '1500.000'],
            ['2026-04-16', '2026-04-30', '1800.000'],
        ]);

    // The page shows the new lines straight away, not the ones it was opened on.
    $page->assertSee('1500.000 LYD')->assertSee('1800.000 LYD');
});

it('writes nothing and says nothing when the draft already matches the rates', function () {
    [$run] = ($this->aprilDraft)();
    $viewer = ($this->holding)('run_payroll', 'view_payroll_run', 'view_any_payroll_run');
    $idsBefore = $run->lines()->orderBy('id')->pluck('id')->all();
    $stamp = $run->lines()->sole()->updated_at;
    $activityBefore = Activity::query()->count();

    $this->travel(5)->minutes();
    ($this->reviewPage)($viewer, $run);
    ($this->reviewPage)($viewer, $run);

    expect($run->lines()->orderBy('id')->pluck('id')->all())->toBe($idsBefore)
        ->and($run->lines()->sole()->updated_at->equalTo($stamp))->toBeTrue()
        ->and(Activity::query()->count())->toBe($activityBefore)
        ->and(salaryDraftNotifications())->toBe([]);
});

it('returns false and files no activity for a second recalculation', function () {
    [$run, $employee] = ($this->aprilDraft)();
    ($this->raiseTo)($employee, '3600.000', '2026-04-16');

    expect(app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run))->toBeTrue();

    $activityBefore = Activity::query()->count();

    expect(app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run))->toBeFalse()
        ->and(Activity::query()->count())->toBe($activityBefore);
});

it('files one activity entry on the run saying lines were recalculated', function () {
    [$run, $employee] = ($this->aprilDraft)();
    ($this->raiseTo)($employee, '3600.000', '2026-04-16');

    app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run);

    $entry = Activity::query()
        ->where('subject_type', $run->getMorphClass())
        ->where('subject_id', $run->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($entry->causer_id)->toBe($this->creator->getKey())
        ->and($entry->properties['recalculation'])->toBe('salary_lines_recalculated_from_current_rates')
        ->and($entry->properties['lines_created'])->toBe(2)
        ->and($entry->properties['lines_removed'])->toBe(1)
        ->and($entry->properties['lines_updated'])->toBe(0)
        ->and($entry->properties['adjustments_reattached'])->toBe(0);
});

it('never changes a finalized run, whatever the rates do afterwards', function () {
    [$run, $employee] = ($this->aprilDraft)();
    app(FinalizePayrollRunAction::class)->execute($this->finalizer, $run);
    $before = $run->lines()->sole();

    ($this->raiseTo)($employee, '3600.000', '2026-04-16');

    expect(app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run))->toBeFalse();

    $viewer = ($this->holding)('run_payroll', 'view_payroll_run', 'view_any_payroll_run');
    ($this->reviewPage)($viewer, $run);

    $after = $run->lines()->sole();

    expect($after->getKey())->toBe($before->getKey())
        ->and($after->computed_amount)->toBe('3000.000')
        ->and($after->segment_end->toDateString())->toBe('2026-04-30')
        ->and(salaryDraftNotifications())->toBe([])
        ->and(app(WageCostReport::class)->forMonth(2026, 4)->sole()['total']->toDecimal())->toBe('3000.000');
});

it('adds a line for a person who received a rate after the draft was opened', function () {
    [$run] = ($this->aprilDraft)();
    $newcomer = User::factory()->create();
    StaffCompensation::factory()->create([
        'user_id' => $newcomer->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '900.000',
        'effective_from' => '2026-04-11',
        'effective_to' => null,
    ]);

    app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run);

    // 900.000 x 20 / 30 = 600.000
    $line = $run->lines()->where('user_id', $newcomer->getKey())->sole();

    expect($line->segment_start->toDateString())->toBe('2026-04-11')
        ->and($line->computed_amount)->toBe('600.000')
        ->and($run->lines()->count())->toBe(2);
});

it('leaves instructor and adjustment runs alone', function (string $state) {
    $run = PayrollRun::factory()->{$state}()->create();
    $line = $state === 'instructorBatch'
        ? PayrollLine::factory()->instructor()->for($run, 'run')->create()
        : PayrollLine::factory()->adjustment()->for($run, 'run')->create();
    $activityBefore = Activity::query()->count();

    expect(app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run))->toBeFalse()
        ->and($run->lines()->sole()->getKey())->toBe($line->getKey())
        ->and(Activity::query()->count())->toBe($activityBefore);
})->with(['an instructor run' => ['instructorBatch'], 'an adjustment run' => ['adjustment']]);

it('corrects a tampered figure in place, keeping the line id and its bonus', function () {
    [$run] = ($this->aprilDraft)();
    $line = $run->lines()->sole();
    $line->update(['computed_amount' => '2999.000']);
    $bonus = PayrollLineAdjustment::factory()->for($line, 'line')->create(['amount' => '40.000', 'reason' => 'SENTINEL-KEPT']);

    expect(app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run))->toBeTrue();

    $after = $run->lines()->sole();

    expect($after->getKey())->toBe($line->getKey())
        ->and($after->computed_amount)->toBe('3000.000')
        ->and($bonus->fresh()->payroll_line_id)->toBe($line->getKey())
        ->and($bonus->fresh()->reason)->toBe('SENTINEL-KEPT');
});

/*
|--------------------------------------------------------------------------
| Bonuses and deductions are never dropped (owner decision: reconcile, re-attach)
|--------------------------------------------------------------------------
*/

it('moves a split line\'s bonus and deduction to the earliest line of the month, intact', function () {
    [$run, $employee] = ($this->aprilDraft)();
    $original = $run->lines()->sole();
    $author = User::factory()->create();
    $bonus = PayrollLineAdjustment::factory()->for($original, 'line')->create([
        'amount' => '200.000', 'reason' => 'SENTINEL-BONUS', 'created_by' => $author->getKey(),
    ]);
    $deduction = PayrollLineAdjustment::factory()->for($original, 'line')->create([
        'amount' => '-50.000', 'reason' => 'SENTINEL-DEDUCTION', 'created_by' => $author->getKey(),
    ]);

    ($this->raiseTo)($employee, '3600.000', '2026-04-16');
    app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run);

    $first = $run->lines()->orderBy('segment_start')->firstOrFail();

    expect($first->segment_end->toDateString())->toBe('2026-04-15')
        ->and(PayrollLine::query()->whereKey($original->getKey())->exists())->toBeFalse()
        ->and(PayrollLineAdjustment::query()->count())->toBe(2)
        ->and($bonus->fresh()->payroll_line_id)->toBe($first->getKey())
        ->and($bonus->fresh()->amount)->toBe('200.000')
        ->and($bonus->fresh()->reason)->toBe('SENTINEL-BONUS')
        ->and($bonus->fresh()->created_by)->toBe($author->getKey())
        ->and($deduction->fresh()->payroll_line_id)->toBe($first->getKey())
        ->and($deduction->fresh()->amount)->toBe('-50.000')
        ->and($deduction->fresh()->reason)->toBe('SENTINEL-DEDUCTION')
        ->and($deduction->fresh()->created_by)->toBe($author->getKey());

    $entry = Activity::query()->where('subject_id', $run->getKey())
        ->where('subject_type', $run->getMorphClass())->latest('id')->firstOrFail();

    expect($entry->properties['adjustments_reattached'])->toBe(2);
});

it('keeps the employee\'s adjustment total per posting month unchanged across a split and posts it', function () {
    [$run, $employee] = ($this->aprilDraft)();
    PayrollLineAdjustment::factory()->for($run->lines()->sole(), 'line')->create(['amount' => '200.000']);
    PayrollLineAdjustment::factory()->for($run->lines()->sole(), 'line')->create(['amount' => '-50.000']);
    $sumFor = fn (): string => (string) PayrollLineAdjustment::query()
        ->join('payroll_lines', 'payroll_lines.id', '=', 'payroll_line_adjustments.payroll_line_id')
        ->where('payroll_lines.user_id', $employee->getKey())
        ->whereDate('payroll_lines.segment_start', '>=', '2026-04-01')
        ->whereDate('payroll_lines.segment_start', '<=', '2026-04-30')
        ->sum('payroll_line_adjustments.amount');

    expect($sumFor())->toEqual('150.000');

    ($this->raiseTo)($employee, '3600.000', '2026-04-16');
    app(FinalizePayrollRunAction::class)->execute($this->finalizer, $run);

    // 200.000 - 50.000 = 150.000 still, and 3300.000 + 150.000 = 3450.000 is the April cost.
    expect($sumFor())->toEqual('150.000')
        ->and(app(WageCostReport::class)->forMonth(2026, 4)->sole()['total']->toDecimal())->toBe('3450.000');
});

it('keeps an untouched line and its adjustment where they are while another month splits', function () {
    $employee = User::factory()->create();
    StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '3100.000',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);
    // Two months in one run, as an older run holds them; the Action opens one month now (P4-T40).
    $run = legacySalaryRun($this->creator, '2026-03-01', '2026-04-30');
    [$march, $april] = $run->lines()->orderBy('segment_start')->get()->all();
    $marchBonus = PayrollLineAdjustment::factory()->for($march, 'line')->create(['amount' => '40.000']);
    $aprilBonus = PayrollLineAdjustment::factory()->for($april, 'line')->create(['amount' => '25.000']);

    ($this->raiseTo)($employee, '3600.000', '2026-04-16');
    app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run);

    $aprilFirst = $run->lines()->orderBy('segment_start')->get()[1];

    expect($run->lines()->count())->toBe(3)
        ->and($march->fresh()?->getKey())->toBe($march->getKey())
        ->and($marchBonus->fresh()->payroll_line_id)->toBe($march->getKey())
        ->and($aprilFirst->segment_start->toDateString())->toBe('2026-04-01')
        ->and($aprilFirst->segment_end->toDateString())->toBe('2026-04-15')
        ->and($aprilBonus->fresh()->payroll_line_id)->toBe($aprilFirst->getKey());
});

/**
 * A draft whose March line (with a bonus) is no longer produced by the rates and
 * whose employee has no other March line, beside an April line with a stale figure
 * that a successful recalculation would have corrected.
 *
 * @return array{0: PayrollRun, 1: User, 2: PayrollLine, 3: PayrollLine, 4: PayrollLineAdjustment}
 */
function salaryDraftWithUnplaceableBonus(): array
{
    $employee = User::factory()->create();
    $rate = StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '3000.000',
        'effective_from' => '2026-04-01',
        'effective_to' => null,
    ]);
    $run = PayrollRun::factory()->create(['period_start' => '2026-04-01', 'period_end' => '2026-04-30']);
    $stale = PayrollLine::factory()->for($run, 'run')->create([
        'user_id' => $employee->getKey(),
        'staff_compensation_id' => $rate->getKey(),
        'segment_start' => '2026-04-01',
        'segment_end' => '2026-04-30',
        'frozen_rate' => '3000.000',
        'frozen_days' => 30,
        'frozen_days_in_month' => 30,
        'computed_amount' => '2999.000',
    ]);
    $march = PayrollLine::factory()->for($run, 'run')->create([
        'user_id' => $employee->getKey(),
        'staff_compensation_id' => $rate->getKey(),
        'segment_start' => '2026-03-01',
        'segment_end' => '2026-03-31',
        'frozen_rate' => '3000.000',
        'frozen_days' => 31,
        'frozen_days_in_month' => 31,
        'computed_amount' => '3000.000',
    ]);
    $bonus = PayrollLineAdjustment::factory()->for($march, 'line')->create(['amount' => '75.000']);

    return [$run, $employee, $stale, $march, $bonus];
}

it('refuses the whole recalculation, writing nothing, when a bonus has no line left in its month', function () {
    [$run, , $stale, $march, $bonus] = salaryDraftWithUnplaceableBonus();
    $activityBefore = Activity::query()->count();

    expect(fn () => app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run))
        ->toThrow(SalaryAdjustmentsHaveNoLineException::class);

    expect($stale->fresh()->computed_amount)->toBe('2999.000')
        ->and($march->fresh())->not->toBeNull()
        ->and($bonus->fresh()->payroll_line_id)->toBe($march->getKey())
        ->and($run->lines()->count())->toBe(2)
        ->and(Activity::query()->count())->toBe($activityBefore);
});

it('shows that refusal on the review page as a persistent danger toast and still renders the draft', function () {
    [$run] = salaryDraftWithUnplaceableBonus();
    $viewer = ($this->holding)('run_payroll', 'view_payroll_run', 'view_any_payroll_run');

    ($this->reviewPage)($viewer, $run)->assertSuccessful();

    expect(salaryDraftNotifications())->toBe([[
        'title' => 'A bonus or deduction on this draft cannot be kept with the current pay rates, because the employee has no line left in that month. Nothing was changed; delete the draft and create it again.',
        'status' => 'danger',
        'duration' => 'persistent',
    ]]);
});

it('refuses finalize with the same message and posts nothing', function () {
    [$run, , $stale, $march] = salaryDraftWithUnplaceableBonus();

    expect(fn () => app(FinalizePayrollRunAction::class)->execute($this->finalizer, $run))
        ->toThrow(SalaryAdjustmentsHaveNoLineException::class, 'cannot be kept with the current pay rates');

    expect($run->fresh()->finalized_at)->toBeNull()
        ->and($stale->fresh()->finalized_at)->toBeNull()
        ->and($stale->fresh()->computed_amount)->toBe('2999.000')
        ->and($march->fresh())->not->toBeNull();
});

it('shows the refusal as a danger toast when finalize is pressed on the page', function () {
    [$run] = salaryDraftWithUnplaceableBonus();
    $operator = ($this->holding)('view_payroll_run', 'view_any_payroll_run', 'finalize_payroll');

    ($this->reviewPage)($operator, $run)->callAction('finalize');

    expect(salaryDraftNotifications())->toBe([[
        'title' => 'A bonus or deduction on this draft cannot be kept with the current pay rates, because the employee has no line left in that month. Nothing was changed; delete the draft and create it again.',
        'status' => 'danger',
        'duration' => 'persistent',
    ]])
        ->and($run->fresh()->finalized_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Authorization: run_payroll recalculates a draft, finalize_payroll finalizes
|--------------------------------------------------------------------------
*/

it('refuses the standalone recalculation to an actor one permission short, and allows run_payroll alone', function () {
    [$run, $employee] = ($this->aprilDraft)();
    ($this->raiseTo)($employee, '3600.000', '2026-04-16');

    // Everything the review page needs except run_payroll.
    $oneShort = ($this->holding)('view_payroll_run', 'view_any_payroll_run', 'finalize_payroll');

    expect(fn () => app(RecalculateSalaryDraftAction::class)->execute($oneShort, $run))
        ->toThrow(AuthorizationException::class)
        ->and(($this->shape)($run))->toBe([['2026-04-01', '2026-04-30', '3000.000']]);

    expect(app(RecalculateSalaryDraftAction::class)->execute($this->creator, $run))->toBeTrue()
        ->and($run->lines()->count())->toBe(2);
});

it('renders the page for a read-only viewer without writing or notifying', function () {
    [$run, $employee] = ($this->aprilDraft)();
    ($this->raiseTo)($employee, '3600.000', '2026-04-16');
    $viewer = ($this->holding)('view_payroll_run', 'view_any_payroll_run');
    $activityBefore = Activity::query()->count();

    ($this->reviewPage)($viewer, $run)->assertSuccessful()->assertSee('3000.000 LYD');

    expect(($this->shape)($run))->toBe([['2026-04-01', '2026-04-30', '3000.000']])
        ->and(Activity::query()->count())->toBe($activityBefore)
        ->and(salaryDraftNotifications())->toBe([]);
});

it('lets finalize recalculate for an actor who holds finalize_payroll but not run_payroll', function () {
    [$run, $employee] = ($this->aprilDraft)();
    ($this->raiseTo)($employee, '3600.000', '2026-04-16');

    app(FinalizePayrollRunAction::class)->execute($this->finalizer, $run);

    expect(($this->shape)($run))->toBe([
        ['2026-04-01', '2026-04-15', '1500.000'],
        ['2026-04-16', '2026-04-30', '1800.000'],
    ]);
});
