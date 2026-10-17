<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Domain\Publications\Models\Article;
use Carbon\CarbonImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

uses(RefreshDatabase::class);

// Livewire marks "a component rendered this request" in static state that outlives
// the test, and injects its script into the next public page rendered. Flushed
// before each test, as the other public test files do.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The public site in Arabic (P4-T17a)
|--------------------------------------------------------------------------
|
| WHAT THIS PROVES. With the locale set to Arabic, every public page and all three
| verifier pages render with none of the English catalogue text left in them. That
| is a property of the rendered pages, which CatalogueParityTest cannot see: a view
| can still read a key the Arabic file lacks, or print an English string of its own
| beside the translated ones.
|
| HOW THE LOCALE IS SET. The public pages are sessionless, with no user and no
| locale middleware: `SetLocale` is panel authentication middleware and never runs
| for them. The only input to their language is the application's locale, so these
| tests call `app()->setLocale('ar')` before the request, and the page renders in
| Arabic exactly as it will once P4-T18a's `/ar` URLs set it per request.
|
| WHAT THAT ALONE IS NOT ENOUGH FOR. Every link on a public page goes through
| PublicRoute::url(), which under a non-English locale needs an `ar.`-named twin
| of the English route and throws when it is missing (it never falls back to
| English). Those twins are T18a's work, so publicArabicUseArabic() registers
| throwaway ones from the router's own English routes, the way PublicRouteTest and
| DesignSystemTest do. They only have to EXIST for the links to resolve; the
| pages are still requested at their English URLs.
|
| T18a REPLACES BOTH SHIMS in the one place that issues requests,
| publicArabicRenderedPages(): the request goes to the real `/ar` URL, which sets
| the locale itself, and publicArabicUseArabic() goes.
|
| THE NEEDLES ARE COMPUTED, NOT LISTED. Every string of the three English
| catalogues is turned into a pattern at test time, so a key added tomorrow is
| checked from the day it exists. A line with placeholders (`Starts :date`) becomes
| a pattern with a gap where the placeholder is; a plural line becomes one pattern
| per segment. Three kinds of value are not searched for:
|
|   - one that is the same in Arabic as in English (a code, a date pattern): it
|     cannot tell the two languages apart, and CatalogueParityTest lists each one
|     with its reason;
|   - one whose own words, with the placeholders taken out, hold no run of three
|     letters (`© :year :name`, `:page | :name`): there is nothing to look for;
|   - any word that is not catalogue text. The centre's name and contact details
|     come from config/public_site.php and are set to sentinels, and the fixtures
|     (articles, courses, a student) use words no catalogue contains, so brand,
|     references and user data are outside the search by construction. A test
|     below checks the fixtures really are.
|
| WHAT A MATCH IS. Text is read from the parsed page: its text nodes, and the
| attributes that carry words (alt, title, aria-label, placeholder, a meta tag's
| content). Script and style bodies are code. A match is whole-word: "Search" does
| not match inside "Searching", and it does not match inside Arabic.
|
| A CONTROL RUNS THE SAME PAGES IN ENGLISH and must find the English text, or the
| search would be passing because it looks at nothing. A THIRD CHECK goes the
| other way: wherever an English line shows on an English page, an Arabic line
| must show on the same Arabic page, because a key the Arabic file holds but blank
| renders as nothing, which the English search cannot see.
*/

/** The catalogues this file reads. */
const PUBLIC_ARABIC_CATALOGUES = ['public', 'publications', 'verify'];

/**
 * Every line of the three catalogues in one language, by the key the application
 * asks the translator for.
 *
 * @return array<string, string>
 */
function publicArabicCatalogueLines(string $locale): array
{
    static $lines = [];

    if (! isset($lines[$locale])) {
        $lines[$locale] = [];

        foreach (PUBLIC_ARABIC_CATALOGUES as $name) {
            $file = require dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$name.'.php';

            foreach (Arr::dot($file) as $key => $value) {
                if (is_string($value)) {
                    $lines[$locale]["{$name}.{$key}"] = $value;
                }
            }
        }
    }

    return $lines[$locale];
}

/**
 * The patterns that find one catalogue value in rendered text, one per plural
 * segment, or none when the value is too short to look for.
 *
 * A placeholder becomes a gap of up to 300 characters. Whitespace is collapsed on
 * both sides. Unanchored, a pattern also refuses to start or end inside a word, so
 * "Search" is not found in "Searching"; anchored, it must be the whole string.
 *
 * @return list<string> preg patterns
 */
function publicArabicPatterns(string $value, bool $anchored): array
{
    $segments = preg_match('/\S\|\S/u', $value) === 1 ? explode('|', $value) : [$value];
    $patterns = [];

    foreach ($segments as $segment) {
        $segment = trim((string) preg_replace('/\s+/u', ' ', $segment));
        $parts = preg_split('/:[A-Za-z_][A-Za-z0-9_]*/', $segment);

        // The rule for "too short to be meaningful". Searching inside larger text
        // needs the value's own words, with every placeholder taken out, to hold a
        // run of at least three letters, or "of" would be found in any sentence. An
        // anchored pattern must equal the whole string, so one letter is enough
        // (the dinar's "د.ل" is two); a value with no letter at all is skipped.
        $minimum = $anchored ? '\p{L}' : '\p{L}{3,}';

        if ($parts === false || preg_match("/{$minimum}/u", implode('', $parts)) !== 1) {
            continue;
        }

        $body = implode('.{0,300}?', array_map(fn (string $part): string => preg_quote($part, '/'), $parts));

        if ($anchored) {
            $patterns[] = '/^'.$body.'$/u';

            continue;
        }

        $first = $parts[0];
        $last = $parts[array_key_last($parts)];
        $before = $first !== '' && preg_match('/^[\p{L}\p{N}]/u', $first) === 1 ? '(?<![\p{L}\p{N}])' : '';
        $after = $last !== '' && preg_match('/[\p{L}\p{N}]$/u', $last) === 1 ? '(?![\p{L}\p{N}])' : '';

        $patterns[] = '/'.$before.$body.$after.'/u';
    }

    return $patterns;
}

/**
 * The patterns for every key of the catalogues in one language.
 *
 * The English needles leave out a key whose Arabic is the English character for
 * character: it cannot tell the languages apart, and CatalogueParityTest allows
 * each such key only with a stated reason.
 *
 * @return array<string, list<string>> key => patterns
 */
function publicArabicNeedles(string $locale, bool $anchored): array
{
    $arabic = publicArabicCatalogueLines('ar');
    $needles = [];

    foreach (publicArabicCatalogueLines($locale) as $key => $value) {
        if ($locale === 'en' && ($arabic[$key] ?? null) === $value) {
            continue;
        }

        $patterns = publicArabicPatterns($value, $anchored);

        if ($patterns !== []) {
            $needles[$key] = $patterns;
        }
    }

    return $needles;
}

/**
 * The attributes that carry words a reader can see or hear. Class names, links,
 * identifiers and the like are not words anyone reads.
 */
const PUBLIC_ARABIC_READABLE_ATTRIBUTES = ['alt', 'title', 'aria-label', 'aria-description', 'placeholder', 'content', 'value', 'label'];

/**
 * Everything a page says: its text, and the attributes that carry words, each as
 * one whitespace-collapsed string. The bodies of script and style are code.
 *
 * @return list<string>
 */
function publicArabicReadableStrings(string $html): array
{
    $strings = [];

    $walk = function (Node $node) use (&$walk, &$strings): void {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $strings[] = $child->data;

                continue;
            }

            if (! $child instanceof Element || in_array(strtolower($child->localName), ['script', 'style'], true)) {
                continue;
            }

            foreach (PUBLIC_ARABIC_READABLE_ATTRIBUTES as $attribute) {
                if ($child->hasAttribute($attribute)) {
                    $strings[] = $child->getAttribute($attribute);
                }
            }

            $walk($child);
        }
    };

    $walk(HTMLDocument::createFromString($html, LIBXML_NOERROR));

    $collapsed = array_map(fn (string $string): string => trim((string) preg_replace('/\s+/u', ' ', $string)), $strings);

    return array_values(array_filter($collapsed, fn (string $string): bool => $string !== ''));
}

/**
 * The keys found anywhere in a page, with the text that matched, using the
 * unanchored patterns: a value counts when it appears inside a larger string too.
 *
 * @param  list<string>  $strings  publicArabicReadableStrings()
 * @param  array<string, list<string>>  $needles
 * @return array<string, string> key => the matching text
 */
function publicArabicFound(array $strings, array $needles): array
{
    // Strings are joined by a newline, which a gap never crosses, so no match can
    // run from one element into the next.
    $text = implode("\n", $strings);
    $found = [];

    foreach ($needles as $key => $patterns) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                $found[$key] = mb_substr($match[0], 0, 80);

                continue 2;
            }
        }
    }

    return $found;
}

/**
 * The keys whose anchored pattern equals a whole string of the page.
 *
 * A string that equals a value with no placeholder belongs to that value alone.
 * "All publications" is the whole of two lines, and it would also fit the gap in
 * `:count publications`, which is not what the page is showing.
 *
 * @param  list<string>  $strings
 * @param  array<string, list<string>>  $needles  anchored
 * @return list<string>
 */
function publicArabicFoundExactly(array $strings, array $needles): array
{
    $found = [];

    foreach ($strings as $string) {
        $plain = [];
        $gapped = [];

        foreach ($needles as $key => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $string) !== 1) {
                    continue;
                }

                if (str_contains($pattern, '.{0,300}?')) {
                    $gapped[] = $key;
                } else {
                    $plain[] = $key;
                }

                continue 2;
            }
        }

        foreach ($plain !== [] ? $plain : $gapped as $key) {
            $found[$key] = true;
        }
    }

    return array_keys($found);
}

/**
 * The words the fixtures use. None is a word of any catalogue; the test further
 * down checks that rather than trusting this comment.
 *
 * @var array<string, string>
 */
const PUBLIC_ARABIC_BRAND_SENTINELS = [
    'public_site.brand.name' => 'SENTINEL-BRAND-NAME',
    'public_site.brand.short_name' => 'SENTINEL-BRAND-SHORT',
    'public_site.brand.tagline' => 'SENTINEL-BRAND-TAGLINE',
    'public_site.brand.name_ar' => 'SENTINEL-BRAND-ARABIC',
    'public_site.contact.email' => 'desk@sentinel.test',
    'public_site.contact.phone' => '+218 91 234 5678',
    'public_site.contact.map_url' => 'https://maps.sentinel.test/place',
];

/**
 * Put the application in Arabic, as a request to an `/ar` URL will: the locale,
 * and an `ar.`-named twin of every English public and verifier route, which
 * PublicRoute::url() requires before it will build a link.
 *
 * The twins copy the English route's methods and URI under a throwaway prefix.
 * They are never requested. Idempotent within one application, and the
 * application is rebuilt for every test, so none outlives its test.
 */
function publicArabicUseArabic(): void
{
    if (! Route::has('ar.public.home')) {
        $english = array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route): bool => is_string($route->getName())
                && (str_starts_with($route->getName(), 'public.') || str_starts_with($route->getName(), 'verify.certificates.')),
        );

        foreach ($english as $route) {
            Route::match($route->methods(), '/ar-render-test/'.ltrim($route->uri(), '/'), fn (): string => 'twin')->name('ar.'.$route->getName());
        }

        Route::getRoutes()->refreshNameLookups();
    }

    app()->setLocale('ar');
}

/**
 * Render every public page, and the three kinds of verifier page, in each
 * language, and return the HTML.
 *
 * The empty states are rendered first, before any fixture exists; then the
 * fixtures are created and the full pages rendered. Rendering is memoised for the
 * life of the process, because the rows are rolled back after each test but the
 * HTML is plain text that every check below reads. This is the one function that
 * issues requests: P4-T18a changes it to request the `/ar` URLs.
 *
 * @return array{pages: array<string, array<string, string>>, fixtures: list<string>} the HTML by
 *                                                                                    locale and then label, and every string of fixture data
 */
function publicArabicRenderedPages(TestCase $test): array
{
    static $rendered = null;

    if ($rendered !== null) {
        return $rendered;
    }

    config(PUBLIC_ARABIC_BRAND_SENTINELS);
    $test->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));

    $pages = ['en' => [], 'ar' => []];

    $render = function (string $label, string $path, int $status) use ($test, &$pages): void {
        foreach (['en', 'ar'] as $locale) {
            $locale === 'ar' ? publicArabicUseArabic() : app()->setLocale('en');

            $response = $test->get($path);
            $response->assertStatus($status);

            $pages[$locale][$label] = (string) $response->getContent();
        }
    };

    foreach (['/' => 'home', '/programmes' => 'programmes', '/publications' => 'publications'] as $path => $name) {
        $render("{$name}, nothing published", $path, 200);
    }

    $fixtures = array_values(PUBLIC_ARABIC_BRAND_SENTINELS);

    foreach (range(1, 14) as $number) {
        $fixtures[] = $title = "Zephyr Paper {$number}";
        $fixtures[] = $titleAr = "ورقة زفير {$number}";

        Article::factory()->published()->create([
            'title_en' => $title,
            'title_ar' => $titleAr,
            'slug' => "zephyr-paper-{$number}",
            'description_en' => "Zephyr abstract {$number}.",
            'description_ar' => "ملخص زفير {$number}.",
            'topic' => $number <= 7 ? 'Quartz' : 'Basalt',
            'authors' => 'Ada Quill',
            'issued_on' => CarbonImmutable::parse('2026-01-01')->addDays($number)->toDateString(),
            'download_count' => $number,
        ]);
    }

    array_push($fixtures, 'Quartz', 'Basalt', 'Ada Quill', 'Zephyr', 'Nonexistent');

    $withIntake = Course::factory()->create([
        'name_en' => 'Basalt Alpha',
        'name_ar' => 'بازلت ألفا',
        'description_en' => 'Alpha syllabus.',
        'description_ar' => 'منهج ألفا.',
        'total_hours' => 48,
        'default_price' => '1450.000',
    ]);
    Batch::factory()->for($withIntake)->create(['start_date' => '2026-11-02', 'end_date' => '2027-01-30', 'price' => '1450.000', 'total_hours' => 48]);

    $withoutIntake = Course::factory()->create([
        'name_en' => 'Basalt Beta',
        'name_ar' => 'بازلت بيتا',
        'description_en' => 'Beta syllabus.',
        'description_ar' => 'منهج بيتا.',
        'total_hours' => 12,
        'default_price' => '300.000',
    ]);

    array_push($fixtures, 'Basalt Alpha', 'بازلت ألفا', 'Alpha syllabus.', 'منهج ألفا.', 'Basalt Beta', 'بازلت بيتا', 'Beta syllabus.', 'منهج بيتا.');

    foreach ([
        'home' => '/',
        'about' => '/about',
        'programmes' => '/programmes',
        'programme with an intake' => "/programmes/{$withIntake->getKey()}",
        'programme without an intake' => "/programmes/{$withoutIntake->getKey()}",
        'publications' => '/publications',
        'publications, second page' => '/publications?page=2',
        'publications in a topic' => '/publications?topic=Quartz',
        'publications matching a search' => '/publications?q=Zephyr',
        'publications in a topic matching a search' => '/publications?topic=Quartz&q=Zephyr',
        'publications by downloads' => '/publications?sort=downloads',
        'publications with no match' => '/publications?q=Nonexistent',
        'article' => '/publications/zephyr-paper-3',
        'contact' => '/contact',
        'verifier form' => '/verify/certificates',
    ] as $label => $path) {
        $render($label, $path, 200);
    }

    // THE CERTIFICATES COME LAST. Their factory builds an enrolment, and with it a
    // batch and a course with random words for a name, which would otherwise show
    // on the home and programmes pages as programmes nobody chose.
    $certificate = fn (): array => ['student_name' => 'Ada Quill', 'course_name' => 'Basalt Alpha'];
    $valid = StudentCertificate::factory()->create($certificate());
    $revoked = StudentCertificate::factory()->revoked('Fixture reason.')->create($certificate());
    $replaced = StudentCertificate::factory()->replaced()->create($certificate());
    $unknown = app(CertificateReference::class)->mint(now());

    array_push($fixtures, $valid->reference_number, $revoked->reference_number, $replaced->reference_number);

    // FIVE VERIFIER PAGES IN EACH OF TWO LOCALES IS TEN REQUESTS, which is exactly
    // the verifier limiter's ten a minute. A sixth page here needs the limiter
    // cleared between the locales, or its first request answers 429.
    $render('verifier, valid certificate', "/verify/certificates/{$valid->reference_number}", 200);
    $render('verifier, revoked certificate', "/verify/certificates/{$revoked->reference_number}", 200);
    $render('verifier, replaced certificate', "/verify/certificates/{$replaced->reference_number}", 200);
    $render('verifier, no such certificate', "/verify/certificates/{$unknown}", 404);

    app()->setLocale('en');

    return $rendered = ['pages' => $pages, 'fixtures' => $fixtures];
}

/*
|--------------------------------------------------------------------------
| The pages
|--------------------------------------------------------------------------
*/

it('renders every public page and verifier page in Arabic with no English catalogue text', function () {
    $rendered = publicArabicRenderedPages($this);
    $needles = publicArabicNeedles('en', anchored: false);

    $leaks = [];

    foreach ($rendered['pages']['ar'] as $label => $html) {
        expect($html)->toContain('lang="ar"');

        foreach (publicArabicFound(publicArabicReadableStrings($html), $needles) as $key => $text) {
            $leaks[] = "{$label}: {$key} shows as English: {$text}";
        }
    }

    expect($leaks)->toBe([], "English catalogue text on an Arabic page:\n".implode("\n", $leaks));
});

it('finds the English text on the same pages in English, so the search is not blind', function () {
    $rendered = publicArabicRenderedPages($this);
    $needles = publicArabicNeedles('en', anchored: false);

    $keys = [];

    foreach ($rendered['pages']['en'] as $html) {
        $keys += publicArabicFound(publicArabicReadableStrings($html), $needles);
    }

    // The floor is far below what these pages show (about 100 of the 170 keys),
    // and well above what a search that reads nothing would find.
    expect(count($keys))->toBeGreaterThan(60);

    // Each state a page can be in shows its own line: every status of the verifier,
    // the miss, a programme with and without an intake, a plural count, an empty
    // library and a page of a list.
    foreach ([
        'verify.status_message_valid',
        'verify.status_message_revoked',
        'verify.status_message_replaced',
        'verify.not_found_body',
        'public.programmes.starts',
        'public.programmes.no_intake',
        'public.programmes.empty',
        'publications.public.count_in_topic_matching',
        'publications.public.empty_library',
        'publications.public.empty_heading',
        'publications.public.page_of',
        'publications.public.sort_switch',
    ] as $key) {
        expect($keys)->toHaveKey($key);
    }
});

it('shows an Arabic line wherever an English line shows, so a blank Arabic value is caught', function () {
    $rendered = publicArabicRenderedPages($this);
    $english = publicArabicNeedles('en', anchored: true);
    $arabic = publicArabicNeedles('ar', anchored: true);
    $englishLines = publicArabicCatalogueLines('en');

    // Keys that share an English value are one line to a reader: "Verify" is three
    // keys, and a page that shows only one of them has Arabic for it if any of the
    // three does.
    $sharing = [];

    foreach (array_keys($english) as $key) {
        $sharing[$englishLines[$key]][] = $key;
    }

    $missing = [];
    $compared = 0;

    foreach ($rendered['pages']['en'] as $label => $html) {
        $seen = publicArabicFoundExactly(publicArabicReadableStrings($html), $english);
        $arabicSeen = publicArabicFoundExactly(publicArabicReadableStrings($rendered['pages']['ar'][$label]), $arabic);

        foreach (array_unique(array_map(fn (string $key): string => $englishLines[$key], $seen)) as $value) {
            $compared++;

            if (array_intersect($sharing[$value], $arabicSeen) === []) {
                $missing[] = "{$label}: no Arabic line for \"{$value}\" (".implode(', ', $sharing[$value]).')';
            }
        }
    }

    expect($compared)->toBeGreaterThan(100)
        ->and($missing)->toBe([], "An English line shows with no Arabic line beside it:\n".implode("\n", $missing));
});

it('uses fixtures, a brand and contact details that contain no catalogue text', function () {
    $rendered = publicArabicRenderedPages($this);

    $found = publicArabicFound(
        publicArabicReadableStrings('<html><body><p>'.implode('</p><p>', array_map('e', $rendered['fixtures'])).'</p></body></html>'),
        publicArabicNeedles('en', anchored: false),
    );

    expect($found)->toBe([], 'Fixture text that is also catalogue text would read as a leak: '.json_encode($found));
});

it('sets the home heading phrase inside the Arabic name of the centre', function () {
    // The home page sets the phrase apart wherever the translated heading contains
    // it. The Arabic name joins its first word to "الدراسات" with a lam, so the
    // glossary's "الدراسات القانونية" is not inside it, and this phrase is.
    expect(config('public_site.brand.name_ar'))->toContain(__('public.home.heading_emphasis', [], 'ar'));
});

/*
|--------------------------------------------------------------------------
| The verifier's uniform miss, in Arabic
|--------------------------------------------------------------------------
*/

it('renders byte-identical bodies at 404 for a malformed GET, an unknown GET, an empty POST and a malformed POST, in Arabic too', function () {
    publicArabicUseArabic();

    $unknown = app(CertificateReference::class)->mint(now());

    $malformedGet = $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']));
    $unknownGet = $this->get(route('verify.certificates.show', ['reference' => $unknown]));
    $emptyPost = $this->post(route('verify.certificates.submit'), ['reference' => '']);
    $malformedPost = $this->post(route('verify.certificates.submit'), ['reference' => 'not-a-real-reference']);

    foreach ([$malformedGet, $unknownGet, $emptyPost, $malformedPost] as $response) {
        $response->assertNotFound();
    }

    expect($malformedGet->getContent())
        ->toBe($unknownGet->getContent())
        ->toBe($emptyPost->getContent())
        ->toBe($malformedPost->getContent());

    // The bytes are Arabic ones: the direction and the language come from the locale.
    expect($malformedGet->getContent())->toContain('<html lang="ar" dir="rtl">');
});

/*
|--------------------------------------------------------------------------
| Plural forms through the real views
|--------------------------------------------------------------------------
*/

it('shows the Arabic plural form that belongs to each count, on the library and on the programme cards', function () {
    // SENTINEL FORMS, so the assertion does not freeze the draft copy: each of the
    // six forms of the two lines is a word of its own. The counts and the form each
    // takes are written out by hand from the CLDR rule for Arabic: 103 is "few"
    // again and 111 "many" again, because the form follows the last two digits.
    app('translator')->load('*', 'publications', 'ar');
    app('translator')->load('*', 'public', 'ar');
    app('translator')->addLines([
        'publications.public.downloads' => 'D-ZERO|D-ONE|D-TWO|D-FEW :count|D-MANY :count|D-OTHER :count',
        'public.programmes.hours' => 'H-ZERO|H-ONE|H-TWO|H-FEW :count|H-MANY :count|H-OTHER :count',
    ], 'ar');
    publicArabicUseArabic();

    $downloads = [0 => 'D-ZERO', 1 => 'D-ONE', 2 => 'D-TWO', 7 => 'D-FEW 7', 30 => 'D-MANY 30', 103 => 'D-FEW 103', 111 => 'D-MANY 111', 200 => 'D-OTHER 200'];

    foreach (array_keys($downloads) as $index => $count) {
        Article::factory()->published()->create([
            'title_en' => "Plural Paper {$index}",
            'issued_on' => CarbonImmutable::parse('2026-01-01')->addDays($index)->toDateString(),
            'download_count' => $count,
        ]);
    }

    $hours = [1 => 'H-ONE', 2 => 'H-TWO', 5 => 'H-FEW 5', 12 => 'H-MANY 12', 100 => 'H-OTHER 100', 103 => 'H-FEW 103'];

    foreach (array_keys($hours) as $index => $total) {
        Course::factory()->create(['name_en' => "Plural Course {$index}", 'total_hours' => $total]);
    }

    $library = publicArabicReadableStrings((string) $this->get('/publications')->assertOk()->getContent());
    $programmes = publicArabicReadableStrings((string) $this->get('/programmes')->assertOk()->getContent());

    // in_array and not toContain(): that is variadic, so a second argument would
    // be a second expected string rather than the failure message.
    foreach ($downloads as $count => $line) {
        expect(in_array($line, $library, true))->toBeTrue("A count of {$count} downloads should read \"{$line}\".");
    }

    foreach ($hours as $total => $line) {
        expect(in_array($line, $programmes, true))->toBeTrue("{$total} hours should read \"{$line}\".");
    }
});

/*
|--------------------------------------------------------------------------
| The search's own self-tests: each must fail a planted sample
|--------------------------------------------------------------------------
*/

it('reads text and the attributes that carry words, and not code or addresses', function () {
    $html = '<html><head><title>The title</title><style>.a { content: "STYLE-BODY"; }</style>'
        .'<script>var x = "SCRIPT-BODY";</script></head><body><p>Some <b>bold</b> text</p>'
        .'<img src="/IMG-SOURCE.png" class="CLASS-NAME" alt="Alt words"><input placeholder="Hint words">'
        .'<a href="/LINK-TARGET" aria-label="Aria words">x</a></body></html>';

    $strings = publicArabicReadableStrings($html);

    expect($strings)->toContain('The title', 'Some', 'bold', 'text', 'Alt words', 'Hint words', 'Aria words')
        ->and(implode("\n", $strings))->not->toContain('STYLE-BODY')
        ->not->toContain('SCRIPT-BODY')
        ->not->toContain('IMG-SOURCE')
        ->not->toContain('CLASS-NAME')
        ->not->toContain('LINK-TARGET');
});

it('finds a catalogue value in a page, at a word boundary, with a gap for a placeholder', function (string $value, string $text, bool $found) {
    $found
        ? expect(publicArabicFound([$text], ['k' => publicArabicPatterns($value, anchored: false)]))->toHaveKey('k')
        : expect(publicArabicFound([$text], ['k' => publicArabicPatterns($value, anchored: false)]))->toBe([]);
})->with([
    'the whole string' => ['Read more', 'Read more', true],
    'inside a sentence' => ['Read more', 'You can Read more here', true],
    'a placeholder gap' => ['Starts :date', 'Starts 02 Nov 2026', true],
    'a gap in the middle' => ['Page :current of :last', 'Page 2 of 5', true],
    'a plural segment, the first' => [':count hour|:count hours', '1 hour', true],
    'a plural segment, the second' => [':count hour|:count hours', '48 hours', true],
    'not inside a longer word' => ['Search', 'Searching the library', false],
    'not inside a word on the left' => ['Search', 'Research', false],
    'a different case is a different word' => ['Search', 'search', false],
    'Arabic text has none of it' => ['Read more', 'قراءة المزيد', false],
    'too short: no run of three letters' => ['© :year :name', '© 2026 Someone', false],
    'too short: a divider' => [':page | :name', 'About | Someone', false],
    'a singular is not found in its plural' => [':count hour', '48 hours', false],
]);

it('does not run a match from one string into the next', function () {
    expect(publicArabicFound(['Page', '2 of 5'], ['k' => publicArabicPatterns('Page :current of :last', anchored: false)]))->toBe([]);
});

it('matches an anchored pattern against a whole string only', function () {
    $needles = ['k' => publicArabicPatterns('Starts :date', anchored: true)];

    expect(publicArabicFoundExactly(['Starts 02 Nov 2026'], $needles))->toBe(['k'])
        ->and(publicArabicFoundExactly(['Course Starts 02 Nov 2026'], $needles))->toBe([])
        ->and(publicArabicFoundExactly(['Starts'], $needles))->toBe([]);
});

it('gives a string that is a whole plain value to that value, not to a line with a gap', function () {
    $needles = [
        'gapped' => publicArabicPatterns(':count publication|:count publications', anchored: true),
        'plain' => publicArabicPatterns('All publications', anchored: true),
    ];

    expect(publicArabicFoundExactly(['All publications'], $needles))->toBe(['plain'])
        ->and(publicArabicFoundExactly(['14 publications'], $needles))->toBe(['gapped']);
});

it('looks for a value of two letters when it must be the whole string', function () {
    expect(publicArabicFoundExactly(['د.ل'], ['dinar' => publicArabicPatterns('د.ل', anchored: true)]))->toBe(['dinar'])
        ->and(publicArabicPatterns('د.ل', anchored: false))->toBe([]);
});

it('builds needles from the English catalogue, leaving out the values that cannot tell the languages apart', function () {
    $needles = publicArabicNeedles('en', anchored: false);

    // Present: a sentence, a plural, a line with a placeholder.
    expect($needles)->toHaveKeys(['public.home.lead', 'publications.public.downloads', 'verify.status_message_revoked']);

    // Absent: the same in both languages, and the wordless.
    expect($needles)->not->toHaveKey('publications.public.pdf')
        ->not->toHaveKey('public.programmes.date_format')
        ->not->toHaveKey('public.home.verify_placeholder')
        ->not->toHaveKey('public.footer.copyright')
        ->not->toHaveKey('public.page_title')
        ->not->toHaveKey('public.home.heading');

    // A plural gets a pattern per segment.
    expect($needles['publications.public.downloads'])->toHaveCount(2);
});
