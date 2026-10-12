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
    registerTemporaryRoute('/ar-temporary', 'ar.public.home');
    app()->setLocale('ar');

    expect(PublicRoute::url('public.home'))
        ->toBe(url('/ar-temporary'))
        ->not->toBe(url('/'));
});

it('passes route parameters through under an Arabic locale', function () {
    registerTemporaryRoute('/ar-temporary/verify/{reference}', 'ar.verify.certificates.show');
    app()->setLocale('ar');

    expect(PublicRoute::url('verify.certificates.show', ['reference' => 'CERT-2026-0087']))
        ->toBe(url('/ar-temporary/verify/CERT-2026-0087'));
});

it('throws, naming both routes, when the Arabic route does not exist, and never returns the English URL', function () {
    app()->setLocale('ar');

    $thrown = null;
    $returned = null;

    try {
        $returned = PublicRoute::url('public.home');
    } catch (LogicException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(LogicException::class)
        ->and($returned)->toBeNull()
        ->and($thrown->getMessage())->toContain('ar.public.home')
        ->and($thrown->getMessage())->toContain('public.home');
});

it('throws for any locale that is not English, not only Arabic', function () {
    app()->setLocale('fr');

    expect(fn () => PublicRoute::url('public.home'))->toThrow(LogicException::class, 'fr.public.home');
});

/**
 * The first bare route() call in a view's source, or null.
 *
 * A NAMED FUNCTION WITH SAMPLES, like the other scanners in this suite, so the
 * scan cannot quietly narrow. CASE-SENSITIVE ON PURPOSE: `PublicRoute::url(` and
 * `Route::has(` contain "Route" with a capital and are the sanctioned forms.
 *
 * Three bypass shapes are named beside the plain helper, because each reaches the
 * same unqualified URL: a leading backslash, the URL facade, and `url()->route(`.
 * A method call on some other object (`$request->route(`) is not a link and is
 * left alone.
 *
 * A BLADE COMMENT IS NOT SCANNED. It is removed before the template renders, so
 * a note explaining the rule may name the call without being the call, which is
 * how the first version of the navigation partial tripped its own scan. Anything
 * after the comment still is scanned.
 */
function firstBareRouteCall(string $source): ?string
{
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    $pattern = '/(?<![\w$>:])route\s*\(|\b(?:URL::|url\(\s*\)->)route\s*\(/';

    return preg_match($pattern, $code, $match) === 1 ? trim($match[0]) : null;
}

/**
 * The layout and every file under resources/views/public/, as absolute paths.
 *
 * @return array<int, string>
 */
function publicViewFilesForRouteScan(): array
{
    $files = [resource_path('views/layouts/public.blade.php')];

    foreach (File::allFiles(resource_path('views/public')) as $file) {
        $files[] = (string) $file->getRealPath();
    }

    return $files;
}

it('flags a bare route() call', function (string $sample) {
    expect(firstBareRouteCall($sample))->not->toBeNull();
})->with([
    "{{ route('x') }}",
    "<a href=\"{{ route('public.home') }}\">x</a>",
    "{{ route ('x') }}",
    "{{ \\route('x') }}",
    "@php \$a = route('x'); @endphp",
    "{{ URL::route('x') }}",
    "{{ url()->route('x') }}",
]);

it('leaves the sanctioned forms alone', function (string $sample) {
    expect(firstBareRouteCall($sample))->toBeNull();
})->with([
    "{{ PublicRoute::url('x') }}",
    "@if (Route::has('x')) @endif",
    "{{ \$request->route('x') }}",
    "{{ __('public.route_label') }}",
    '<p>Route (optional)</p>',
    // A Blade comment is removed before rendering, so it may name the call.
    "{{-- Never route('x') here; use PublicRoute. --}}",
]);

it('still scans what follows a Blade comment', function () {
    expect(firstBareRouteCall("{{-- a note --}}{{ route('x') }}"))->not->toBeNull();
});

it('finds the files it scans, including the layout and the partials', function () {
    $names = array_map('basename', publicViewFilesForRouteScan());

    // The control: a scan over an empty list passes by finding nothing.
    expect($names)->toContain('public.blade.php')
        ->toContain('header.blade.php')
        ->toContain('navigation.blade.php')
        ->toContain('footer.blade.php')
        ->toContain('home.blade.php');
});

it('finds no bare route() call in the public layout or views', function () {
    $offenders = [];

    foreach (publicViewFilesForRouteScan() as $path) {
        if (($match = firstBareRouteCall((string) file_get_contents($path))) !== null) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' -> '.$match;
        }
    }

    expect($offenders)->toBeEmpty(
        "Public links go through PublicRoute::url(), so Arabic visitors stay in Arabic:\n  ".implode("\n  ", $offenders),
    );
});
