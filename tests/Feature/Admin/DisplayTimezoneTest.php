<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages\ViewStudentCertificate;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exports\RevenueReportExporter;
use App\Domain\Finance\Filament\Pages\ExportHistory;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ViewCharge;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ViewExpense;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ReviewPayrollRun;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Every admin date and time reads on the centre's calendar (P4-T34, QA P3-2)
|--------------------------------------------------------------------------
|
| Storage is UTC and stays UTC (`config('app.timezone')`). The centre lives in
| Africa/Tripoli, two hours ahead, and the receipts, the export history, the
| certificate queue, the verifier and the portal already say so. Everything else
| in the admin panel used to print the stored UTC instant, so near midnight a
| payment's list row and the daily tender report named different dates.
|
| ONE INSTANT, USED THROUGHOUT: 2026-03-31 22:30:00 UTC. In Tripoli that is
| 2026-04-01 00:30:00 — the DATE differs as well as the time, so a column left in
| UTC fails on both, and a column that converted twice (a pre-localised value
| handed to a second conversion) lands on 02:30 and fails too.
|
| EVERY EXPECTED STRING IS WRITTEN OUT BY HAND. Filament's default formats are
| `M j, Y H:i:s` for a date-time and `M j, Y` for a date, so:
|
|   UTC (the bug)      Mar 31, 2026 22:30:00   /   Mar 31, 2026
|   Tripoli (the fix)   Apr 1, 2026 00:30:00   /   Apr 1, 2026
|
| Nothing is built from CentreCalendar or FilamentTimezone, which would move the
| expectation together with the code under test.
|
| NO SUPER ADMIN. Every actor holds exactly the permissions named, so a page that
| quietly asked for a different ability would fail here.
*/
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // 22:30 UTC on 31 March: 00:30 on 1 April in Tripoli. The zone is named in
    // the call, so the instant is the same whatever `config('app.timezone')` says.
    $this->travelTo(CarbonImmutable::parse('2026-03-31 22:30:00', 'UTC'));

    $this->actorWith = function (string ...$permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor->fresh();
    };

    /*
     * An audit entry stamped at an exact UTC instant. The row is written the way
     * the application writes one and then moved, because created_at is not
     * something a caller chooses; saveQuietly() keeps the move out of the log.
     */
    $this->activityAt = function (string $utc, string $description = 'probe'): Activity {
        // No event: a null event has no label to miss, and an undeclared one is refused.
        activity('default')->log($description);

        $entry = Activity::query()->latest('id')->firstOrFail();
        $entry->forceFill(['created_at' => $utc])->saveQuietly();

        return $entry->fresh();
    };
});

/*
|--------------------------------------------------------------------------
| The registration
|--------------------------------------------------------------------------
*/

it('registers the centre zone as the display zone while storage stays UTC', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(FilamentTimezone::get())->toBe('Africa/Tripoli');
});

it('is what a date-time column and entry read in both panels, because the setting is the container\'s and not a panel\'s', function (string $panel): void {
    Filament::setCurrentPanel(Filament::getPanel($panel));

    expect(TextColumn::make('at')->dateTime()->getTimezone())->toBe('Africa/Tripoli')
        ->and(TextColumn::make('at')->since()->getTimezone())->toBe('Africa/Tripoli')
        ->and(TextEntry::make('at')->dateTime()->getTimezone())->toBe('Africa/Tripoli')
        ->and(TextEntry::make('at')->since()->getTimezone())->toBe('Africa/Tripoli');
})->with(['staff dashboard' => 'admin', 'student portal' => 'student']);

it('leaves a bare date column on the storage zone, which is right for a DATE column and why a TIMESTAMP column names its own', function (): void {
    expect(TextColumn::make('due_date')->date()->getTimezone())->toBe('UTC')
        ->and(TextEntry::make('due_date')->date()->getTimezone())->toBe('UTC');
});

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
*/

it('lists a payment on the Tripoli date and time', function (): void {
    $payment = Payment::factory()->create(['received_at' => '2026-03-31 22:30:00']);
    $actor = ($this->actorWith)('view_any_payment', 'view_payment');

    Livewire::actingAs($actor)
        ->test(ListPayments::class)
        ->assertTableColumnFormattedStateSet('received_at', 'Apr 1, 2026 00:30:00', $payment)
        ->assertTableColumnFormattedStateNotSet('received_at', 'Mar 31, 2026 22:30:00', $payment);
});

it('shows a payment\'s received and reversed times on the Tripoli calendar', function (): void {
    $payment = Payment::factory()->reversed()->create([
        'received_at' => '2026-03-31 22:30:00',
        'reversed_at' => '2026-03-31 23:45:00',
    ]);
    $actor = ($this->actorWith)('view_any_payment', 'view_payment');

    Livewire::actingAs($actor)
        ->test(ViewPayment::class, ['record' => $payment->getKey()])
        ->assertSee('Apr 1, 2026 00:30:00')
        ->assertSee('Apr 1, 2026 01:45:00')
        ->assertDontSee('Mar 31, 2026 22:30:00')
        ->assertDontSee('Mar 31, 2026 23:45:00');
});

it('still STORES a recorded payment in UTC', function (): void {
    $charge = Charge::factory()->create(['amount' => '200.000']);
    $recorder = ($this->actorWith)('create_payment');

    $payment = app(RecordPaymentAction::class)->execute($recorder, new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '200.000',
        tenders: [new TenderData(TenderMethod::Cash, '200.000')],
        idempotencyKey: Str::uuid()->toString(),
    ));

    // The raw column, bypassing the model's cast: the stored text is the UTC
    // wall clock, not the two-hours-later one the screens now print.
    $stored = DB::table('payments')->where('id', $payment->getKey())->value('received_at');

    expect($stored)->toBe('2026-03-31 22:30:00');
});

/*
|--------------------------------------------------------------------------
| Staff, payroll, the audit log
|--------------------------------------------------------------------------
*/

it('lists a staff member\'s last sign-in on the Tripoli calendar', function (): void {
    $member = User::factory()->create(['is_active' => true, 'last_login_at' => '2026-03-31 22:30:00']);
    $actor = ($this->actorWith)('view_any_user', 'view_user');

    Livewire::actingAs($actor)
        ->test(ListUsers::class)
        ->assertTableColumnFormattedStateSet('last_login_at', 'Apr 1, 2026 00:30:00', $member)
        ->assertTableColumnFormattedStateNotSet('last_login_at', 'Mar 31, 2026 22:30:00', $member);
});

it('shows a payroll run\'s finalization time on the Tripoli calendar, in the list and on the review page', function (): void {
    $run = PayrollRun::factory()->finalized()->create(['finalized_at' => '2026-03-31 22:30:00']);
    $actor = ($this->actorWith)('view_any_payroll_run', 'view_payroll_run');

    Livewire::actingAs($actor)
        ->test(ListPayrollRuns::class)
        ->assertTableColumnFormattedStateSet('finalized_at', 'Apr 1, 2026 00:30:00', $run)
        ->assertTableColumnFormattedStateNotSet('finalized_at', 'Mar 31, 2026 22:30:00', $run);

    Livewire::actingAs($actor)
        ->test(ReviewPayrollRun::class, ['record' => $run->getKey()])
        ->assertSee('Apr 1, 2026 00:30:00')
        ->assertDontSee('Mar 31, 2026 22:30:00');
});

it('shows the time a payroll adjustment was added on the Tripoli calendar, under its line', function (): void {
    $run = PayrollRun::factory()->create();
    $line = PayrollLine::factory()->for($run, 'run')->create();
    PayrollLineAdjustment::factory()->for($line, 'line')->create(['created_at' => '2026-03-31 22:30:00']);
    $actor = ($this->actorWith)('view_any_payroll_run', 'view_payroll_run');

    Livewire::actingAs($actor)
        ->test(ReviewPayrollRun::class, ['record' => $run->getKey()])
        ->assertSee('Apr 1, 2026 00:30:00')
        ->assertDontSee('Mar 31, 2026 22:30:00');
});

it('lists an audit entry on the Tripoli date and time', function (): void {
    $entry = ($this->activityAt)('2026-03-31 22:30:00');
    $actor = ($this->actorWith)('view_any_activity', 'view_activity');

    Livewire::actingAs($actor)
        ->test(ListActivities::class)
        ->assertTableColumnFormattedStateSet('created_at', 'Apr 1, 2026 00:30:00', $entry)
        ->assertTableColumnFormattedStateNotSet('created_at', 'Mar 31, 2026 22:30:00', $entry);
});

it('shows an audit entry\'s time on the Tripoli calendar on its own page', function (): void {
    $entry = ($this->activityAt)('2026-03-31 22:30:00');
    $actor = ($this->actorWith)('view_any_activity', 'view_activity');

    Livewire::actingAs($actor)
        ->test(ViewActivity::class, ['record' => $entry->getKey()])
        ->assertSee('Apr 1, 2026 00:30:00')
        ->assertDontSee('Mar 31, 2026 22:30:00');
});

/*
|--------------------------------------------------------------------------
| The rest of the date-times the QA finding named
|--------------------------------------------------------------------------
*/

it('shows a bill\'s write-off time on the Tripoli calendar', function (): void {
    $charge = Charge::factory()->writtenOff()->create(['written_off_at' => '2026-03-31 22:30:00']);
    $actor = ($this->actorWith)('view_any_charge', 'view_charge');

    Livewire::actingAs($actor)
        ->test(ViewCharge::class, ['record' => $charge->getKey()])
        ->assertSee('Apr 1, 2026 00:30:00')
        ->assertDontSee('Mar 31, 2026 22:30:00');
});

it('shows an expense\'s reversal time on the Tripoli calendar', function (): void {
    $expense = Expense::factory()->reversed()->create(['reversed_at' => '2026-03-31 22:30:00']);
    $actor = ($this->actorWith)('view_any_expense', 'view_expense');

    Livewire::actingAs($actor)
        ->test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertSee('Apr 1, 2026 00:30:00')
        ->assertDontSee('Mar 31, 2026 22:30:00');
});

it('shows a certificate\'s issue time on the Tripoli calendar on its own page', function (): void {
    $certificate = StudentCertificate::factory()->create(['issued_at' => '2026-03-31 22:30:00']);
    $actor = ($this->actorWith)('view_any_student_certificate', 'view_student_certificate');

    Livewire::actingAs($actor)
        ->test(ViewStudentCertificate::class, ['record' => $certificate->getKey()])
        ->assertSee('Apr 1, 2026 00:30:00')
        ->assertDontSee('Mar 31, 2026 22:30:00');
});

it('shows a certificate\'s revocation time on the Tripoli calendar on its own page', function (): void {
    // Issued at 22:30 UTC and revoked at 23:45 UTC, so each line of the page has
    // an instant of its own and one cannot satisfy the other's assertion.
    $certificate = StudentCertificate::factory()->revoked()->create([
        'issued_at' => '2026-03-31 22:30:00',
        'revoked_at' => '2026-03-31 23:45:00',
    ]);
    $actor = ($this->actorWith)('view_any_student_certificate', 'view_student_certificate');

    Livewire::actingAs($actor)
        ->test(ViewStudentCertificate::class, ['record' => $certificate->getKey()])
        ->assertSee('Apr 1, 2026 01:45:00')
        ->assertDontSee('Mar 31, 2026 23:45:00');
});

it('lists an article\'s publication time on the Tripoli calendar', function (): void {
    $article = Article::factory()->create(['published_at' => '2026-03-31 22:30:00']);
    $actor = ($this->actorWith)('view_any_article', 'view_article');

    Livewire::actingAs($actor)
        ->test(ListArticles::class)
        ->assertTableColumnFormattedStateSet('published_at', 'Apr 1, 2026 00:30:00', $article)
        ->assertTableColumnFormattedStateNotSet('published_at', 'Mar 31, 2026 22:30:00', $article);
});

/*
|--------------------------------------------------------------------------
| No double conversion where the zone was already explicit
|--------------------------------------------------------------------------
*/

it('shows the export history exactly as before: converted once, not twice', function (): void {
    $owner = ($this->actorWith)('view_financial_report', 'export_financial_report');

    $export = new Export([
        'exporter' => RevenueReportExporter::class,
        'file_disk' => 'local',
        'file_name' => 'report',
        'total_rows' => 3,
        'completed_at' => null,
        'processed_rows' => 0,
        'successful_rows' => 0,
        'created_at' => '2026-03-31 22:30:00',
    ]);
    $export->user()->associate($owner);
    $export->save();

    // 00:30 once. A second conversion on top of the first would read 02:30.
    Livewire::actingAs($owner)
        ->test(ExportHistory::class)
        ->assertTableColumnFormattedStateSet('created_at', 'Apr 1, 2026 00:30:00', $export)
        ->assertTableColumnFormattedStateNotSet('created_at', 'Apr 1, 2026 02:30:00', $export)
        ->assertTableColumnFormattedStateNotSet('created_at', 'Mar 31, 2026 22:30:00', $export);
});

/*
|--------------------------------------------------------------------------
| A date picker's day is a calendar day, and the filter reads it as the centre's
|--------------------------------------------------------------------------
|
| Two facts, tested separately because they fail separately:
|
|   1. A DatePicker WITHOUT a time keeps `config('app.timezone')` and ignores
|      Filament's display zone, so the day the user picked reaches the filter
|      unshifted.
|   2. The activity filter turns that day into the centre's day: 1 April in
|      Tripoli runs from 22:00 UTC on 31 March up to, but not including, 22:00
|      UTC on 1 April. Reading it as the UTC day instead is the defect.
|
| THE SIX ENTRIES straddle both edges of Tripoli's 1 April:
|
|   a  Mar 31 21:59:59 UTC = Mar 31 23:59:59 Tripoli   before the day
|   b  Mar 31 22:00:00 UTC = Apr  1 00:00:00 Tripoli   the first instant of it
|   c  Mar 31 22:30:00 UTC = Apr  1 00:30:00 Tripoli   the brief's entry
|   d  Apr  1 21:59:59 UTC = Apr  1 23:59:59 Tripoli   the last second of it
|   e  Apr  1 22:00:00 UTC = Apr  2 00:00:00 Tripoli   after the day
|   f  Apr  1 22:30:00 UTC = Apr  2 00:30:00 Tripoli   after the day
*/

it('keeps a date picker without a time on the storage zone, while a picker with a time moves to the centre\'s', function (): void {
    $castOf = fn (DateTimePicker $picker): DateTimeStateCast => collect($picker->getDefaultStateCasts())
        ->first(fn (object $cast): bool => $cast instanceof DateTimeStateCast);

    $dayOnly = DatePicker::make('day');
    $withTime = DateTimePicker::make('moment');

    expect($dayOnly->getTimezone())->toBe('UTC')
        ->and($withTime->getTimezone())->toBe('Africa/Tripoli')
        // The day typed is the day stored: 1 April stays 1 April.
        ->and($castOf($dayOnly)->get('2026-04-01'))->toBe('2026-04-01')
        // The control: a picker WITH a time reads what is typed as Tripoli's
        // wall clock, so 00:30 on 1 April is 22:30 UTC on 31 March. This is the
        // behaviour a date picker must not have here, and the reason the filter
        // below converts the day itself.
        ->and($castOf($withTime)->get('2026-04-01 00:30:00'))->toBe('2026-03-31 22:30:00');
});

it('does not shift a date picker\'s state: the day typed is the day the filter receives', function (): void {
    $actor = ($this->actorWith)('view_any_activity', 'view_activity');

    $filter = Livewire::actingAs($actor)
        ->test(ListActivities::class)
        ->filterTable('created_at', ['from' => '2026-04-01', 'until' => '2026-04-02'])
        ->instance()
        ->getTable()
        ->getFilter('created_at');

    expect($filter->getState())->toMatchArray([
        'from' => '2026-04-01',
        'until' => '2026-04-02',
    ]);

    // And the two pickers are the application's own, date-only ones: a picker
    // WITH a time would read the day as Tripoli's wall clock and hand over the
    // 31st (see the component test above for the contrast).
    $pickers = $filter->getSchemaComponents();

    expect($pickers)->toHaveCount(2);

    foreach ($pickers as $picker) {
        expect($picker)->toBeInstanceOf(DateTimePicker::class)
            ->and($picker->hasTime())->toBeFalse()
            ->and($picker->getTimezone())->toBe('UTC');
    }
});

it('filters the audit log by the centre\'s day: one day, both edges', function (): void {
    $entries = [
        'a' => ($this->activityAt)('2026-03-31 21:59:59', 'a'),
        'b' => ($this->activityAt)('2026-03-31 22:00:00', 'b'),
        'c' => ($this->activityAt)('2026-03-31 22:30:00', 'c'),
        'd' => ($this->activityAt)('2026-04-01 21:59:59', 'd'),
        'e' => ($this->activityAt)('2026-04-01 22:00:00', 'e'),
        'f' => ($this->activityAt)('2026-04-01 22:30:00', 'f'),
    ];
    $actor = ($this->actorWith)('view_any_activity', 'view_activity');

    Livewire::actingAs($actor)
        ->test(ListActivities::class)
        ->filterTable('created_at', ['from' => '2026-04-01', 'until' => '2026-04-01'])
        ->assertCanSeeTableRecords([$entries['b'], $entries['c'], $entries['d']])
        ->assertCanNotSeeTableRecords([$entries['a'], $entries['e'], $entries['f']]);
});

it('filters the audit log from a centre day onward', function (): void {
    $before = ($this->activityAt)('2026-03-31 21:59:59', 'before');
    $first = ($this->activityAt)('2026-03-31 22:00:00', 'first');
    $later = ($this->activityAt)('2026-04-05 10:00:00', 'later');
    $actor = ($this->actorWith)('view_any_activity', 'view_activity');

    Livewire::actingAs($actor)
        ->test(ListActivities::class)
        ->filterTable('created_at', ['from' => '2026-04-01'])
        ->assertCanSeeTableRecords([$first, $later])
        ->assertCanNotSeeTableRecords([$before]);
});

it('filters the audit log up to the end of a centre day', function (): void {
    $earlier = ($this->activityAt)('2026-03-20 10:00:00', 'earlier');
    $last = ($this->activityAt)('2026-04-01 21:59:59', 'last');
    $after = ($this->activityAt)('2026-04-01 22:00:00', 'after');
    $actor = ($this->actorWith)('view_any_activity', 'view_activity');

    Livewire::actingAs($actor)
        ->test(ListActivities::class)
        ->filterTable('created_at', ['until' => '2026-04-01'])
        ->assertCanSeeTableRecords([$earlier, $last])
        ->assertCanNotSeeTableRecords([$after]);
});
