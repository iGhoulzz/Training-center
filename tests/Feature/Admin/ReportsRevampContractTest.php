<?php

declare(strict_types=1);

use App\Domain\Finance\Exports\PrepareReportCsvExport;
use App\Domain\Finance\Exports\RevenueReportExporter;
use App\Domain\Finance\Filament\Pages\ExportHistory;
use App\Domain\Finance\Filament\Pages\FinanceHub;
use App\Domain\Finance\Filament\Pages\Reports\DailyTenderReportPage;
use App\Domain\Finance\Filament\Pages\Reports\OutstandingAgedReportPage;
use App\Domain\Finance\Filament\Pages\Reports\PaymentMethodReportPage;
use App\Domain\Finance\Filament\Pages\Reports\ProfitReportPage;
use App\Domain\Finance\Filament\Pages\Reports\RevenueReportPage;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Finance\Filament\Pages\Reports\WageCostReportPage;
use App\Domain\Finance\Filament\Widgets\PaymentMethodChart;
use App\Domain\Finance\Filament\Widgets\ProfitChart;
use App\Domain\Finance\Filament\Widgets\RevenueChart;
use App\Domain\Finance\Filament\Widgets\WageCostChart;
use App\Domain\Finance\Jobs\GenerateReportPdfJob;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Models\PayrollLine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(now()->setDate(2026, 10, 15));
    Queue::fake();
    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };
});

dataset('revamped report pages', [
    [RevenueReportPage::class, RevenueChart::class],
    [OutstandingAgedReportPage::class, null],
    [PaymentMethodReportPage::class, PaymentMethodChart::class],
    [DailyTenderReportPage::class, null],
    [WageCostReportPage::class, WageCostChart::class],
    [ProfitReportPage::class, ProfitChart::class],
    [StudentPaymentHistoryPage::class, null],
]);

it('keeps all seven report destinations and each reports own chart on the authorized surface', function (string $pageClass, ?string $chart) {
    $page = Livewire::actingAs(($this->actor)(['view_financial_report']))->test($pageClass)->assertOk();
    $html = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html());
    expect($html)->toContain('aria-label="Financial reports"', 'aria-current="page"');
    $document = HTMLDocument::createFromString((string) $html, LIBXML_NOERROR);
    $form = $document->querySelector('form.tc-report-filters');
    expect($form)->not->toBeNull()
        ->and($form?->getAttribute('wire:submit'))->toBe('applyFilters')
        ->and($form?->querySelector('button[type="submit"]'))->not->toBeNull()
        ->and($form?->querySelector('.fi-input-wrp'))->not->toBeNull();
    foreach ([RevenueReportPage::class, OutstandingAgedReportPage::class, PaymentMethodReportPage::class, DailyTenderReportPage::class, WageCostReportPage::class, ProfitReportPage::class, StudentPaymentHistoryPage::class] as $destination) {
        expect($html)->toContain('href="'.e($destination::getUrl()).'"');
    }
    foreach ([RevenueChart::class, PaymentMethodChart::class, WageCostChart::class, ProfitChart::class] as $widget) {
        if ($widget === $chart) {
            $page->assertSeeLivewire($widget);
        } else {
            $page->assertDontSeeLivewire($widget);
        }
    }
    expect(str_contains($html, 'data-report-summary'))->toBe($chart !== null);
    $page->assertActionHidden('export_xlsx')->assertActionHidden('export_pdf');
    Livewire::actingAs(($this->actor)([]))->test($pageClass)->assertForbidden();
})->with('revamped report pages');

it('queues each export without an instant download and refuses a viewer with one fewer ability', function (string $action) {
    Charge::factory()->create();
    $actor = ($this->actor)(['view_financial_report', 'export_financial_report']);
    $page = Livewire::actingAs($actor)->test(OutstandingAgedReportPage::class);
    expect(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html()))
        ->toContain('Exports run in the background. You will be notified when your file is ready.');
    $page->callAction($action)->assertHasNoActionErrors()->assertNoFileDownloaded()->assertNoRedirect();
    if ($action === 'export_pdf') {
        Queue::assertPushed(GenerateReportPdfJob::class);
    } else {
        Queue::assertPushed(ChainedBatch::class, fn (ChainedBatch $batch): bool => $batch->jobs->first() instanceof PrepareReportCsvExport);
        expect(Export::query()->sole()->completed_at)->toBeNull();
    }
    Queue::fake();
    Livewire::actingAs(($this->actor)(['view_financial_report']))->test(OutstandingAgedReportPage::class)
        ->assertActionHidden($action)->call('mountAction', $action)->assertNoFileDownloaded();
    Queue::assertNothingPushed();
})->with(['export_pdf', 'export_xlsx']);

it('rechecks both report abilities on the same signed download after revocation', function (string $format, string $revoked) {
    Storage::fake('private');
    Storage::fake('local');
    $actor = ($this->actor)(['view_financial_report', 'export_financial_report']);
    if ($format === 'pdf') {
        $reference = (string) Str::uuid();
        Storage::disk('private')->put("financial-reports/{$actor->id}/{$reference}.pdf", 'report');
        $url = URL::temporarySignedRoute('filament.admin.pages.reports.pdf.download', now()->addHour(), ['requester' => $actor->id, 'reference' => $reference]);
    } else {
        $export = new Export(['exporter' => RevenueReportExporter::class, 'file_disk' => 'local', 'file_name' => 'report', 'total_rows' => 1, 'processed_rows' => 1, 'successful_rows' => 1, 'completed_at' => now()]);
        $export->user()->associate($actor);
        $export->save();
        Storage::disk('local')->put($export->getFileDirectory().'/report.xlsx', 'report');
        $url = URL::temporarySignedRoute('filament.admin.pages.reports.xlsx.download', now()->addHour(), ['export' => $export->id]);
    }
    $this->actingAs($actor)->get($url)->assertSuccessful();
    $actor->revokePermissionTo($revoked);
    $this->actingAs($actor->fresh())->get($url)->assertForbidden();
})->with(['pdf', 'xlsx'])->with(['view_financial_report', 'export_financial_report']);

it('renders its own exact report total and the complete profit definition where applicable', function (string $pageClass, string $total) {
    $charge = Charge::factory()->create();
    $payment = Payment::factory()->create(['received_at' => '2026-10-05 10:00:00']);
    PaymentAllocation::factory()->create(['charge_id' => $charge->id, 'payment_id' => $payment->id, 'amount' => '100.125']);
    PaymentTender::factory()->for($payment)->create(['amount' => '100.125']);
    $secondCharge = Charge::factory()->create();
    $secondPayment = Payment::factory()->create(['received_at' => '2026-10-06 10:00:00']);
    PaymentAllocation::factory()->create(['charge_id' => $secondCharge->id, 'payment_id' => $secondPayment->id, 'amount' => '0.875']);
    PaymentTender::factory()->for($secondPayment)->bankTransfer()->create(['amount' => '0.875']);
    PayrollLine::factory()->finalized()->create(['posting_period_start' => '2026-10-01', 'computed_amount' => '150.250']);
    PayrollLine::factory()->finalized()->create(['posting_period_start' => '2026-10-01', 'computed_amount' => '0.750']);
    Expense::factory()->create(['paid_on' => '2026-10-05', 'amount' => '25.001']);
    Expense::factory()->reversed()->create(['paid_on' => '2026-10-06', 'amount' => '999.000']);
    $page = Livewire::actingAs(($this->actor)(['view_financial_report']))->test($pageClass)->assertOk();
    $html = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html());
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//div[@role="region"]/table/tbody/tr')->length)->toBe($pageClass === ProfitReportPage::class ? 1 : 2);
    $summary = $xpath->query('//aside[@data-report-summary]')->item(0)?->textContent;
    expect($summary)->toContain($total)->not->toContain('reports.presentation.');
    if ($pageClass === ProfitReportPage::class) {
        expect($summary)->toContain('Profit = collected revenue - finalized wage cost - expenses paid in the period (reversed expenses excluded).');
    }
    $monthly = in_array($pageClass, [WageCostReportPage::class, ProfitReportPage::class], true);
    $page->fillForm(['from' => $monthly ? '2026-09' : '2026-09-01', 'to' => $monthly ? '2026-09' : '2026-09-30'])->call('applyFilters')->assertHasNoFormErrors();
    $html = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html());
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    expect((new DOMXPath($document))->query('//aside[@data-report-summary]')->item(0)?->textContent)->toContain('0.000')->not->toContain($total);
    Livewire::actingAs(($this->actor)([]))->test($pageClass)->assertForbidden();
})->with([
    'revenue' => [RevenueReportPage::class, '101.000'],
    'payment methods' => [PaymentMethodReportPage::class, '101.000'],
    'wage cost' => [WageCostReportPage::class, '151.000'],
    'profit with non-reversed paid expenses' => [ProfitReportPage::class, '-75.001'],
]);

it('offers export history navigation only when its destination allows access', function () {
    $actor = ($this->actor)(['view_financial_report', 'export_financial_report']);
    $page = Livewire::actingAs($actor)->test(FinanceHub::class)->assertOk();
    expect(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html()))->toContain('href="'.e(ExportHistory::getUrl()).'"');
    $page = Livewire::actingAs(($this->actor)(['view_financial_report']))->test(FinanceHub::class)->assertOk();
    expect($page->html())->not->toContain('href="'.e(ExportHistory::getUrl()).'"');
    Livewire::test(ExportHistory::class)->assertForbidden();
});
