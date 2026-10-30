<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\AddPayrollLineAdjustmentAction;
use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Actions\DeletePayrollRunAction;
use App\Domain\Finance\Actions\FinalizePayrollRunAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Exceptions\SalaryMonthAlreadyHasRunException;
use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\CreatePayrollRun;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ReviewPayrollRun;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/PayrollLegacySalaryRun.php';

/*
|--------------------------------------------------------------------------
| One salary run per calendar month (P4-T40)
|--------------------------------------------------------------------------
|
| A monthly salary run covers exactly one calendar month, and a month has at most
| one such run, draft or finalized. The form asks for a month; the Action refuses
| a period that is not one whole month and a month that is already covered (by
| overlap, so an older run of an odd period still blocks every month it touches);
| a unique generated column holds the same rule at the database. The review page
| groups a salary run's lines by employee and says why a person has two.
|
| The race between two creations of one month is in
| PayrollSalaryRunMonthConcurrencyTest, which needs real subprocesses.
|
| EVERY ACTOR HOLDS EXACTLY THE PERMISSIONS NAMED, NEVER A ROLE. The figures are
| written out by hand: 3720 a month over 31 days is 120 a day, so 16 days are 1920;
| 3100 over 31 days is 100 a day, so 15 days are 1500.
*/
uses(RefreshDatabase::class);

/**
 * Every notification sent so far, as title, status and duration. Reading them takes
 * them out of the session, so call it once per test.
 *
 * @return list<array{title: string, status: string, duration: int|string}>
 */
function salaryMonthSentNotifications(): array
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
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo('2026-10-30 10:00:00');

    $this->holding = function (string ...$permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(...array_map(fn (string $name): Permission => Permission::findByName($name), $permissions));
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };

    $this->creator = ($this->holding)('run_payroll');
    // The create PAGE also needs to see the list it belongs to; the Action needs run_payroll alone.
    $this->pageCreator = ($this->holding)('run_payroll', 'view_any_payroll_run');

    // Alpha's rate rose on 16 October (two lines); Beta has one rate all month (one line).
    $this->alpha = User::factory()->create(['name' => 'Alpha Employee']);
    $this->alphaOld = StaffCompensation::factory()->create([
        'user_id' => $this->alpha->getKey(), 'type' => CompensationType::Salary,
        'amount' => '3100.000', 'effective_from' => '2026-01-01', 'effective_to' => '2026-10-15',
    ]);
    $this->alphaNew = StaffCompensation::factory()->create([
        'user_id' => $this->alpha->getKey(), 'type' => CompensationType::Salary,
        'amount' => '3720.000', 'effective_from' => '2026-10-16', 'effective_to' => null,
    ]);
    $this->beta = User::factory()->create(['name' => 'Beta Employee']);
    StaffCompensation::factory()->create([
        'user_id' => $this->beta->getKey(), 'type' => CompensationType::Salary,
        'amount' => '3100.000', 'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);

    $this->createSalary = fn (string $start, string $end, ?User $actor = null): PayrollRun => app(CreatePayrollRunAction::class)->execute(
        $actor ?? $this->creator,
        PayrollRunType::MonthlySalary,
        $start,
        $end,
    );
    $this->october = fn (): PayrollRun => ($this->createSalary)('2026-10-01', '2026-10-31');
});

/*
| The form
*/

it('opens a run for the month picked on the form, from its first day to its last', function () {
    $page = Livewire::actingAs($this->pageCreator)
        ->test(CreatePayrollRun::class)
        ->fillForm(['type' => PayrollRunType::MonthlySalary->value, 'salary_month' => '2026-10', 'notes' => 'October payroll'])
        ->call('create')
        ->assertHasNoFormErrors();

    $run = PayrollRun::query()->sole();
    $lines = $run->lines()->with('user')->orderBy('user_id')->orderBy('segment_start')->get();

    expect($run->period_start->toDateString())->toBe('2026-10-01')
        ->and($run->period_end->toDateString())->toBe('2026-10-31')
        ->and($run->notes)->toBe('October payroll')
        ->and($lines->map(fn (PayrollLine $line): array => [
            $line->user->name, $line->segment_start->toDateString(), $line->segment_end->toDateString(), $line->computed_amount,
        ])->all())->toBe([
            ['Alpha Employee', '2026-10-01', '2026-10-15', '1500.000'],
            ['Alpha Employee', '2026-10-16', '2026-10-31', '1920.000'],
            ['Beta Employee', '2026-10-01', '2026-10-31', '3100.000'],
        ]);

    $page->assertRedirect(PayrollRunResource::getUrl('review', ['record' => $run]));
});

it('asks for a month rather than two dates', function () {
    $page = Livewire::actingAs($this->pageCreator)
        ->test(CreatePayrollRun::class)
        ->fillForm(['type' => PayrollRunType::MonthlySalary->value]);

    $page->assertFormFieldExists('salary_month')->assertFormFieldDoesNotExist('period_start')->assertFormFieldDoesNotExist('period_end');
});

it('offers 24 months back to 3 ahead of the current one, newest first, and marks a month that has a run', function () {
    $run = ($this->october)();

    $options = PayrollRunResource::salaryMonthOptions();
    $keys = array_keys($options);

    expect($keys[0])->toBe('2027-01')
        ->and(end($keys))->toBe('2024-10')
        ->and($options)->toHaveCount(28)
        ->and($options['2026-11'])->toBe('November 2026')
        ->and($options['2026-10'])->toBe("October 2026 (already has run #{$run->getKey()})")
        ->and($options['2026-09'])->toBe('September 2026');
});

it('marks both months a run of an odd period touches', function () {
    $legacy = PayrollRun::factory()->create(['period_start' => '2026-08-16', 'period_end' => '2026-09-15']);

    $options = PayrollRunResource::salaryMonthOptions();

    expect($options['2026-08'])->toBe("August 2026 (already has run #{$legacy->getKey()})")
        ->and($options['2026-09'])->toBe("September 2026 (already has run #{$legacy->getKey()})")
        ->and($options['2026-07'])->toBe('July 2026')
        ->and($options['2026-10'])->toBe('October 2026');
});

it('names the months in the current language', function () {
    app()->setLocale('ar');

    // Hand-written: the Arabic name of October is "أكتوبر" and of November "نوفمبر".
    expect(PayrollRunResource::salaryMonthOptions()['2026-11'])->toBe('نوفمبر 2026');
});

it('shows the refusal as a persistent danger toast and keeps the form, when the month already has a run', function () {
    $existing = ($this->october)();

    $page = Livewire::actingAs($this->pageCreator)
        ->test(CreatePayrollRun::class)
        ->fillForm(['type' => PayrollRunType::MonthlySalary->value, 'salary_month' => '2026-10'])
        ->call('create');

    expect(salaryMonthSentNotifications())->toBe([[
        'title' => "October 2026 already has a salary run (#{$existing->getKey()}). Open it instead.",
        'status' => 'danger',
        'duration' => 'persistent',
    ]]);
    $page->assertNoRedirect();

    expect(PayrollRun::query()->count())->toBe(1)
        ->and(PayrollLine::query()->count())->toBe(3);
});

/*
| The Action: one whole month
*/

it('refuses a salary period that is not exactly one calendar month and writes nothing', function (string $start, string $end) {
    expect(fn () => ($this->createSalary)($start, $end))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe([
                'period_start' => ['A monthly salary run covers exactly one calendar month, from its first day to its last.'],
            ]);
        });

    expect(PayrollRun::query()->count())->toBe(0)
        ->and(PayrollLine::query()->count())->toBe(0);
})->with([
    'a month and a day' => ['2026-10-01', '2026-11-01'],
    'not from the first' => ['2026-10-02', '2026-10-31'],
    'two months' => ['2026-10-01', '2026-11-30'],
    'short of the last day' => ['2026-10-01', '2026-10-30'],
    'a single day' => ['2026-10-15', '2026-10-15'],
]);

it('accepts the first to the last day of every kind of month', function (string $start, string $end) {
    expect(($this->createSalary)($start, $end)->period_end->toDateString())->toBe($end);
})->with([
    'a 31-day month' => ['2026-12-01', '2026-12-31'],
    'a 30-day month' => ['2026-11-01', '2026-11-30'],
    'February in a common year' => ['2026-02-01', '2026-02-28'],
    'February in a leap year' => ['2028-02-01', '2028-02-29'],
]);

/*
| The Action: one run per month, whatever its state
*/

it('refuses a second run for a month that has a draft, naming the first, and writes nothing', function () {
    $first = ($this->october)();

    try {
        ($this->october)();
        $this->fail('The second October run was not refused.');
    } catch (SalaryMonthAlreadyHasRunException $exception) {
        expect($exception->getMessage())->toBe("October 2026 already has a salary run (#{$first->getKey()}). Open it instead.")
            ->and($exception->existingRunId)->toBe($first->getKey());
    }

    expect(PayrollRun::query()->count())->toBe(1)
        ->and(PayrollLine::query()->count())->toBe(3);
});

it('refuses a second run for a month that has a finalized run, naming the first, and writes nothing', function () {
    $first = ($this->october)();
    app(FinalizePayrollRunAction::class)->execute(($this->holding)('finalize_payroll'), $first);

    expect(fn () => ($this->october)())
        ->toThrow(SalaryMonthAlreadyHasRunException::class, "October 2026 already has a salary run (#{$first->getKey()}). Open it instead.");

    expect(PayrollRun::query()->count())->toBe(1)
        ->and(PayrollLine::query()->count())->toBe(3);
});

it('refuses in Arabic when the language is Arabic', function () {
    $first = ($this->october)();
    app()->setLocale('ar');

    expect(fn () => ($this->october)())
        ->toThrow(SalaryMonthAlreadyHasRunException::class, "يوجد مسير رواتب لشهر أكتوبر 2026 (رقم {$first->getKey()}). افتحه بدلًا من ذلك.");
});

it('lets the month be opened again once its draft is deleted', function () {
    $first = ($this->october)();
    app(DeletePayrollRunAction::class)->execute(($this->holding)('delete_payroll_run'), $first);

    $second = ($this->october)();

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(PayrollRun::query()->count())->toBe(1)
        ->and($second->lines()->count())->toBe(3);
});

it('lets a different month be opened beside an existing one', function () {
    ($this->october)();

    $november = ($this->createSalary)('2026-11-01', '2026-11-30');
    $september = ($this->createSalary)('2026-09-01', '2026-09-30');

    expect(PayrollRun::query()->count())->toBe(3)
        ->and($november->period_start->toDateString())->toBe('2026-11-01')
        ->and($september->period_end->toDateString())->toBe('2026-09-30');
});

it('lets an older run of an odd period block every month it touches, and no other', function (string $start, string $end, bool $blocked) {
    // Inserted raw, as a run opened before the one-month rule: 1 October to 1 November.
    $legacyId = DB::table('payroll_runs')->insertGetId([
        'type' => 'monthly_salary', 'period_start' => '2026-10-01', 'period_end' => '2026-11-01',
        'created_by' => $this->creator->getKey(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    if (! $blocked) {
        expect(($this->createSalary)($start, $end)->exists)->toBeTrue();

        return;
    }

    expect(fn () => ($this->createSalary)($start, $end))
        ->toThrow(SalaryMonthAlreadyHasRunException::class, "already has a salary run (#{$legacyId}). Open it instead.");

    expect(PayrollRun::query()->count())->toBe(1);
})->with([
    'October' => ['2026-10-01', '2026-10-31', true],
    'November' => ['2026-11-01', '2026-11-30', true],
    'September' => ['2026-09-01', '2026-09-30', false],
    'December' => ['2026-12-01', '2026-12-31', false],
]);

it('names the requested month, not the old run\'s own period, in the refusal', function () {
    DB::table('payroll_runs')->insert([
        'type' => 'monthly_salary', 'period_start' => '2026-10-01', 'period_end' => '2026-11-01',
        'created_by' => $this->creator->getKey(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => ($this->createSalary)('2026-11-01', '2026-11-30'))
        ->toThrow(SalaryMonthAlreadyHasRunException::class, 'November 2026 already has a salary run');
});

it('does not limit instructor batch or adjustment runs, which have no month', function () {
    $october = ($this->october)();

    $adjustments = [
        app(CreatePayrollRunAction::class)->execute($this->creator, PayrollRunType::Adjustment),
        app(CreatePayrollRunAction::class)->execute($this->creator, PayrollRunType::Adjustment),
    ];
    $instructor = PayrollRun::factory()->instructorBatch()->count(2)->create();

    expect(PayrollRun::query()->count())->toBe(5)
        ->and(DB::table('payroll_runs')->whereIn('id', [...array_map(fn (PayrollRun $run): int => $run->getKey(), $adjustments), ...$instructor->modelKeys()])->whereNotNull('salary_month')->count())->toBe(0)
        ->and(DB::table('payroll_runs')->where('id', $october->getKey())->value('salary_month'))->toBe('2026-10-01');
});

/*
| The database
*/

it('holds the rule at the database: a unique stored generated column on salary runs', function () {
    $column = DB::selectOne(
        'select extra as extra from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
        ['payroll_runs', 'salary_month'],
    );
    $index = DB::selectOne(
        'select non_unique as non_unique from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? and column_name = ?',
        ['payroll_runs', 'uniq_salary_run_per_month', 'salary_month'],
    );

    expect($column->extra)->toContain('STORED GENERATED')
        ->and((int) $index->non_unique)->toBe(0);

    $row = fn (): array => [
        'type' => 'monthly_salary', 'period_start' => '2026-12-01', 'period_end' => '2026-12-31',
        'created_by' => $this->creator->getKey(), 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('payroll_runs')->insert($row());

    expect(fn () => DB::table('payroll_runs')->insert($row()))->toThrow(QueryException::class, 'uniq_salary_run_per_month');
});

it('finds salary runs that cover the same calendar month, listing their ids, without touching them', function () {
    $migration = require database_path('migrations/2026_10_30_000100_add_salary_month_to_payroll_runs_table.php');

    // The real table can no longer hold a clash, so the check runs on a scratch copy of its shape.
    DB::statement('create temporary table payroll_runs_scratch (id int primary key, type varchar(30), period_start date null, period_end date null)');
    DB::table('payroll_runs_scratch')->insert([
        // The same start, three times.
        ['id' => 4, 'type' => 'monthly_salary', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'],
        ['id' => 9, 'type' => 'monthly_salary', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'],
        ['id' => 11, 'type' => 'monthly_salary', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'],
        // Different starts in the same month: 1–15 and 16–30 June.
        ['id' => 20, 'type' => 'monthly_salary', 'period_start' => '2026-06-01', 'period_end' => '2026-06-15'],
        ['id' => 21, 'type' => 'monthly_salary', 'period_start' => '2026-06-16', 'period_end' => '2026-06-30'],
        // A cross-month run (1 Aug – 1 Sep) and the September run.
        ['id' => 30, 'type' => 'monthly_salary', 'period_start' => '2026-08-01', 'period_end' => '2026-09-01'],
        ['id' => 31, 'type' => 'monthly_salary', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'],
        // Clean: a lone month, and other run types.
        ['id' => 5, 'type' => 'monthly_salary', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30'],
        ['id' => 12, 'type' => 'instructor_batch', 'period_start' => null, 'period_end' => null],
        ['id' => 13, 'type' => 'instructor_batch', 'period_start' => null, 'period_end' => null],
    ]);

    expect($migration->duplicateSalaryRuns('payroll_runs_scratch'))->toBe([
        '2026-06: runs #20, #21',
        '2026-09: runs #30, #31',
        '2026-10: runs #4, #9, #11',
    ])->and(DB::table('payroll_runs_scratch')->count())->toBe(10);

    DB::table('payroll_runs_scratch')->whereIn('id', [9, 11, 21, 31])->delete();

    // A run crossing into a month no other run touches clashes with nothing, itself included.
    expect($migration->duplicateSalaryRuns('payroll_runs_scratch'))->toBe([]);

    DB::statement('drop temporary table payroll_runs_scratch');
});

/*
| Authorization
*/

it('refuses an actor who may not run payroll before looking at the period', function () {
    $short = ($this->holding)('view_any_payroll_run', 'view_payroll_run', 'finalize_payroll', 'delete_payroll_run');

    expect(fn () => ($this->createSalary)('2026-10-02', '2026-11-01', $short))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->createSalary)('2026-10-01', '2026-10-31', $short))->toThrow(AuthorizationException::class);

    expect(PayrollRun::query()->count())->toBe(0);
});

it('lets exactly the run_payroll permission open a month, and refuse a taken one', function () {
    $run = ($this->createSalary)('2026-10-01', '2026-10-31', ($this->holding)('run_payroll'));

    expect($run->exists)->toBeTrue()
        ->and(fn () => ($this->createSalary)('2026-10-01', '2026-10-31', ($this->holding)('run_payroll')))
        ->toThrow(SalaryMonthAlreadyHasRunException::class);
});

it('hides the create page from an actor without run_payroll', function () {
    Livewire::actingAs(($this->holding)('view_any_payroll_run', 'view_payroll_run'))
        ->test(CreatePayrollRun::class)
        ->assertForbidden();
});

/*
| The review page: one card per employee
*/

/** @return array<string, array{string, string, string, string}> */
dataset('review wording', [
    'English' => ['en', 'Split on 16 Oct 2026 because the pay rate changed.', 'Split by calendar month.', 'Total for this run'],
    'Arabic' => ['ar', 'قُسِّم الراتب في 16 أكتوبر 2026 لأن معدل الأجر تغيّر.', 'قُسِّم بحسب الشهر الميلادي.', 'الإجمالي لهذا المسير'],
]);

it('shows a two-segment employee as one card with both segments and the person\'s total', function (string $locale, string $rateChanged, string $byMonth, string $totalLabel) {
    app()->setLocale($locale);
    $run = ($this->october)();
    $first = $run->lines()->where('user_id', $this->alpha->getKey())->orderBy('segment_start')->firstOrFail();
    app(AddPayrollLineAdjustmentAction::class)->execute($this->creator, $first, '80.000', 'October bonus');
    $viewer = ($this->holding)('view_any_payroll_run', 'view_payroll_run', 'run_payroll');

    $html = Livewire::actingAs($viewer)->test(ReviewPayrollRun::class, ['record' => $run->getKey()])->assertSuccessful()->html();

    // Alpha: 1500.000 + 1920.000 + the 80.000 bonus = 3500.000 for the run; Beta: 3100.000.
    expect(substr_count($html, 'Alpha Employee'))->toBe(1)
        ->and(substr_count($html, 'Beta Employee'))->toBe(1)
        ->and(substr_count($html, $totalLabel))->toBe(2)
        ->and(substr_count($html, '3500.000'))->toBe(1)
        ->and(substr_count($html, $rateChanged))->toBe(1)
        ->and($html)->not->toContain($byMonth)
        ->and($html)->toContain('1500.000')->toContain('1920.000')->toContain('October bonus');

    // Alpha's card holds both segments: the figures sit after Alpha's name and before Beta's.
    $alpha = strpos($html, 'Alpha Employee');
    $beta = strpos($html, 'Beta Employee');
    expect($alpha)->toBeLessThan(strpos($html, '1500.000'))
        ->and(strpos($html, '1920.000'))->toBeLessThan($beta);
})->with('review wording');

it('says nothing about a split for an employee with one segment', function () {
    $run = ($this->createSalary)('2026-09-01', '2026-09-30');
    // September: Alpha's old rate and Beta's rate cover the whole month, one segment each.
    $viewer = ($this->holding)('view_any_payroll_run', 'view_payroll_run');

    $html = Livewire::actingAs($viewer)->test(ReviewPayrollRun::class, ['record' => $run->getKey()])->html();

    expect($run->lines()->count())->toBe(2)
        ->and($html)->not->toContain('Split on')
        ->and($html)->not->toContain('Split by calendar month');
});

it('explains a split by calendar month, and a split by rate on top of it, on an older multi-month run', function () {
    // November: Alpha keeps the 3720.000 rate, Beta a second rate from 1 December.
    $gamma = User::factory()->create(['name' => 'Gamma Employee']);
    $gammaOld = StaffCompensation::factory()->create([
        'user_id' => $gamma->getKey(), 'type' => CompensationType::Salary,
        'amount' => '3000.000', 'effective_from' => '2026-01-01', 'effective_to' => '2026-11-30',
    ]);
    StaffCompensation::factory()->create([
        'user_id' => $gamma->getKey(), 'type' => CompensationType::Salary,
        'amount' => '3600.000', 'effective_from' => '2026-12-01', 'effective_to' => null,
    ]);
    $run = legacySalaryRun($this->creator, '2026-11-01', '2026-12-31');
    $viewer = ($this->holding)('view_any_payroll_run', 'view_payroll_run');

    $html = Livewire::actingAs($viewer)->test(ReviewPayrollRun::class, ['record' => $run->getKey()])->html();

    // Alpha and Beta carry one rate through both months (split by month only); Gamma's rate changed too.
    expect($gammaOld->exists)->toBeTrue()
        ->and(substr_count($html, 'Split by calendar month.'))->toBe(3)
        ->and(substr_count($html, 'Split on 1 Dec 2026 because the pay rate changed.'))->toBe(1)
        ->and(substr_count($html, 'Gamma Employee'))->toBe(1);
});

it('renders an instructor batch run as the flat list it always was', function () {
    $run = PayrollRun::factory()->instructorBatch()->create();
    $instructor = User::factory()->create(['name' => 'Delta Instructor']);
    PayrollLine::factory()->instructor()->for($run, 'run')->create(['user_id' => $instructor->getKey()]);
    $viewer = ($this->holding)('view_any_payroll_run', 'view_payroll_run');

    $html = Livewire::actingAs($viewer)->test(ReviewPayrollRun::class, ['record' => $run->getKey()])->html();

    expect($html)->toContain('Delta Instructor')
        ->toContain('Hours')
        ->not->toContain('Total for this run')
        ->not->toContain('Split on');
});

it('tells one person\'s two lines apart in the bonus picker by the days they cover', function () {
    $run = ($this->october)();
    $viewer = ($this->holding)('view_any_payroll_run', 'view_payroll_run', 'run_payroll');
    $second = $run->lines()->where('user_id', $this->alpha->getKey())->orderBy('segment_start')->get()[1];

    $page = Livewire::actingAs($viewer)->test(ReviewPayrollRun::class, ['record' => $run->getKey()])->mountAction('add_adjustment');
    // The options travel as JSON in the modal's markup, with \uXXXX escapes for the dashes and the dot.
    $html = html_entity_decode(preg_replace_callback(
        '/\\\\u([0-9a-f]{4})/i',
        fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8'),
        $page->getMountedActionModalHtml(),
    ), ENT_QUOTES | ENT_HTML5);

    expect($html)->toContain('Alpha Employee — 1–15 Oct 2026 · 1500.000 LYD')
        ->toContain('Alpha Employee — 16–31 Oct 2026 · 1920.000 LYD')
        ->toContain('Beta Employee — 1–31 Oct 2026 · 3100.000 LYD');

    $page->setActionData(['line_id' => $second->getKey(), 'amount' => '5.000', 'reason' => 'Picked the second'])->callMountedAction()->assertHasNoActionErrors();

    expect($second->adjustments()->count())->toBe(1);
});

/*
| The factory
*/

it('gives each default factory run a month of its own, last month first, and none to the other kinds', function () {
    // "Now" is 30 October 2026 on the centre's calendar, so last month is September.
    $runs = PayrollRun::factory()->count(3)->create();
    $instructor = PayrollRun::factory()->instructorBatch()->create();
    $fourth = PayrollRun::factory()->create();

    expect($runs->map(fn (PayrollRun $run): string => $run->period_start->toDateString().'..'.$run->period_end->toDateString())->all())
        ->toBe(['2026-09-01..2026-09-30', '2026-08-01..2026-08-31', '2026-07-01..2026-07-31'])
        ->and($instructor->period_start)->toBeNull()
        ->and($fourth->period_start->toDateString())->toBe('2026-06-01');
});
