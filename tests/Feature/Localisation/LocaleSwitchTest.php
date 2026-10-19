<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\UpdateOwnLocaleAction;
use App\Http\Middleware\AuthenticateAccountSession;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

// Livewire marks "a component rendered this request" in static state that outlives
// the test, and injects its script into the next page rendered. Flushed before each test,
// and after it too: the admin tests below render a panel page, which sets it.
beforeEach(function (): void {
    Livewire::flushState();
});

afterEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The language switch (P4-T18a)
|--------------------------------------------------------------------------
|
| One component, three surfaces: a LINK on the public site and in the verifier, a
| FORM in the admin. This file holds what each draws and does, and the numbers the
| design rests on, recomputed from the stylesheets rather than quoted from a comment.
|
| THE WORDS ARE SENTINELS. The switch reads each language's own name and letter through
| that language's catalogue, so each test plants a different word in each catalogue and
| looks for the right one in the right place; a page that read the page's catalogue
| instead of the target's would show the wrong word.
|
| EVERY EXPECTED VALUE IS WRITTEN OUT BY HAND: the contrast figures come from an
| independent computation (WCAG's formula, over the tokens' hex values), not from the
| code under test.
*/

/** The two languages: the prefix, the language, and the other language. */
const LOCALE_SWITCH_LOCALES = [
    'English' => ['', 'en', 'ar'],
    'Arabic' => ['/ar', 'ar', 'en'],
];

/** Put a different sentinel in each catalogue's language-switch keys. */
function localeSwitchSentinels(): void
{
    foreach (['en', 'ar'] as $locale) {
        app('translator')->load('*', 'public', $locale);
        app('translator')->addLines([
            'public.locale_switch.label' => strtoupper($locale).'-GROUP-WORD',
            'public.locale_switch.name' => strtoupper($locale).'-NAME-WORD',
            'public.locale_switch.short' => strtoupper($locale).'-LETTER',
        ], $locale);
    }
}

function localeSwitchDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/*
|--------------------------------------------------------------------------
| The public and verifier markup
|--------------------------------------------------------------------------
*/

it('draws a two-segment pill with the current language marked and the other one a link', function (string $label, string $path) {
    [$prefix, $language, $other] = LOCALE_SWITCH_LOCALES[$label];
    localeSwitchSentinels();

    $document = localeSwitchDocument((string) $this->get("{$prefix}{$path}")->assertOk()->getContent());
    $pill = $document->querySelector('[data-locale-switch]');

    // The pill: a group named in the PAGE's language, left to right whatever the page is.
    expect($pill)->not->toBeNull()
        ->and($pill->getAttribute('role'))->toBe('group')
        ->and($pill->getAttribute('aria-label'))->toBe(strtoupper($language).'-GROUP-WORD')
        ->and($pill->getAttribute('dir'))->toBe('ltr')
        ->and($pill->getAttribute('data-current'))->toBe($language)
        ->and(count($document->querySelectorAll('[data-locale-switch]')))->toBe(1);

    // The halves, in document order EN then the Arabic letter, each in its own language.
    $halves = iterator_to_array($pill->querySelectorAll('.locale-switch__option'));

    expect($halves)->toHaveCount(2)
        ->and($halves[0]->getAttribute('lang'))->toBe('en')
        ->and($halves[0]->textContent)->toBe('EN-LETTER')
        ->and($halves[1]->getAttribute('lang'))->toBe('ar')
        ->and($halves[1]->textContent)->toBe('AR-LETTER');

    // Exactly one of them is a link, and it is the other language's.
    $links = iterator_to_array($pill->querySelectorAll('a'));

    expect($links)->toHaveCount(1)
        ->and($links[0]->getAttribute('hreflang'))->toBe($other)
        ->and($links[0]->getAttribute('lang'))->toBe($other)
        // Its accessible name is the TARGET language's own name, read through that language.
        ->and($links[0]->getAttribute('aria-label'))->toBe(strtoupper($other).'-NAME-WORD')
        ->and($links[0]->textContent)->toBe(strtoupper($other).'-LETTER')
        ->and(explode(' ', $links[0]->getAttribute('class')))->toContain('tap-target');

    // The current one is visible, marked, and not a link.
    $current = $pill->querySelector('[aria-current="true"]');

    expect($current)->not->toBeNull()
        ->and($current->localName)->toBe('span')
        ->and($current->getAttribute('lang'))->toBe($language)
        ->and($current->textContent)->toBe(strtoupper($language).'-LETTER')
        ->and($current->closest('a'))->toBeNull();

    // The thumb is decoration.
    expect($pill->querySelector('.locale-switch__thumb')->getAttribute('aria-hidden'))->toBe('true');
})->with(function () {
    foreach (array_keys(LOCALE_SWITCH_LOCALES) as $label) {
        yield "{$label} home" => [$label, ''];
        yield "{$label} about" => [$label, '/about'];
        yield "{$label} verifier form" => [$label, '/verify/certificates'];
    }
});

it('puts the switch in the navy top bar, outside the sticky header, and not in the main content', function () {
    $document = localeSwitchDocument((string) $this->get('/about')->assertOk()->getContent());
    $pill = $document->querySelector('[data-locale-switch]');

    expect($pill->closest('header'))->toBeNull()
        ->and($pill->closest('main'))->toBeNull()
        ->and($pill->closest('footer'))->toBeNull()
        // The bar is the first block of the page, and the navy one.
        ->and(explode(' ', $pill->closest('div.bg-navy')?->getAttribute('class') ?? ''))->toContain('bg-navy', 'text-on-navy');
});

it('puts the verifier\'s switch in its header bar beside the way back to the site', function () {
    $document = localeSwitchDocument((string) $this->get('/verify/certificates')->assertOk()->getContent());
    $pill = $document->querySelector('[data-locale-switch]');

    expect($pill->closest('header'))->not->toBeNull()
        ->and($pill->closest('main'))->toBeNull()
        ->and($pill->closest('.bar-end')?->querySelector('.back-link'))->not->toBeNull();
});

it('builds its links through PublicRoute, with no route(), url() or literal path in the public and verifier markup', function () {
    $source = (string) file_get_contents(resource_path('views/components/locale-switch.blade.php'));
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    // The public and verifier markup is everything after the admin branch.
    $links = substr($code, (int) strpos($code, '@else'));

    expect($links)->toContain('PublicRoute::counterpart(')->toContain('PublicRoute::urlIn(')
        ->and(preg_match('/(?<![\w$:>])url\s*\(/', $links))->toBe(0)
        ->and(preg_match('/(?<![\w$])route\s*\(/', $links))->toBe(0)
        ->and(preg_match('/\bhref\s*=\s*["\']\s*\//i', $links))->toBe(0);
});

it('carries no inline style, no script and no raw colour', function () {
    $source = (string) file_get_contents(resource_path('views/components/locale-switch.blade.php'));
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    expect(preg_match('/(?<![\w-])style\s*=/i', $code))->toBe(0)
        ->and(stripos($code, '<script'))->toBeFalse()
        ->and(preg_match('/#[0-9a-f]{3,8}\b|(?<![a-z-])(?:rgba?|hsla?|oklch)\s*\(/i', $code))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The look, the motion and the numbers
|--------------------------------------------------------------------------
*/

/** The declaration block of the first rule with this selector, or null. */
function localeSwitchRuleBody(string $css, string $selector): ?string
{
    if (preg_match('/'.preg_quote($selector, '/').'\s*\{([^{}]*)\}/', $css, $match) !== 1) {
        return null;
    }

    return $match[1];
}

/** @return array{0: float, 1: float, 2: float} a #rrggbb colour as red, green and blue. */
function localeSwitchHex(string $hex): array
{
    return [(float) hexdec(substr($hex, 1, 2)), (float) hexdec(substr($hex, 3, 2)), (float) hexdec(substr($hex, 5, 2))];
}

/**
 * A colour laid over another at an opacity, as the browser composites it.
 *
 * @param  array{0: float, 1: float, 2: float}  $top
 * @param  array{0: float, 1: float, 2: float}  $under
 * @return array{0: float, 1: float, 2: float}
 */
function localeSwitchOver(array $top, float $alpha, array $under): array
{
    return [
        $top[0] * $alpha + $under[0] * (1 - $alpha),
        $top[1] * $alpha + $under[1] * (1 - $alpha),
        $top[2] * $alpha + $under[2] * (1 - $alpha),
    ];
}

/**
 * WCAG 2.x contrast ratio of two opaque colours.
 *
 * @param  array{0: float, 1: float, 2: float}  $first
 * @param  array{0: float, 1: float, 2: float}  $second
 */
function localeSwitchContrast(array $first, array $second): float
{
    $luminance = function (array $colour): float {
        [$red, $green, $blue] = array_map(
            fn (float $channel): float => ($channel / 255) <= 0.04045 ? ($channel / 255) / 12.92 : ((($channel / 255) + 0.055) / 1.055) ** 2.4,
            $colour,
        );

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    };

    return (max($luminance($first), $luminance($second)) + 0.05) / (min($luminance($first), $luminance($second)) + 0.05);
}

it('measures contrast the way WCAG does', function () {
    // Hand-computed: black on white is 21:1, and the design's own footer line, cream at
    // 50% over the navy, is 4.54:1 (public.css and footer.blade.php both say so).
    $navy = [13.0, 39.0, 69.0];

    expect(localeSwitchContrast([0.0, 0.0, 0.0], [255.0, 255.0, 255.0]))->toEqualWithDelta(21.0, 0.001)
        ->and(localeSwitchContrast(localeSwitchOver([250.0, 246.0, 236.0], 0.5, $navy), $navy))->toEqualWithDelta(4.54, 0.01);
});

it('draws the public pill from the design\'s tokens, and holds 4.5:1 for each label', function () {
    $css = (string) file_get_contents(resource_path('css/public.css'));

    preg_match_all('/--color-(navy|cream|gold|ink):\s*(#[0-9a-f]{6});/i', $css, $tokens);
    $colour = array_combine($tokens[1], array_map('localeSwitchHex', $tokens[2]));

    $pill = localeSwitchRuleBody($css, '.locale-switch');
    $thumb = localeSwitchRuleBody($css, '.locale-switch__thumb');
    $option = localeSwitchRuleBody($css, '.locale-switch__option');
    $current = localeSwitchRuleBody($css, '.locale-switch__option--current');

    // Only existing tokens, by name: a gold hairline at 30%, a cream fill at 6%, a gold
    // thumb, ink on it, and the other half in cream at 72%.
    expect($pill)->toContain('border-gold/30')->toContain('bg-cream/6')
        ->and($thumb)->toContain('bg-gold')
        ->and($option)->toContain('text-cream/72')
        ->and($current)->toContain('text-ink');

    // The fill and the label opacities, read from the rules and applied to the tokens.
    preg_match('/bg-cream\/(\d+)/', (string) $pill, $fill);
    preg_match('/text-cream\/(\d+)/', (string) $option, $label);

    $surface = localeSwitchOver($colour['cream'], (int) $fill[1] / 100, $colour['navy']);
    $inactive = localeSwitchOver($colour['cream'], (int) $label[1] / 100, $surface);

    // 6.94:1 and 7.54:1, worked out by hand with WCAG's formula.
    expect(localeSwitchContrast($inactive, $surface))->toBeGreaterThanOrEqual(4.5)->toEqualWithDelta(6.94, 0.01)
        ->and(localeSwitchContrast($colour['ink'], $colour['gold']))->toBeGreaterThanOrEqual(4.5)->toEqualWithDelta(7.54, 0.01);
});

it('holds 4.5:1 for the verifier\'s inactive label over the worst its glow can put behind the pill', function () {
    $partial = (string) file_get_contents(resource_path('views/verify/partials/locale.blade.php'));
    $form = (string) file_get_contents(resource_path('views/verify/form.blade.php'));

    preg_match('/--switch-fill:\s*rgb\((\d+) (\d+) (\d+) \/ ([\d.]+)\)/', $partial, $fill);
    preg_match('/--text-switch:\s*rgb\((\d+) (\d+) (\d+) \/ ([\d.]+)\)/', $partial, $label);
    preg_match('/--glow:\s*rgb\((\d+) (\d+) (\d+) \/ ([\d.]+)\)/', $form, $glow);
    preg_match('/--navy:\s*(#[0-9a-f]{6})/i', $form, $navy);

    $rgb = fn (array $match): array => [(float) $match[1], (float) $match[2], (float) $match[3]];

    // The page is navy plus the glow at its full strength, then the pill's fill, then the label.
    $behind = localeSwitchOver($rgb($glow), (float) $glow[4], localeSwitchHex($navy[1]));
    $surface = localeSwitchOver($rgb($fill), (float) $fill[4], $behind);
    $inactive = localeSwitchOver($rgb($label), (float) $label[4], $surface);

    // 5.42:1, worked out by hand.
    expect(localeSwitchContrast($inactive, $surface))->toBeGreaterThanOrEqual(4.5)->toEqualWithDelta(5.42, 0.01);
});

it('holds 4.5:1 for the admin pill from the panel\'s own palettes', function () {
    $gray = (new ReflectionClassConstant(AdminPanelProvider::class, 'GRAY'))->getValue();
    $amber = (new ReflectionClassConstant(AdminPanelProvider::class, 'AMBER'))->getValue();
    preg_match('/--color-on-primary:\s*(#[0-9a-f]{6})/i', (string) file_get_contents(resource_path('css/filament/admin/theme.css')), $onPrimary);

    $source = (string) file_get_contents(resource_path('views/components/locale-switch.blade.php'));

    // The classes the pill is drawn with: gray-800 and primary-500 (the amber 500), the
    // inactive label gray-400, the current one the on-primary token.
    expect($source)->toContain('bg-gray-800')->toContain('bg-primary-500')->toContain('text-gray-400')->toContain('text-on-primary');

    // 5.78:1 and 8.59:1, worked out by hand.
    expect(localeSwitchContrast(localeSwitchHex($gray[400]), localeSwitchHex($gray[800])))->toEqualWithDelta(5.78, 0.01)
        ->and(localeSwitchContrast(localeSwitchHex($onPrimary[1]), localeSwitchHex($amber[500])))->toEqualWithDelta(8.59, 0.01);
});

it('draws the admin pill only with classes the admin theme compiles', function () {
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', (string) file_get_contents(resource_path('views/components/locale-switch.blade.php')));
    $start = (int) strpos($source, "@if (\$variant === 'admin')");
    $admin = substr($source, $start, (int) strpos($source, '@else') - $start);

    // The public site's own tokens (rounded-pill, text-caption, bg-gold ...) live in
    // public.css, which the panel does not load: such a class would compile to nothing
    // in the admin, and the pill would lose its shape without any error.
    // Plain class attributes only: x-bind:class holds an Alpine expression, not class names.
    preg_match_all('/\sclass="([^"]*)"/', $admin, $attributes);
    $classes = array_values(array_unique(array_filter(
        preg_split('/\s+/', implode(' ', array_map(fn (string $value): string => (string) preg_replace('/\{\{.*?\}\}/s', ' ', $value), $attributes[1]))) ?: [],
        fn (string $class): bool => preg_match('/^[a-z][a-z0-9-]*$/', $class) === 1,
    )));

    expect($classes)->toContain('rounded-full', 'bg-gray-800', 'bg-primary-500', 'text-on-primary')
        ->not->toContain('rounded-pill')->not->toContain('text-caption')->not->toContain('bg-gold');

    $built = glob(public_path('build/assets/theme-*.css')) ?: [];

    if ($built === []) {
        return;
    }

    $css = (string) file_get_contents($built[0]);
    $missing = array_values(array_filter($classes, fn (string $class): bool => ! str_contains($css, '.'.$class)));

    expect($missing)->toBe([], 'These classes are not in the compiled admin theme: '.implode(', ', $missing));
});

it('slides the thumb by a cross-document view transition that reduced motion switches off entirely', function () {
    $css = (string) file_get_contents(resource_path('css/public.css'));

    // The opt-in is inside `no-preference`, so reduce means no transition at all.
    expect(preg_match('/@media\s*\(prefers-reduced-motion:\s*no-preference\)\s*\{\s*@view-transition\s*\{\s*navigation:\s*auto;\s*\}\s*\}/', $css))->toBe(1)
        // The thumb and the two labels are named, so the browser can slide the thumb and keep the labels above it.
        ->and(localeSwitchRuleBody($css, '.locale-switch__thumb'))->toContain('view-transition-name: locale-thumb')
        ->and(localeSwitchRuleBody($css, '.locale-switch__option--en'))->toContain('view-transition-name: locale-en')
        ->and(localeSwitchRuleBody($css, '.locale-switch__option--ar'))->toContain('view-transition-name: locale-ar')
        // The page behind it cross-fades for about 200ms.
        ->and(localeSwitchRuleBody($css, '::view-transition-group(root)'))->toContain('animation-duration: 0.2s');
});

it('nudges the thumb toward the other half on hover, by a signed distance, and not at all with reduced motion', function () {
    $css = (string) file_get_contents(resource_path('css/public.css'));

    $nudge = localeSwitchRuleBody($css, '.locale-switch:has(a:is(:hover, :focus-visible)) .locale-switch__thumb');

    // From EN the other half is to the right, from the Arabic letter to the left.
    expect($nudge)->toContain('translate: var(--locale-nudge) 0')
        ->and(localeSwitchRuleBody($css, '.locale-switch'))->toContain('--locale-nudge: 3px')
        ->and(localeSwitchRuleBody($css, ".locale-switch[data-current='ar']"))->toContain('--locale-nudge: -3px')
        // Reduced motion drops the transition and the nudge.
        ->and(preg_match('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[^@]*translate:\s*none/s', $css))->toBe(1);
});

it('restates the same opt-in, names and reduced-motion rule in the verifier\'s own CSS', function () {
    $partial = (string) file_get_contents(resource_path('views/verify/partials/locale.blade.php'));

    expect(preg_match('/@media\s*\(prefers-reduced-motion:\s*no-preference\)\s*\{\s*@view-transition\s*\{\s*navigation:\s*auto;\s*\}\s*\}/', $partial))->toBe(1)
        ->and(localeSwitchRuleBody($partial, '.locale-switch__thumb'))->toContain('view-transition-name: locale-thumb')
        ->and(localeSwitchRuleBody($partial, '.locale-switch__option--en'))->toContain('view-transition-name: locale-en')
        ->and(localeSwitchRuleBody($partial, '.locale-switch__option--ar'))->toContain('view-transition-name: locale-ar')
        ->and(localeSwitchRuleBody($partial, '::view-transition-group(root)'))->toContain('animation-duration: .2s')
        ->and(preg_match('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[^@]*translate:\s*none/s', $partial))->toBe(1);
});

it('gives the verifier\'s link a 44 by 44 target below 1024px, in plain CSS', function () {
    $partial = (string) file_get_contents(resource_path('views/verify/partials/locale.blade.php'));

    // The cell around the pill is a 44px row, and the link's hit area is stretched to 44 on
    // each axis by the same formula the public site's tap-target utility uses.
    expect(localeSwitchRuleBody($partial, '.locale-cell'))->toContain('min-block-size: 44px')
        ->and(localeSwitchRuleBody($partial, 'a.locale-switch__option::after'))
        ->toContain('inset-block: min(0px, calc(50% - 22px))')
        ->toContain('inset-inline: min(0px, calc(50% - 22px))');
});

it('includes the verifier\'s locale CSS inside the one inline style of each page, and adds no style attribute', function () {
    foreach (['form', 'show', 'not-found'] as $file) {
        // The Blade comment at the top names the block, so it is taken out first.
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', (string) file_get_contents(resource_path("views/verify/{$file}.blade.php")));

        // Inside the block: after the page's own CSS and before it closes.
        expect(preg_match('/<style>.*@include\(\'verify\.partials\.locale\'\)\s*<\/style>/s', $source))->toBe(1, "{$file} does not include the locale CSS inside its <style>.")
            ->and(substr_count($source, '<style'))->toBe(1);
    }
});

/*
|--------------------------------------------------------------------------
| The admin: a form, behind a flag
|--------------------------------------------------------------------------
*/

/** A staff user holding exactly the panel permission and nothing else. */
function localeSwitchStaff(array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, 'locale' => 'en', ...$attributes]);
    $user->givePermissionTo('access_admin_panel');

    return $user;
}

/** Make the CSRF check real: PreventRequestForgery returns early when running unit tests. */
function localeSwitchEnforceCsrf(): void
{
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
}

describe('the admin switch', function () {
    beforeEach(function () {
        $this->seed(RolePermissionSeeder::class);
    });

    it('is off by default', function () {
        expect(config('public_site.admin_locale_switch'))->toBeFalse();
    });

    it('is absent from the panel while the flag is off, and the hook renders nothing', function () {
        config(['public_site.admin_locale_switch' => false]);

        $html = (string) $this->actingAs(localeSwitchStaff())->get('/admin')->assertSuccessful()->getContent();

        expect($html)->not->toContain('data-locale-switch')->not->toContain(route('account.locale.update'));
    });

    it('draws a CSRF-protected form in the topbar, before the user menu, while the flag is on', function () {
        config(['public_site.admin_locale_switch' => true]);

        $document = localeSwitchDocument((string) $this->actingAs(localeSwitchStaff())->get('/admin')->assertSuccessful()->getContent());
        $pill = $document->querySelector('[data-locale-switch]');
        $form = $pill?->closest('form');

        expect($pill)->not->toBeNull()
            ->and($form?->getAttribute('action'))->toBe(route('account.locale.update'))
            ->and($form?->getAttribute('method'))->toBe('post')
            ->and($form?->querySelector('input[name="_token"]')?->getAttribute('value'))->not->toBeEmpty()
            ->and($pill->closest('.fi-topbar'))->not->toBeNull()
            ->and($pill->getAttribute('dir'))->toBe('ltr')
            ->and($pill->getAttribute('data-current'))->toBe('en');

        // One button, for the other language, carrying its value; the current one is a span.
        $buttons = iterator_to_array($pill->querySelectorAll('button'));

        expect($buttons)->toHaveCount(1)
            ->and($buttons[0]->getAttribute('name'))->toBe('locale')
            ->and($buttons[0]->getAttribute('value'))->toBe('ar')
            ->and($buttons[0]->getAttribute('lang'))->toBe('ar')
            ->and($pill->querySelector('[aria-current="true"]')->localName)->toBe('span');

        // It sits before the user menu in the topbar's order.
        $order = (string) $document->querySelector('.fi-topbar')->innerHTML;

        expect(strpos($order, 'data-locale-switch'))->toBeLessThan(strpos($order, 'fi-user-menu'));
    });

    it('draws the pill for an Arabic user with the thumb on the Arabic half and a button for English', function () {
        $html = Blade::render('<x-locale-switch variant="admin" />');

        // Rendered for an Arabic user: the pill's other half is EN.
        app()->setLocale('ar');
        $arabic = localeSwitchDocument(Blade::render('<x-locale-switch variant="admin" />'));

        expect($html)->toContain('data-current="en"')
            ->and($arabic->querySelector('[data-locale-switch]')->getAttribute('data-current'))->toBe('ar')
            ->and($arabic->querySelector('button')->getAttribute('value'))->toBe('en');
    });

    it('sets the signed-in user\'s own locale, and nobody else\'s, and logs it', function () {
        $actor = localeSwitchStaff();
        $bystander = User::factory()->create(['locale' => 'en']);

        $this->actingAs($actor)->from('/admin')->post(route('account.locale.update'), ['locale' => 'ar', 'id' => $bystander->getKey(), 'user_id' => $bystander->getKey()])
            ->assertRedirect('/admin');

        expect($actor->refresh()->locale)->toBe('ar')
            ->and($bystander->refresh()->locale)->toBe('en');

        $entry = Activity::query()->where('subject_type', User::class)->where('subject_id', $actor->getKey())->where('event', 'updated')->latest('id')->first();

        expect($entry)->not->toBeNull()
            ->and((int) $entry->causer_id)->toBe((int) $actor->getKey())
            ->and($entry->attribute_changes->get('attributes'))->toBe(['locale' => 'ar'])
            ->and($entry->attribute_changes->get('old'))->toBe(['locale' => 'en']);
    });

    it('applies the stored locale to the next panel request', function () {
        $actor = localeSwitchStaff();

        $this->actingAs($actor)->post(route('account.locale.update'), ['locale' => 'ar']);

        $this->actingAs($actor->refresh())->get('/admin')->assertSuccessful();

        expect(app()->getLocale())->toBe('ar');
    });

    it('refuses a locale the application cannot render, and writes nothing', function (mixed $locale) {
        $actor = localeSwitchStaff();

        $this->actingAs($actor)->from('/admin')->post(route('account.locale.update'), ['locale' => $locale])
            ->assertRedirect('/admin')
            ->assertSessionHasErrors('locale');

        expect($actor->refresh()->locale)->toBe('en');
    })->with([
        'a language the application does not have' => ['fr'],
        'a different case' => ['AR'],
        'an empty value' => [''],
        'nothing' => [null],
        'an array' => [['ar']],
    ]);

    it('refuses a guest with a 403 and writes nothing', function () {
        $user = User::factory()->create(['locale' => 'en']);

        $this->post(route('account.locale.update'), ['locale' => 'ar'])->assertForbidden();

        expect($user->refresh()->locale)->toBe('en')
            ->and(Activity::query()->where('subject_type', User::class)->where('event', 'updated')->count())->toBe(0);
    });

    it('refuses an inactive account with a 403', function () {
        $inactive = localeSwitchStaff(['is_active' => false]);

        $this->actingAs($inactive)->post(route('account.locale.update'), ['locale' => 'ar'])->assertForbidden();

        expect($inactive->refresh()->locale)->toBe('en');
    });

    it('refuses a portal-only sign-in with a 403', function () {
        // Its own test, with no staff user signed in at all (Codex, #122): sharing one with
        // the inactive case left that user on the default guard, so the second request
        // proved nothing about the portal. The student is put on its own guard directly:
        // actingAs($user, 'student') would also make it the DEFAULT guard, which it is not
        // in a request to this route.
        $student = User::factory()->create(['locale' => 'en']);

        Auth::guard('student')->setUser($student);

        expect(Auth::guard('web')->user())->toBeNull();

        $this->post(route('account.locale.update'), ['locale' => 'ar'])->assertForbidden();

        expect($student->refresh()->locale)->toBe('en');
    });

    it('refuses a POST without a CSRF token, and accepts the same one with it', function () {
        localeSwitchEnforceCsrf();

        $actor = localeSwitchStaff();
        $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        $this->actingAs($actor)->post(route('account.locale.update'), ['locale' => 'ar'])->assertStatus(419);

        expect($actor->refresh()->locale)->toBe('en');

        $this->actingAs($actor)->withSession(['_token' => $token])->post(route('account.locale.update'), ['locale' => 'ar', '_token' => $token])->assertRedirect();

        expect($actor->refresh()->locale)->toBe('ar');
    });

    /*
    |----------------------------------------------------------------------
    | The panel's own protections (P4-T18a, review round)
    |----------------------------------------------------------------------
    |
    | The route is a plain web route, so the middleware the panel puts around its
    | pages does not run for it unless the route says so. Each case below is one
    | the panel refuses, with the panel as the control: a test that only showed
    | the route refusing could not tell a real refusal from a request that never
    | reached it.
    */

    it('refuses a session opened before the password changed, as the panel does, and writes nothing', function () {
        $actor = localeSwitchStaff();
        $bystander = localeSwitchStaff();

        // The FIRST request on a session stores its password hash, and later ones are
        // measured against it (PrivateFileAccessTest explains). The panel is that first
        // request here.
        $this->actingAs($actor)->get('/admin')->assertSuccessful();
        $actor->forceFill(['password' => Hash::make('reset-by-an-admin-2')])->save();

        $this->post(route('account.locale.update'), ['locale' => 'ar'])->assertRedirect('/admin/login');

        expect($actor->refresh()->locale)->toBe('en')
            ->and(auth()->check())->toBeFalse('The session survived the password change.');

        // The control: the panel refuses the same sequence the same way.
        $this->actingAs($bystander)->get('/admin')->assertSuccessful();
        $bystander->forceFill(['password' => Hash::make('reset-by-an-admin-3')])->save();

        $this->get('/admin')->assertRedirect('/admin/login');
    });

    it('holds a user who must change their password on the password form, as the panel does, and writes nothing', function () {
        $actor = localeSwitchStaff(['must_change_password' => true]);

        $this->actingAs($actor)->post(route('account.locale.update'), ['locale' => 'ar'])
            ->assertRedirect('/admin/password-change');

        expect($actor->refresh()->locale)->toBe('en');

        // The control.
        $this->actingAs($actor)->get('/admin')->assertRedirect('/admin/password-change');
    });

    it('refuses an active user who cannot access the admin panel, as the panel does, and writes nothing', function () {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($actor)->post(route('account.locale.update'), ['locale' => 'ar'])->assertForbidden();

        expect($actor->refresh()->locale)->toBe('en')
            ->and(Activity::query()->where('subject_type', User::class)->where('event', 'updated')->count())->toBe(0);

        // The control.
        $this->actingAs($actor)->get('/admin')->assertForbidden();
    });

    it('allows ten changes a minute and refuses the eleventh before it writes a row', function () {
        $actor = localeSwitchStaff();
        $this->actingAs($actor);

        // Every toggle that changes the language appends a row to an append-only log, so
        // the rate is the bound on how fast it can grow. Alternating, so each one changes.
        foreach (range(1, 10) as $attempt) {
            $this->post(route('account.locale.update'), ['locale' => $attempt % 2 === 1 ? 'ar' : 'en'])->assertRedirect();
        }

        $rows = fn (): int => Activity::query()->where('subject_type', User::class)->where('subject_id', $actor->getKey())->where('event', 'updated')->count();

        expect($rows())->toBe(10);

        $this->post(route('account.locale.update'), ['locale' => 'ar'])->assertStatus(429);

        expect($rows())->toBe(10)
            ->and($actor->refresh()->locale)->toBe('en');
    });

    it('keeps its own throttle budget, apart from every other per-user throttle', function () {
        // An unprefixed numeric throttle keys an authenticated request by the user alone,
        // so every such route shares one budget: the staff file, photo and receipt routes
        // use them. The locale route's prefix gives it its own (Codex, #122). A stand-in
        // route with the same unprefixed throttle is used up first.
        Route::middleware(['web', 'throttle:10,1'])->get('/locale-switch-throttle-neighbour', fn (): string => 'ok');

        $actor = localeSwitchStaff();
        $this->actingAs($actor);

        foreach (range(1, 10) as $attempt) {
            $this->get('/locale-switch-throttle-neighbour')->assertOk();
        }

        $this->get('/locale-switch-throttle-neighbour')->assertStatus(429);

        $this->post(route('account.locale.update'), ['locale' => 'ar'])->assertRedirect();

        expect($actor->refresh()->locale)->toBe('ar');
    });

    it('carries the panel\'s session check, its password hold and a throttle on the route itself', function () {
        $middleware = Route::getRoutes()->getByName('account.locale.update')->gatherMiddleware();

        // in_array, not expect()->toContain(): that helper is variadic, so a message would be
        // read as a second expected value.
        foreach ([AuthenticateAccountSession::class, ForcePasswordChange::class, 'throttle:10,1,account-locale'] as $expected) {
            expect(in_array($expected, $middleware, true))->toBeTrue("account.locale.update does not carry {$expected}.");
        }
    });
});

describe('UpdateOwnLocaleAction', function () {
    it('sets the actor\'s own locale to each supported value', function (string $locale) {
        $actor = User::factory()->create(['locale' => $locale === 'ar' ? 'en' : 'ar']);

        (new UpdateOwnLocaleAction)->execute($actor, $locale);

        expect($actor->refresh()->locale)->toBe($locale);
    })->with(SetLocale::SUPPORTED);

    it('rejects anything that is not a supported locale, by name, and leaves the row alone', function (string $locale) {
        $actor = User::factory()->create(['locale' => 'en']);

        expect(fn () => (new UpdateOwnLocaleAction)->execute($actor, $locale))->toThrow(InvalidArgumentException::class);
        expect($actor->refresh()->locale)->toBe('en');
    })->with(['fr', '', 'AR', 'ar ', 'en-GB', 'ar_LY', "ar\n", '0']);

    it('rejects an inactive actor', function () {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => false]);

        expect(fn () => (new UpdateOwnLocaleAction)->execute($actor, 'ar'))->toThrow(InvalidArgumentException::class);
        expect($actor->refresh()->locale)->toBe('en');
    });

    it('takes no target: the only user it can change is the one it is given', function () {
        $execute = new ReflectionMethod(UpdateOwnLocaleAction::class, 'execute');

        expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $execute->getParameters()))->toBe(['actor', 'locale']);

        $actor = User::factory()->create(['locale' => 'en']);
        $others = User::factory()->count(2)->create(['locale' => 'en']);

        (new UpdateOwnLocaleAction)->execute($actor, 'ar');

        expect($actor->refresh()->locale)->toBe('ar');

        foreach ($others as $other) {
            expect($other->refresh()->locale)->toBe('en');
        }
    });

    it('leaves no entry for a choice that changes nothing', function () {
        $actor = User::factory()->create(['locale' => 'ar']);
        $this->actingAs($actor);
        $before = Activity::query()->count();

        (new UpdateOwnLocaleAction)->execute($actor, 'ar');

        expect(Activity::query()->count())->toBe($before);
    });
});
