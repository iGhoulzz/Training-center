<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Pages\CertificateIssuanceQueue;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\CourseResource;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Filament\Pages\ExportHistory;
use App\Domain\Finance\Filament\Pages\FinanceHub;
use App\Domain\Finance\Filament\Pages\Reports\DailyTenderReportPage;
use App\Domain\Finance\Filament\Pages\Reports\OutstandingAgedReportPage;
use App\Domain\Finance\Filament\Pages\Reports\PaymentMethodReportPage;
use App\Domain\Finance\Filament\Pages\Reports\ProfitReportPage;
use App\Domain\Finance\Filament\Pages\Reports\RevenueReportPage;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Finance\Filament\Pages\Reports\WageCostReportPage;
use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Filament\Resources\DiscountResource;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource;
use App\Domain\Finance\Filament\Resources\ExpenseResource;
use App\Domain\Finance\Filament\Resources\PaymentResource;
use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource;
use App\Domain\Publications\Filament\Resources\ArticleResource;
use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Domain\Staff\Filament\Resources\StaffProfileResource;
use App\Domain\Staff\Filament\Resources\UserResource;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\PasswordChange;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the admin dashboard without a frontend build or hot file', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('access_admin_panel');
    $originalPublicPath = public_path();
    $originalPanel = Filament::getPanel('admin');

    // Isolate the real asset lookup without moving or deleting this checkout's build.
    app()->usePublicPath(sys_get_temp_dir().DIRECTORY_SEPARATOR.'training-center-unbuilt-'.Str::uuid());

    try {
        expect(file_exists(public_path('build/manifest.json')))->toBeFalse()
            ->and(file_exists(public_path('hot')))->toBeFalse();

        $panel = (new AdminPanelProvider(app()))->panel(new Panel);
        app(PanelRegistry::class)->register($panel);
        Filament::setCurrentPanel($panel);

        $response = $this->actingAs($actor)->get('/admin')->assertSuccessful();

        expect(renderedWithoutLivewireState($response))->toContain('Front desk');
    } finally {
        app()->usePublicPath($originalPublicPath);
        app(PanelRegistry::class)->register($originalPanel);
        Filament::setCurrentPanel($originalPanel);
    }
});

it('loads the custom theme from an available asset source', function (string $source, string $expectedAsset) {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('access_admin_panel');
    $originalPublicPath = public_path();
    $originalPanel = Filament::getPanel('admin');
    $fixturePublicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'training-center-theme-'.Str::uuid();
    File::makeDirectory($fixturePublicPath);

    try {
        if ($source === 'manifest') {
            File::makeDirectory($fixturePublicPath.DIRECTORY_SEPARATOR.'build');
            File::put($fixturePublicPath.'/build/manifest.json', json_encode([
                'resources/css/filament/admin/theme.css' => [
                    'file' => 'assets/theme-shell-fixture.css',
                    'src' => 'resources/css/filament/admin/theme.css',
                    'isEntry' => true,
                ],
            ], JSON_THROW_ON_ERROR));
        } else {
            File::put($fixturePublicPath.'/hot', 'http://localhost:5173');
        }

        app()->usePublicPath($fixturePublicPath);
        $panel = (new AdminPanelProvider(app()))->panel(new Panel);
        app(PanelRegistry::class)->register($panel);
        Filament::setCurrentPanel($panel);

        $response = $this->actingAs($actor)->get('/admin')->assertSuccessful();

        expect(renderedWithoutLivewireState($response))->toContain($expectedAsset);
    } finally {
        app()->usePublicPath($originalPublicPath);
        app(PanelRegistry::class)->register($originalPanel);
        Filament::setCurrentPanel($originalPanel);
        File::delete($fixturePublicPath.'/hot', $fixturePublicPath.'/build/manifest.json');

        if (is_dir($fixturePublicPath.'/build')) {
            rmdir($fixturePublicPath.'/build');
        }

        rmdir($fixturePublicPath);
    }
})->with([
    'production manifest' => ['manifest', '/build/assets/theme-shell-fixture.css'],
    'development hot file' => ['hot', 'http://localhost:5173/resources/css/filament/admin/theme.css'],
]);

it('renders the centre date using the translated date pattern at the UTC day boundary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 22:30:00', 'UTC'));
    __('navigation.dashboard_context');
    app('translator')->addLines(['navigation.dashboard_date_format' => '[Date sentinel] YYYY-MM-DD'], 'en');
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('access_admin_panel');

    $response = $this->actingAs($actor)->get('/admin')->assertSuccessful();

    expect(renderedWithoutLivewireState($response))->toContain('Date sentinel 2026-10-13');
});

it('interpolates the password minimum into the translated instructions', function () {
    app('translator')->addLines(['navigation.password_change_description' => 'Minimum sentinel :minimum characters'], 'en');
    $actor = User::factory()->create(['is_active' => true, 'must_change_password' => true]);
    $actor->givePermissionTo('access_admin_panel');

    $response = $this->actingAs($actor)->get('/admin/password-change')->assertSuccessful();

    expect(renderedWithoutLivewireState($response))->toContain('Minimum sentinel 12 characters');
});

it('enforces the displayed twelve-character password boundary', function (int $length, bool $rejected) {
    $actor = User::factory()->create(['is_active' => true, 'must_change_password' => true]);
    $actor->givePermissionTo('access_admin_panel');
    $this->actingAs($actor);
    $password = str_repeat('a', $length);

    $component = Livewire::test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ])
        ->call('save');

    if ($rejected) {
        $component->assertHasFormErrors(['password' => 'min']);
        expect(Hash::check('password', $actor->refresh()->password))->toBeTrue();
    } else {
        $component->assertHasNoFormErrors();
        expect(Hash::check($password, $actor->refresh()->password))->toBeTrue();
    }
})->with([
    'eleven characters refused' => [11, true],
    'twelve characters accepted' => [12, false],
]);

it('renders the front desk at the existing dashboard route', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(['access_admin_panel', 'create_enrollment']);

    $response = $this->actingAs($actor)->get('/admin')->assertSuccessful();
    $html = renderedWithoutLivewireState($response);

    expect($html)->toContain('Front desk')
        ->toContain('href="'.route('filament.admin.pages.enroll-and-collect').'"')
        ->not->toContain('filament-info-widget')
        ->not->toContain('Certificates due')
        ->not->toContain('Today / Proposed');
});

it('keeps every registered resource and navigable page in the shell', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $response = $this->actingAs($actor)->get('/admin')->assertSuccessful();
    $panel = Filament::getPanel('admin');

    expect(array_values($panel->getResources()))->toEqualCanonicalizing([
        StudentResource::class, CourseResource::class, BatchResource::class,
        StudentCertificateResource::class, ChargeResource::class, DiscountResource::class,
        PaymentResource::class, PayrollRunResource::class, StaffCompensationResource::class,
        ExpenseCategoryResource::class, ExpenseResource::class, ArticleResource::class,
        ActivityResource::class, RoleResource::class, StaffProfileResource::class, UserResource::class,
    ])->and(array_values($panel->getPages()))->toEqualCanonicalizing([
        Dashboard::class, PasswordChange::class, EnrollAndCollect::class, FinanceHub::class,
        ExportHistory::class, CertificateIssuanceQueue::class, RevenueReportPage::class,
        OutstandingAgedReportPage::class, PaymentMethodReportPage::class, DailyTenderReportPage::class,
        WageCostReportPage::class, ProfitReportPage::class, StudentPaymentHistoryPage::class,
    ]);

    $html = renderedWithoutLivewireState($response);

    foreach (array_merge($panel->getResources(), $panel->getPages()) as $destination) {
        if ($destination === PasswordChange::class) {
            continue;
        }

        expect($destination::canAccess())->toBeTrue($destination);
        expect($html)->toContain('href="'.$destination::getNavigationUrl().'"');
    }

    expect(array_map(fn ($group): ?string => $group->getLabel(), $panel->getNavigation()))
        ->toBe(['Front desk', 'Enrolment', 'Finance', 'Reports', 'Administration', 'Publications']);
    expect($html)->not->toContain('href="'.PasswordChange::getNavigationUrl().'"');
});

it('shows the student destination to an actor holding its exact permission', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(['access_admin_panel', 'view_any_student']);

    $html = renderedWithoutLivewireState($this->actingAs($actor)->get('/admin')->assertSuccessful());

    expect($html)->toContain('href="'.StudentResource::getNavigationUrl().'"')
        ->not->toContain('href="'.PaymentResource::getNavigationUrl().'"')
        ->not->toContain('href="'.EnrollAndCollect::getNavigationUrl().'"');
});

it('omits the student destination when every other permission is held', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(Permission::query()->where('guard_name', 'web')->where('name', '!=', 'view_any_student')->get());

    $html = renderedWithoutLivewireState($this->actingAs($actor)->get('/admin')->assertSuccessful());

    expect($html)->not->toContain('href="'.StudentResource::getNavigationUrl().'"')
        ->toContain('href="'.PaymentResource::getNavigationUrl().'"');

    $this->get(StudentResource::getNavigationUrl())->assertForbidden();
});

it('renders the translated password heading in the contained layout', function () {
    app('translator')->addLines(['navigation.password_change' => 'Rotation heading sentinel'], 'en');
    $actor = User::factory()->create(['is_active' => true, 'must_change_password' => true]);
    $actor->givePermissionTo('access_admin_panel');

    $response = $this->actingAs($actor)->get(route('filament.admin.pages.password-change'))->assertSuccessful();
    $html = renderedWithoutLivewireState($response);

    expect($html)->toContain('Rotation heading sentinel')
        ->toContain('method="POST" action="'.route('filament.admin.auth.logout').'"')
        ->not->toContain('fi-sidebar-nav')
        ->not->toContain('fi-topbar-ctn');
});
