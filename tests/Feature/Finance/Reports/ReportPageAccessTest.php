<?php

declare(strict_types=1);

use App\Domain\Finance\Exports\PrepareReportCsvExport;
use App\Domain\Finance\Exports\ReportExportAuthorization;
use App\Domain\Finance\Exports\ReportSnapshot;
use App\Domain\Finance\Filament\Pages\Reports\DailyTenderReportPage;
use App\Domain\Finance\Filament\Pages\Reports\OutstandingAgedReportPage;
use App\Domain\Finance\Filament\Pages\Reports\PaymentMethodReportPage;
use App\Domain\Finance\Filament\Pages\Reports\ProfitReportPage;
use App\Domain\Finance\Filament\Pages\Reports\RevenueReportPage;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Finance\Filament\Pages\Reports\WageCostReportPage;
use App\Domain\Finance\Jobs\GenerateReportPdfJob;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->admin = ($this->actorWith)('admin');
    $this->staff = ($this->actorWith)('staff');

    $viewerRole = Role::findOrCreate('financial_report_viewer', 'web');
    $this->system->syncRolePermissions($viewerRole, [
        'access_admin_panel',
        'view_financial_report',
    ]);
    $this->viewer = ($this->actorWith)('financial_report_viewer');
});

dataset('financial report pages', [
    RevenueReportPage::class,
    OutstandingAgedReportPage::class,
    PaymentMethodReportPage::class,
    DailyTenderReportPage::class,
    WageCostReportPage::class,
    ProfitReportPage::class,
    StudentPaymentHistoryPage::class,
]);

it('keeps exactly one finance page discovery seam', function () {
    $source = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));

    expect($source)->not->toBeFalse()
        ->and(substr_count((string) $source, "discoverPages(in: app_path('Domain/Finance/Filament/Pages')"))
        ->toBe(1);
});

it('uses the authorized snapshot preparation job', function () {
    $page = Livewire::actingAs($this->admin)->test(RevenueReportPage::class)->instance();
    $export = collect($page->getCachedHeaderActions())
        ->first(fn ($action): bool => $action->getName() === 'export_xlsx');

    expect($export)->not->toBeNull()
        ->and($export->getJob())->toBe(PrepareReportCsvExport::class);
});

it('requires both report abilities on an active account before exporting', function () {
    $exporterRole = Role::findOrCreate('financial_report_exporter', 'web');
    $this->system->syncRolePermissions($exporterRole, [
        'access_admin_panel',
        'export_financial_report',
    ]);
    $exporterWithoutView = ($this->actorWith)('financial_report_exporter');

    expect(ReportExportAuthorization::allows($this->admin))->toBeTrue()
        ->and(ReportExportAuthorization::allows($this->viewer))->toBeFalse()
        ->and(ReportExportAuthorization::allows($exporterWithoutView))->toBeFalse()
        ->and(ReportExportAuthorization::allows($this->staff))->toBeFalse()
        ->and(ReportExportAuthorization::allows(null))->toBeFalse();

    $this->admin->update(['is_active' => false]);

    expect(ReportExportAuthorization::allows($this->admin->refresh()))->toBeFalse();
});

it('lets an admin reach every report page and see both queued export actions', function (string $page) {
    expect($this->admin->can('view_financial_report'))->toBeTrue()
        ->and($this->admin->can('export_financial_report'))->toBeTrue();

    $this->actingAs($this->admin)
        ->get($page::getUrl())
        ->assertSuccessful();

    Livewire::actingAs($this->admin)
        ->test($page)
        ->assertOk()
        ->assertActionVisible('export_xlsx')
        ->assertActionVisible('export_pdf');
})->with('financial report pages');

it('refuses a staff member from every report route and component', function (string $page) {
    expect($this->staff->can('access_admin_panel'))->toBeTrue()
        ->and($this->staff->can('view_financial_report'))->toBeFalse()
        ->and($this->staff->can('export_financial_report'))->toBeFalse();

    $this->actingAs($this->staff)
        ->get($page::getUrl())
        ->assertForbidden();

    Livewire::actingAs($this->staff)
        ->test($page)
        ->assertForbidden();
})->with('financial report pages');

it('separates viewing a report from exporting it', function (string $page) {
    expect($this->viewer->can('view_financial_report'))->toBeTrue()
        ->and($this->viewer->can('export_financial_report'))->toBeFalse();

    Livewire::actingAs($this->viewer)
        ->test($page)
        ->assertOk()
        ->assertActionHidden('export_xlsx')
        ->assertActionHidden('export_pdf');
})->with('financial report pages');

/*
|--------------------------------------------------------------------------
| Whole-month report filters and their frozen export snapshot
|--------------------------------------------------------------------------
|
| Catches a production mutation that keeps the single-month control, ignores
| either endpoint when applying filters, or snapshots a displayed range other
| than the range used to build the report data.
*/

it('applies from and to whole-month filters and freezes their displayed range in a wage-cost export snapshot', function () {
    $employee = User::factory()->create();
    PayrollLine::factory()->create([
        'user_id' => $employee->getKey(),
        'computed_amount' => '250.000',
        'segment_start' => '2025-12-01',
        'segment_end' => '2025-12-31',
        'frozen_days' => 31,
        'frozen_days_in_month' => 31,
        'posting_period_start' => '2025-12-01',
        'finalized_at' => '2025-12-31 09:00:00',
    ]);
    PayrollLine::factory()->create([
        'user_id' => $employee->getKey(),
        'computed_amount' => '125.000',
        'segment_start' => '2026-01-01',
        'segment_end' => '2026-01-31',
        'frozen_days' => 31,
        'frozen_days_in_month' => 31,
        'posting_period_start' => '2026-01-01',
        'finalized_at' => '2026-01-31 09:00:00',
    ]);

    Queue::fake();

    Livewire::actingAs($this->admin)
        ->test(WageCostReportPage::class)
        ->set('filters.from', '2025-12')
        ->set('filters.to', '2026-01')
        ->call('applyFilters')
        ->assertSet('appliedFilters', ['from' => '2025-12', 'to' => '2026-01'])
        ->callAction('export_pdf')
        ->assertHasNoActionErrors();

    /** @var GenerateReportPdfJob $job */
    $job = Queue::pushed(GenerateReportPdfJob::class)->sole();
    $snapshot = ReportSnapshot::fromArray($job->snapshot);

    expect($snapshot->filters)->toBe([
        'From' => '2025-12',
        'To' => '2026-01',
    ])->and($snapshot->dataset->carrierIds())->toBe([$employee->getKey()])
        ->and($snapshot->dataset->cell($employee->getKey(), 'total'))->toBe('375.000');
});

it('rejects a reversed whole-month range before replacing the applied report snapshot', function () {
    $component = Livewire::actingAs($this->admin)->test(WageCostReportPage::class);
    $originalAppliedFilters = $component->get('appliedFilters');

    $component
        ->fillForm([
            'from' => '2026-02',
            'to' => '2026-01',
        ])
        ->call('applyFilters')
        ->assertHasFormErrors(['to'])
        ->assertSet('appliedFilters', $originalAppliedFilters);
});
