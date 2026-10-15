<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
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

it('keeps a persistent bill visible before confirmation and renders the selected price', function () {
    $actor = ($this->actor)(['create_enrollment']);
    $batch = Batch::factory()->active()->create(['price' => '216.350']);
    $page = Livewire::actingAs($actor)->test(EnrollAndCollect::class)->set('data.batch_id', $batch->getKey());
    $html = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html());
    expect($html)->toContain('aria-label="Bill"', '216.350', 'Amount to bill');
    Livewire::actingAs(($this->actor)([]))->test(EnrollAndCollect::class)->assertForbidden();
});

it('records the first partial collection and refuses a second installment with the exact notification', function () {
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
    $blocked->set('collectionChargeId', Charge::query()->latest('id')->value('id'))
        ->set('collectionIdempotencyKey', (string) str()->uuid())
        ->fillForm(['amount' => '20.000', 'tenders' => [['method' => 'cash', 'amount' => '20.000']]], 'collectForm')
        ->call('finalize')->assertForbidden();
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

it('creates pricing through its attributed Action and refuses the actor with one fewer grant', function () {
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

it('shows the exact frozen payroll total and line count on each run card', function () {
    $actor = ($this->actor)(['view_any_payroll_run']);
    $run = PayrollRun::factory()->create();
    PayrollLine::factory()->count(2)->create(['payroll_run_id' => $run->id, 'frozen_rate' => '2500.001']);
    Livewire::actingAs($actor)->test(ListPayrollRuns::class)
        ->assertCanSeeTableRecords([$run])
        ->assertTableColumnStateSet('run_total', '5000.002', $run)
        ->assertTableColumnStateSet('line_count', '2 lines', $run)
        ->assertTableActionHidden('review', $run);
    Livewire::actingAs(($this->actor)(['view_any_payroll_run', 'view_payroll_run']))->test(ListPayrollRuns::class)
        ->assertTableActionVisible('review', $run);
    Livewire::actingAs(($this->actor)([]))->test(ListPayrollRuns::class)->assertForbidden();
});

it('links the outstanding amount to the authorized student payment filter only with the payment grant', function () {
    $charge = Charge::factory()->create();
    $studentId = $charge->enrollment->student_id;
    $payment = Payment::factory()->create(['student_id' => $studentId]);
    $other = Payment::factory()->create();
    $permissions = ['view_any_charge', 'view_student'];
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
        ->and($page->instance()->getTable()->getColumn('student_identity')->record($record)->getUrl())->toBeNull();
});
