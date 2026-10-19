<?php

declare(strict_types=1);

use App\Support\PublicRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// The isolation declaration DatabaseIsolationTest demands of every feature file.
// Nothing here writes a row.
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| PublicRoute: every public link is locale-aware from the first commit (P4-T11)
|--------------------------------------------------------------------------
|
| T18 adds Arabic routes named `ar.` plus the English name, because reusing the
| English names would overwrite the English URL lookup. A bare `route('x')` in a
| view would then send an Arabic visitor back to English on the first click, and
| nothing would look broken. These tests pin the helper, and a scan of the public
| views stops the bare call from being written at all.
*/

/**
 * Register a throwaway route and refresh the router's name lookups.
 *
 * `Route::get()` adds to the collection but not to its name index, so without
 * the refresh `route()` and `Route::has()` could not see what was just added.
 * The application is rebuilt for every test, so the route does not outlive it.
 */
function registerTemporaryRoute(string $uri, string $name): void
{
    Route::get($uri, fn (): string => 'temporary')->name($name);
    Route::getRoutes()->refreshNameLookups();
}

it('returns the plain route URL in English', function () {
    app()->setLocale('en');

    expect(PublicRoute::url('public.home'))->toBe(route('public.home'))
        ->and(PublicRoute::url('public.home'))->toBe(url('/'));
});

it('passes route parameters through in English', function () {
    app()->setLocale('en');

    expect(PublicRoute::url('verify.certificates.show', ['reference' => 'CERT-2026-0087']))
        ->toBe(url('/verify/certificates/CERT-2026-0087'));
});

it('resolves the ar. route under an Arabic locale', function () {
    app()->setLocale('ar');

    // The real Arabic route (P4-T18a), under its own prefix, and not the English URL.
    expect(PublicRoute::url('public.home'))
        ->toBe(url('/ar'))
        ->not->toBe(url('/'));
});

it('passes route parameters through under an Arabic locale', function () {
    app()->setLocale('ar');

    expect(PublicRoute::url('verify.certificates.show', ['reference' => 'CERT-2026-0087']))
        ->toBe(url('/ar/verify/certificates/CERT-2026-0087'));
});

it('throws, naming both routes, when the Arabic route does not exist, and never returns the English URL', function () {
    // An English route with no Arabic twin: what a page added to one language only
    // would be. The real public pages all have one now, so this one is made here.
    registerTemporaryRoute('/english-only', 'public.english-only');
    app()->setLocale('ar');

    $thrown = null;
    $returned = null;

    try {
        $returned = PublicRoute::url('public.english-only');
    } catch (LogicException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(LogicException::class)
        ->and($returned)->toBeNull()
        ->and($thrown->getMessage())->toContain('ar.public.english-only')
        ->and($thrown->getMessage())->toContain('public.english-only');
});

it('throws for any locale that is not English, not only Arabic', function () {
    app()->setLocale('fr');

    expect(fn () => PublicRoute::url('public.home'))->toThrow(LogicException::class, 'fr.public.home');
});

/**
 * The first link shape in a view's source that bypasses PublicRoute, or null.
 *
 * EVERY SHAPE THAT PRODUCES AN ENGLISH-ONLY URL, NOT JUST route(). The first
 * version caught the `route(` helper and its three spellings, and a review then
 * planted five more in the footer without any test failing: `url('/x')`, a literal
 * `href="/x"`, `action([...])`, `app('url')->route(`, and `to_route(`. Each sends
 * an Arabic visitor to the English page exactly as a bare route() does, so each is
 * a hit:
 *
 *   - `route(`, wherever it appears: the helper, `\route(`, `URL::route(`,
 *     `url()->route(`, `app('url')->route(` and `$request->route(`;
 *   - `to_route(` and `action(` (which also covers `URL::action(`);
 *   - `url(` as a function, and the URL facade or `app('url')` however they are used;
 *   - an `href` or `action` attribute whose literal value starts with a slash.
 *
 * A NAMED FUNCTION WITH SAMPLES, like the other scanners in this suite, so the
 * scan cannot quietly narrow. CASE-SENSITIVE ON PURPOSE for the calls:
 * `PublicRoute::url(` and `Route::has(` contain "Route" with a capital, and
 * `Facade::url(` follows a double colon, so neither is a hit. `asset(` is not one
 * either: a static file has no Arabic twin.
 *
 * A BLADE COMMENT IS NOT SCANNED. It is removed before the template renders, so
 * a note explaining the rule may name the call without being the call, which is
 * how the first version of the navigation partial tripped its own scan. Anything
 * after the comment still is scanned.
 */
function firstUnlocalisedLink(string $source): ?string
{
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    $patterns = [
        '/(?<![\w$])route\s*\(/',
        '/(?<![\w$])to_route\s*\(/',
        '/(?<![\w$])action\s*\(/',
        // Not after `::`, which is how PublicRoute::url( is written, nor after `->`.
        '/(?<![\w$:>])url\s*\(/',
        '/\bURL::/',
        '/\bapp\s*\(\s*[\'"]url[\'"]\s*\)/',
        '/\b(?:href|action)\s*=\s*["\']\s*\//i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code, $match) === 1) {
            return trim($match[0]);
        }
    }

    return null;
}

/**
 * The layout, every file under resources/views/public/ and every public component
 * under resources/views/components/public/, as absolute paths.
 *
 * The components are included because one renders inside a public page, so a link
 * built in it is on the page as surely as one in the page's own file.
 *
 * @return array<int, string>
 */
function publicViewFilesForRouteScan(): array
{
    $files = [resource_path('views/layouts/public.blade.php')];

    foreach ([resource_path('views/public'), resource_path('views/components/public')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $files[] = (string) $file->getRealPath();
        }
    }

    return $files;
}

it('flags every link shape that bypasses PublicRoute', function (string $sample) {
    expect(firstUnlocalisedLink($sample))->not->toBeNull();
})->with([
    // The route() helper and its spellings.
    "{{ route('x') }}",
    "<a href=\"{{ route('public.home') }}\">x</a>",
    "{{ route ('x') }}",
    "{{ \\route('x') }}",
    "@php \$a = route('x'); @endphp",
    "{{ URL::route('x') }}",
    "{{ url()->route('x') }}",
    "{{ \$request->route('x') }}",
    "{{ app('url')->route('x') }}",
    // The shapes a review planted in the footer.
    "{{ to_route('x') }}",
    "{{ action([FooController::class, 'x']) }}",
    "{{ url('/verify/certificates') }}",
    "{{ url ('/verify/certificates') }}",
    '<a href="/verify/certificates">x</a>',
    "<a href='/verify/certificates'>x</a>",
    '<a HREF = "/verify/certificates">x</a>',
    '<form action="/verify/certificates">',
    // The URL facade and the container's url service, however they are used.
    "{{ URL::to('/x') }}",
    "{{ app('url')->to('/x') }}",
    '{{ app("url")->full() }}',
]);

it('leaves the sanctioned forms alone', function (string $sample) {
    expect(firstUnlocalisedLink($sample))->toBeNull();
})->with([
    "{{ PublicRoute::url('x') }}",
    "<a href=\"{{ PublicRoute::url('public.home') }}\">x</a>",
    "@if (Route::has('x')) @endif",
    "{{ asset('images/mark.svg') }}",
    "{{ __('public.route_label') }}",
    '<p>Route (optional)</p>',
    // Anchors, mail and telephone links, and a bound value are not paths.
    '<a href="#main">x</a>',
    '<a href="mailto:info@example.org">x</a>',
    '<a href="tel:+218000000000">x</a>',
    '<a href="{{ $href }}">x</a>',
    // A Blade comment is removed before rendering, so it may name the shapes.
    "{{-- Never route('x'), url('/x') or href=\"/x\" here; use PublicRoute. --}}",
]);

it('still scans what follows a Blade comment', function () {
    expect(firstUnlocalisedLink("{{-- a note --}}{{ route('x') }}"))->not->toBeNull();
});

it('finds the files it scans, including the layout, the partials and the component', function () {
    $names = array_map('basename', publicViewFilesForRouteScan());

    // The control: a scan over an empty list passes by finding nothing.
    expect($names)->toContain('public.blade.php')
        ->toContain('header.blade.php')
        ->toContain('navigation.blade.php')
        ->toContain('footer.blade.php')
        ->toContain('home.blade.php')
        ->toContain('brand.blade.php');
});

it('finds no link that bypasses PublicRoute in the public layout, views or components', function () {
    $offenders = [];

    foreach (publicViewFilesForRouteScan() as $path) {
        if (($match = firstUnlocalisedLink((string) file_get_contents($path))) !== null) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' -> '.$match;
        }
    }

    expect($offenders)->toBeEmpty(
        "Public links go through PublicRoute::url(), so Arabic visitors stay in Arabic:\n  ".implode("\n  ", $offenders),
    );
});
