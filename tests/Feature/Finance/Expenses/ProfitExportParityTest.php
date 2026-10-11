<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Exports\ReportDataBuilder;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Exports\ReportSnapshot;
use App\Domain\Finance\Filament\Pages\Reports\ProfitReportPage;
use App\Domain\Finance\Filament\Pages\Reports\WageCostReportPage;
use App\Domain\Finance\Jobs\GenerateReportPdfJob;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

/*
|--------------------------------------------------------------------------
| One month of Profit, read through the screen, the XLSX and the PDF
|--------------------------------------------------------------------------
|
| `ReportDataBuilder::profit()` is the ONE snapshot the screen, the PDF and the XLSX
| all read, so the expenses figure cannot diverge between them. This file proves it
| for one month instead of trusting the sentence: the same hand-computed figure is
| found on the dataset, in the rendered page, in the stored spreadsheet and in the
| HTML the PDF is rendered from.
|
| THE MONTH (October 2026), worked out by hand:
|
|   collected revenue   3000.000 + 2000.000                 = 5000.000
|   finalized wage cost                                     = 1200.000
|   standing expenses   300.500 + 99.500                    =  400.000
|   a reversed expense  999.000 — absent from every figure
|   profit              5000.000 - 1200.000 - 400.000       = 3400.000
*/
uses(RefreshDatabase::class);

const PROFIT_PARITY_OPTIONS = ['from' => '2026-10', 'to' => '2026-10'];

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->admin, 'admin');
    $this->admin = $this->admin->refresh();

    $category = ExpenseCategory::factory()->create();

    foreach ([['3000.000', '2026-10-05 10:00:00'], ['2000.000', '2026-10-20 10:00:00']] as [$amount, $receivedAt]) {
        $course = Course::factory()->create();
        $batch = Batch::factory()->for($course)->create();
        $enrollment = Enrollment::factory()->for($batch)->create();
        $charge = Charge::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'list_price' => '9000.000',
            'amount' => '9000.000',
        ]);
        $payment = Payment::factory()->create(['received_at' => $receivedAt]);

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => $amount,
        ]);
    }

    PayrollLine::factory()->create([
        'user_id' => User::factory()->create()->getKey(),
        'computed_amount' => '1200.000',
        'posting_period_start' => '2026-10-01',
        'finalized_at' => '2026-10-28 09:00:00',
    ]);

    Expense::factory()->for($category, 'category')->create(['amount' => '300.500', 'paid_on' => '2026-10-03']);
    Expense::factory()->for($category, 'category')->create(['amount' => '99.500', 'paid_on' => '2026-10-28']);
    Expense::factory()->for($category, 'category')->reversed()->create(['amount' => '999.000', 'paid_on' => '2026-10-04']);

    $this->openProfitPage = fn () => Livewire::actingAs($this->admin)
        ->test(ProfitReportPage::class)
        ->set('filters.from', '2026-10')
        ->set('filters.to', '2026-10')
        ->call('applyFilters');
});

/**
 * Every cell of every row of the stored spreadsheet.
 *
 * Named for this file: Pest loads every test file into one process, so a helper
 * called `xlsxValues` here would collide with the one in ReportExportTest.
 *
 * @return list<list<string|int|float|bool|null>>
 */
function profitParityXlsxRows(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
        }
    }

    $reader->close();

    return $rows;
}

/*
|--------------------------------------------------------------------------
| The one snapshot
|--------------------------------------------------------------------------
*/

it('builds one dataset whose expenses cell is the standing expenses of the month', function () {
    $dataset = app(ReportDataBuilder::class)->build(ReportKind::Profit, PROFIT_PARITY_OPTIONS, $this->admin);

    expect(array_keys($dataset->columns))->toBe(['revenue', 'wage_cost', 'expenses', 'profit'])
        ->and($dataset->rows)->toHaveCount(1)
        ->and($dataset->rows[0]['cells'])->toBe([
            'revenue' => '5000.000',
            'wage_cost' => '1200.000',
            'expenses' => '400.000',
            'profit' => '3400.000',
        ]);
});

it('labels the expenses column, and states the profit definition in its heading', function () {
    $dataset = app(ReportDataBuilder::class)->build(ReportKind::Profit, PROFIT_PARITY_OPTIONS, $this->admin);

    expect($dataset->columns['expenses'])->toBe('Expenses paid (LYD, reversed excluded)')
        ->and($dataset->columns['profit'])->toBe('Profit (LYD) = revenue - wage cost - expenses');
});

/*
|--------------------------------------------------------------------------
| The screen
|--------------------------------------------------------------------------
*/

it('shows the expenses figure and its heading on the page', function () {
    ($this->openProfitPage)()
        ->assertSee('Expenses paid (LYD, reversed excluded)')
        ->assertSee('400.000')
        ->assertSee('3400.000')
        // The reversed 999.000 is on no part of the page.
        ->assertDontSee('999.000');
});

it('states the profit definition under the page title', function () {
    ($this->openProfitPage)()
        ->assertSee('Profit = collected revenue - finalized wage cost - expenses paid in the period (reversed expenses excluded).');
});

it('states the definition on the Profit page only, not on another report', function () {
    Livewire::actingAs($this->admin)
        ->test(WageCostReportPage::class)
        ->assertDontSee('reversed expenses excluded');
});

/*
|--------------------------------------------------------------------------
| The XLSX
|--------------------------------------------------------------------------
*/

it('writes the same expenses figure under the same heading into the spreadsheet', function () {
    Storage::fake('local');

    ($this->openProfitPage)()
        ->callAction('export_xlsx')
        ->assertHasNoActionErrors();

    $export = Export::query()->sole();

    expect($export->total_rows)->toBe(1)
        ->and($export->successful_rows)->toBe(1);

    $rows = profitParityXlsxRows(Storage::disk('local')->path(
        $export->getFileDirectory().'/'.$export->file_name.'.xlsx',
    ));

    $headingAt = collect($rows)->search(fn (array $row): bool => in_array('Expenses paid (LYD, reversed excluded)', $row, true));

    expect($headingAt)->not->toBeFalse();

    $heading = $rows[$headingAt];
    $figures = $rows[$headingAt + 1];
    $at = array_search('Expenses paid (LYD, reversed excluded)', $heading, true);

    expect($figures[$at])->toBe('400.000')
        ->and($figures[array_search('Collected revenue (LYD)', $heading, true)])->toBe('5000.000')
        ->and($figures[array_search('Wage cost (LYD)', $heading, true)])->toBe('1200.000')
        ->and($figures[array_search('Profit (LYD) = revenue - wage cost - expenses', $heading, true)])->toBe('3400.000');
});

/*
|--------------------------------------------------------------------------
| The PDF
|--------------------------------------------------------------------------
*/

it('puts the same expenses figure under the same heading into the document the PDF is rendered from', function () {
    $originalQueue = Queue::getFacadeRoot();

    try {
        Queue::fake();

        ($this->openProfitPage)()
            ->callAction('export_pdf')
            ->assertHasNoActionErrors();

        Queue::assertPushed(GenerateReportPdfJob::class);

        /** @var GenerateReportPdfJob $queued */
        $queued = Queue::pushed(GenerateReportPdfJob::class)->sole();
        $snapshot = ReportSnapshot::fromArray($queued->snapshot);
    } finally {
        Queue::swap($originalQueue);
    }

    expect($snapshot->kind)->toBe(ReportKind::Profit)
        ->and($snapshot->dataset->rows[0]['cells']['expenses'])->toBe('400.000')
        ->and($snapshot->dataset->rows[0]['cells']['profit'])->toBe('3400.000');

    // The very template GenerateReportPdfJob renders, fed the very snapshot it was
    // queued with.
    $html = view('finance.reports.pdf', [
        'snapshot' => $snapshot,
        'locale' => $snapshot->locale,
        'direction' => 'ltr',
    ])->render();

    expect($html)->toContain('Expenses paid (LYD, reversed excluded)')
        ->toContain('400.000')
        ->toContain('Profit (LYD) = revenue - wage cost - expenses')
        ->toContain('3400.000')
        ->not->toContain('999.000');
});

it('stores a real PDF when the job runs on the same snapshot', function () {
    Storage::fake('private');
    $snapshot = [
        'kind' => 'profit',
        'title' => 'Profit',
        'locale' => 'en',
        'filters' => ['From' => '2026-10', 'To' => '2026-10'],
        'dataset' => app(ReportDataBuilder::class)
            ->build(ReportKind::Profit, PROFIT_PARITY_OPTIONS, $this->admin)
            ->toArray(),
    ];

    $job = new GenerateReportPdfJob($snapshot, $this->admin->getKey());
    $job->handle();

    $path = "financial-reports/{$this->admin->getKey()}/{$job->reference}.pdf";

    Storage::disk('private')->assertExists($path);
    expect(Storage::disk('private')->get($path))->toStartWith('%PDF-');
});

/*
|--------------------------------------------------------------------------
| The three agree with each other, not only with the literal
|--------------------------------------------------------------------------
*/

it('finds one and the same expenses figure on the dataset, in the spreadsheet and in the PDF source', function () {
    Storage::fake('local');
    $originalQueue = Queue::getFacadeRoot();

    $datasetFigure = app(ReportDataBuilder::class)
        ->build(ReportKind::Profit, PROFIT_PARITY_OPTIONS, $this->admin)
        ->rows[0]['cells']['expenses'];

    ($this->openProfitPage)()->callAction('export_xlsx');
    $export = Export::query()->sole();
    $rows = profitParityXlsxRows(Storage::disk('local')->path(
        $export->getFileDirectory().'/'.$export->file_name.'.xlsx',
    ));
    $headingAt = collect($rows)->search(fn (array $row): bool => in_array('Expenses paid (LYD, reversed excluded)', $row, true));
    $xlsxFigure = $rows[$headingAt + 1][array_search('Expenses paid (LYD, reversed excluded)', $rows[$headingAt], true)];

    try {
        Queue::fake();
        ($this->openProfitPage)()->callAction('export_pdf');
        $snapshot = ReportSnapshot::fromArray(Queue::pushed(GenerateReportPdfJob::class)->sole()->snapshot);
    } finally {
        Queue::swap($originalQueue);
    }
    $pdfFigure = $snapshot->dataset->rows[0]['cells']['expenses'];

    // All three equal each other, and the literal worked out by hand.
    expect($datasetFigure)->toBe($xlsxFigure)
        ->and($xlsxFigure)->toBe($pdfFigure)
        ->and($pdfFigure)->toBe('400.000');
});
