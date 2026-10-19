<?php

declare(strict_types=1);

use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Livewire's "a component rendered" flag outlives a test and would inject its own
// script into a public page, which the script assertions below would then count.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The hero seal, turned like a coin (owner, 2026-10-17)
|--------------------------------------------------------------------------
|
| The public site's one script, and the only page that loads it. What this file
| holds:
| - the seal is a coin of two identical, decorative faces;
| - it uses the cut-out PNG once that file exists, and the logo before then;
| - the script loads only on the home page, only inside the layout's
|   built-assets guard, which CI depends on;
| - the script reads the pointer and nothing else.
*/

/**
 * The home page's seal stage and its two faces.
 *
 * @return array{stage: Element|null, faces: array<int, Element>}
 */
function heroSealParts(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $stage = $document->querySelector('[data-seal-coin]');

    return [
        'stage' => $stage,
        'faces' => $stage === null ? [] : iterator_to_array($stage->querySelectorAll('[data-seal-coin-body] img')),
    ];
}

it('draws the seal as a coin of two identical, decorative faces', function () {
    ['stage' => $stage, 'faces' => $faces] = heroSealParts((string) $this->get('/')->assertOk()->getContent());

    expect($stage)->not->toBeNull()
        ->and($faces)->toHaveCount(2)
        ->and($faces[0]->getAttribute('src'))->toBe($faces[1]->getAttribute('src'))
        ->and($faces[0]->getAttribute('alt'))->toBe('')
        ->and($faces[1]->getAttribute('alt'))->toBe('')
        ->and($faces[1]->getAttribute('class'))->toContain('seal-coin-back')
        ->and($faces[0]->getAttribute('class'))->not->toContain('seal-coin-back');
});

it('uses the cut-out seal once its file exists, and the logo in its circle before then', function () {
    // A sentinel path that does not exist: the hero falls back to the logo.
    config(['public_site.brand.seal' => 'images/brand/no-such-seal.png']);
    ['faces' => $before] = heroSealParts((string) $this->get('/')->assertOk()->getContent());

    expect($before[0]->getAttribute('src'))->toEndWith('/'.config('public_site.brand.logo'))
        ->and($before[0]->getAttribute('class'))->toContain('rounded-pill')->not->toContain('object-contain');

    // A path that does exist stands in for the PNG: the hero uses it, uncropped.
    config(['public_site.brand.seal' => 'favicon.ico']);
    ['faces' => $after] = heroSealParts((string) $this->get('/')->assertOk()->getContent());

    expect($after[0]->getAttribute('src'))->toEndWith('/favicon.ico')
        ->and($after[0]->getAttribute('class'))->toContain('object-contain')->not->toContain('rounded-pill');
})->skip(fn (): bool => ! file_exists(public_path('favicon.ico')), 'needs public/favicon.ico as an existing file');

it('loads the seal script only through the layout, inside the built-assets guard', function () {
    $layout = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', (string) file_get_contents(resource_path('views/layouts/public.blade.php')));

    // CI runs the suite before it builds assets, so a @vite outside the guard would
    // throw on every public page. The script sits inside it, behind the section.
    expect(preg_match('/@if\s*\(\s*\$stylesheetBuilt\s*\)(.*?)@endif\s*<\/head>/s', $layout, $guarded))->toBe(1)
        ->and(preg_match("/@hasSection\('seal_coin'\)\s*@vite\('resources\/js\/public-seal\.js'\)\s*@endif/", $guarded[1]))->toBe(1)
        ->and(substr_count($layout, 'public-seal.js'))->toBe(1);
});

it('asks for the seal script from the home page and from no other view', function () {
    $askers = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (preg_match("/@section\(\s*'seal_coin'/", (string) file_get_contents((string) $file->getRealPath())) === 1) {
            $askers[] = str_replace([resource_path('views').DIRECTORY_SEPARATOR, '\\'], ['', '/'], (string) $file->getRealPath());
        }
    }

    expect($askers)->toBe(['public/home.blade.php']);
});

it('renders the seal script on the home page alone, and only from a build', function () {
    $built = file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'));

    expect(str_contains((string) $this->get('/')->getContent(), 'public-seal'))->toBe($built);

    foreach (['/about', '/programmes', '/publications', '/contact'] as $path) {
        $html = strtolower((string) $this->get($path)->assertOk()->getContent());

        expect(str_contains($html, 'public-seal'))->toBeFalse("{$path} loads the seal script.")
            ->and(str_contains($html, '<script'))->toBeFalse("{$path} loads a script.");
    }
});

it('reads only the pointer: no network, no storage, nothing on touch or with reduced motion', function () {
    $script = (string) file_get_contents(resource_path('js/public-seal.js'));
    $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $script);

    foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'import(', 'eval(', 'innerHTML'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("public-seal.js uses [{$forbidden}].");
    }

    expect($code)->toContain("matchMedia('(prefers-reduced-motion: reduce)')")
        ->toContain("event.pointerType === 'touch'")
        ->toContain("'pointerleave'")
        ->and((string) file_get_contents(base_path('vite.config.js')))->toContain("'resources/js/public-seal.js'");

    // With reduced motion the CSS never turns the seal either, whatever is set.
    expect(preg_match('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{\s*\.seal-coin\s*\{[^}]*transform:\s*none/s', (string) file_get_contents(resource_path('css/public.css'))))->toBe(1);
});
