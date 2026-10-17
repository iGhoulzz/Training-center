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
 * The CSS named colours (CSS Color 4), as a regex alternation.
 *
 * `transparent` and `currentColor` are left out on purpose: neither is a raw colour.
 * One is no colour at all, and the other is how an icon takes the colour of its text.
 */
function designSystemNamedColours(): string
{
    return 'aliceblue|antiquewhite|aqua|aquamarine|azure|beige|bisque|black|blanchedalmond|blue|blueviolet|brown|'
        .'burlywood|cadetblue|chartreuse|chocolate|coral|cornflowerblue|cornsilk|crimson|cyan|darkblue|darkcyan|'
        .'darkgoldenrod|darkgray|darkgreen|darkgrey|darkkhaki|darkmagenta|darkolivegreen|darkorange|darkorchid|'
        .'darkred|darksalmon|darkseagreen|darkslateblue|darkslategray|darkslategrey|darkturquoise|darkviolet|'
        .'deeppink|deepskyblue|dimgray|dimgrey|dodgerblue|firebrick|floralwhite|forestgreen|fuchsia|gainsboro|'
        .'ghostwhite|gold|goldenrod|gray|green|greenyellow|grey|honeydew|hotpink|indianred|indigo|ivory|khaki|'
        .'lavender|lavenderblush|lawngreen|lemonchiffon|lightblue|lightcoral|lightcyan|lightgoldenrodyellow|'
        .'lightgray|lightgreen|lightgrey|lightpink|lightsalmon|lightseagreen|lightskyblue|lightslategray|'
        .'lightslategrey|lightsteelblue|lightyellow|lime|limegreen|linen|magenta|maroon|mediumaquamarine|'
        .'mediumblue|mediumorchid|mediumpurple|mediumseagreen|mediumslateblue|mediumspringgreen|'
        .'mediumturquoise|mediumvioletred|midnightblue|mintcream|mistyrose|moccasin|navajowhite|navy|oldlace|'
        .'olive|olivedrab|orange|orangered|orchid|palegoldenrod|palegreen|paleturquoise|palevioletred|'
        .'papayawhip|peachpuff|peru|pink|plum|powderblue|purple|rebeccapurple|red|rosybrown|royalblue|'
        .'saddlebrown|salmon|sandybrown|seagreen|seashell|sienna|silver|skyblue|slateblue|slategray|slategrey|'
        .'snow|springgreen|steelblue|tan|teal|thistle|tomato|turquoise|violet|wheat|white|whitesmoke|yellow|'
        .'yellowgreen';
}

/**
 * Whether this text holds a colour value: a hex colour, a colour function or a named
 * colour.
 *
 * A name counts only as a whole word of letters. The test is "no letter or hyphen on
 * either side", so `var(--color-gold)` and `text-navy` are token references and not
 * the named colours `gold` and `navy`. An underscore, Tailwind's space in an
 * arbitrary value, does separate words: `0_0_0_1px_red` holds `red`.
 */
function designSystemColourValueIn(string $text): ?string
{
    $patterns = [
        '/#[0-9a-f]{3,8}(?![0-9a-z])/i',
        '/(?<![a-z-])(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)\s*\(/i',
        '/(?<![a-z-])(?:'.designSystemNamedColours().')(?![a-z-])/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $match) === 1) {
            return $match[0];
        }
    }

    return null;
}

/**
 * The first raw colour in this source, or null.
 *
 * A raw colour is one the design system's tokens did not supply. Three places can
 * hold one:
 *
 * - An arbitrary value or arbitrary property in a class, wherever its class string
 *   is written: a `class` attribute, an `@class` array, or `$attributes->class()`.
 *   Examples are `text-[#0d2745]`, `text-[red]`, `bg-[oklch(0.3_0.1_250)]`,
 *   `shadow-[0_2px_rgba(0,0,0,.1)]` and `[color:red]`, and a `color:` type hint
 *   (`bg-[color:var(--x)]`) counts as well. A bracket group with no space, quote or
 *   bracket inside it is how Tailwind writes one, and a PHP array or index never
 *   looks like that.
 * - A colour attribute (`fill`, `stroke`, `color`, `stop-color`, `flood-color`,
 *   `lighting-color`, `bgcolor`) with any value except `none`, `currentColor`,
 *   `inherit`, `transparent` or a `url(#…)` paint reference. That includes a Blade
 *   echo, which could print anything.
 * - A hex or functional colour written straight into a class attribute.
 *
 * An `href="#main"` anchor is not a colour, because the scan reads only these three
 * places. A Blade comment is removed first.
 */
function designSystemFirstRawColour(string $source): ?string
{
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    preg_match_all('/(?<=[\w:\/.-])\[([^\]\[\s"\']+)\]|(?<=[\s"\'])\[([a-z-]+:[^\]\[\s"\']+)\]/i', $code, $brackets, PREG_SET_ORDER);

    foreach ($brackets as $bracket) {
        $value = ($bracket[1] ?? '') !== '' ? $bracket[1] : ($bracket[2] ?? '');

        if (preg_match('/(?:^|[^a-z-])color\s*:/i', $value) === 1 || designSystemColourValueIn($value) !== null) {
            return $bracket[0];
        }
    }

    // Quoted or unquoted: `fill=red` is as valid in HTML as `fill="red"` (Codex, #102).
    preg_match_all('/(?<![\w-])(?:fill|stroke|color|stop-color|flood-color|lighting-color|bgcolor)\s*=\s*(?:(["\'])(.*?)\1|([^\s"\'=<>`]+))/is', $code, $attributes, PREG_SET_ORDER);

    foreach ($attributes as $attribute) {
        $value = ($attribute[1] ?? '') !== '' ? ($attribute[2] ?? '') : ($attribute[3] ?? '');

        if (preg_match('/^\s*(?:none|currentcolor|inherit|transparent|url\(\s*#[\w-]+\s*\))\s*$/i', $value) !== 1) {
            return trim($attribute[0]);
        }
    }

    preg_match_all('/\bclass\s*=\s*"([^"]*)"/i', $code, $classes);

    foreach ($classes[1] as $value) {
        if (preg_match('/#[0-9a-f]{3,8}\b|(?<![a-z-])(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix)\s*\(/i', $value, $match) === 1) {
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
    // Named colours and the other colour syntaxes Tailwind compiles (Codex, #102).
    '<p class="text-[red]">',
    '<p class="hover:bg-[RebeccaPurple]">',
    '<p class="border-[gold]">',
    '<p class="shadow-[0_2px_12px_rgba(13,39,69,.04)]">',
    '<p class="shadow-[0_0_0_1px_red]">',
    '<p class="bg-[linear-gradient(red,blue)]">',
    '<p class="text-[color-mix(in_srgb,#fff,#000)]">',
    '<p class="text-[hwb(0_0%_0%)]">',
    '<p class="[color:red]">',
    '<p class="lg:[background:#fff]">',
    "@class(['p-4', 'text-[red]' => \$on])",
    "\$attributes->class(['bg-[oklch(0.3_0.1_250)]'])",
    '<svg fill="red">',
    '<svg fill="RED">',
    '<svg fill="oklch(0.3 0.1 250)">',
    '<svg stroke="hsl(1 2% 3%)">',
    '<stop stop-color="blue" />',
    '<svg fill="{{ $colour }}">',
    '<feFlood flood-color="black" />',
    '<td bgcolor="#eee">',
    // Unquoted attribute values (Codex, #102, second pass).
    '<svg fill=red>',
    '<path stroke=blue />',
    '<svg fill=#fff>',
    '<svg fill=oklch(0.3_0.1_250)>',
    '<svg stroke={{ $colour }}>',
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
    '<svg fill="currentcolor" stroke="inherit">',
    '<svg fill=none stroke=currentColor>',
    '<svg fill=url(#seal-gradient)>',
    '<svg fill="url(#seal-gradient)">',
    '<svg stroke-width="2" stroke-linecap="round" stroke-linejoin="miter">',
    '<p class="text-[13.5px] leading-[1.75] max-w-[390px] text-sm/[1.6]">',
    // A token named like a CSS colour is a token: `gold` and `navy` here are ours.
    '<p class="text-gold bg-navy/5 border-gold/50 bg-[var(--color-navy)]">',
    "@props(['variant' => 'header'])",
    "<?php \$links[0]; \$row['red']; ?>",
    '<p class="data-[state=open]:block group-[.is-red]:p-4">',
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
| Readable on a phone
|--------------------------------------------------------------------------
|
| Below 1024px, sentences of prose are at least 16px wherever they sit; from 1024px
| they take the design's own size (docs/ENGINEERING.md, "Frontend (phase 4)"). The
| shell's prose is the footer's description and its portal note: each must carry
| the 16px base as its unprefixed size and the design's 13.5px only from `lg:`. A
| bare `text-ui` would make it 13.5px on a phone.
*/

it('sets the footer prose at 16px below 1024px and at the design size from there', function () {
    $html = (string) $this->get('/')->assertOk()->getContent();
    $document = designSystemDocument($html);

    $prose = [
        // The first paragraph in the footer; the copyright line is the last.
        'description' => $document->querySelector('footer p'),
        'portal note' => $document->querySelector('nav[aria-labelledby="footer-services-heading"] li:last-child'),
    ];

    foreach ($prose as $label => $element) {
        expect($element)->not->toBeNull("The footer's {$label} was not found.");

        $classes = preg_split('/\s+/', trim((string) $element->getAttribute('class'))) ?: [];

        expect(in_array('text-base', $classes, true))->toBeTrue("The footer's {$label} is not 16px on a phone.")
            ->and(in_array('lg:text-ui', $classes, true))->toBeTrue("The footer's {$label} is not the design's size from 1024px.")
            ->and(array_intersect(['text-ui', 'text-caption', 'text-sm', 'text-xs'], $classes))->toBeEmpty("The footer's {$label} is smaller than 16px on a phone.");
    }
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

it('sizes an icon and sets the shape of its stroke ends and corners, round and round by default', function () {
    $default = designSystemDocument(Blade::render('<x-public.icon name="phone" :size="13" :stroke="1.7" />'))->querySelector('svg');
    $plain = designSystemDocument(Blade::render('<x-public.icon name="phone" linecap="butt" linejoin="miter" />'))->querySelector('svg');
    $capsOnly = designSystemDocument(Blade::render('<x-public.icon name="graduation-cap" linejoin="miter" />'))->querySelector('svg');

    expect($default->getAttribute('width'))->toBe('13')
        ->and($default->getAttribute('height'))->toBe('13')
        ->and($default->getAttribute('stroke-width'))->toBe('1.7')
        ->and($default->getAttribute('stroke-linecap'))->toBe('round')
        ->and($default->getAttribute('stroke-linejoin'))->toBe('round')
        ->and($plain->getAttribute('stroke-linecap'))->toBe('butt')
        ->and($plain->getAttribute('stroke-linejoin'))->toBe('miter')
        ->and($plain->getAttribute('width'))->toBe('16')
        ->and($plain->getAttribute('stroke-width'))->toBe('2')
        ->and($capsOnly->getAttribute('stroke-linecap'))->toBe('round')
        ->and($capsOnly->getAttribute('stroke-linejoin'))->toBe('miter');
});

it('draws the shell icons with the stroke ends and corners the design gives each one', function () {
    $document = designSystemDocument((string) $this->get('/about')->assertOk()->getContent());

    // The top bar's phone and mail: plain ends and mitred corners, the design draws
    // neither a linecap nor a linejoin there.
    $bar = $document->querySelectorAll('ul[aria-label] svg');

    expect(count($bar))->toBe(2);

    foreach ($bar as $icon) {
        expect($icon->getAttribute('stroke-linecap'))->toBe('butt')
            ->and($icon->getAttribute('stroke-linejoin'))->toBe('miter')
            ->and($icon->getAttribute('stroke-width'))->toBe('2')
            ->and($icon->getAttribute('width'))->toBe('13');
    }

    // The portal's graduation cap, in the header and in the narrow menu: round
    // caps and mitred corners, 14px at weight 2.
    $portal = $document->querySelectorAll('header a svg');

    expect(count($portal))->toBe(2);

    foreach ($portal as $icon) {
        expect($icon->getAttribute('stroke-linecap'))->toBe('round')
            ->and($icon->getAttribute('stroke-linejoin'))->toBe('miter')
            ->and($icon->getAttribute('stroke-width'))->toBe('2')
            ->and($icon->getAttribute('width'))->toBe('14');
    }
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
| The navigation targets the home sections
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

    foreach (designSystemDocument($html)->querySelectorAll('header nav ul a') as $link) {
        $links[] = [$link->getAttribute('href'), $link->hasAttribute('aria-current') ? $link->getAttribute('aria-current') : null];
    }

    return $links;
}

it('links both header menus to home fragments without claiming a current page', function (string $page) {
    $pages = designSystemPages();
    $path = $pages[$page];
    $html = (string) $this->get($path)->assertOk()->getContent();

    $home = $page === 'home' ? '' : url('/');
    $expected = array_map(fn (string $fragment): array => [$home.$fragment, null], ['#about', '#programmes', '#publications', '#verify', '#contact']);
    $links = designSystemNavigationLinks($html);

    expect(array_slice($links, 0, 5))->toBe($expected)
        ->and(array_slice($links, -5))->toBe($expected);

    if ($page === 'home') {
        $document = designSystemDocument($html);

        foreach (['about', 'programmes', 'publications', 'verify', 'contact'] as $id) {
            $targets = $document->querySelectorAll('main section[id="'.$id.'"]');

            expect(count($targets))->toBe(1, "{$id} must have one home target")
                ->and($targets[0]->getAttribute('tabindex'))->toBe('-1');
        }
    }
})->with(['home', 'about', 'contact', 'programmes', 'programme', 'publications', 'article']);

it('dismisses the mobile menu when one of its links is selected', function () {
    $html = (string) $this->get('/')->assertOk()->getContent();
    $document = designSystemDocument($html);
    $nav = $document->querySelector('header nav#public-navigation');
    $open = $document->querySelector('header nav a[href="#public-navigation"]');
    $close = $document->querySelector('header nav a[href="#public-navigation-closed"]');
    $menu = $document->querySelector('header nav ul#public-mobile-menu');

    expect($nav)->not->toBeNull()
        ->and($nav->getAttribute('tabindex'))->toBe('-1')
        ->and($document->querySelector('header nav #public-navigation-closed')?->getAttribute('tabindex'))->toBe('-1')
        ->and($open?->getAttribute('aria-controls'))->toBe('public-mobile-menu')
        ->and($close?->getAttribute('aria-controls'))->toBe('public-mobile-menu')
        ->and(explode(' ', $open?->getAttribute('class') ?? ''))->toContain('group-target/nav:hidden')
        ->and(explode(' ', $close?->getAttribute('class') ?? ''))->toContain('group-target/nav:inline-flex')
        ->and(explode(' ', $menu?->getAttribute('class') ?? ''))->toContain('hidden', 'group-target/nav:block')
        ->and($html)->not->toContain('<script');
});

it('points the Arabic header to its localized home fragments', function (string $routeName, string $home) {
    Route::get('/ar-test-home', fn (): string => 'home')->name('ar.public.home');
    Route::get('/ar-test-navigation', function (): string {
        app()->setLocale('ar');

        return '<header>'.Blade::render("@include('public.partials.navigation')").'</header>';
    })->name($routeName);
    Route::getRoutes()->refreshNameLookups();

    $links = designSystemNavigationLinks((string) $this->get('/ar-test-navigation')->assertOk()->getContent());
    $expected = array_map(fn (string $fragment): array => [($home === '' ? '' : url($home)).$fragment, null], ['#about', '#programmes', '#publications', '#verify', '#contact']);

    expect(array_slice($links, 0, 5))->toBe($expected)
        ->and(array_slice($links, -5))->toBe($expected);
})->with(['home' => ['ar.public.home', ''], 'internal page' => ['ar.public.about', '/ar-test-home']]);

it('does not mark home fragment links current for an internal Arabic route name', function (string $routeName) {
    // T18 registers the Arabic copy of each page under `ar.` plus the English name.
    // The navigation reads only the route's name, so the page the route renders does
    // not matter, and each name is requested through a route of its own.
    $uri = '/ar-temporary/'.$routeName;

    designSystemTemporaryRoute($uri, $routeName, 'public.about');

    $marked = array_values(array_filter(
        designSystemNavigationLinks((string) $this->get($uri)->assertOk()->getContent()),
        fn (array $link): bool => $link[1] !== null,
    ));

    expect($marked)->toBe([]);
})->with([
    'about' => ['ar.public.about'],
    'contact' => ['ar.public.contact'],
    'the programme index' => ['ar.public.programmes.index'],
    'a programme page' => ['ar.public.programmes.show'],
    'the library' => ['ar.public.publications.index'],
    'an article' => ['ar.public.publications.show'],
    'a download' => ['ar.public.publications.download'],
]);

it('marks no link for a route that is not one of the sections', function () {
    // The verifier's route is neither a public page nor in the navigation's list of
    // sections, so a request that arrives under its name marks nothing.
    designSystemTemporaryRoute('/ar-temporary/verify', 'ar.verify.certificates.form', 'public.about');

    $marked = array_filter(
        designSystemNavigationLinks((string) $this->get('/ar-temporary/verify')->assertOk()->getContent()),
        fn (array $link): bool => $link[1] !== null,
    );

    expect($marked)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The shell's measures, which a browser confirms and a test can only pin
|--------------------------------------------------------------------------
|
| Heights and hit areas are layout, so the proof of each is a measurement in the
| browser (the task's report lists them). These pin the classes that produce them,
| so a later edit that drops one fails here instead of being found by eye.
*/

it('gives the top bar real 44px links below 1024px and the design 38px bar from there up', function () {
    $document = designSystemDocument((string) $this->get('/about')->assertOk()->getContent());

    $container = $document->querySelector('ul[aria-label]')->closest('div');
    $classes = explode(' ', $container->getAttribute('class'));

    // No padding of its own below 1024px, so the 44px links fill the bar; the
    // design's 9px from there up.
    expect($classes)->toContain('py-0')->toContain('lg:py-2.25');

    $links = $document->querySelectorAll('ul[aria-label] a');

    expect(count($links))->toBe(2);

    foreach ($links as $link) {
        $linkClasses = explode(' ', $link->getAttribute('class'));

        // A real box, not the invisible hit area, which the page's top edge and the
        // sticky header would clip: 44px below 1024px, the design's line from there.
        expect($linkClasses)->toContain('min-h-11')->toContain('lg:min-h-0')->toContain('items-center')
            ->not->toContain('tap-target');
    }
});

it('sizes the portal pill at the design 42px from 1024px and 44px below', function () {
    $document = designSystemDocument((string) $this->get('/about')->assertOk()->getContent());
    $login = route('filament.student.auth.login');

    $links = [];

    foreach ($document->querySelectorAll('header a') as $link) {
        if ($link->getAttribute('href') === $login) {
            $links[] = $link;
        }
    }

    expect($links)->toHaveCount(2);

    foreach ($links as $link) {
        // `leading-[normal]` is the design's `font` shorthand, which resets the line
        // height; the minimum height is a finger's, so it applies below 1024px only.
        expect(explode(' ', $link->getAttribute('class')))
            ->toContain('leading-[normal]')->toContain('max-lg:min-h-11')->not->toContain('min-h-11');
    }
});

it('gives the navigation row the design line height, and the footer portal link the design line height from 1024px', function () {
    $document = designSystemDocument((string) $this->get('/about')->assertOk()->getContent());

    // The row's links inherit the page's 1.6 in the design (22.4px at 14px), which
    // is not `text-sm`'s own 20px.
    $row = $document->querySelectorAll('header nav > ul a');

    expect(count($row))->toBe(5);

    foreach ($row as $link) {
        expect(explode(' ', $link->getAttribute('class')))->toContain('text-sm/[1.6]')->not->toContain('text-sm');
    }

    // The footer's portal link is a <button> in the design, whose `font` shorthand
    // resets the line height; its neighbours are links and keep the page's.
    $footer = [];

    foreach ($document->querySelectorAll('footer a') as $link) {
        if ($link->getAttribute('href') === route('filament.student.auth.login')) {
            $footer[] = $link;
        }
    }

    expect($footer)->toHaveCount(1)
        ->and(explode(' ', $footer[0]->getAttribute('class')))->toContain('lg:leading-[normal]');
});

it('keeps the open Menu button navy, with its light text, while it is hovered', function () {
    $close = designSystemDocument((string) $this->get('/about')->assertOk()->getContent())->querySelector('header nav a[href="#public-navigation-closed"]');

    // The hover tint is 5% navy, which under the open state's cream text would be
    // 1.1:1. The open and hovered state is a colour of its own.
    expect(explode(' ', $close->getAttribute('class')))
        ->toContain('bg-navy')->toContain('text-cream')->toContain('hover:bg-navy-hover');
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
