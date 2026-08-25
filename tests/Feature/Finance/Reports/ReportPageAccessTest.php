<?php

declare(strict_types=1);

use App\Domain\Finance\Exports\PrepareReportCsvExport;
use App\Domain\Finance\Exports\ReportExportAuthorization;
use App\Domain\Finance\Filament\Pages\Reports\DailyTenderReportPage;
use App\Domain\Finance\Filament\Pages\Reports\OutstandingAgedReportPage;
use App\Domain\Finance\Filament\Pages\Reports\PaymentMethodReportPage;
use App\Domain\Finance\Filament\Pages\Reports\ProfitReportPage;
use App\Domain\Finance\Filament\Pages\Reports\RevenueReportPage;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Finance\Filament\Pages\Reports\WageCostReportPage;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
