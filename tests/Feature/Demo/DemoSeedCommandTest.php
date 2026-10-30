<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Support\IdentifierCode;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Exports\ReportDataBuilder;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Queries\ChargeAgeing;
use App\Domain\Finance\Reports\WageCostReport;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\MonthRange;
use App\Domain\Publications\Models\Article;
use App\Models\Role;
use App\Models\User;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| php artisan demo:seed
|--------------------------------------------------------------------------
|
| DatabaseTruncation, not RefreshDatabase: the command commits its own work
| across many transactions, and the base-seeder step must start from an empty
| database. The `demo-seed` group names the heavy tests (each runs the whole
| six-month history, receipts included).
*/
uses(DatabaseTruncation::class);

uses()->group('demo-seed');

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function (): void {
    Storage::fake('private');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    $this->app->instance('env', 'testing');
});

/** Run the command and return [exit code, console output]. */
function runDemoSeed(): array
{
    $code = Artisan::call('demo:seed');

    return [$code, Artisan::output()];
}

/** The bill of a student on a batch, by the student's number and the batch code. */
function demoCharge(int $student, string $batchCode): Charge
{
    return Charge::query()
        ->whereIn('enrollment_id', Enrollment::query()
            ->whereIn('student_id', Student::withTrashed()->where('email', sprintf('student%02d@example.test', $student))->select('id'))
            ->whereIn('batch_id', Batch::query()->where('code', $batchCode)->select('id'))
            ->select('id'))
        ->firstOrFail();
}

/** payment states of every bill: [unpaid, part_paid, settled, written_off] counts. */
function demoChargeStates(): array
{
    $states = ['unpaid' => 0, 'part_paid' => 0, 'settled' => 0, 'written_off' => 0];

    $rows = Charge::query()
        ->select('charges.*')
        ->selectRaw(ChargeBalance::outstandingSql().' as outstanding')
        ->selectRaw(ChargeBalance::allocatedSql().' as allocated')
        ->get();

    foreach ($rows as $charge) {
        $outstanding = Money::fromDecimal((string) $charge->getAttribute('outstanding'));
        $allocated = Money::fromDecimal((string) $charge->getAttribute('allocated'));

        $states[match (true) {
            $charge->written_off_at !== null => 'written_off',
            $outstanding->isZero() => 'settled',
            $allocated->isPositive() => 'part_paid',
            default => 'unpaid',
        }]++;
    }

    return $states;
}

/**
 * What each report opens on by default (ReportPage::filterComponents): the current
 * month for Revenue, Payment methods, Wage cost and Profit, today for Daily tender and
 * Outstanding. Student payment history opens on no student, by design, so it is not here.
 */
function assertDemoReportsOpenOnFigures(): void
{
    $today = CentreCalendar::localise(now());
    $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
    $month = $today->format('Y-m');

    $defaults = [
        'revenue' => ['from' => $today->startOfMonth()->format('Y-m-d'), 'to' => $today->endOfMonth()->format('Y-m-d')],
        'payment_method' => ['from' => $today->startOfMonth()->format('Y-m-d'), 'to' => $today->endOfMonth()->format('Y-m-d')],
        'daily_tender' => ['date' => $today->format('Y-m-d')],
        'outstanding_aged' => ['date' => $today->format('Y-m-d')],
        'wage_cost' => ['from' => $month, 'to' => $month],
        'profit' => ['from' => $month, 'to' => $month],
    ];

    foreach ($defaults as $kind => $options) {
        $dataset = app(ReportDataBuilder::class)->build(ReportKind::from($kind), $options, $owner);

        expect(count($dataset->rows))->toBeGreaterThan(0, "The {$kind} report opens empty on its default filters.");
    }
}

/**
 * The history reads in order on any day: nothing after the real now, every create before
 * its finalize by a real gap, drafts after the runs they follow, and the owner's role grant
 * before the owner's first act.
 */
function assertDemoHistoryIsInOrder(CarbonImmutable $now): void
{
    $limit = $now->utc()->format('Y-m-d H:i:s');

    foreach ([['activity_log', 'created_at'], ['payments', 'received_at'], ['payroll_runs', 'created_at'], ['payroll_runs', 'finalized_at'], ['enrollments', 'enrolled_at'], ['expenses', 'created_at']] as [$table, $column]) {
        expect((string) DB::table($table)->max($column))->toBeLessThanOrEqual($limit, "{$table}.{$column} is dated after now.");
    }

    $finalized = PayrollRun::query()->whereNotNull('finalized_at')->get();
    expect($finalized)->toHaveCount(6);

    foreach ($finalized as $run) {
        expect($run->finalized_at->getTimestamp() - $run->created_at->getTimestamp())->toBeGreaterThanOrEqual(60);
    }

    $drafts = PayrollRun::query()->whereNull('finalized_at')->get();
    expect($drafts)->toHaveCount(2);

    foreach ($drafts as $draft) {
        expect($draft->created_at->getTimestamp())->toBeGreaterThanOrEqual($finalized->max(fn (PayrollRun $run): int => $run->finalized_at->getTimestamp()));
    }

    // The instructor run is paid earlier in the CURRENT month, so this month's wage cost is not empty.
    $instructors = $finalized->firstWhere('type', PayrollRunType::InstructorBatch);
    expect(CentreCalendar::localise($instructors->finalized_at)->format('Y-m'))->toBe(CentreCalendar::localise($now)->format('Y-m'));

    $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
    $granted = DB::table('activity_log')->where('event', 'roles_changed')->where('subject_id', $owner->getKey())->where('subject_type', User::class)->min('created_at');
    $firstAct = DB::table('activity_log')->where('causer_id', $owner->getKey())->min('created_at');

    expect((string) $granted)->toBeLessThan((string) $firstAct);
}

/** A fingerprint of the run that ignores dates and random codes, for the determinism test. */
function demoDigest(): array
{
    return [
        'counts' => collect(['users', 'students', 'courses', 'batches', 'enrollments', 'charges', 'payments', 'payment_tenders', 'payment_allocations', 'expenses', 'payroll_runs', 'payroll_lines', 'payroll_line_adjustments', 'staff_compensation', 'articles', 'student_certificates', 'discounts', 'batch_instructor'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all(),
        'charges' => DB::table('charges')->orderBy('amount')->orderBy('list_price')->pluck('amount')->all(),
        'tenders' => DB::table('payment_tenders')->orderBy('amount')->pluck('amount')->all(),
        'payroll' => DB::table('payroll_lines')->orderBy('computed_amount')->pluck('computed_amount')->all(),
        'states' => demoChargeStates(),
    ];
}

it('refuses in production and writes nothing', function () {
    $this->app->instance('env', 'production');

    $this->artisan('demo:seed')->assertFailed();

    expect(Role::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(Course::query()->count())->toBe(0);
});

it('refuses any environment other than local or testing', function () {
    $this->app->instance('env', 'staging');

    $this->artisan('demo:seed')->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('refuses a database that already holds data, naming it, and writes nothing', function (string $noun, Closure $populate) {
    $populate();
    $before = Role::query()->count();

    $this->artisan('demo:seed')
        ->expectsOutputToContain("already holds {$noun}")
        ->expectsOutputToContain('Run php artisan migrate:fresh first')
        ->assertFailed();

    expect(Role::query()->count())->toBe($before)
        ->and(User::query()->where('email', 'owner@example.test')->exists())->toBeFalse();
})->with([
    'a payroll run' => ['payroll runs', fn () => PayrollRun::factory()->create()],
    'a payment' => ['payments', fn () => Payment::factory()->create()],
    'a charge' => ['charges', fn () => Charge::factory()->create()],
    'a student' => ['students', fn () => Student::factory()->create()],
    'a removed student' => ['students', fn () => Student::factory()->create()->delete()],
    'a course' => ['courses', fn () => Course::factory()->create()],
]);

it('builds the whole demo history, leaves every receipt generated and keeps the ledger consistent', function () {
    // A real queue connection, so only the command's own switch to sync can keep the jobs table empty.
    config(['queue.default' => 'database']);
    $bound = app()->bound(IdentifierCode::class);

    [$code, $output] = runDemoSeed();

    expect($code)->toBe(0)
        ->and(config('queue.default'))->toBe('database')
        ->and(app()->bound(IdentifierCode::class))->toBe($bound);

    // The clock is real again.
    expect(Date::getTestNow())->toBeNull()
        ->and(abs(now()->diffInSeconds(CarbonImmutable::now())))->toBeLessThan(5);

    // The base seeders ran on their own: roles exist and the owner is usable.
    $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
    expect(Hash::check('demo-password', $owner->password))->toBeTrue()
        ->and($owner->must_change_password)->toBeFalse()
        ->and($owner->isSuperAdmin())->toBeTrue();

    // Staff: 1 super admin, 1 admin, 2 front desk, 3 instructors, 3 salaried, 1 departed.
    $roles = fn (string $role) => User::query()->whereDoesntHave('student')->whereHas('roles', fn ($q) => $q->where('name', $role))->count();
    expect(User::query()->whereDoesntHave('student')->count())->toBe(11)
        ->and($roles('super_admin'))->toBe(1)
        ->and($roles('admin'))->toBe(1)
        ->and($roles('staff'))->toBe(9)
        ->and(User::query()->where('is_active', false)->pluck('email')->all())->toBe(['hana@example.test'])
        ->and(User::query()->where('locale', 'ar')->count())->toBeGreaterThan(2);

    // Catalogue: 5 courses, 9 batches (3 finished, 4 running, 2 upcoming), one price change.
    expect(Course::query()->count())->toBe(5)
        ->and(Course::query()->where('code', 'ENG-B1')->value('default_price'))->toBe('480.000')
        ->and(Batch::query()->count())->toBe(9)
        ->and(Batch::query()->where('status', BatchStatus::Completed)->count())->toBe(3)
        ->and(Batch::query()->where('status', BatchStatus::Active)->count())->toBe(4)
        ->and(Batch::query()->where('status', BatchStatus::Planned)->count())->toBe(2)
        ->and(Batch::query()->where('code', 'WEB-101-02')->value('price'))->toBe('800.000');

    // One instructor was removed again, and the log says so.
    expect(DB::table('batch_instructor')->count())->toBe(9)
        ->and(DB::table('activity_log')->where('event', 'instructor_removed')->count())->toBe(1);

    // Students: 49 made, one removed; 2 have portal logins.
    expect(Student::query()->count())->toBe(48)
        ->and(Student::onlyTrashed()->count())->toBe(1)
        ->and(Student::query()->whereNotNull('user_id')->count())->toBe(2)
        ->and($output)->toContain('student05@example.test')->toContain('student30@example.test');

    // Enrolments by status, counted by hand from the scenario.
    expect(Enrollment::query()->where('status', EnrollmentStatus::Active)->count())->toBe(28)
        ->and(Enrollment::query()->where('status', EnrollmentStatus::Completed)->count())->toBe(19)
        ->and(Enrollment::query()->where('status', EnrollmentStatus::Withdrawn)->count())->toBe(5);

    // Certificates: one revoked, one replaced, and two completions still waiting for theirs.
    expect(DB::table('student_certificates')->where('status', 'revoked')->count())->toBe(1)
        ->and(DB::table('student_certificates')->where('status', 'replaced')->count())->toBe(1)
        ->and(DB::table('student_certificates')->where('status', 'valid')->count())->toBe(15);

    // Hand-computed bill amounts: list price less the discount, half-up to the dirham.
    expect(demoCharge(1, 'ENG-B1-01')->amount)->toBe('450.000')   // before the price rise
        ->and(demoCharge(30, 'ENG-B1-02')->amount)->toBe('480.000')   // after it
        ->and(demoCharge(8, 'ENG-B1-01')->amount)->toBe('405.000')    // 10% off 450
        ->and(demoCharge(10, 'XLS-110-01')->amount)->toBe('270.000')  // 10% off 300
        ->and(demoCharge(31, 'ENG-B1-02')->amount)->toBe('384.000')   // 20% off 480, the retired promotion
        ->and(demoCharge(29, 'ACC-201-01')->amount)->toBe('1020.000') // 15% off 1200
        ->and(demoCharge(41, 'DAT-301-01')->amount)->toBe('1487.500') // 15% off 1750
        ->and(demoCharge(42, 'WEB-101-02')->amount)->toBe('800.000')  // the batch price, not 850
        ->and(demoCharge(16, 'WEB-101-01')->amount)->toBe('800.000')  // adjusted down from 850
        ->and(demoCharge(18, 'WEB-101-01')->amount)->toBe('900.000'); // adjusted up from 850
    expect(DB::table('discounts')->where('is_active', false)->pluck('name')->all())->toBe(['Spring promotion'])
        ->and(DB::table('discounts')->count())->toBe(3);

    // Every Charges-page state is present, counted by hand.
    expect(demoChargeStates())->toBe(['unpaid' => 9, 'part_paid' => 11, 'settled' => 30, 'written_off' => 2]);

    // Every ageing bucket holds bills, exactly the ones the scenario ages into it.
    $buckets = app(ChargeAgeing::class)->asOf(CentreCalendar::localise(now())->toDateString())->countBy()->all();
    ksort($buckets);
    expect($buckets)->toBe(['0-30' => 6, '31-60' => 6, '61-90' => 3, '91+' => 5]);

    // Payments: 61 taken (two of them today), two reversed; a bill with three live payments; a bill whose only payment was reversed.
    expect(Payment::query()->count())->toBe(61)
        ->and(Payment::query()->whereNotNull('reversed_at')->count())->toBe(2)
        ->and(DB::table('payment_tenders')->distinct()->pluck('method')->sort()->values()->all())->toBe(['bank_transfer', 'card', 'cash', 'other']);

    $livePayments = DB::table('payment_allocations')
        ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
        ->whereNull('payments.reversed_at')
        ->groupBy('payment_allocations.charge_id')
        ->selectRaw('payment_allocations.charge_id, count(*) as live')
        ->pluck('live', 'charge_id');
    expect($livePayments->filter(fn ($n) => $n === 3)->count())->toBe(4)
        ->and($livePayments->has(demoCharge(25, 'ACC-201-01')->getKey()))->toBeFalse()
        ->and(DB::table('payment_allocations')->where('charge_id', demoCharge(25, 'ACC-201-01')->getKey())->count())->toBe(1)
        // The reversed second installment is owed again: 850.000 less the 425.000 still standing.
        ->and(ChargeBalance::outstandingFor((int) demoCharge(17, 'WEB-101-01')->getKey())->toDecimal())->toBe('425.000');

    expect(Charge::query()->whereNotNull('written_off_at')->count())->toBe(2);

    // Every receipt exists, on the disk, and nothing is waiting in the queue.
    expect(Payment::query()->whereNull('receipt_path')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
    foreach (Payment::query()->get() as $payment) {
        Storage::disk('private')->assertExists((string) $payment->receipt_path);
    }
    expect(DB::table('payment_receipt_snapshots')->where('locale', 'ar')->count())->toBeGreaterThan(5);

    // Expenses: 4 categories, a month-by-month ledger, one reversed.
    expect(DB::table('expense_categories')->count())->toBe(4)
        ->and(DB::table('expenses')->count())->toBe(21)
        ->and(DB::table('expenses')->whereNotNull('reversed_at')->count())->toBe(1)
        ->and(DB::table('expenses')->whereNull('reversed_at')->distinct()->pluck('expense_category_id')->count())->toBe(4);

    // Payroll: finalized runs of each kind, two drafts.
    $finalized = fn (PayrollRunType $type) => PayrollRun::query()->where('type', $type)->whereNotNull('finalized_at')->count();
    expect($finalized(PayrollRunType::MonthlySalary))->toBe(4)
        ->and($finalized(PayrollRunType::InstructorBatch))->toBe(1)
        ->and($finalized(PayrollRunType::Adjustment))->toBe(1)
        ->and(PayrollRun::query()->whereNull('finalized_at')->pluck('type')->map->value->sort()->values()->all())
        ->toBe(['instructor_batch', 'monthly_salary']);

    // The draft salary run holds a two-segment employee: 2200.000 then 2350.000, covering the whole month.
    $draft = PayrollRun::query()->whereNull('finalized_at')->where('type', PayrollRunType::MonthlySalary)->firstOrFail();
    $tarek = PayrollLine::query()->where('payroll_run_id', $draft->getKey())
        ->where('user_id', User::query()->where('email', 'tarek@example.test')->value('id'))
        ->orderBy('segment_start')->get();
    expect($tarek)->toHaveCount(2)
        ->and($tarek->pluck('frozen_rate')->all())->toBe(['2200.000', '2350.000'])
        ->and($tarek->sum('frozen_days'))->toBe((int) $tarek->first()->frozen_days_in_month)
        ->and(DB::table('payroll_line_adjustments')->where('payroll_line_id', '>', 0)->whereIn('payroll_line_id', PayrollLine::query()->where('payroll_run_id', $draft->getKey())->select('id'))->pluck('amount')->sort()->values()->all())
        ->toBe(['-60.000', '100.000']);

    // A finalized month with a split segment: 1800.000 then 1950.000 for the same person.
    $splitMonth = CentreCalendar::localise(now())->startOfMonth()->subMonthsNoOverflow(3);
    $rania = PayrollLine::query()->whereNotNull('finalized_at')
        ->where('user_id', User::query()->where('email', 'rania@example.test')->value('id'))
        ->whereDate('segment_start', '>=', $splitMonth->toDateString())
        ->whereDate('segment_end', '<=', $splitMonth->endOfMonth()->toDateString())
        ->orderBy('segment_start')->get();
    expect($rania->pluck('frozen_rate')->all())->toBe(['1800.000', '1950.000']);

    // The oldest finalized salary run was corrected by a reasoned adjustment line.
    $correction = PayrollLine::query()->whereNotNull('corrects_payroll_line_id')->firstOrFail();
    expect($correction->computed_amount)->toBe('120.000')
        ->and($correction->reason)->not->toBeEmpty()
        ->and($correction->finalized_at)->not->toBeNull();

    // Publications: three published, one draft, in both languages.
    expect(Article::query()->whereNotNull('published_at')->count())->toBe(3)
        ->and(Article::query()->whereNull('published_at')->count())->toBe(1)
        ->and(Article::query()->whereNull('title_ar')->count())->toBe(0);

    // The activity log was written by many different people.
    expect(DB::table('activity_log')->whereNotNull('causer_id')->distinct()->count('causer_id'))->toBeGreaterThanOrEqual(7);

    // No log row names nobody, except the two events SystemRoleWriter records on purpose
    // with causedByAnonymous(): a role or permission grant made by setup, not by a person.
    expect(DB::table('activity_log')->whereNull('causer_id')->distinct()->pluck('event')->sort()->values()->all())
        ->toBe(['permissions_changed', 'roles_changed']);

    // The reports a person opens first are not empty.
    assertDemoReportsOpenOnFigures();

    // Everything fictional: example.test addresses and all-zero-block phone numbers.
    expect(Student::withTrashed()->where('email', 'not like', '%@example.test')->count())->toBe(0)
        ->and(User::query()->where('email', 'not like', '%@example.test')->count())->toBe(0)
        ->and(Student::withTrashed()->where('phone', 'not like', '+218 91 000 0%')->count())->toBe(0);

    assertDemoLedgerIsConsistent();
});

/** The ledger invariants, checked over whatever the seed produced. */
function assertDemoLedgerIsConsistent(): void
{
    // Outstanding on every bill is what was billed less its live allocations.
    foreach (Charge::query()->get() as $charge) {
        $live = (string) DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.charge_id', $charge->getKey())
            ->whereNull('payments.reversed_at')
            ->selectRaw('COALESCE(SUM(payment_allocations.amount), 0) as live')
            ->value('live');

        $expected = Money::fromDecimal($charge->amount)->subtract(Money::fromDecimal($live));

        expect(ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal())->toBe($expected->toDecimal())
            ->and($expected->isNegative())->toBeFalse();
    }

    // No payment allocates more or less than its tenders brought in.
    foreach (Payment::query()->get() as $payment) {
        $tendered = Money::fromDecimal((string) DB::table('payment_tenders')->where('payment_id', $payment->getKey())->sum('amount'));
        $allocated = Money::fromDecimal((string) DB::table('payment_allocations')->where('payment_id', $payment->getKey())->sum('amount'));

        expect($allocated->equals($tendered))->toBeTrue();
    }

    // WageCostReport reports, for each month wages were posted to, the finalized lines
    // plus their adjustments summed independently here.
    $months = DB::table('payroll_lines')->whereNotNull('finalized_at')->distinct()->pluck('posting_period_start');
    expect($months->count())->toBeGreaterThanOrEqual(4);

    foreach ($months as $month) {
        $start = CarbonImmutable::parse((string) $month);
        $lines = (string) DB::table('payroll_lines')->whereNotNull('finalized_at')->where('posting_period_start', $month)->sum('computed_amount');
        $adjustments = (string) DB::table('payroll_line_adjustments')
            ->join('payroll_lines', 'payroll_lines.id', '=', 'payroll_line_adjustments.payroll_line_id')
            ->whereNotNull('payroll_lines.finalized_at')
            ->where('payroll_lines.posting_period_start', $month)
            ->sum('payroll_line_adjustments.amount');

        $expected = Money::fromDecimal($lines)->add(Money::fromDecimal($adjustments));

        expect(app(WageCostReport::class)->totalForMonths(MonthRange::single($start->year, $start->month))->toDecimal())
            ->toBe($expected->toDecimal());
    }
}

it('puts the clock, the queue setting and the code generator back even when an Action throws part-way', function () {
    config(['queue.default' => 'database']);
    $bound = app()->bound(IdentifierCode::class);

    Payment::creating(function (): void {
        throw new RuntimeException('forced failure');
    });

    [$code, $output] = runDemoSeed();

    expect($code)->toBe(1)
        ->and($output)->toContain('forced failure')->toContain('Run php artisan migrate:fresh before trying again')
        ->and(Date::getTestNow())->toBeNull()
        ->and(abs(now()->diffInSeconds(CarbonImmutable::now())))->toBeLessThan(5)
        ->and(config('queue.default'))->toBe('database')
        ->and(app()->bound(IdentifierCode::class))->toBe($bound)
        // It really did stop midway: students exist, payments do not.
        ->and(Student::query()->count())->toBeGreaterThan(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('refuses on its own when the seeder is run directly, and writes nothing', function (string $environment, ?Closure $populate) {
    $this->app->instance('env', $environment);
    $populate?->call($this);

    // The way round the command: `db:seed --class=... --force`.
    expect(fn () => Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]))->toThrow(RuntimeException::class);

    expect(Role::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'owner@example.test')->exists())->toBeFalse()
        ->and(Course::query()->count())->toBe(0)
        ->and(Date::getTestNow())->toBeNull();
})->with([
    'production' => ['production', null],
    'staging' => ['staging', null],
    'an occupied local database' => ['local', fn () => Student::factory()->create()],
]);

it('keeps the history in order on the first of a month, and gives the same shape twice', function () {
    $now = CarbonImmutable::parse('2026-11-01 07:00:00', 'UTC');
    Date::setTestNow($now);

    expect(runDemoSeed()[0])->toBe(0);

    assertDemoHistoryIsInOrder($now);
    assertDemoReportsOpenOnFigures();

    $first = demoDigest();

    // The other way in: roles and owner seeded first, then the demo on top.
    Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('private');

    expect(User::query()->count())->toBe(1)
        ->and(runDemoSeed()[0])->toBe(0);

    expect(demoDigest())->toBe($first);
});

it('keeps the history in order in the middle of a month', function () {
    $now = CarbonImmutable::parse('2026-09-17 10:00:00', 'UTC');
    Date::setTestNow($now);

    expect(runDemoSeed()[0])->toBe(0);

    assertDemoHistoryIsInOrder($now);
    assertDemoReportsOpenOnFigures();
});
