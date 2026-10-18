<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Publications\Models\Article;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Livewire marks "a component rendered this request" in static state that outlives
// the test, and injects its script into the next page rendered. Flushed before each test.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| Direction, the Arabic face and the Arabic brand (P4-T18a)
|--------------------------------------------------------------------------
|
| WHAT THIS HOLDS. Each public page and each of the three verifier pages says what it
| is in its `<html>` (language and direction), prints the centre's name in the page's
| language, draws its dates with the language's month names, and reaches the browser with
| the CSS that makes Arabic render as Arabic: the UI face where the display face has no
| glyphs, no letter-spacing, and every direction-bearing decoration mirrored.
|
| A SENTINEL FOR EVERY NAME. The centre's names come from config/public_site.php, so each
| test sets them to words of its own and looks for those. A page that hardcoded the real
| name, or took the English name where the Arabic belonged, would still print real copy
| and pass a test that looked for it.
*/

const DIRECTION_BRAND = [
    'public_site.brand.name' => 'SENTINEL-ENGLISH-NAME',
    'public_site.brand.name_ar' => 'SENTINEL-ARABIC-NAME',
    'public_site.brand.short_name' => 'SENTINEL-SHORT',
    'public_site.brand.tagline' => 'SENTINEL-TAGLINE',
];

/**
 * Every public page and the three verifier pages, as label => English path, with the
 * fixtures the parametric ones need.
 *
 * @return array<string, string>
 */
function directionPages(): array
{
    $course = Course::factory()->create(['name_en' => 'Direction Course', 'name_ar' => 'دورة الاتجاه']);
    $article = Article::factory()->published()->create(['title_en' => 'Direction Article', 'title_ar' => 'مقال الاتجاه', 'slug' => 'direction-article']);
    $certificate = StudentCertificate::factory()->create();

    return [
        'home' => '/',
        'about' => '/about',
        'programmes' => '/programmes',
        'a programme' => "/programmes/{$course->getKey()}",
        'contact' => '/contact',
        'publications' => '/publications',
        'an article' => "/publications/{$article->slug}",
        'the verifier form' => '/verify/certificates',
        'a verified certificate' => "/verify/certificates/{$certificate->reference_number}",
    ];
}

/** The Arabic URL of an English one. */
function directionArabicPath(string $english): string
{
    return $english === '/' ? '/ar' : "/ar{$english}";
}

function directionDocument(TestResponse $response): HTMLDocument
{
    return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
}

/*
|--------------------------------------------------------------------------
| lang and dir
|--------------------------------------------------------------------------
*/

it('puts the language and the direction on every public page and verifier page, in each language', function () {
    foreach (directionPages() as $label => $path) {
        $english = directionDocument($this->get($path)->assertOk())->querySelector('html');
        $arabic = directionDocument($this->get(directionArabicPath($path))->assertOk())->querySelector('html');

        expect($english->getAttribute('lang'))->toBe('en', "{$label}")
            ->and($english->getAttribute('dir'))->toBe('ltr', "{$label}")
            ->and($arabic->getAttribute('lang'))->toBe('ar', "{$label}")
            ->and($arabic->getAttribute('dir'))->toBe('rtl', "{$label}");
    }
});

it('keeps the language pill left to right on a right-to-left page, so EN stays on the left', function () {
    foreach (directionPages() as $label => $path) {
        foreach ([$path, directionArabicPath($path)] as $url) {
            $pill = directionDocument($this->get($url)->assertOk())->querySelector('[data-locale-switch]');

            expect($pill)->not->toBeNull("{$url}: no language switch.")
                ->and($pill->getAttribute('dir'))->toBe('ltr', "{$url}: the pill follows the page's direction.");
        }
    }
});

/*
|--------------------------------------------------------------------------
| The centre's name, in the page's language
|--------------------------------------------------------------------------
*/

it('sets the Arabic home heading in the Arabic name, with the emphasis phrase from its own key', function () {
    // The Arabic name is built to contain the Arabic phrase (the key's comment says so).
    config(['public_site.brand.name_ar' => 'المركز العربي التخصصي للدراسات القانونية والتدريب']);

    $heading = directionDocument($this->get('/ar')->assertOk())->querySelector('h1');
    $emphasis = $heading->querySelector('span');

    // Hand-written: the key's phrase is two words, inside the name, and set apart.
    expect($heading->textContent)->toBe('المركز العربي التخصصي للدراسات القانونية والتدريب')
        ->and($emphasis?->textContent)->toBe('للدراسات القانونية');
});

it('prints the Arabic name on Arabic pages and the English name on English ones: title, accessible name, copyright and heading', function () {
    config(DIRECTION_BRAND);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));

    foreach (directionPages() as $label => $path) {
        if (str_contains($path, 'verify')) {
            continue;
        }

        $arabic = $this->get(directionArabicPath($path))->assertOk();
        $english = $this->get($path)->assertOk();
        $arabicDocument = directionDocument($arabic);
        $englishDocument = directionDocument($english);

        // The title: the Arabic name alone on the home page, "page | name" elsewhere.
        expect($arabicDocument->querySelector('title')->textContent)->toContain('SENTINEL-ARABIC-NAME')->not->toContain('SENTINEL-ENGLISH-NAME')
            ->and($englishDocument->querySelector('title')->textContent)->toContain('SENTINEL-ENGLISH-NAME')->not->toContain('SENTINEL-ARABIC-NAME');

        // The header link's hidden name and the footer's copyright line.
        expect($arabicDocument->querySelector('header a .sr-only')->textContent)->toBe('SENTINEL-ARABIC-NAME')
            ->and($englishDocument->querySelector('header a .sr-only')->textContent)->toBe('SENTINEL-ENGLISH-NAME')
            ->and(trim((string) preg_replace('/\s+/', ' ', $arabicDocument->querySelector('footer')->textContent)))->toContain('© 2026 SENTINEL-ARABIC-NAME')
            ->and(trim((string) preg_replace('/\s+/', ' ', $englishDocument->querySelector('footer')->textContent)))->toContain('© 2026 SENTINEL-ENGLISH-NAME');

        // The English name is nowhere on an Arabic page.
        expect(str_contains((string) $arabic->getContent(), 'SENTINEL-ENGLISH-NAME'))->toBeFalse("{$label}: the Arabic page prints the English name.");

        // And the Arabic name on an English page is only ever the lang="ar" element that
        // prints it as an Arabic name: the top bar's and the footer's.
        $total = substr_count((string) $english->getContent(), 'SENTINEL-ARABIC-NAME');
        $marked = 0;

        foreach ($englishDocument->querySelectorAll('[lang="ar"][dir="rtl"]') as $element) {
            $marked += substr_count($element->textContent, 'SENTINEL-ARABIC-NAME');
        }

        expect($total)->toBe(2, "{$label}: the Arabic name should appear twice on an English page.")
            ->and($marked)->toBe(2, "{$label}: the Arabic name appears outside its lang=\"ar\" element.");
    }
});

it('prints the Arabic name as the home page heading, and the English name on the English one', function () {
    config(DIRECTION_BRAND);

    expect(directionDocument($this->get('/ar')->assertOk())->querySelector('h1')->textContent)->toBe('SENTINEL-ARABIC-NAME')
        ->and(directionDocument($this->get('/')->assertOk())->querySelector('h1')->textContent)->toBe('SENTINEL-ENGLISH-NAME');
});

it('marks the Latin short name and tagline as English, so the Arabic page does not draw them in its Arabic face', function () {
    config(DIRECTION_BRAND);

    $document = directionDocument($this->get('/ar')->assertOk());

    foreach (['SENTINEL-SHORT', 'SENTINEL-TAGLINE'] as $text) {
        $found = 0;

        foreach ($document->querySelectorAll('[lang="en"]') as $element) {
            $found += $element->textContent === $text ? 1 : 0;
        }

        expect($found)->toBeGreaterThan(0, "{$text} is not marked lang=\"en\".");
    }
});

/*
|--------------------------------------------------------------------------
| Dates in the language's own months
|--------------------------------------------------------------------------
*/

it('draws a programme\'s start date and an article\'s date with the language\'s month names', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));

    $course = Course::factory()->create(['name_en' => 'Dated Course', 'name_ar' => 'دورة مؤرخة', 'total_hours' => 48]);
    Batch::factory()->for($course)->create(['start_date' => '2026-11-02', 'end_date' => '2027-01-30', 'price' => '1450.000', 'total_hours' => 48]);
    Article::factory()->published()->create(['title_en' => 'Dated Paper', 'title_ar' => 'ورقة مؤرخة', 'issued_on' => '2026-03-09']);

    $english = $this->get('/programmes')->assertOk()->getContent().$this->get('/publications')->assertOk()->getContent();
    $arabic = $this->get('/ar/programmes')->assertOk()->getContent().$this->get('/ar/publications')->assertOk()->getContent();

    // Written by hand: 'd M Y' in each language. November is نوفمبر and March مارس; the
    // digits stay Western, as Libya writes them.
    expect($english)->toContain('02 Nov 2026')->toContain('09 Mar 2026')
        ->and($arabic)->toContain('02 نوفمبر 2026')->toContain('09 مارس 2026')
        ->and($arabic)->not->toContain('Nov 2026')->not->toContain('Mar 2026');
});

/*
|--------------------------------------------------------------------------
| The CSS that makes Arabic Arabic
|--------------------------------------------------------------------------
*/

/**
 * The declaration block of the first rule in a stylesheet whose selector list contains
 * a selector, or null.
 */
function directionRuleBody(string $css, string $selector): ?string
{
    // A selector list may span lines and be followed by more selectors, so the body is
    // the first `{ ... }` after the selector, with no brace between.
    if (preg_match('/'.preg_quote($selector, '/').'\s*(?:,[^{]*)?\{([^{}]*)\}/', $css, $match) !== 1) {
        return null;
    }

    return $match[1];
}

it('sets Arabic in the UI face, not the display face, and takes the italic off it', function () {
    $css = (string) file_get_contents(resource_path('css/public.css'));

    // The UI face is the one the theme names first for Arabic, so the rule points at
    // the token and the token at the font.
    $body = directionRuleBody($css, '.font-display:lang(ar)');

    expect($body)->not->toBeNull()
        ->and($body)->toContain('font-family: var(--font-sans)')->toContain('font-weight: 600')->toContain('font-style: normal')
        ->and($css)->toMatch("/--font-sans:\s*'IBM Plex Sans Arabic'/")
        ->and(directionRuleBody($css, '.italic:lang(ar)'))->toContain('font-style: normal');
});

it('gives an Arabic display heading the room Arabic needs', function () {
    $css = (string) file_get_contents(resource_path('css/public.css'));

    expect(directionRuleBody($css, ':is(h1, h2, h3, h4).font-display:lang(ar)'))->toContain('line-height: 1.4');
});

it('takes the letter-spacing off Arabic, and leaves the Latin reference field its own', function () {
    $css = (string) file_get_contents(resource_path('css/public.css'));

    // Cursive letters pulled apart by tracking are the defect; a code typed into an
    // input is Latin and keeps what the design gives it.
    expect(directionRuleBody($css, '*:lang(ar):not(input)'))->toContain('letter-spacing: normal');
});

it('mirrors every glow that the markup positions from the left, by the hand-written complement', function () {
    // The x position of each radial glow in LTR and where it sits in RTL, by hand: the
    // complement to 100, so the glow stays behind the same content when the page flips.
    $pairs = ['78' => '22', '12' => '88', '20' => '80', '84' => '16', '80' => '20'];

    $sources = '';

    foreach (['public/home.blade.php', 'components/public/page-header.blade.php'] as $file) {
        $sources .= (string) file_get_contents(resource_path("views/{$file}"));
    }

    preg_match_all('/(?<![:\w-])bg-radial-\[circle_at_(\d+)%/', $sources, $physical);
    preg_match_all('/rtl:bg-radial-\[circle_at_(\d+)%/', $sources, $mirrored);

    expect($physical[1])->not->toBeEmpty()
        ->and(array_map(fn (string $x): string => $pairs[$x], $physical[1]))->toBe($mirrored[1]);
});

it('mirrors every arrow that points onward or back', function () {
    $offenders = [];

    foreach (['public', 'components/public'] as $directory) {
        foreach (File::allFiles(resource_path("views/{$directory}")) as $file) {
            preg_match_all('/<x-public\.icon\s[^>]*name="arrow-(?:left|right)"[^>]*>/', (string) file_get_contents((string) $file->getRealPath()), $icons);

            foreach ($icons[0] as $icon) {
                if (! str_contains($icon, 'rtl:-scale-x-100')) {
                    $offenders[] = $file->getFilename().': '.$icon;
                }
            }
        }
    }

    expect($offenders)->toBeEmpty("These arrows do not flip in Arabic:\n  ".implode("\n  ", $offenders));
});

it('restates the Arabic rules in the verifier\'s own stylesheet, once, in each of its three pages', function () {
    $reference = StudentCertificate::factory()->create()->reference_number;

    foreach (['/ar/verify/certificates', "/ar/verify/certificates/{$reference}", '/ar/verify/certificates/not-a-real-reference'] as $url) {
        $document = directionDocument($this->get($url));
        $css = '';

        foreach ($document->querySelectorAll('style') as $style) {
            $css .= str_contains($style->textContent, '--navy:') ? $style->textContent : '';
        }

        // One inline stylesheet that carries the page's tokens, and the Arabic block in it.
        expect(substr_count($css, '--navy:'))->toBe(1, "{$url}")
            ->and(directionRuleBody($css, '*:lang(ar):not(input)'))->toContain('letter-spacing: normal')
            ->and(directionRuleBody($css, 'h1:lang(ar)'))->toContain('font-family: var(--font-ui)')
            // The glow behind the intro is mirrored from 20% to the complement, by hand.
            ->and(directionRuleBody($css, 'html[dir="rtl"] body'))->toContain('circle at 80% 30%');
    }
});

it('serves the compiled stylesheet with the Arabic type rules and the view transition, when a build exists', function () {
    $built = glob(public_path('build/assets/public-*.css')) ?: [];
    $css = $built === [] ? '' : (string) file_get_contents($built[0]);

    // The build lowers and minifies the source, so the checks are on the parts that
    // survive: the pseudo-class, the property and the at-rule.
    expect($css)->toContain(':lang(ar)')->toContain('view-transition-name:locale-thumb')->toContain('@view-transition')->toContain('letter-spacing:normal');
})->skip(fn (): bool => (glob(public_path('build/assets/public-*.css')) ?: []) === [], 'needs a built public stylesheet (npm run build)');
