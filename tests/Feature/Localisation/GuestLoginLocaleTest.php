<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\LogOutInactiveUser;
use App\Http\Middleware\SetGuestPanelLocale;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Livewire::flushState();
});

/** The signed snapshot a browser sends back, selected by component rather than DOM order. */
function guestLoginSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES);
        $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);

        if (($decoded['memo']['name'] ?? null) === app('livewire.factory')->resolveComponentName(Login::class)) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No login snapshot was rendered.');
}

it('renders each login in the requested supported language and direction', function (string $panel, string $locale, string $direction) {
    app()->setLocale($locale === 'ar' ? 'en' : 'ar');

    $html = (string) $this->get("/{$panel}/login?locale={$locale}")->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect($document->documentElement->getAttribute('lang'))->toBe($locale)
        ->and($document->documentElement->getAttribute('dir'))->toBe($direction)
        ->and($document->querySelector('h1')?->textContent)->toContain(__('filament-panels::auth/pages/login.heading', [], $locale));
})->with(['admin', 'portal'])->with([
    'Arabic' => ['ar', 'rtl'],
    'English' => ['en', 'ltr'],
]);

it('remembers the guest choice when a later login request has no locale', function (string $panel) {
    $this->get("/{$panel}/login?locale=ar")->assertOk();
    app()->setLocale('en');

    $this->get("/{$panel}/login")->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);

    $this->get("/{$panel}/login?locale=en")->assertOk();
    app()->setLocale('ar');

    $this->get("/{$panel}/login")->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);
})->with(['admin', 'portal']);

it('replaces an invalid guest choice with English instead of inheriting Arabic', function (string $panel, string $query) {
    $this->get("/{$panel}/login?locale=ar")->assertOk();

    $this->get("/{$panel}/login?{$query}")->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);

    app()->setLocale('ar');
    $this->get("/{$panel}/login")->assertOk()->assertSee('lang="en"', false);
})->with(['admin', 'portal'])->with(['locale=fr', 'locale=AR', 'locale=', 'locale[]=ar']);

it('resets a fresh guest login to English even if a previous request left Arabic standing', function (string $panel) {
    app()->setLocale('ar');

    $this->get("/{$panel}/login")->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);
})->with(['admin', 'portal']);

it('returns Arabic validation errors over the real Livewire update route', function (string $panel) {
    $snapshot = guestLoginSnapshot((string) $this->get("/{$panel}/login?locale=ar")->assertOk()->getContent());

    // This goes through the HTTP route; Livewire::test() skips persistent middleware.
    Livewire::flushState();
    app()->setLocale('en');
    $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
        ]],
    ])->assertOk();

    $updated = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    $rendered = HTMLDocument::createFromString($response->json('components.0.effects.html'), LIBXML_NOERROR)->body->textContent;

    expect($updated['memo']['locale'])->toBe('ar')
        ->and($updated['memo']['errors'])->toBe([
            'data.email' => ['حقل البريد الإلكتروني مطلوب.'],
            'data.password' => ['حقل كلمة المرور مطلوب.'],
        ]);

    foreach ($updated['memo']['errors'] as $messages) {
        expect($messages)->not->toBeEmpty();

        foreach ($messages as $message) {
            expect($message)->toMatch('/\p{Arabic}/u')->not->toContain('required')
                ->and($rendered)->toContain($message);
        }
    }
})->with(['admin', 'portal']);

it('renders the email-format refusal in Arabic on a login Livewire update', function (string $panel) {
    $snapshot = guestLoginSnapshot((string) $this->get("/{$panel}/login?locale=ar")->assertOk()->getContent());

    Livewire::flushState();
    app()->setLocale('en');
    $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['data.email' => 'invalid-address', 'data.password' => 'Wrong-password!'],
            'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
        ]],
    ])->assertOk();

    $updated = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    $rendered = HTMLDocument::createFromString($response->json('components.0.effects.html'), LIBXML_NOERROR)->body->textContent;

    expect($updated['memo']['locale'])->toBe('ar')
        ->and($updated['memo']['errors'])->toBe([
            'data.email' => ['يجب أن يكون حقل البريد الإلكتروني عنوان بريد إلكتروني صالحًا.'],
        ])
        ->and($rendered)->toContain('يجب أن يكون حقل البريد الإلكتروني عنوان بريد إلكتروني صالحًا.');
})->with(['admin', 'portal']);

it('uses the authenticated panel user locale ahead of both guest session and query choices', function (string $panel, string $locale) {
    $this->seed(RolePermissionSeeder::class);
    $guestLocale = $locale === 'ar' ? 'en' : 'ar';
    $this->get("/{$panel}/login?locale={$guestLocale}")->assertOk();

    $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
    $user->givePermissionTo($panel === 'admin' ? 'access_admin_panel' : 'view_own_student_record');

    if ($panel === 'portal') {
        $user->givePermissionTo('access_student_portal');
        Student::factory()->for($user)->create();
    }

    $this->actingAs($user, $panel === 'admin' ? 'web' : 'student')
        ->get(($panel === 'admin' ? '/admin' : '/portal/overview')."?locale={$guestLocale}")
        ->assertOk()->assertSee('lang="'.$locale.'"', false)->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);

    expect($user->refresh()->locale)->toBe($locale);

    $this->get(($panel === 'admin' ? '/admin' : '/portal/overview')."?locale={$locale}")
        ->assertOk()->assertSessionHas('guest_panel_locale', $guestLocale);
})->with(['admin', 'portal'])->with(['ar', 'en']);

it('treats a user signed in only to the other panel as a guest at this login', function (string $panel, string $otherGuard) {
    Auth::guard($otherGuard)->setUser(User::factory()->create(['locale' => 'en', 'is_active' => true]));

    $this->get("/{$panel}/login?locale=ar")->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
})->with([
    'student at admin login' => ['admin', 'student'],
    'staff at portal login' => ['portal', 'web'],
]);

it('adds guest locale to the normal and persistent stacks while keeping the existing security middleware', function (string $panel) {
    $configuration = Filament::getPanel($panel);

    expect($configuration->getMiddleware())->toContain(SetGuestPanelLocale::class)
        ->and($configuration->getAuthMiddleware())->toBe([
            LogOutInactiveUser::class,
            Authenticate::class,
            SetLocale::class,
            ForcePasswordChange::class,
        ])
        ->and(Livewire::getPersistentMiddleware())->toContain(SetGuestPanelLocale::class, LogOutInactiveUser::class, SetLocale::class, AuthenticateSession::class, ForcePasswordChange::class);
})->with(['admin', 'student']);
