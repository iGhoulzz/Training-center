<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\StudentCertificate;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function restyledVerifierDocument(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

it('keeps a labelled native reference form with CSRF and accessible guidance', function () {
    $response = $this->get(route('verify.certificates.form'))->assertSuccessful();
    $document = restyledVerifierDocument((string) $response->getContent());

    expect($document->query('//form')->length)->toBe(1);
    expect($document->evaluate('string(//form/@method)'))->toBe('POST');
    expect($document->evaluate('string(//form/@action)'))->toBe(route('verify.certificates.submit'));
    expect($document->evaluate('string(//form/input[@name="_token"]/@value)'))->not->toBeEmpty();
    expect($document->query('//form//input[@name="reference"][@required]')->length)->toBe(1);
    expect($document->query('//label[@for="reference"]')->length)->toBe(1);
    expect($document->evaluate('string(//input[@name="reference"]/@aria-describedby)'))->toBe('reference-help');
    expect(trim((string) $document->evaluate('string(//*[@id="reference-help"])')))->not->toBeEmpty();
    expect($document->query('//button')->length)->toBe(1);
    expect($document->query('//button[@type="submit"]')->length)->toBe(1);
    expect($document->query('//form[@novalidate]')->length)->toBe(0);
});

it('gives every verifier page a public-site link and one main heading', function (string $page) {
    $certificate = StudentCertificate::factory()->create();
    $url = match ($page) {
        'form' => route('verify.certificates.form'),
        'result' => route('verify.certificates.show', ['reference' => $certificate->reference_number]),
        'miss' => route('verify.certificates.show', ['reference' => 'not-a-reference']),
    };

    $response = $this->get($url);
    $document = restyledVerifierDocument((string) $response->getContent());

    expect($response->status())->toBe($page === 'miss' ? 404 : 200);
    expect($document->evaluate('string(//header//a/@href)'))->toBe(url('/'));
    expect(trim((string) $document->evaluate('string(//header//a)')))->not->toBeEmpty();
    expect($document->query('//main')->length)->toBe(1);
    expect($document->query('//main//h1')->length)->toBe(1);
})->with(['form', 'result', 'miss']);

it('renders each certificate state in an accessible result with only the closed projection', function (string $state, string $label, string $message) {
    // The confirming centre is the brand's full name (P4-T20c), as a sentinel.
    config(['public_site.brand.name' => 'SENTINEL-CONFIRMING-CENTRE']);

    $factory = StudentCertificate::factory();

    if ($state === 'revoked') {
        $factory = $factory->revoked('PRIVATE-REVOCATION-REASON');
    } elseif ($state === 'replaced') {
        $factory = $factory->replaced();
    }

    $certificate = $factory->create([
        'student_name' => 'Amina Zarrouk',
        'course_name' => 'Welding Level 1',
        'completed_on' => '2026-05-01',
        'issued_at' => '2026-05-04 09:00:00',
        ...($state === 'revoked' ? ['revoked_at' => '2026-06-02 22:30:00'] : []),
    ]);

    $response = $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number]))
        ->assertSuccessful()
        ->assertSee($message)
        ->assertDontSee('Contact hours')
        ->assertDontSee('PRIVATE-REVOCATION-REASON');
    $document = restyledVerifierDocument((string) $response->getContent());

    expect($document->query('//section[@aria-labelledby="result-heading"]//h2[@id="result-heading"]')->length)->toBe(1);
    expect($document->query('//dl/dt | //dl/div/dt')->length)->toBe(6);
    expect($document->query('//dl/dd | //dl/div/dd')->length)->toBe(6);

    $labels = array_map(fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($document->query('//dl//dt')));
    expect($labels)->toBe([
        __('verify.field_status'), __('verify.field_student'), __('verify.field_course'),
        __('verify.field_completed_on'), __('verify.field_issued_at'), __('verify.field_confirmation'),
    ]);
    $values = array_map(fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($document->query('//dl//dd')));
    expect($values)->toBe([
        $label, 'Amina Zarrouk', 'Welding Level 1',
        '2026-05-01', '2026-05-04 11:00', 'SENTINEL-CONFIRMING-CENTRE',
    ]);
    expect($document->evaluate('string(//main//a/@href)'))->toBe(route('verify.certificates.form'));
})->with([
    'valid' => ['valid', 'Valid', 'This certificate is valid.'],
    'replaced' => ['replaced', 'Replaced', 'This certificate has been superseded by a more recent certificate and is no longer current.'],
    'revoked' => ['revoked', 'Revoked', 'This certificate was revoked on 2026-06-03 and is no longer valid.'],
]);

it('keeps every miss byte-identical without an input, token or submitted value', function () {
    $responses = [
        $this->post(route('verify.certificates.submit'), []),
        $this->post(route('verify.certificates.submit'), ['reference' => '']),
        $this->post(route('verify.certificates.submit'), ['reference' => 'PRIVATE-MALFORMED-INPUT']),
        $this->get(route('verify.certificates.show', ['reference' => 'PRIVATE-MALFORMED-INPUT'])),
        $this->get(route('verify.certificates.show', ['reference' => 'TC-2099-ABCDEFGH'])),
    ];

    $expectedBody = $responses[0]->getContent();

    foreach ($responses as $response) {
        $response->assertNotFound()->assertDontSee('PRIVATE-MALFORMED-INPUT')->assertDontSee('TC-2099-ABCDEFGH');
        expect($response->getContent())->toBe($expectedBody)->not->toContain('_token');
        $document = restyledVerifierDocument((string) $response->getContent());
        expect($document->query('//form | //input')->length)->toBe(0);
        expect($document->query('//section[@aria-labelledby="result-heading"]//h2[@id="result-heading"]')->length)->toBe(1);
        expect($document->evaluate('string(//main//a/@href)'))->toBe(route('verify.certificates.form'));
    }
});

/*
|--------------------------------------------------------------------------
| The verifier, to the design (P4-T20c)
|--------------------------------------------------------------------------
|
| The three pages stay standalone: one inline <style>, no script, no third-party
| origin, and no stylesheet of the site's. What changed is that they now draw the
| design's CERTIFICATE VERIFIER section, with the centre's real brand, from custom
| properties at the top of that <style>.
|
| THE BRAND TESTS USE SENTINELS, NOT THE REAL COPY, for the reason DesignSystemTest
| gives: a page that hardcoded the name, or read app.name (which is the short display
| name now), would still show the real copy and pass.
*/

/**
 * The page as a DOM that answers CSS selectors.
 */
function verifierDesignDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/**
 * The three pages, by name: the form, a found certificate, and the uniform miss.
 *
 * @return array<string, string> the URL of each
 */
function verifierDesignPages(): array
{
    $certificate = StudentCertificate::factory()->create();

    return [
        'form' => route('verify.certificates.form'),
        'result' => route('verify.certificates.show', ['reference' => $certificate->reference_number]),
        'miss' => route('verify.certificates.show', ['reference' => 'not-a-reference']),
    ];
}

/**
 * The page's own CSS: every <style> that holds its custom properties. With a build,
 * Vite::fonts() adds a <style> of @font-face rules, which is not the page's.
 */
function verifierDesignCss(HTMLDocument $document): string
{
    $css = '';

    foreach ($document->querySelectorAll('style') as $style) {
        if (str_contains($style->textContent, '--navy:')) {
            $css .= $style->textContent;
        }
    }

    return $css;
}

/**
 * The custom properties a stylesheet declares, by name.
 *
 * @return array<string, string>
 */
function verifierDesignTokens(string $css): array
{
    preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $css, $matches, PREG_SET_ORDER);

    $tokens = [];

    foreach ($matches as $match) {
        $tokens[$match[1]] ??= trim($match[2]);
    }

    return $tokens;
}

/**
 * A token's colour as [red, green, blue, alpha]: #rrggbb, `rgb(r g b / a)` or a
 * `var()` of another token, which are the only forms the verifier's CSS declares.
 *
 * @param  array<string, string>  $tokens
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function verifierDesignColour(string $name, array $tokens): array
{
    $value = $tokens[$name] ?? throw new RuntimeException("The page declares no {$name}.");

    if (preg_match('/^var\((--[a-z0-9-]+)\)$/i', $value, $reference) === 1) {
        return verifierDesignColour($reference[1], $tokens);
    }

    if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $value, $hex) === 1) {
        return [(float) hexdec($hex[1]), (float) hexdec($hex[2]), (float) hexdec($hex[3]), 1.0];
    }

    if (preg_match('/^rgb\((\d+) (\d+) (\d+)(?: \/ ([\d.]+))?\)$/i', $value, $rgb) === 1) {
        return [(float) $rgb[1], (float) $rgb[2], (float) $rgb[3], (float) ($rgb[4] ?? 1)];
    }

    throw new RuntimeException("{$name} is {$value}, a colour form this test does not read.");
}

/**
 * A colour laid over an opaque one, as the browser composites it.
 *
 * @param  array{0: float, 1: float, 2: float, 3: float}  $top
 * @param  array{0: float, 1: float, 2: float, 3: float}  $under
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function verifierDesignOver(array $top, array $under): array
{
    return [
        $top[0] * $top[3] + $under[0] * (1 - $top[3]),
        $top[1] * $top[3] + $under[1] * (1 - $top[3]),
        $top[2] * $top[3] + $under[2] * (1 - $top[3]),
        1.0,
    ];
}

/**
 * WCAG 2.x contrast ratio of two opaque colours.
 *
 * @param  array{0: float, 1: float, 2: float, 3: float}  $first
 * @param  array{0: float, 1: float, 2: float, 3: float}  $second
 */
function verifierDesignContrast(array $first, array $second): float
{
    $luminance = function (array $colour): float {
        [$red, $green, $blue] = array_map(
            fn (float $channel): float => ($channel / 255) <= 0.04045
                ? ($channel / 255) / 12.92
                : ((($channel / 255) + 0.055) / 1.055) ** 2.4,
            array_slice($colour, 0, 3),
        );

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    };

    $lighter = max($luminance($first), $luminance($second));
    $darker = min($luminance($first), $luminance($second));

    return ($lighter + 0.05) / ($darker + 0.05);
}

it('measures contrast the way WCAG does', function () {
    // Hand-computed: black on white is 21:1, and the design's own note, cream at .45
    // over the navy page, is 3.95:1, which is why it was raised. A checker that could not
    // fail would make the contrast test below read as enforcement while enforcing nothing.
    expect(verifierDesignContrast([0.0, 0.0, 0.0, 1.0], [255.0, 255.0, 255.0, 1.0]))->toEqualWithDelta(21.0, 0.001);

    $navy = [13.0, 39.0, 69.0, 1.0];
    $designNote = verifierDesignOver([250.0, 246.0, 236.0, 0.45], $navy);

    expect(verifierDesignContrast($designNote, $navy))->toEqualWithDelta(3.95, 0.01)
        ->and(verifierDesignContrast($designNote, $navy))->toBeLessThan(4.5);
});

it('takes every part of the brand from config on every verifier page', function (string $page) {
    config([
        'public_site.brand.name' => 'SENTINEL-FULL-NAME',
        'public_site.brand.short_name' => 'SENTINEL-SHORT',
        'public_site.brand.tagline' => 'SENTINEL-TAGLINE',
        'public_site.brand.name_ar' => 'SENTINEL-ARABIC',
        'public_site.brand.logo' => 'images/brand/sentinel-logo.png',
        'app.name' => 'SENTINEL-APP-NAME',
    ]);

    $html = (string) $this->get(verifierDesignPages()[$page])->getContent();
    $document = verifierDesignDocument($html);
    $home = $document->querySelector('header a');

    // The first link in the header is the brand, and it goes home.
    expect($home?->getAttribute('href'))->toBe(url('/'));

    // The full name is announced by a hidden span; the short name beside it is hidden
    // from assistive technology so it is not read letter by letter.
    expect($home->querySelector('.sr-only')?->textContent)->toBe('SENTINEL-FULL-NAME')
        ->and($home->querySelector('[aria-hidden="true"]')?->textContent)->toBe('SENTINEL-SHORT');

    // The Arabic name is marked as Arabic and right to left, once.
    $arabic = $document->querySelectorAll('header [lang="ar"]');

    expect(count($arabic))->toBe(1)
        ->and($arabic[0]->textContent)->toBe('SENTINEL-ARABIC')
        ->and($arabic[0]->getAttribute('dir'))->toBe('rtl');

    // Hand-written URL, so the expectation is not read back from the config the view reads.
    expect($home->querySelector('img')?->getAttribute('src'))->toBe(url('/images/brand/sentinel-logo.png'));

    // The short display name (app.name) is not the brand, and the lockup draws no tagline.
    expect($html)->not->toContain('SENTINEL-APP-NAME')->not->toContain('SENTINEL-TAGLINE');
})->with(['form', 'result', 'miss']);

it('draws the logo as a decorative image on this origin and nothing else as an image', function (string $page) {
    $document = verifierDesignDocument((string) $this->get(verifierDesignPages()[$page])->getContent());
    $images = $document->querySelectorAll('img');

    expect(count($images))->toBe(1);

    // Decorative: the name beside it carries the meaning, so the alt text is present and empty.
    expect($images[0]->hasAttribute('alt'))->toBeTrue()
        ->and($images[0]->getAttribute('alt'))->toBe('')
        ->and($images[0]->getAttribute('src'))->toBe(url('/images/brand/asclst-logo.jpg'))
        ->and(parse_url($images[0]->getAttribute('src'), PHP_URL_HOST))->toBe(parse_url(url('/'), PHP_URL_HOST))
        ->and($images[0]->getAttribute('width'))->toBe('44')
        ->and($images[0]->getAttribute('height'))->toBe('44');
})->with(['form', 'result', 'miss']);

it('loads the fonts only inside the built-assets guard, computed as the public layout computes it', function (string $file) {
    $source = (string) file_get_contents(resource_path("views/verify/{$file}.blade.php"));
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    // CI runs the suite before it builds assets, so a font call outside the guard would
    // ask for a manifest that does not exist and fail every verifier page.
    expect(preg_match('/@if\s*\(\s*\$stylesheetBuilt\s*\)(.*?)@endif/s', $code, $guarded))->toBe(1)
        ->and($guarded[1])->toContain('Vite::fonts(')->toContain("'instrument-serif'")->toContain("'ibm-plex-sans-arabic'")
        ->and(substr_count($code, 'Vite::fonts('))->toBe(1)
        ->and(substr_count($code, '@vite'))->toBe(0)
        ->and($code)->toContain("\$stylesheetBuilt = file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'));");
})->with(['form', 'show', 'not-found']);

it('emits no font tag without a build, and only same-origin ones with it, the same bytes every time', function () {
    $pages = verifierDesignPages();
    $originalPublicPath = public_path();
    $unbuilt = sys_get_temp_dir().DIRECTORY_SEPARATOR.'training-center-verifier-unbuilt-'.Str::uuid();
    $built = sys_get_temp_dir().DIRECTORY_SEPARATOR.'training-center-verifier-built-'.Str::uuid();
    File::makeDirectory($unbuilt);
    File::makeDirectory($built.'/build', recursive: true);
    File::put($built.'/build/manifest.json', '{}');
    File::put($built.'/build/fonts-manifest.json', json_encode([
        'version' => 1,
        'families' => ['instrument-serif' => ['name' => 'Instrument Serif'], 'ibm-plex-sans-arabic' => ['name' => 'IBM Plex Sans Arabic']],
        'preloads' => [['alias' => 'instrument-serif', 'file' => 'assets/serif-fixture.woff2', 'type' => 'font/woff2', 'crossorigin' => 'anonymous']],
        'style' => [
            'familyStyles' => [
                'instrument-serif' => '@font-face { font-family: "Instrument Serif"; src: url("/build/assets/serif-fixture.woff2") format("woff2"); }',
                'ibm-plex-sans-arabic' => '@font-face { font-family: "IBM Plex Sans Arabic"; src: url("/build/assets/arabic-fixture.woff2") format("woff2"); }',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        // No build: nothing for the fonts to read, and the page is still complete.
        app()->usePublicPath($unbuilt);

        foreach ($pages as $label => $url) {
            $html = (string) $this->get($url)->getContent();

            expect($html)->toContain('<h1>')
                ->not->toContain('rel="preload"')
                ->not->toContain('@font-face');
        }

        // A build: the preload is on this origin, the face is declared, and a second
        // request for the same page is byte for byte the first (Vite remembers the
        // preloads it has emitted for the life of the process).
        app()->usePublicPath($built);

        foreach ($pages as $label => $url) {
            $first = (string) $this->get($url)->getContent();
            $second = (string) $this->get($url)->getContent();
            $document = verifierDesignDocument($first);
            $preloads = $document->querySelectorAll('link[rel="preload"]');

            expect(count($preloads))->toBe(1, "{$label}: expected the one preload the fixture declares.")
                ->and($preloads[0]->getAttribute('href'))->toBe(url('/build/assets/serif-fixture.woff2'))
                ->and($first)->toContain('font-family: "Instrument Serif"')->toContain('font-family: "IBM Plex Sans Arabic"')
                ->and($second)->toBe($first, "{$label}: a second render differs from the first.");
        }
    } finally {
        app()->usePublicPath($originalPublicPath);
        Vite::flush();
        File::deleteDirectory($unbuilt);
        File::deleteDirectory($built);
    }
});

it('gives each certificate state its own colours', function (string $state, string $foreground) {
    $factory = StudentCertificate::factory();

    if ($state === 'revoked') {
        $factory = $factory->revoked('Any reason');
    } elseif ($state === 'replaced') {
        $factory = $factory->replaced();
    }

    $certificate = $factory->create();
    $document = verifierDesignDocument((string) $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number]))->getContent());
    $card = $document->querySelector('section.result');

    // Hand-written from the design's RESULTS: valid #7bd8a0, replaced #e0c179, revoked #f0908f.
    expect($card?->classList->contains("result--{$state}"))->toBeTrue()
        ->and(verifierDesignTokens(verifierDesignCss($document))["--{$state}-fg"])->toBe($foreground);
})->with([
    'valid' => ['valid', '#7bd8a0'],
    'replaced' => ['replaced', '#e0c179'],
    'revoked' => ['revoked', '#f0908f'],
]);

it('draws the miss in the neutral colours, with no status modifier', function () {
    $document = verifierDesignDocument((string) $this->get(route('verify.certificates.show', ['reference' => 'not-a-reference']))->getContent());
    $card = $document->querySelector('section.result');

    expect($card?->getAttribute('class'))->toBe('result')
        ->and(verifierDesignTokens(verifierDesignCss($document))['--neutral-fg'])->toBe('var(--cream)');
});

it('carries no inline style attribute on any verifier page or in its source', function () {
    foreach (verifierDesignPages() as $label => $url) {
        $document = verifierDesignDocument((string) $this->get($url)->getContent());

        expect(count($document->querySelectorAll('[style]')))->toBe(0, "{$label}: an element carries a style attribute.");
    }

    foreach (['form', 'show', 'not-found'] as $file) {
        // A <style> block is the page's one allowed stylesheet; a style ATTRIBUTE is not.
        $source = (string) file_get_contents(resource_path("views/verify/{$file}.blade.php"));
        $withoutBlock = (string) preg_replace('/<style\b.*?<\/style>/s', ' ', $source);

        expect(preg_match('/(?<![\w-])style\s*=/i', $withoutBlock))->toBe(0, "{$file}.blade.php has a style attribute.");
    }
});

it('keeps every text colour at 4.5:1 over the navy page, its glow, its panel and the result card', function () {
    foreach (verifierDesignPages() as $label => $url) {
        $document = verifierDesignDocument((string) $this->get($url)->getContent());
        $tokens = verifierDesignTokens(verifierDesignCss($document));
        $colour = fn (string $name): array => verifierDesignColour($name, $tokens);

        // The page is navy under the gradient; the glow is the gradient at its brightest
        // point, the worst case behind the text in the left column. The panel is a tint
        // over the page, and the field a tint over the panel.
        $page = $colour('--navy');
        $glow = verifierDesignOver($colour('--glow'), $page);
        $panel = verifierDesignOver($colour('--panel-fill'), $page);

        $pairs = [
            'cream on the page' => [$colour('--cream'), $page],
            'cream on the glow' => [$colour('--cream'), $glow],
            'the eyebrow on the page' => [$colour('--gold'), $page],
            'the eyebrow on the glow' => [$colour('--gold'), $glow],
            'the intro on the page' => [$colour('--text-intro'), $page],
            'the intro on the glow' => [$colour('--text-intro'), $glow],
            'the note on the page' => [$colour('--text-note'), $page],
            'the note on the glow' => [$colour('--text-note'), $glow],
            'the button text on gold' => [$colour('--ink'), $colour('--gold')],
            'the button text on gold, hovered' => [$colour('--ink'), $colour('--gold-light')],
        ];

        if ($label === 'form') {
            $field = verifierDesignOver($colour('--field-fill'), $panel);

            $pairs += [
                'the label on the panel' => [$colour('--text-label'), $panel],
                'the hint on the panel' => [$colour('--text-sub'), $panel],
                'the placeholder on the field' => [$colour('--text-placeholder'), $field],
                'the typed text on the field' => [$colour('--cream'), $field],
            ];
        } else {
            $states = $label === 'result' ? ['neutral', 'valid', 'replaced', 'revoked'] : ['neutral'];

            foreach ($states as $state) {
                $card = verifierDesignOver($colour("--{$state}-fill"), $panel);

                $pairs["the {$state} headline on its card"] = [$colour("--{$state}-fg"), $card];
                $pairs["the {$state} sub-line on its card"] = [$colour('--text-sub'), $card];

                if ($label === 'result') {
                    $pairs["the {$state} row label on its card"] = [$colour('--text-row-label'), $card];
                    $pairs["the {$state} row value on its card"] = [$colour('--cream'), $card];
                }
            }
        }

        foreach ($pairs as $name => [$foreground, $background]) {
            expect(verifierDesignContrast(verifierDesignOver($foreground, $background), $background))
                ->toBeGreaterThanOrEqual(4.5, "{$label}: {$name} is under 4.5:1.");
        }
    }
});

it('gives every link and button a touch target of at least 44px', function (string $file) {
    $source = (string) file_get_contents(resource_path("views/verify/{$file}.blade.php"));

    foreach (['.back-link', '.button'] as $selector) {
        preg_match('/(?<![\w-])'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $source, $rule);

        expect(str_contains($rule[1] ?? '', 'min-block-size: 44px'))->toBeTrue("{$file}: {$selector} has no 44px minimum height.");
    }

    // The brand link wraps a 44px mark.
    expect($source)->toContain('inline-size: 44px;')->toContain('block-size: 44px;');

    if ($file === 'form') {
        preg_match('/input\[type="text"\]\s*\{([^}]*)\}/', $source, $input);

        expect($input[1] ?? '')->toContain('min-block-size: 44px')->toContain('font-size: 1rem');
    }
})->with(['form', 'show', 'not-found']);
