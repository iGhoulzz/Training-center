<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Domain\Publications\Models\Article;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\View\ViewException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Livewire marks "a component rendered this request" in static state that outlives
// the test, and injects its script into the next public page rendered. Flushed
// before each test, as the other public test files do.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The public design system (P4-T20a)
|--------------------------------------------------------------------------
|
| WHAT THIS FILE HOLDS THE PUBLIC SITE TO. The design's code is the visual
| specification, rebuilt as tokens and components and never pasted in as inline CSS
| (docs/ENGINEERING.md, "Frontend (phase 4)"). So a public view has no `style`
| attribute, no raw colour and no colour from outside the system's own tokens, and
| the centre's brand reaches the page from config/public_site.php alone.
|
| EVERY SCANNER HAS SELF-TESTS: samples it must catch and samples it must leave
| alone. A scan that quietly matches less than it claims reads as enforcement while
| enforcing nothing, which is how the project's earlier scanners were found out.
|
| THE BRAND TESTS USE SENTINELS, NOT THE REAL COPY. Each config key is set to a
| value of its own and the test looks for that value where it should appear, so a
| page that read the real name from somewhere else, or hardcoded it, fails.
*/

/**
 * The layout, every file under resources/views/public/ and every public component,
 * as absolute paths: the markup every public page is made of.
 *
 * @return array<int, string>
 */
function designSystemViewFiles(): array
{
    $files = [resource_path('views/layouts/public.blade.php')];

    foreach ([resource_path('views/public'), resource_path('views/components/public')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $files[] = (string) $file->getRealPath();
        }
    }

    return $files;
}

/**
 * The first inline style in this source, or null.
 *
 * Every way of writing one: a `style` attribute (any case, spaces around the equals
 * sign), its bound forms `:style` and `x-bind:style` (a colon is not a character of
 * the attribute's name here, so the plain pattern already reads them), the `@style`
 * directive, and the attribute bag's `->style()`. The name must be exactly `style`:
 * `data-style` and `xstyle` are other attributes, and the word in prose has no equals
 * sign. A Blade comment is removed first, because it is not rendered and may name the
 * rule it explains.
 */
function designSystemFirstInlineStyle(string $source): ?string
{
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    $patterns = [
        '/(?<![\w-])style\s*=/i',
        '/@style\s*\(/',
        '/->\s*style\s*\(/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code, $match) === 1) {
            return trim($match[0]);
        }
    }

    return null;
}

/**
 * The first raw colour in this source, or null.
 *
 * A raw colour is one the design system's tokens did not supply: an arbitrary-value
 * utility (`text-[#0d2745]`, `bg-[rgb(1,2,3)]`, `border-[color:red]`), a hex or
 * functional colour inside a class attribute, or a colour attribute on an SVG
 * (`fill="#fff"`). `currentColor` is not one: it is how an icon takes the colour of
 * its text. An `href="#main"` anchor is not one either, because the scan reads the
 * inside of `class` and the colour attributes and nothing else. A Blade comment
 * is removed first.
 */
function designSystemFirstRawColour(string $source): ?string
{
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    $patterns = [
        '/-\[\s*(?:#|(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\s*\(|color\s*:)/i',
        '/(?<![\w-])(?:fill|stroke|color|stop-color|flood-color)\s*=\s*["\']\s*(?:#|(?:rgba?|hsla?)\s*\()/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code, $match) === 1) {
            return trim($match[0]);
        }
    }

    preg_match_all('/\bclass\s*=\s*"([^"]*)"/i', $code, $classes);

    foreach ($classes[1] as $value) {
        if (preg_match('/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\s*\(/i', $value, $match) === 1) {
            return trim($match[0]);
        }
    }

    return null;
}

/**
 * The first class in this source that names a colour outside the design system.
 *
 * Three kinds: Tailwind's own default palette (`bg-white`, `text-slate-700`), which
 * is a raw colour by another name; the T11 palette this task replaced, whose names
 * (`navy-900`, `cream-50`, `gold-400`) no longer exist and would render as nothing;
 * and `font-serif`, which now means the browser's serif and not the display face.
 * The system's own tokens (`navy`, `gold-light`, `body-secondary` and the rest) have
 * no shade number and so are never a hit.
 */
function designSystemFirstForeignColour(string $source): ?string
{
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $utility = '(?:bg|text|border|ring|outline|fill|stroke|from|to|via|divide|decoration|accent|caret|shadow|placeholder)';

    $patterns = [
        '/(?<![\w-])(?:[a-z-]+:)*'.$utility.'-(?:white|black)(?![\w-])/i',
        '/(?<![\w-])(?:[a-z-]+:)*'.$utility.'-(?:'.$palette.')-\d{2,3}(?![\w-])/i',
        '/(?<![\w-])(?:[a-z-]+:)*'.$utility.'-(?:navy|cream|gold)-\d{2,3}(?![\w-])/i',
        '/(?<![\w-])font-serif(?![\w-])/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code, $match) === 1) {
            return trim($match[0]);
        }
    }

    return null;
}

/**
 * A relative path for a failure message.
 */
function designSystemRelativePath(string $path): string
{
    return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
}

/**
 * Every public page worth rendering, as label => path, with the fixtures the
 * parametric ones need: a course for a programme page and a published article for an
 * article page.
 *
 * @return array<string, string>
 */
function designSystemPages(): array
{
    $course = Course::factory()->create(['name_en' => 'Design Course']);
    $article = Article::factory()->published()->create(['title_en' => 'Design Article', 'topic' => 'Design']);

    return [
        'home' => '/',
        'about' => '/about',
        'contact' => '/contact',
        'programmes' => '/programmes',
        'programme' => '/programmes/'.$course->id,
        'publications' => '/publications',
        'article' => '/publications/'.$article->slug,
    ];
}

/**
 * Parse a rendered page.
 */
function designSystemDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/*
|--------------------------------------------------------------------------
| No inline style
|--------------------------------------------------------------------------
*/

it('catches every way of writing an inline style', function (string $sample) {
    expect(designSystemFirstInlineStyle($sample))->not->toBeNull("The scanner missed: {$sample}");
})->with([
    '<div style="color: red">',
    "<div style='color: red'>",
    '<div STYLE="x">',
    '<div style = "x">',
    "<div\n    style=\"x\"\n>",
    '<div :style="$styles">',
    '<div x-bind:style="styles">',
    "<div @style(['color: red' => true])>",
    "{{ \$attributes->style('color: red') }}",
    "<div {{ \$attributes->style('x') }}>",
    // After a Blade comment, which is not scanned, the scan resumes.
    '{{-- a note --}}<div style="x">',
]);

it('leaves everything that is not an inline style alone', function (string $sample) {
    expect(designSystemFirstInlineStyle($sample))->toBeNull("The scanner wrongly flagged: {$sample}");
})->with([
    '<div data-style="x">',
    '<div xstyle="x">',
    '<div class="mystyle">',
    '<p>The style of this page is set in the stylesheet.</p>',
    "{{ __('public.style') }}",
    '<a href="#main">Skip</a>',
    '<style>.a { color: red; }</style>',
    // A Blade comment is removed before rendering, so it may name the rule.
    '{{-- Never style="x" or :style here. --}}',
]);

it('finds the files the view scans read, including the layout, the partials and the components', function () {
    $names = array_map('basename', designSystemViewFiles());

    // The control: a scan over an empty list passes by finding nothing.
    expect($names)->toContain('public.blade.php')
        ->toContain('header.blade.php')
        ->toContain('navigation.blade.php')
        ->toContain('footer.blade.php')
        ->toContain('home.blade.php')
        ->toContain('brand.blade.php')
        ->toContain('button.blade.php')
        ->toContain('container.blade.php')
        ->toContain('icon.blade.php')
        ->toContain('portal-link.blade.php');
});

it('has no inline style in any public view or component', function () {
    $offenders = [];

    foreach (designSystemViewFiles() as $path) {
        if (($match = designSystemFirstInlineStyle((string) file_get_contents($path))) !== null) {
            $offenders[] = designSystemRelativePath($path).' -> '.$match;
        }
    }

    expect($offenders)->toBeEmpty("Public views style through the design system's tokens, never inline:\n  ".implode("\n  ", $offenders));
});

it('renders no style attribute on any public page', function () {
    foreach (designSystemPages() as $label => $path) {
        $html = (string) $this->get($path)->assertOk()->getContent();

        // The control: a page that did not render would pass for having no style.
        expect($html)->toContain('<main');

        // Parsed, so the attribute is looked for on elements and not in text. A
        // <style> element (the self-hosted font faces) is not an attribute.
        $styled = designSystemDocument($html)->querySelectorAll('[style]');

        expect(count($styled))->toBe(0, "{$label} renders an element with a style attribute.");
    }
});

/*
|--------------------------------------------------------------------------
| No raw colour, and no colour outside the system
|--------------------------------------------------------------------------
*/

it('catches every raw colour', function (string $sample) {
    expect(designSystemFirstRawColour($sample))->not->toBeNull("The scanner missed: {$sample}");
})->with([
    '<p class="text-[#0d2745]">',
    '<p class="bg-[#fff]">',
    '<p class="border-[#C9A44C]/50">',
    '<p class="text-[rgb(13,39,69)]">',
    '<p class="text-[rgba(13,39,69,.5)]">',
    '<p class="bg-[hsl(210,50%,20%)]">',
    '<p class="bg-[oklch(0.3_0.1_250)]">',
    '<p class="bg-[color:var(--x)]">',
    '<p class="mt-4 text-#0d2745">',
    '<p class="p-4 rgb(1,2,3)">',
    '<p class="hsl(1,2%,3%)">',
    '<svg fill="#fff">',
    "<svg stroke='#0d2745'>",
    '<svg stop-color="rgb(1,2,3)">',
    '{{-- a note --}}<p class="text-[#fff]">',
]);

it('leaves every token and every non-colour alone', function (string $sample) {
    expect(designSystemFirstRawColour($sample))->toBeNull("The scanner wrongly flagged: {$sample}");
})->with([
    '<a href="#main">Skip</a>',
    '<a href="#">Top</a>',
    // An anchor that merely looks like a hex colour is a link, not a colour.
    '<a href="#fade" class="p-4">Fade</a>',
    '<a href="#c0ffee">Coffee</a>',
    '<div id="#x" class="p-4">',
    '<p class="text-navy bg-cream/92 border-navy/10 hover:text-gold-dark">',
    '<p class="w-[390px] flex-[1_1_300px] tracking-[0.02em] border-[1.5px]">',
    '<svg fill="none" stroke="currentColor">',
    '<p class="[&::-webkit-details-marker]:hidden aria-[current=page]:text-gold-dark">',
    '<p data-colour="#fff">',
    '{{-- Never text-[#fff] or class="#fff". --}}',
]);

it('uses no raw colour in any public view or component', function () {
    $offenders = [];

    foreach (designSystemViewFiles() as $path) {
        if (($match = designSystemFirstRawColour((string) file_get_contents($path))) !== null) {
            $offenders[] = designSystemRelativePath($path).' -> '.$match;
        }
    }

    expect($offenders)->toBeEmpty("A colour is a token in resources/css/public.css, never a value in a view:\n  ".implode("\n  ", $offenders));
});

it('catches every colour class from outside the design system', function (string $sample) {
    expect(designSystemFirstForeignColour($sample))->not->toBeNull("The scanner missed: {$sample}");
})->with([
    '<p class="bg-white">',
    '<p class="text-black">',
    '<p class="hover:bg-white">',
    '<p class="md:hover:text-slate-700">',
    '<p class="border-gray-200">',
    '<p class="text-sky-500/50">',
    '<p class="bg-navy-900">',
    '<p class="text-cream-50">',
    '<p class="hover:bg-cream-100">',
    '<p class="focus-visible:outline-gold-400">',
    '<p class="border-navy-800/40">',
    '<p class="font-serif text-xl">',
]);

it('leaves the design system own colours and fonts alone', function (string $sample) {
    expect(designSystemFirstForeignColour($sample))->toBeNull("The scanner wrongly flagged: {$sample}");
})->with([
    '<p class="bg-navy text-cream border-navy/10 bg-card text-ink text-body-secondary text-meta text-subtitle">',
    '<p class="hover:bg-navy-hover text-gold-light bg-gold-dark text-on-navy">',
    '<p class="font-display font-sans">',
    '<p class="bg-white-glove">',
    '<p class="shadow-card shadow-gold shadow-card-hover shadow-pill">',
    '<p>The white paper and the black book</p>',
    '{{-- Never bg-white or navy-900 or font-serif. --}}',
]);

it('names no colour from outside the design system in any public view or component', function () {
    $offenders = [];

    foreach (designSystemViewFiles() as $path) {
        if (($match = designSystemFirstForeignColour((string) file_get_contents($path))) !== null) {
            $offenders[] = designSystemRelativePath($path).' -> '.$match;
        }
    }

    expect($offenders)->toBeEmpty("Use a token from resources/css/public.css:\n  ".implode("\n  ", $offenders));
});

/*
|--------------------------------------------------------------------------
| The fonts, the logo and the brand
|--------------------------------------------------------------------------
*/

it('loads the self-hosted fonts only inside the built-stylesheet guard', function () {
    $layout = (string) file_get_contents(resource_path('views/layouts/public.blade.php'));
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $layout);

    // CI runs the suite before it builds assets, so a font call outside the guard
    // would ask for a manifest that does not exist and fail every public page.
    expect(preg_match('/@if\s*\(\s*\$stylesheetBuilt\s*\)(.*?)@endif/s', $code, $guarded))->toBe(1)
        ->and($guarded[1])->toContain('Vite::fonts(')->toContain("'instrument-serif'")->toContain("'ibm-plex-sans-arabic'")->toContain('@vite(')
        ->and(substr_count($code, 'Vite::fonts('))->toBe(1)
        ->and(substr_count($code, '@vite('))->toBe(1);
});

it('commits the logo and serves it as a JPEG', function () {
    $path = public_path('images/brand/asclst-logo.jpg');

    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(1000);

    $size = getimagesize($path);

    expect($size)->not->toBeFalse()
        ->and($size['mime'])->toBe('image/jpeg');
});

it('renders the logo as a decorative image in the header of every public page', function () {
    foreach (designSystemPages() as $label => $path) {
        $document = designSystemDocument((string) $this->get($path)->assertOk()->getContent());

        $images = $document->querySelectorAll('header img');

        expect(count($images))->toBe(1, "{$label}: the header holds {$images->length} images, not one.");

        $image = $images[0];

        // Decorative: the name beside it carries the meaning. Hand-written URL, so
        // the expectation is not read back from the config the view reads.
        expect($image->getAttribute('src'))->toBe(url('/images/brand/asclst-logo.jpg'))
            ->and($image->hasAttribute('alt'))->toBeTrue("{$label}: the header logo has no alt attribute.")
            ->and($image->getAttribute('alt'))->toBe('')
            ->and($image->getAttribute('width'))->toBe('46')
            ->and($image->getAttribute('height'))->toBe('46');
    }
});

it('takes every part of the brand from config, on every public page', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));

    config([
        'public_site.brand.name' => 'SENTINEL-FULL-NAME',
        'public_site.brand.short_name' => 'SENTINEL-SHORT',
        'public_site.brand.tagline' => 'SENTINEL-TAGLINE',
        'public_site.brand.name_ar' => 'SENTINEL-ARABIC',
        'public_site.brand.logo' => 'images/brand/sentinel-logo.png',
    ]);

    foreach (designSystemPages() as $label => $path) {
        $html = (string) $this->get($path)->assertOk()->getContent();
        $document = designSystemDocument($html);

        // The page title is the full name, alone or after the page's own.
        expect($document->querySelector('title')->textContent)->toContain('SENTINEL-FULL-NAME');

        // The header's home link is announced as the full name: a visually hidden
        // span carries it, and the short name and tagline beside it are hidden from
        // assistive technology so they are not read letter by letter.
        $home = $document->querySelector('header a[href="'.url('/').'"]');

        expect($home)->not->toBeNull("{$label}: no home link in the header.");

        $accessible = $home->querySelector('.sr-only');
        $hidden = $home->querySelector('[aria-hidden="true"]');

        expect($accessible?->textContent)->toBe('SENTINEL-FULL-NAME')
            ->and($hidden?->textContent)->toContain('SENTINEL-SHORT')->toContain('SENTINEL-TAGLINE')
            ->and($home->querySelector('img')->getAttribute('src'))->toBe(url('/images/brand/sentinel-logo.png'));

        // Two Arabic strings and no more: the top bar's and the footer's. Each is
        // marked as Arabic and right to left.
        $arabic = $document->querySelectorAll('[lang="ar"]');

        expect(count($arabic))->toBe(2, "{$label}: expected the top bar's and the footer's Arabic name.");

        foreach ($arabic as $element) {
            expect($element->textContent)->toBe('SENTINEL-ARABIC')
                ->and($element->getAttribute('dir'))->toBe('rtl');
        }

        expect($arabic[0]->closest('footer'))->toBeNull("{$label}: the first Arabic name is not in the top bar.")
            ->and($arabic[0]->closest('header'))->toBeNull()
            ->and($arabic[1]->closest('footer'))->not->toBeNull("{$label}: the second Arabic name is not in the footer.");

        // The footer's lockup, and its copyright line: the year by hand, the name
        // from config.
        $footer = $document->querySelector('footer');

        expect($footer->querySelector('img')->getAttribute('src'))->toBe(url('/images/brand/sentinel-logo.png'))
            ->and($footer->textContent)->toContain('SENTINEL-FULL-NAME')
            ->and(trim((string) preg_replace('/\s+/', ' ', $footer->textContent)))->toContain('© 2026 SENTINEL-FULL-NAME');
    }
});

it('says nowhere on a public page that the brand is the application name', function () {
    config(['app.name' => 'SENTINEL-APP-NAME']);

    foreach (designSystemPages() as $label => $path) {
        $html = (string) $this->get($path)->assertOk()->getContent();

        expect(str_contains($html, 'SENTINEL-APP-NAME'))->toBeFalse("{$label} prints config('app.name').");
    }
});

/*
|--------------------------------------------------------------------------
| The components
|--------------------------------------------------------------------------
*/

it('draws every named icon as a decorative inline image coloured by its text', function (string $name) {
    $html = Blade::render('<x-public.icon name="'.$name.'" class="text-gold" />');
    $svg = designSystemDocument($html)->querySelector('svg');

    expect($svg)->not->toBeNull()
        ->and($svg->getAttribute('aria-hidden'))->toBe('true')
        ->and($svg->getAttribute('focusable'))->toBe('false')
        ->and($svg->getAttribute('stroke'))->toBe('currentColor')
        ->and($svg->getAttribute('fill'))->toBe('none')
        ->and($svg->getAttribute('class'))->toContain('text-gold')
        ->and(count($svg->querySelectorAll('path, circle')))->toBeGreaterThan(0);
})->with(['phone', 'mail', 'graduation-cap', 'arrow-right', 'download', 'document', 'search', 'pin', 'clock', 'award', 'bar-chart']);

it('sizes an icon and rounds or squares its strokes on request', function () {
    $round = designSystemDocument(Blade::render('<x-public.icon name="phone" :size="13" :stroke="1.7" />'))->querySelector('svg');
    $square = designSystemDocument(Blade::render('<x-public.icon name="phone" :round="false" />'))->querySelector('svg');

    expect($round->getAttribute('width'))->toBe('13')
        ->and($round->getAttribute('height'))->toBe('13')
        ->and($round->getAttribute('stroke-width'))->toBe('1.7')
        ->and($round->getAttribute('stroke-linecap'))->toBe('round')
        ->and($square->hasAttribute('stroke-linecap'))->toBeFalse()
        ->and($square->getAttribute('width'))->toBe('16');
});

it('refuses an icon that does not exist rather than drawing an empty box', function () {
    $thrown = null;

    try {
        Blade::render('<x-public.icon name="no-such-icon" />');
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // Blade wraps what a view throws, once for each view it renders through, so the
    // cause is at the end of the chain.
    $cause = $thrown;

    while ($cause?->getPrevious() !== null) {
        $cause = $cause->getPrevious();
    }

    expect($thrown)->toBeInstanceOf(ViewException::class)
        ->and($cause)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($cause->getMessage())->toBe('The icon [no-such-icon] does not exist.');
});

it('renders a button as a link in the variant and size asked for, and merges the callers classes', function () {
    $link = designSystemDocument(Blade::render('<x-public.button href="/somewhere" variant="outline" size="sm" class="mt-4">Label</x-public.button>'))->querySelector('a');

    expect($link->getAttribute('href'))->toBe('/somewhere')
        ->and($link->textContent)->toBe('Label')
        ->and(explode(' ', $link->getAttribute('class')))
        ->toContain('border-navy/20')->toContain('text-navy')->toContain('tap-target')->toContain('text-caption')->toContain('mt-4')
        ->not->toContain('bg-gold');

    $primary = designSystemDocument(Blade::render('<x-public.button href="/x">Go</x-public.button>'))->querySelector('a');

    expect(explode(' ', $primary->getAttribute('class')))->toContain('bg-gold')->toContain('text-ink')->toContain('px-5');
});

it('lays a container out at the site width or the narrow article width', function () {
    $default = designSystemDocument(Blade::render('<x-public.container class="py-4">x</x-public.container>'))->querySelector('div');
    $narrow = designSystemDocument(Blade::render('<x-public.container size="narrow">x</x-public.container>'))->querySelector('div');

    expect(explode(' ', $default->getAttribute('class')))->toContain('max-w-site')->toContain('px-6')->toContain('mx-auto')->toContain('py-4')->not->toContain('max-w-narrow')
        ->and(explode(' ', $narrow->getAttribute('class')))->toContain('max-w-narrow')->not->toContain('max-w-site');
});

/*
|--------------------------------------------------------------------------
| The navigation names the page it is on
|--------------------------------------------------------------------------
*/

/**
 * Register a throwaway route that renders a public page, and refresh the router's
 * name lookups, so a route named in T18's `ar.` style can be requested.
 */
function designSystemTemporaryRoute(string $uri, string $name, string $view): void
{
    Route::get($uri, fn () => view($view))->name($name);
    Route::getRoutes()->refreshNameLookups();
}

/**
 * The href and aria-current of every link in the main navigation, menu and row.
 *
 * @return array<int, array{0: string, 1: string|null}>
 */
function designSystemNavigationLinks(string $html): array
{
    $links = [];

    foreach (designSystemDocument($html)->querySelectorAll('header nav a') as $link) {
        $links[] = [$link->getAttribute('href'), $link->hasAttribute('aria-current') ? $link->getAttribute('aria-current') : null];
    }

    return $links;
}

it('marks the link of the section the visitor is in, and no other, on its pages', function (string $page, ?string $sectionPath) {
    $pages = designSystemPages();
    $html = (string) $this->get($pages[$page])->assertOk()->getContent();

    $marked = array_values(array_filter(designSystemNavigationLinks($html), fn (array $link): bool => $link[1] !== null));

    if ($sectionPath === null) {
        expect($marked)->toBe([], "{$page} marks a link as the current page.");

        return;
    }

    // The navigation is rendered twice, as a menu and as a row, so the section's
    // link is marked in both and nowhere else. The value is exactly "page".
    expect($marked)->toBe([[url($sectionPath), 'page'], [url($sectionPath), 'page']]);
})->with([
    'home marks none' => ['home', null],
    'about' => ['about', '/about'],
    'contact' => ['contact', '/contact'],
    'the programme index' => ['programmes', '/programmes'],
    'a programme page' => ['programme', '/programmes'],
    'the library' => ['publications', '/publications'],
    'an article' => ['article', '/publications'],
]);

it('keeps the current section marked when the route name carries the Arabic prefix', function () {
    designSystemTemporaryRoute('/ar-temporary/programmes', 'ar.public.programmes.index', 'public.about');
    designSystemTemporaryRoute('/ar-temporary/publications/x', 'ar.public.publications.show', 'public.about');

    foreach (['/ar-temporary/programmes' => '/programmes', '/ar-temporary/publications/x' => '/publications'] as $temporary => $section) {
        $marked = array_values(array_filter(
            designSystemNavigationLinks((string) $this->get($temporary)->assertOk()->getContent()),
            fn (array $link): bool => $link[1] !== null,
        ));

        expect($marked)->toBe([[url($section), 'page'], [url($section), 'page']], $temporary);
    }
});

it('never marks the verifier link, which is not a page of this layout', function () {
    foreach (designSystemPages() as $label => $path) {
        foreach (designSystemNavigationLinks((string) $this->get($path)->assertOk()->getContent()) as [$href, $current]) {
            if ($href === url('/verify/certificates')) {
                expect($current)->toBeNull("{$label} marks the verifier link.");
            }
        }
    }
});
