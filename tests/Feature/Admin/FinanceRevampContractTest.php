<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Filament\Pages\Reports\DailyTenderReportPage;
use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\CreateDiscount;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Domain\Finance\Filament\Resources\PaymentResource;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Reports\OutstandingAgedReport;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Queue::fake();
    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };
    $this->collection = function (User $actor) {
        $student = Student::factory()->create();
        $batch = Batch::factory()->active()->create(['price' => '100.125']);

        return Livewire::actingAs($actor)->test(EnrollAndCollect::class)
            ->fillForm(['student_id' => $student->getKey(), 'batch_id' => $batch->getKey()])
            ->call('confirm')->assertNotified(__('collect.confirmed'));
    };
});

it('keeps the discounted bill preview visible in its aside before confirmation', function () {
    $actor = ($this->actor)(['create_enrollment', 'apply_discount']);
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create(['price' => '216.350']);
    $discount = Discount::factory()->create(['name' => 'Preview discount', 'percentage' => '1.00']);
    $page = Livewire::actingAs($actor)->test(EnrollAndCollect::class)->fillForm([
        'student_id' => $student->id, 'batch_id' => $batch->id, 'discount_id' => $discount->id,
    ]);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $aside = $xpath->query('//aside[@aria-label="Bill"]');
    expect($aside)->toHaveCount(1);
    foreach ([
        'Student' => $student->student_code.' — '.$student->full_name,
        'Batch' => $batch->code.' — '.$batch->course->code,
        'List price' => '216.350 LYD',
        'Discount' => 'Preview discount (1.00%)',
    ] as $label => $value) {
        $entry = $xpath->query('.//div[dt[normalize-space(.)="'.$label.'"]]/dd', $aside->item(0));
        expect($entry)->toHaveCount(1)
            ->and(preg_replace('/\s+/u', ' ', trim($entry->item(0)->textContent)))->toBe($value);
    }
    $amount = $xpath->query('.//span[normalize-space(.)="Amount to bill"]/following-sibling::span[1]', $aside->item(0));
    expect($amount)->toHaveCount(1)
        ->and(preg_replace('/\s+/u', ' ', trim($amount->item(0)->textContent)))->toBe('214.187 LYD');
    expect(Charge::query()->count())->toBe(0);
    Livewire::actingAs(($this->actor)([]))->test(EnrollAndCollect::class)->assertForbidden();
});

it('renders the numbered enrolment stages with accessible inline choices and an explicit confirmation', function () {
    $actor = ($this->actor)(['create_enrollment', 'apply_discount', 'create_student']);
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create(['price' => '216.350']);
    // Cards appear for a search or an authorized hint, never for a blank search.
    $page = Livewire::actingAs($actor)->test(EnrollAndCollect::class)->set('studentSearch', $student->last_name);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $steps = $xpath->query('//nav[@aria-label="Enrolment steps"]/ol/li/button');
    expect($steps)->toHaveCount(4);
    foreach (['Student', 'Batch', 'Discount', 'Collect'] as $index => $label) {
        expect(preg_replace('/\s+/u', ' ', trim($steps->item($index)->textContent)))
            ->toStartWith(($index + 1).' '.$label);
    }
    expect($xpath->query('//button[@aria-current="step"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@type="radio" and @name="student_id" and @value="'.$student->id.'"]'))->toHaveCount(1)
        ->and($xpath->query('//label[@for="student-search"]'))->toHaveCount(1)
        ->and($document->textContent)->toContain('New student instead');
    $page->set('data.student_id', $student->id)->call('advance')
        ->set('data.batch_id', $batch->id)->call('advance')->call('advance');
    $html = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html());
    expect($html)->toContain('Confirm enrolment')->not->toContain('wire:click="finalize"', 'RCT-', 'CHG-');
    $page->call('confirm')->assertSet('flowStep', 1)->assertSet('collectionChargeId', null);
    expect(Charge::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(0);

    $page = Livewire::actingAs(($this->actor)(['create_enrollment']))->test(EnrollAndCollect::class);
    $document->loadHTML('<?xml encoding="UTF-8">'.$page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    expect($document->textContent)->not->toContain('New student instead', 'Discount');
});

it('searches all active discount choices beyond the first page and recomputes the live bill', function () {
    $actor = ($this->actor)(['create_enrollment', 'apply_discount']);
    Discount::factory()->count(25)->sequence(fn ($sequence): array => ['name' => 'Alpha offer '.$sequence->index])->create();
    $discount = Discount::factory()->create(['name' => 'Zed award', 'percentage' => '1.00']);
    $retired = Discount::factory()->inactive()->create(['name' => 'Zed retired']);
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create(['price' => '216.350']);
    $page = Livewire::actingAs($actor)->test(EnrollAndCollect::class)
        ->set('data.student_id', $student->id)->call('advance')
        ->set('data.batch_id', $batch->id)->call('advance')->set('discountSearch', 'Zed');
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $options = $xpath->query('//fieldset[@aria-label="Discount options"]');
    expect($options)->toHaveCount(1)->and($options->item(0)->textContent)->toContain('Zed award', '-2.163 LYD')
        ->not->toContain('Alpha offer', $retired->name, '214.187');
    $page->set('data.discount_id', $discount->id);
    expect($page->instance()->bill['amount']?->toDecimal())->toBe('214.187');
    $page->set('data.discount_id', '');
    expect($page->instance()->bill['amount']?->toDecimal())->toBe('216.350');
    expect(Charge::query()->count())->toBe(0);
});

it('records the first partial collection and refuses a different second amount in the same open panel with the exact notification', function () {
    $actor = ($this->actor)(['create_enrollment', 'create_payment']);
    $page = ($this->collection)($actor)->fillForm([
        'amount' => '40.125', 'tenders' => [['method' => 'cash', 'amount' => '40.125']],
    ], 'collectForm')->call('finalize')->assertNotified(__('collect.collected_successfully'));
    expect(Payment::query()->count())->toBe(1)->and(PaymentTender::query()->sole()->amount)->toBe('40.125');
    $page->fillForm([
        'amount' => '20.000', 'tenders' => [['method' => 'cash', 'amount' => '20.000']],
    ], 'collectForm')->call('finalize')->assertNotified(__('collect.installment_conflict'));
    expect(Payment::query()->count())->toBe(1);
    $blocked = ($this->collection)(($this->actor)(['create_enrollment']))->assertSet('collectionChargeId', null);
    // The bill id is server-owned since the P4-T32 review: the browser cannot point
    // the panel at a bill, so this actor never gets as far as the payment Action.
    expect(fn () => $blocked->set('collectionChargeId', Charge::query()->latest('id')->value('id')))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(Payment::query()->count())->toBe(1);
});

it('refuses unbalanced tenders then records exactly balanced split tenders', function () {
    $actor = ($this->actor)(['create_enrollment', 'create_payment']);
    $page = ($this->collection)($actor)->fillForm([
        'amount' => '100.125', 'tenders' => [['method' => 'cash', 'amount' => '100.124']],
    ], 'collectForm')->call('finalize')->assertHasFormErrors(['amount'], 'collectForm');
    expect($page->instance()->getErrorBag()->first('collect.amount'))->toBe(__('collect.tender_total_mismatch'));
    expect(Payment::query()->count())->toBe(0);
    $page->fillForm([
        'amount' => '100.125',
        'tenders' => [['method' => 'cash', 'amount' => '40.001'], ['method' => 'bank_transfer', 'amount' => '60.124']],
    ], 'collectForm')->call('finalize')->assertNotified(__('collect.collected_successfully'));
    expect(PaymentTender::query()->orderBy('id')->pluck('amount')->all())->toBe(['40.001', '60.124']);
    Livewire::actingAs(($this->actor)(['create_payment']))->test(EnrollAndCollect::class)->assertForbidden();
});

it('creates an attributed discount and refuses the actor with one fewer grant', function () {
    $actor = ($this->actor)(['view_any_discount', 'manage_pricing']);
    Livewire::actingAs($actor)->test(CreateDiscount::class)
        ->fillForm(['name' => 'Sibling', 'percentage' => '12.50'])->call('create')->assertHasNoFormErrors();
    $discount = Discount::query()->sole();
    expect($discount->percentage)->toBe('12.50')
        ->and((int) Activity::query()->where('subject_type', Discount::class)->where('subject_id', $discount->id)->sole()->causer_id)->toBe($actor->id);
    Livewire::actingAs(($this->actor)(['view_any_discount']))->test(CreateDiscount::class)->assertForbidden();
    expect(Discount::query()->count())->toBe(1);
});

it('preserves decimal money state and persists all three places on the expense form', function () {
    $category = ExpenseCategory::factory()->create();
    $actor = ($this->actor)(['view_any_expense', 'create_expense']);
    $page = Livewire::actingAs($actor)->test(CreateExpense::class)->fillForm([
        'expense_category_id' => $category->id, 'amount' => '999999999.999',
        'paid_on' => '2026-01-01', 'description' => 'Rent',
    ]);
    expect($page->instance()->form->getState()['amount'])->toBe('999999999.999');
    $page->call('create')->assertHasNoFormErrors();
    expect(Expense::query()->sole()->amount)->toBe('999999999.999');
    Livewire::actingAs(($this->actor)(['view_any_expense']))->test(CreateExpense::class)->assertForbidden();
    expect(Expense::query()->count())->toBe(1);
});

it('keeps ageing totals equal to the report at every boundary with bounded hydration', function () {
    $date = CarbonImmutable::parse('2026-06-30');
    foreach ([-15, 0, 30, 31, 60, 61, 90, 91] as $days) {
        Charge::factory()->create(['amount' => '100.125', 'due_date' => $date->subDays($days)->toDateString()]);
    }
    Charge::factory()->writtenOff()->create(['amount' => '500.000', 'due_date' => '2026-06-30']);
    $part = Charge::factory()->create(['amount' => '200.000', 'due_date' => '2026-06-30']);
    $settled = Charge::factory()->create(['amount' => '500.000', 'due_date' => '2026-06-30']);
    foreach ([[$part, '25.125', false], [$part, '30.000', true], [$settled, '500.000', false]] as [$charge, $amount, $reversed]) {
        $payment = $reversed ? Payment::factory()->reversed()->create() : Payment::factory()->create();
        PaymentAllocation::factory()->create(['payment_id' => $payment->id, 'charge_id' => $charge->id, 'amount' => $amount]);
    }
    $report = app(OutstandingAgedReport::class);
    DB::enableQueryLog();
    $totals = $report->totalsAsOf('2026-06-30');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)->and($totals)->toHaveCount(4);
    expect(array_map(fn (array $row): string => $row['amount']->toDecimal(), $totals))
        ->toBe(['0-30' => '475.250', '31-60' => '200.250', '61-90' => '200.250', '91+' => '100.125']);
    expect(array_column($totals, 'count'))->toBe([4, 2, 2, 1]);
    foreach ($totals as $bucket => $total) {
        $rows = $report->asOf('2026-06-30')->where('bucket', $bucket);
        expect($rows->count())->toBe($total['count'])
            ->and($rows->reduce(fn (Money $sum, array $row): Money => $sum->add($row['outstanding']), Money::zero())->toDecimal())->toBe($total['amount']->toDecimal());
    }
    expect(strtolower($queries[0]['query']))->toContain('sum(', 'count(');
});

it('renders the hand-calculated ageing cards for the next Tripoli calendar day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 22:30:00', 'UTC'));
    $localDate = CarbonImmutable::parse('2026-07-01');
    foreach ([[-1, '10.001'], [0, '20.002'], [30, '30.003'], [31, '40.004'], [60, '50.005'], [61, '60.006'], [90, '70.007'], [91, '80.008']] as [$days, $amount]) {
        Charge::factory()->create(['list_price' => $amount, 'due_date' => $localDate->subDays($days)->toDateString()]);
    }
    $actor = ($this->actor)(['view_any_charge']);
    DB::enableQueryLog();
    try {
        $page = Livewire::actingAs($actor)->test(ListCharges::class);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    $ageingQuery = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'DATEDIFF('));
    expect(now()->toDateTimeString())->toBe('2026-06-30 22:30:00')
        ->and($ageingQuery)->not->toBeNull()
        ->and($ageingQuery['bindings'])->toContain('2026-07-01');
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    foreach ([
        '0–30 days (including not yet due)' => ['60.006 LYD', '3 bills'],
        '31–60 days' => ['90.009 LYD', '2 bills'],
        '61–90 days' => ['130.013 LYD', '2 bills'],
        '91+ days' => ['80.008 LYD', '1 bill'],
    ] as $label => [$amount, $count]) {
        $card = $xpath->query('//*[normalize-space(text())="'.$label.'"]/ancestor::section[1]');
        expect($card)->toHaveCount(1);
        $text = preg_replace('/\s+/u', ' ', $card->item(0)->textContent);
        expect($text)->toContain($amount)
            ->and($xpath->query('.//svg[@role="img"]', $card->item(0))->item(0)->getAttribute('aria-label'))->toBe($count);
    }
    expect($page->instance()->getSubheading())->toBe('Finance · 8 bills outstanding');
});

it('keeps actual charge list render reads bounded from three to nine distinct student rows', function () {
    $actor = ($this->actor)(['view_any_charge', 'view_student', 'view_any_student', 'view_charge', 'view_batch', 'view_any_payment']);
    $actor->load('permissions', 'roles');
    $counts = [];
    $charges = Charge::factory()->count(3)->create();
    foreach ([3, 9] as $size) {
        if ($size === 9) {
            $charges = $charges->merge(Charge::factory()->count(6)->create());
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = Livewire::actingAs($actor)->test(ListCharges::class);
            $html = $page->html();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $page->assertCanSeeTableRecords($charges);
        foreach ($charges as $charge) {
            expect($html)->toContain(e($charge->reference));
        }
        $counts[$size] = collect($queries)->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && preg_match('/\b(?:from|join) `?(students|courses|batches|enrollments|charges|payments|payment_allocations)`?\b/i', $query['query']) === 1)->count();
    }
    expect($counts[3])->toBeGreaterThan(0)->toBeLessThanOrEqual(8)
        ->and($counts[9])->toBe($counts[3])->toBeLessThanOrEqual(8);
});

it('renders batched charge student labels without per-row identity queries or missing soft-deleted students', function () {
    $actor = ($this->actor)(['view_any_charge']);
    $charges = Charge::factory()->count(7)->create();
    $student = $charges->first()->enrollment->student;
    $student->delete();
    DB::enableQueryLog();
    $records = ChargeResource::getEloquentQuery()->get();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($records)->toHaveCount(7)->and($queries)->toHaveCount(4);
    $page = Livewire::actingAs($actor)->test(ListCharges::class)->assertCanSeeTableRecords($charges);
    $html = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html());
    expect($html)->toContain(e($student->full_name));
    Livewire::actingAs(($this->actor)([]))->test(ListCharges::class)->assertForbidden();
});

it('shows both tender methods in the payment row without requiring payment detail access', function () {
    $actor = ($this->actor)(['view_any_payment']);
    $payment = Payment::factory()->create();
    PaymentTender::factory()->create(['payment_id' => $payment->id, 'method' => 'cash', 'amount' => '40.001']);
    PaymentTender::factory()->create(['payment_id' => $payment->id, 'method' => 'bank_transfer', 'amount' => '60.124']);
    $page = Livewire::actingAs($actor)->test(ListPayments::class)->assertCanSeeTableRecords([$payment]);
    $html = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html());
    expect($html)->toContain('Cash', '40.001', '60.124');
    Livewire::actingAs(($this->actor)([]))->test(ListPayments::class)->assertForbidden();
});

it('keeps deleted student payment identity visible without an unusable student link or extra reads', function () {
    $actor = ($this->actor)(['view_any_payment', 'view_student', 'view_any_student']);
    $payment = Payment::factory()->create();
    $student = $payment->student;
    $page = Livewire::actingAs($actor)->test(ListPayments::class);
    $record = $page->instance()->getTableRecords()->first();
    expect($page->instance()->getTable()->getColumn('student.full_name')->record($record)->getUrl())->not->toBeNull();
    $student->delete();
    $page = Livewire::actingAs($actor)->test(ListPayments::class);
    $record = $page->instance()->getTableRecords()->first();
    $column = $page->instance()->getTable()->getColumn('student.full_name')->record($record);
    DB::enableQueryLog();
    $url = $column->getUrl();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($url)->toBeNull()->and($queries)->toBeEmpty();
    expect(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html()))->toContain(e($student->full_name))
        ->not->toContain('href="'.e(StudentResource::getUrl('index', ['student' => $student->id])).'"');
});

it('shows the exact frozen payroll total and pluralized line count on each run card', function (int $lines, string $total, string $label) {
    $actor = ($this->actor)(['view_any_payroll_run']);
    $run = PayrollRun::factory()->create();
    PayrollLine::factory()->count($lines)->create(['payroll_run_id' => $run->id, 'frozen_rate' => '2500.001']);
    Livewire::actingAs($actor)->test(ListPayrollRuns::class)
        ->assertCanSeeTableRecords([$run])
        ->assertTableColumnStateSet('run_total', $total, $run)
        ->assertTableColumnStateSet('line_count', $label, $run)
        ->assertTableActionHidden('review', $run);
    Livewire::actingAs(($this->actor)(['view_any_payroll_run', 'view_payroll_run']))->test(ListPayrollRuns::class)
        ->assertTableActionVisible('review', $run);
    Livewire::actingAs(($this->actor)([]))->test(ListPayrollRuns::class)->assertForbidden();
})->with([
    'one line' => [1, '2500.001', '1 line'],
    'two lines' => [2, '5000.002', '2 lines'],
]);

it('pluralizes outstanding bill headings and card counts on the rendered list', function (int $count, string $heading, string $label) {
    Charge::factory()->count($count)->create();
    $page = Livewire::actingAs(($this->actor)(['view_any_charge']))->test(ListCharges::class);
    expect($page->instance()->getSubheading())->toBe($heading)
        ->and($page->html())->toContain('aria-label="'.$label.'"');
})->with([
    'one bill' => [1, 'Finance · 1 bill outstanding', '1 bill'],
    'two bills' => [2, 'Finance · 2 bills outstanding', '2 bills'],
]);

it('renders each finance row link only with every exact permission it requires', function (string $surface, string $column, array $grants, string $target) {
    $charge = Charge::factory()->create();
    $enrollment = $charge->enrollment;
    $payment = Payment::factory()->create(['student_id' => $enrollment->student_id]);
    PaymentAllocation::factory()->create(['payment_id' => $payment->id, 'charge_id' => $charge->id]);
    $base = $surface === 'charge' ? 'view_any_charge' : 'view_any_payment';
    $pageClass = $surface === 'charge' ? ListCharges::class : ListPayments::class;
    $url = match ($target) {
        'student' => StudentResource::getUrl('index', ['student' => $enrollment->student_id]),
        'charge' => ChargeResource::getUrl('view', ['record' => $charge]),
        'payment' => PaymentResource::getUrl('view', ['record' => $payment]),
        'batch' => BatchResource::getUrl('view', ['record' => $enrollment->batch]),
        'payments' => PaymentResource::getUrl('index', ['filters' => ['student' => ['id' => $enrollment->student_id]]]),
    };
    $page = Livewire::actingAs(($this->actor)([$base, ...$grants]))->test($pageClass);
    $record = $page->instance()->getTableRecords()->first();
    expect($page->instance()->getTable()->getColumn($column)->record($record)->getUrl())->toBe($url)
        ->and(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html()))->toContain('href="'.e($url).'"');
    foreach ($grants as $removed) {
        $permissions = array_values(array_diff([$base, ...$grants], [$removed]));
        $page = Livewire::actingAs(($this->actor)($permissions))->test($pageClass);
        $record = $page->instance()->getTableRecords()->first();
        expect($page->instance()->getTable()->getColumn($column)->record($record)->getUrl())->toBeNull()
            ->and($page->html())->not->toContain('href="'.e($url).'"');
    }
})->with([
    'charge student' => ['charge', 'student_identity', ['view_student', 'view_any_student'], 'student'],
    'charge reference' => ['charge', 'reference', ['view_charge'], 'charge'],
    'charge batch' => ['charge', 'enrollment.batch.code', ['view_batch'], 'batch'],
    'charge outstanding payments' => ['charge', ChargeBalance::OUTSTANDING_ALIAS, ['view_student', 'view_any_payment'], 'payments'],
    'payment reference' => ['payment', 'reference', ['view_payment'], 'payment'],
    'payment student' => ['payment', 'student.full_name', ['view_student', 'view_any_student'], 'student'],
    'payment bill' => ['payment', 'bill', ['view_charge'], 'charge'],
]);

it('renders the daily tender report link only when its page permits access', function () {
    $url = DailyTenderReportPage::getUrl();
    $page = Livewire::actingAs(($this->actor)(['view_any_payment', 'view_financial_report']))->test(ListPayments::class)
        ->assertActionVisible('tender_report');
    expect(DailyTenderReportPage::canAccess())->toBeTrue()
        ->and(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html()))->toContain('href="'.e($url).'"');
    $page = Livewire::actingAs(($this->actor)(['view_any_payment']))->test(ListPayments::class)
        ->assertActionHidden('tender_report');
    expect(DailyTenderReportPage::canAccess())->toBeFalse()
        ->and($page->html())->not->toContain('href="'.e($url).'"');
});

it('links the outstanding amount to the authorized student payment filter only with the payment grant', function () {
    $charge = Charge::factory()->create();
    $studentId = $charge->enrollment->student_id;
    $payment = Payment::factory()->create(['student_id' => $studentId]);
    $other = Payment::factory()->create();
    $permissions = ['view_any_charge', 'view_student', 'view_any_student'];
    $reader = ($this->actor)([...$permissions, 'view_any_payment']);
    $page = Livewire::actingAs($reader)->test(ListCharges::class);
    $record = $page->instance()->getTableRecords()->first();
    $url = PaymentResource::getUrl('index', ['filters' => ['student' => ['id' => $studentId]]]);
    expect($page->instance()->getTable()->getColumn(ChargeBalance::OUTSTANDING_ALIAS)->record($record)->getUrl())->toBe($url);
    expect(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html()))->toContain('href="'.e($url).'"');
    Livewire::actingAs($reader)->withQueryParams(['filters' => ['student' => ['id' => $studentId]]])
        ->test(ListPayments::class)->assertCanSeeTableRecords([$payment])->assertCanNotSeeTableRecords([$other]);
    $page = Livewire::withQueryParams([])->actingAs(($this->actor)($permissions))->test(ListCharges::class);
    $record = $page->instance()->getTableRecords()->first();
    expect($page->instance()->getTable()->getColumn(ChargeBalance::OUTSTANDING_ALIAS)->record($record)->getUrl())->toBeNull();
    $charge->enrollment->student->delete();
    $page = Livewire::actingAs($reader)->test(ListCharges::class);
    $record = $page->instance()->getTableRecords()->first();
    expect($page->instance()->getTable()->getColumn(ChargeBalance::OUTSTANDING_ALIAS)->record($record)->getUrl())->toBeNull()
        ->and($page->instance()->getTable()->getColumn('student_identity')->record($record)->getUrl())->toBeNull()
        ->and($page->html())->not->toContain('href="'.e($url).'"', 'href="'.e(StudentResource::getUrl('index', ['student' => $studentId])).'"');
});
