<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\FinalizePayrollRunAction;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ReviewPayrollRun;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Every notification sent so far, as title, status and duration.
 *
 * Reading them takes them out of the session, so this is called once per test.
 * Filament's own assertNotNotified() is not used for the "no other toast" checks:
 * it runs after an earlier assert has already emptied the session and then passes
 * whatever was sent.
 *
 * @return list<array{title: string, status: string, duration: int|string}>
 */
function payrollSentNotifications(): array
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

    /*
     * Exactly the four permissions the review page needs. The super admin role
     * would pass whatever ability the page or an Action checked.
     */
    $this->payrollActor = User::factory()->create(['is_active' => true, 'name' => 'Payroll Operator']);
    $this->payrollActor->givePermissionTo(
        Permission::findByName('view_any_payroll_run'),
        Permission::findByName('view_payroll_run'),
        Permission::findByName('run_payroll'),
        Permission::findByName('finalize_payroll'),
    );

    /**
     * A draft salary run with one line per computed amount.
     *
     * @param  list<string>  $computedAmounts
     * @return array{0: PayrollRun, 1: list<PayrollLine>}
     */
    $this->draftRun = function (array $computedAmounts): array {
        $run = PayrollRun::factory()->create();
        $lines = [];

        foreach ($computedAmounts as $index => $amount) {
            $employee = User::factory()->create(['name' => "Line Employee {$index}"]);

            // A salary draft follows the rates on file (P4-T38) and opening it
            // recalculates it, so the monthly rate is the amount the whole-month
            // line is worth; otherwise the page would rewrite the fixture.
            $rate = StaffCompensation::factory()->create([
                'user_id' => $employee->getKey(),
                'amount' => $amount,
            ]);

            $lines[] = PayrollLine::factory()->for($run, 'run')->create([
                'user_id' => $employee->getKey(),
                'staff_compensation_id' => $rate->getKey(),
                'frozen_rate' => $amount,
                'computed_amount' => $amount,
            ]);
        }

        return [$run, $lines];
    };

    $this->reviewPage = fn (PayrollRun $run) => Livewire::actingAs($this->payrollActor)
        ->test(ReviewPayrollRun::class, ['record' => $run->getKey()]);
});

it('finalizes with a success toast and re-renders the page as finalized without a reload', function () {
    [$run] = ($this->draftRun)(['309.000']);

    // The finalizer's name only appears once the run is finalized, so the assertSee
    // below proves the page re-rendered, not that the name was there all along.
    $page = ($this->reviewPage)($run)->assertSee('Draft')->assertDontSee('Payroll Operator');

    $page->callAction('finalize')
        ->assertNotified(FilamentNotification::make()->title('Payroll run finalized.')->success())
        ->assertDontSee('Draft')
        ->assertSee('Payroll Operator');

    expect($run->fresh()?->finalized_at)->not->toBeNull()
        ->and($run->fresh()?->finalized_by)->toBe($this->payrollActor->getKey());
});

it('says why a refused finalize was refused and leaves the run a draft', function () {
    [$run, [$line]] = ($this->draftRun)(['309.000']);

    // A different run already finalized the same employee's same month.
    // (an older run that began a month earlier: a month holds one salary run, P4-T40)
    PayrollLine::factory()
        ->finalized()
        ->for(PayrollRun::factory()->finalized()->state([
            'period_start' => $run->period_start->subMonth()->startOfMonth()->toDateString(),
            'period_end' => $run->period_end->toDateString(),
        ]), 'run')
        ->create([
            'user_id' => $line->user_id,
            'segment_start' => $line->segment_start,
            'segment_end' => $line->segment_end,
        ]);

    $page = ($this->reviewPage)($run)->callAction('finalize');

    // Exactly one toast: the refusal, and no success toast beside it.
    expect(payrollSentNotifications())->toBe([
        ['title' => 'A finalized salary segment already overlaps this period.', 'status' => 'danger', 'duration' => 'persistent'],
    ]);
    $page->assertSee('Draft');

    expect($run->fresh()?->finalized_at)->toBeNull()
        ->and($run->fresh()?->finalized_by)->toBeNull()
        ->and($line->fresh()?->finalized_at)->toBeNull();
});

it('still says something when a refusal carries no message', function () {
    [$run] = ($this->draftRun)(['309.000']);

    // The Action is final, so a plain object stands in for it in the container.
    app()->instance(FinalizePayrollRunAction::class, new class
    {
        public function execute(): never
        {
            throw ValidationException::withMessages([]);
        }
    });

    ($this->reviewPage)($run)->callAction('finalize');

    expect(payrollSentNotifications())->toBe([
        ['title' => 'This change was refused.', 'status' => 'danger', 'duration' => 'persistent'],
    ])
        ->and($run->fresh()?->finalized_at)->toBeNull();
});

it('adds a bonus with a success toast and shows its reason, amount and author under its line', function () {
    [$run, [$line]] = ($this->draftRun)(['309.000']);

    $page = ($this->reviewPage)($run)
        ->assertDontSee('SENTINEL-BONUS-REASON');

    $page->callAction('add_adjustment', data: [
        'line_id' => $line->getKey(),
        'amount' => '10.125',
        'reason' => 'SENTINEL-BONUS-REASON',
    ])
        ->assertNotified(FilamentNotification::make()->title('Bonus or deduction added.')->success())
        ->assertSee('SENTINEL-BONUS-REASON')
        ->assertSee('10.125 LYD')
        ->assertSee('Payroll Operator')
        ->assertSee('319.125 LYD');

    $adjustment = PayrollLineAdjustment::query()->sole();

    expect($adjustment->amount)->toBe('10.125')
        ->and($adjustment->reason)->toBe('SENTINEL-BONUS-REASON')
        ->and($adjustment->created_by)->toBe($this->payrollActor->getKey());
});

it('says why a refused bonus was refused and writes nothing', function (string $amount, bool $lineAlreadyFinalized, string $message) {
    [$run, [$line]] = ($this->draftRun)(['309.000']);

    if ($lineAlreadyFinalized) {
        $line->forceFill(['finalized_at' => now(), 'posting_period_start' => '2026-01-01'])->save();
    }

    $page = ($this->reviewPage)($run)->callAction('add_adjustment', data: [
        'line_id' => $line->getKey(),
        'amount' => $amount,
        'reason' => 'SENTINEL-REFUSED-REASON',
    ]);

    $page->assertDontSee('SENTINEL-REFUSED-REASON');

    // Exactly one toast: the refusal, and no success toast beside it.
    expect(payrollSentNotifications())->toBe([
        ['title' => $message, 'status' => 'danger', 'duration' => 'persistent'],
    ])
        ->and(PayrollLineAdjustment::query()->count())->toBe(0);
})->with([
    'a zero amount' => ['0.000', false, 'Enter a non-zero amount.'],
    'a line already finalized' => ['5.000', true, 'Bonuses and deductions can be added only while the payroll run is draft.'],
]);

it('shows the signed amount, reason, author and time of every adjustment under its line', function () {
    [$run, [$line]] = ($this->draftRun)(['309.000']);
    $author = User::factory()->create(['name' => 'Sentinel Adjustment Author']);

    PayrollLineAdjustment::factory()->for($line, 'line')->create([
        'amount' => '10.125',
        'reason' => 'SENTINEL-BONUS-REASON',
        'created_by' => $author->getKey(),
        'created_at' => '2026-05-04 10:30:15',
    ]);
    PayrollLineAdjustment::factory()->for($line, 'line')->create([
        'amount' => '-0.250',
        'reason' => 'SENTINEL-DEDUCTION-REASON',
        'created_by' => $author->getKey(),
    ]);

    ($this->reviewPage)($run)
        ->assertSee('Bonuses and deductions')
        ->assertSee('10.125 LYD')
        ->assertSee('-0.250 LYD')
        ->assertSee('SENTINEL-BONUS-REASON')
        ->assertSee('SENTINEL-DEDUCTION-REASON')
        ->assertSee('Sentinel Adjustment Author')
        // Stored 10:30:15 UTC; the page reads on the centre's clock, two hours ahead (P4-T34).
        ->assertSee('May 4, 2026 12:30:15');
});

it('totals each line after its adjustments in exact decimal arithmetic', function () {
    [$run, [$mixed, $tenths, $plain]] = ($this->draftRun)(['309.000', '100.000', '75.500']);

    foreach (['10.125', '-0.250'] as $amount) {
        PayrollLineAdjustment::factory()->for($mixed, 'line')->create(['amount' => $amount]);
    }

    // 0.1 + 0.2 is the classic float drift; the page must print exactly 100.300.
    foreach (['0.100', '0.200'] as $amount) {
        PayrollLineAdjustment::factory()->for($tenths, 'line')->create(['amount' => $amount]);
    }

    $html = ($this->reviewPage)($run)->html();

    // The computed amount is printed once on each line, so an unadjusted line
    // prints its figure twice: as computed and as total.
    // Changed in P4-T38: a fixture line's rate now equals its amount (so the page's
    // recalculation leaves it alone), which prints the amount once more as the rate.
    // Changed in P4-T40: a salary run is grouped by employee, so each person's total for the
    // run is printed once more, above their segments. A one-segment person's total equals the
    // line total: 318.875 and 100.300 appear twice, and 75.500 four times.
    expect(substr_count($html, '318.875 LYD'))->toBe(2)
        ->and(substr_count($html, '309.000 LYD'))->toBe(2)
        ->and(substr_count($html, '100.300 LYD'))->toBe(2)
        ->and(substr_count($html, '100.000 LYD'))->toBe(2)
        ->and(substr_count($html, '75.500 LYD'))->toBe(4)
        ->and($html)->not->toContain('100.30000')
        ->and($html)->not->toContain('LYD LYD');
});

it('shows no bonus block under a line with no adjustment', function () {
    [$run] = ($this->draftRun)(['309.000', '100.000']);

    ($this->reviewPage)($run)->assertDontSee('Bonuses and deductions');
});

it('reads a fixed number of adjustment and author queries however many lines there are', function () {
    $measure = function (int $lines): int {
        // One salary run per month (P4-T40): the first measurement's run is cleared
        // (its lines and bonuses with it) so the second can use the same month.
        PayrollRun::query()->delete();

        // A salary draft takes every salary rate in force in its period, so the rates
        // an earlier measurement left behind are ended or they would add lines here.
        StaffCompensation::query()->update(['effective_to' => DB::raw('effective_from')]);

        [$run, $created] = ($this->draftRun)(array_fill(0, $lines, '50.000'));

        foreach ($created as $line) {
            PayrollLineAdjustment::factory()->count(2)->for($line, 'line')->create([
                'created_by' => User::factory()->create()->getKey(),
            ]);
        }

        $statements = captureStatements();
        ($this->reviewPage)($run)->assertSuccessful();

        return collect($statements)->filter(fn (array $statement): bool => str_starts_with($statement['sql'], 'select')
            && preg_match('/\bfrom `(?:payroll_line_adjustments|payroll_lines|users)`/', $statement['sql']) === 1)->count();
    };

    $one = $measure(1);
    $four = $measure(4);

    expect($four)->toBe($one);
});
