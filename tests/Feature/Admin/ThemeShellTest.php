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
use Dom\HTMLDocument;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Support\Colors\Color;
use Filament\Support\Colors\ColorManager;
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

/*
|--------------------------------------------------------------------------
| The shell, to the design (P4-T20c)
|--------------------------------------------------------------------------
|
| The admin's colours, brand, width and theme are checked against the design's code
| (Filament Revamp.dc.html and the handoff README's panel tokens), which fixes them.
|
| THE EXPECTED VALUES ARE WRITTEN OUT BELOW, NOT READ BACK from the provider that
| registers them: a palette read from the provider would agree with the provider
| whatever it held. They are Tailwind 3's hex shades, which the README's panel table
| names (gray 950 page, 900 surface, 50 text; amber 500 primary and 400 hover; the
| four status colours at their 400 shade). Filament stores every colour as OKLCH, to
| three decimals, so a registered hex comes back within two units of 255 on a channel
| (seven amber shades are one or two off) and the stored string is what is compared.
|
| THE BRAND TESTS USE SENTINELS, NOT THE REAL COPY, for the reason DesignSystemTest
| gives: a brand that was hardcoded, or read from app.name, would still show the real
| copy and pass.
*/

/**
 * Tailwind 3's palettes in hex, as the design's README and prototype use them.
 *
 * @return array<string, array<int, string>>
 */
function adminDesignPalettes(): array
{
    return [
        'gray' => [50 => '#f9fafb', 100 => '#f3f4f6', 200 => '#e5e7eb', 300 => '#d1d5db', 400 => '#9ca3af', 500 => '#6b7280', 600 => '#4b5563', 700 => '#374151', 800 => '#1f2937', 900 => '#111827', 950 => '#030712'],
        'amber' => [50 => '#fffbeb', 100 => '#fef3c7', 200 => '#fde68a', 300 => '#fcd34d', 400 => '#fbbf24', 500 => '#f59e0b', 600 => '#d97706', 700 => '#b45309', 800 => '#92400e', 900 => '#78350f', 950 => '#451a03'],
        'green' => [50 => '#f0fdf4', 100 => '#dcfce7', 200 => '#bbf7d0', 300 => '#86efac', 400 => '#4ade80', 500 => '#22c55e', 600 => '#16a34a', 700 => '#15803d', 800 => '#166534', 900 => '#14532d', 950 => '#052e16'],
        'blue' => [50 => '#eff6ff', 100 => '#dbeafe', 200 => '#bfdbfe', 300 => '#93c5fd', 400 => '#60a5fa', 500 => '#3b82f6', 600 => '#2563eb', 700 => '#1d4ed8', 800 => '#1e40af', 900 => '#1e3a8a', 950 => '#172554'],
        'red' => [50 => '#fef2f2', 100 => '#fee2e2', 200 => '#fecaca', 300 => '#fca5a5', 400 => '#f87171', 500 => '#ef4444', 600 => '#dc2626', 700 => '#b91c1c', 800 => '#991b1b', 900 => '#7f1d1d', 950 => '#450a0a'],
    ];
}

/**
 * What Filament holds for the admin panel's colours: the panel's own registration run
 * through a fresh ColorManager, which is how Panel::boot() stores it. A fresh one,
 * because the shared manager is only filled once a request has booted the panel and
 * keeps its first answer, so a test that read it would depend on the tests before it.
 *
 * @return array<string, array<int, string>>
 */
function adminStoredColours(): array
{
    $manager = new ColorManager;
    $manager->register(Filament::getPanel('admin')->getColors());

    return $manager->getColors();
}

it('registers each panel colour as the design\'s exact hex palette', function (string $role, string $palette) {
    $registered = adminStoredColours()[$role] ?? null;

    expect($registered)->not->toBeNull();

    foreach (adminDesignPalettes()[$palette] as $shade => $hex) {
        // Filament stores a registered hex as OKLCH: compared as stored.
        expect($registered[$shade])->toBe(Color::convertToOklch($hex), "{$role} {$shade}");

        // And the stored value is the hex to within the three decimals Filament keeps.
        [$red, $green, $blue] = sscanf(Color::convertToHex($registered[$shade]), '#%02x%02x%02x');
        [$expectedRed, $expectedGreen, $expectedBlue] = sscanf($hex, '#%02x%02x%02x');

        expect(max(abs($red - $expectedRed), abs($green - $expectedGreen), abs($blue - $expectedBlue)))
            ->toBeLessThanOrEqual(2, "{$role} {$shade} is further from {$hex} than Filament's rounding allows.");
    }
})->with([
    'gray' => ['gray', 'gray'],
    'primary' => ['primary', 'amber'],
    'warning' => ['warning', 'amber'],
    'success' => ['success', 'green'],
    'info' => ['info', 'blue'],
    'danger' => ['danger', 'red'],
]);

it('registers the README\'s named panel values, and not Tailwind 4\'s re-tuned palettes', function () {
    // Hand-written from the README's panel table, then converted once by Filament's own
    // converter: page #030712, surface #111827, text #f9fafb, primary #f59e0b (hover
    // #fbbf24), success #4ade80, info #60a5fa, warning #fbbf24 and danger #f87171.
    expect(adminStoredColours()['gray'][950])->toBe('oklch(0.13 0.027 261.692)')
        ->and(adminStoredColours()['gray'][900])->toBe('oklch(0.21 0.032 264.665)')
        ->and(adminStoredColours()['gray'][50])->toBe('oklch(0.985 0.002 247.839)')
        ->and(adminStoredColours()['primary'][500])->toBe('oklch(0.769 0.165 70.08)')
        ->and(adminStoredColours()['primary'][400])->toBe('oklch(0.837 0.164 84.429)')
        ->and(adminStoredColours()['success'][400])->toBe('oklch(0.8 0.182 151.711)')
        ->and(adminStoredColours()['info'][400])->toBe('oklch(0.714 0.143 254.624)')
        ->and(adminStoredColours()['warning'][400])->toBe('oklch(0.837 0.164 84.429)')
        ->and(adminStoredColours()['danger'][400])->toBe('oklch(0.711 0.166 22.216)');

    // Tailwind 4's amber is a re-tuning of the same name, visibly more saturated.
    expect(adminStoredColours()['primary'][500])->not->toBe(Color::Amber[500])
        ->and(adminStoredColours()['gray'][950])->not->toBe(Color::Gray[950]);
});

/**
 * The stylesheet without its comments and without its @theme blocks, which are where
 * the design's values are defined: the rest of the file may only use them by name.
 */
function adminThemeWithoutTokenDefinitions(string $css): string
{
    $code = (string) preg_replace('~/\*.*?\*/~s', ' ', $css);

    while (($start = strpos($code, '@theme')) !== false) {
        $open = strpos($code, '{', $start);
        $depth = 0;
        $end = strlen($code) - 1;

        for ($position = (int) $open; $position < strlen($code); $position++) {
            $depth += $code[$position] === '{' ? 1 : ($code[$position] === '}' ? -1 : 0);

            if ($depth === 0) {
                $end = $position;

                break;
            }
        }

        $code = substr($code, 0, $start).' '.substr($code, $end + 1);
    }

    return $code;
}

/**
 * The first raw colour in this stylesheet's rules, or null: a hex value, a colour
 * function, a colour utility from Tailwind's default palette (or white or black), or
 * an arbitrary-value colour. `gray-*` and `primary-*` are the panel's own registered
 * palettes, and `on-primary`, `hairline` and the rest are this file's tokens.
 */
function adminThemeFirstRawColour(string $css): ?string
{
    $code = adminThemeWithoutTokenDefinitions($css);
    $palette = 'slate|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $utility = '(?:bg|text|border|ring|outline|fill|stroke|from|to|via|divide|decoration|accent|caret|shadow|placeholder)';

    $patterns = [
        '/#[0-9a-f]{3,8}\b/i',
        '/\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color-mix|color)\s*\(/i',
        '/(?<![\w-])(?:[a-z-]+:)*'.$utility.'-(?:'.$palette.')-\d{2,3}(?![\w-])/i',
        '/(?<![\w-])(?:[a-z-]+:)*'.$utility.'-(?:white|black)(?![\w-])/i',
        '/-\[\s*(?:#|(?:rgba?|hsla?)\s*\()/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code, $match) === 1) {
            return trim($match[0]);
        }
    }

    return null;
}

it('catches every raw colour the admin theme may not use', function (string $sample) {
    expect(adminThemeFirstRawColour($sample))->not->toBeNull("The scanner missed: {$sample}");
})->with([
    '.a { color: #1c1204; }',
    '.a { color: #fff; }',
    '.a { border-color: rgb(255 255 255 / 10%); }',
    '.a { background: rgba(0, 0, 0, .5); }',
    '.a { background: oklch(0.7 0.1 70); }',
    '.a { background: color-mix(in srgb, red 10%, transparent); }',
    '.a { @apply bg-amber-500; }',
    '.a { @apply hover:text-amber-400; }',
    '.a { @apply bg-red-600; }',
    '.a { @apply ring-white/10; }',
    '.a { @apply bg-black/50; }',
    '.a { @apply text-[#1c1204]; }',
    '.a { @apply bg-[rgb(1,2,3)]; }',
]);

it('leaves the admin theme\'s own tokens and registered palettes alone', function (string $sample) {
    expect(adminThemeFirstRawColour($sample))->toBeNull("The scanner wrongly flagged: {$sample}");
})->with([
    '.a { @apply bg-gray-950 text-gray-50; }',
    '.a { @apply bg-primary-500/13 text-primary-400 hover:bg-primary-400; }',
    '.a { @apply text-on-primary ring-hairline bg-fill-hover; }',
    '.a { @apply bg-danger-600 text-success-400; }',
    '.a { border-color: var(--color-hairline); }',
    '@theme { --color-on-primary: #1c1204; --color-hairline: rgb(255 255 255 / 0.1); } .a { @apply text-on-primary; }',
    '/* never #fff, rgb(1 2 3) or bg-amber-500 */ .a { @apply text-gray-50; }',
    '.a { @apply white-space-nowrap; } .b { content: "#"; }',
]);

it('keeps amber and every raw colour out of the admin theme\'s rules', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)->not->toContain('amber-')
        ->and(adminThemeFirstRawColour($css))->toBeNull('theme.css names a colour outside its tokens.');

    // Where the values live instead: the tokens the rules name.
    expect(str_contains($css, '--color-on-primary: #1c1204;'))->toBeTrue()
        ->and(str_contains($css, '--container-admin: 90rem;'))->toBeTrue();
});

it('draws the brand from config, as a logo and the short name', function () {
    config([
        'public_site.brand.name' => 'SENTINEL-FULL-NAME',
        'public_site.brand.short_name' => 'SENTINEL-SHORT',
        'public_site.brand.tagline' => 'SENTINEL-TAGLINE',
        'public_site.brand.name_ar' => 'SENTINEL-ARABIC',
        'public_site.brand.logo' => 'images/brand/sentinel-logo.png',
        'app.name' => 'SENTINEL-APP-NAME',
    ]);

    $html = view('filament.admin.brand')->render();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $images = $document->querySelectorAll('img');

    // A 28px circle: decorative (the name beside it carries the meaning), on this origin.
    expect(count($images))->toBe(1)
        ->and($images[0]->getAttribute('src'))->toBe(url('/images/brand/sentinel-logo.png'))
        ->and($images[0]->hasAttribute('alt'))->toBeTrue()
        ->and($images[0]->getAttribute('alt'))->toBe('')
        ->and($images[0]->getAttribute('width'))->toBe('28')
        ->and($images[0]->getAttribute('height'))->toBe('28');

    // The short name beside it, and nothing else of the brand: the full name does not fit
    // the 258px sidebar, and the tagline and the Arabic name are the public site's.
    expect(trim($document->querySelector('.tc-brand-name')?->textContent ?? ''))->toBe('SENTINEL-SHORT')
        ->and($html)->not->toContain('SENTINEL-FULL-NAME')
        ->not->toContain('SENTINEL-TAGLINE')
        ->not->toContain('SENTINEL-ARABIC')
        ->not->toContain('SENTINEL-APP-NAME');
});

it('sets the panel\'s brand name from config, never from app.name', function () {
    config(['public_site.brand.short_name' => 'SENTINEL-SHORT', 'app.name' => 'SENTINEL-APP-NAME']);

    $panel = Filament::getPanel('admin');

    expect($panel->getBrandName())->toBe('SENTINEL-SHORT')
        ->and($panel->getBrandName())->not->toBe(config('app.name'));
});

it('puts the brand in the topbar and the sidebar header of every admin page, at the design\'s measurements', function () {
    config([
        'public_site.brand.short_name' => 'SENTINEL-SHORT',
        'public_site.brand.logo' => 'images/brand/sentinel-logo.png',
        'app.name' => 'SENTINEL-APP-NAME',
    ]);

    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('access_admin_panel');

    $html = renderedWithoutLivewireState($this->actingAs($actor)->get('/admin')->assertSuccessful());
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    // The topbar's brand cell (from 1024px) and the sidebar's header (below it).
    foreach (['.fi-topbar-start .tc-brand', '.fi-sidebar-header .tc-brand'] as $selector) {
        $brand = $document->querySelector($selector);

        expect($brand)->not->toBeNull($selector)
            ->and($brand->querySelector('img')?->getAttribute('src'))->toBe(url('/images/brand/sentinel-logo.png'))
            ->and(trim($brand->querySelector('.tc-brand-name')?->textContent ?? ''))->toBe('SENTINEL-SHORT');
    }

    // The sidebar is 258px wide (16.125rem at 16px), the logo's box 28px (1.75rem) and the
    // content 1440px (90rem) at most: the prototype's own numbers.
    $panel = Filament::getPanel('admin');

    expect($panel->getSidebarWidth())->toBe('16.125rem')
        ->and($panel->getBrandLogoHeight())->toBe('1.75rem')
        ->and($document->querySelector('main.fi-main')?->classList->contains('tc-width-admin'))->toBeTrue()
        ->and($document->querySelector('title')?->textContent)->toContain('SENTINEL-SHORT')
        ->and($html)->not->toContain('SENTINEL-APP-NAME');
});

it('gives the content its 1440px width, and the shell its measurements, as tokens and rules', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

    // 90rem is 1440px at the browser's 16px; .tc-width-admin is the class the panel puts on <main>.
    expect(preg_match('/\.tc-width-admin\s*\{[^}]*max-w-admin/s', $css))->toBe(1);

    // Each shell rule names the design's value. A table of the prototype's numbers, in the
    // Tailwind spacing the rule uses: 9px is 2.25, 10px 2.5, 11px 2.75, 14px 3.5, 18px 4.5.
    foreach ([
        '.fi-sidebar-item-btn' => ['gap-x-2.75', 'rounded-lg', 'px-2.5', 'py-2.25'],
        '.fi-sidebar-group-btn' => ['px-2.5', 'pt-4', 'pb-1.5'],
        // gray-400, not the prototype's gray-500: that is 3.67:1 on gray-900, under 4.5:1.
        '.fi-sidebar-group-label' => ['text-[11px]', 'font-semibold', 'tracking-[0.08em]', 'uppercase', 'text-gray-400'],
        '.fi-sidebar-nav' => ['px-3', 'py-3.5'],
        '.fi-header-heading' => ['text-[28px]', 'font-semibold', 'tracking-[-0.025em]', 'text-gray-50'],
        '.fi-header-subheading' => ['text-[12.5px]', 'text-gray-400'],
    ] as $selector => $utilities) {
        preg_match('/(?<![\w.-])'.preg_quote($selector, '/').'\s*\{(.*?)\n    \}/s', $css, $rule);

        foreach ($utilities as $utility) {
            expect(str_contains($rule[1] ?? '', $utility))->toBeTrue("{$selector} does not carry {$utility}.");
        }
    }
});

/**
 * The body of the first rule in theme.css whose selector list is exactly this, or null.
 */
function adminThemeRuleBody(string $css, string $selector): ?string
{
    $code = (string) preg_replace('~/\*.*?\*/~s', ' ', $css);

    if (preg_match('/(?<![\w.-])'.preg_quote($selector, '/').'\s*\{(.*?)\n    \}/s', $code, $match) === 1) {
        return $match[1];
    }

    return null;
}

it('lifts the topbar search placeholder to gray-400, because gray-500 is 3.32:1 on the pill', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
    $rule = adminThemeRuleBody($css, '.fi-topbar .fi-global-search input.fi-input');

    expect($rule)->not->toBeNull()
        ->and(str_contains((string) $rule, 'placeholder:text-gray-400'))->toBeTrue()
        ->and(str_contains((string) $rule, 'placeholder:text-gray-500'))->toBeFalse();

    // Hand-computed from the README's panel values: gray-400 #9ca3af on the pill, which is
    // white at 4% (the --color-fill-subtle token) over the gray-900 topbar, #111827.
    $luminance = function (array $colour): float {
        [$red, $green, $blue] = array_map(
            fn (float $channel): float => ($channel / 255) <= 0.04045 ? ($channel / 255) / 12.92 : ((($channel / 255) + 0.055) / 1.055) ** 2.4,
            $colour,
        );

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    };
    $surface = [0x11, 0x18, 0x27];
    $pill = array_map(fn (int $channel): float => 255 * 0.04 + $channel * (1 - 0.04), $surface);

    $ratio = fn (array $text): float => (max($luminance($text), $luminance($pill)) + 0.05) / (min($luminance($text), $luminance($pill)) + 0.05);

    expect(str_contains($css, '--color-fill-subtle: rgb(255 255 255 / 0.04);'))->toBeTrue()
        ->and($ratio([0x6B, 0x72, 0x80]))->toBeLessThan(4.5)
        ->and($ratio([0x9C, 0xA3, 0xAF]))->toBeGreaterThanOrEqual(4.5);
});

it('gives the brand link in the sidebar header the same focus ring as the topbar\'s', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
    $code = (string) preg_replace('~/\*.*?\*/~s', ' ', $css);

    // The rule that draws the ring, with every selector it covers.
    expect(preg_match('/((?:[^{}]+,\s*)*[^{},]+)\{[^}]*outline-primary-400[^}]*\}/s', $code, $rule))->toBe(1);

    $selectors = array_map('trim', explode(',', $rule[1]));

    expect($selectors)->toContain('.fi-topbar a:focus-visible')
        ->toContain('.fi-sidebar-header a:focus-visible');

    // The brand link below 1024px lives in that header, so the selector reaches it.
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('access_admin_panel');

    $html = renderedWithoutLivewireState($this->actingAs($actor)->get('/admin')->assertSuccessful());
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect($document->querySelector('.fi-sidebar-header a .tc-brand'))->not->toBeNull();
});

it('aligns the content column to the start, not the middle, beyond its 1440px maximum', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
    $rule = adminThemeRuleBody($css, '.fi-main');

    // Filament centres <main> with margin-inline: auto. The prototype's main has no auto
    // margins, so it sits at the start of its column; the logical start margin is zero, and
    // the end margin stays auto, so the column is as wide as its maximum and no wider.
    expect($rule)->not->toBeNull()
        ->and(str_contains((string) $rule, 'ms-0'))->toBeTrue()
        ->and(str_contains((string) $rule, 'ml-'))->toBeFalse();
});
