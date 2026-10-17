<?php

declare(strict_types=1);

use App\Domain\Enrollment\Data\PublicProgramme;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Publications\Data\PublicArticle;
use App\Domain\Publications\Models\Article;
use App\Domain\Publications\Queries\PublicArticleCatalogue;
use App\Support\PublicContact;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Livewire marks "a component rendered this request" in static state that
// outlives the test. A panel or portal page rendered by an EARLIER file leaves it
// set, and Livewire then injects its script into these public pages, so the
// no-<script> assertion fails by file order (it did, in CI, after the portal
// tests). Production starts each request with fresh statics, so this is test-
// process state only. Flushed BEFORE each test, because the dirt comes from
// other files. PublicSessionlessTest flushes after its own panel renders.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The public pages: home, about, programmes, contact (P4-T12)
|--------------------------------------------------------------------------
|
| THE COPY IS A DRAFT, SO THESE TESTS DO NOT QUOTE IT. The owner will replace
| the home and About text, and a test that spelled it out would fail on the
| replacement rather than on a defect. Each page's h1 is asserted by pointing
| its translation key at a sentinel: that proves the heading comes from the key
| the plan names, whatever the key later says.
|
| Contact details are set to values of their own, so the expected mailto: and
| tel: links are computed by hand from them rather than read back from the
| header's markup.
*/

beforeEach(function () {
    config([
        'public_site.brand.name' => 'Test Centre',
        'public_site.contact.email' => 'desk@example.test',
        'public_site.contact.phone' => '+218 91 234 5678',
        'public_site.contact.map_url' => 'https://maps.example.test/place/abc',
    ]);
});

/**
 * Replace one public translation line with a sentinel for the rest of this test.
 *
 * The group is loaded FIRST. `addLines()` writes into the translator's loaded
 * table without reading the file, and a group that is already marked loaded is
 * never read, so adding one line to an unloaded group would blank every other
 * key in it.
 */
function replacePublicLine(string $key, string $value): void
{
    app('translator')->load('*', 'public', 'en');
    app('translator')->addLines([$key => $value], 'en');
}

/**
 * The text of every h1 on a page.
 *
 * @return array<int, string>
 */
function publicPageHeadings(string $html): array
{
    preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/s', $html, $matches);

    return array_map(fn (string $heading): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($heading)))), $matches[1]);
}

/**
 * The href of every link inside the first element of this tag that opens with $opening.
 *
 * @return array<int, string>
 */
function publicLinksWithin(string $html, string $opening, string $closing): array
{
    $start = strpos($html, $opening);
    $end = strpos($html, $closing, (int) $start);

    if ($start === false || $end === false) {
        return [];
    }

    preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/', substr($html, $start, $end - $start), $matches);

    return array_map('html_entity_decode', $matches[1]);
}

it('serves each page with exactly one h1, taken from its key', function (string $path, string $key, string $sentinel, string $expected) {
    replacePublicLine($key, $sentinel);

    $headings = publicPageHeadings((string) $this->get($path)->assertOk()->getContent());

    expect($headings)->toBe([$expected]);
})->with([
    'home' => ['/', 'public.home.heading', 'SENTINEL-HOME for :name', 'SENTINEL-HOME for Test Centre'],
    'about' => ['/about', 'public.about.heading', 'SENTINEL-ABOUT', 'SENTINEL-ABOUT'],
    'programmes' => ['/programmes', 'public.programmes.heading', 'SENTINEL-PROGRAMMES', 'SENTINEL-PROGRAMMES'],
    'contact' => ['/contact', 'public.contact.heading', 'SENTINEL-CONTACT', 'SENTINEL-CONTACT'],
]);

it('puts the programme name in the h1 of its own page', function () {
    $course = Course::factory()->create(['name_en' => 'Showing Course']);

    expect(publicPageHeadings((string) $this->get('/programmes/'.$course->id)->assertOk()->getContent()))->toBe(['Showing Course']);
});

it('links the home page onward to programmes, about, contact and the verifier', function () {
    $html = (string) $this->get('/')->assertOk()->getContent();

    foreach (['/programmes', '/about', '/contact', '/verify/certificates'] as $path) {
        expect($html)->toContain('href="'.url($path).'"');
    }
});

it('shows the email and telephone on the contact page as mailto and tel links', function () {
    $html = (string) $this->get('/contact')->assertOk()->getContent();

    // The tel: link keeps the digits and a leading plus; the visible text keeps
    // the separators the configured value was written with.
    expect($html)->toContain('href="mailto:desk@example.test"')
        ->toContain('>desk@example.test</a>')
        ->toContain('href="tel:+218912345678"')
        ->toContain('>+218 91 234 5678</a>');
});

it('prints the same email and telephone links in the header and in the page body, from one rule', function () {
    // Padded values, so a place that did not apply the trim would show it. The
    // header prints each link once, the home page and the contact page once more
    // in their own body, and the about page has only the header's.
    config([
        'public_site.contact.email' => "  desk@example.test \n",
        'public_site.contact.phone' => '   +218 91 234 5678  ',
    ]);

    foreach (['/' => 2, '/contact' => 2, '/about' => 1] as $path => $expected) {
        $html = (string) $this->get($path)->assertOk()->getContent();

        expect(substr_count($html, '<a href="mailto:desk@example.test" dir="ltr"'))->toBe($expected, "{$path}: mailto links")
            ->and(substr_count($html, '<a href="tel:+218912345678" dir="ltr"'))->toBe($expected, "{$path}: tel links")
            ->and(substr_count($html, '>desk@example.test</a>'))->toBe($expected)
            ->and(substr_count($html, '>+218 91 234 5678</a>'))->toBe($expected);
    }
});

it('hands the callers classes to the contact links and keeps them left to right', function () {
    $html = (string) $this->get('/contact')->assertOk()->getContent();

    // The header's link carries the top bar's classes, the page's its own: the design's
    // contact value (15px ink, gold-dark on hover) with a 44px target.
    $barClasses = 'flex min-h-11 items-center whitespace-nowrap text-on-navy hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0';
    $pageClasses = 'tap-target inline-flex items-center text-copy font-medium text-ink hover:text-gold-dark';

    expect($html)->toContain('<a href="mailto:desk@example.test" dir="ltr" class="'.$barClasses.'">')
        ->toContain('<a href="mailto:desk@example.test" dir="ltr" class="'.$pageClasses.'">')
        ->toContain('<a href="tel:+218912345678" dir="ltr" class="'.$barClasses.'">')
        ->toContain('<a href="tel:+218912345678" dir="ltr" class="'.$pageClasses.'">');
});

it('renders no contact link, and no bar for them, when none is configured', function () {
    config(['public_site.contact.email' => '', 'public_site.contact.phone' => '   ']);
    replacePublicLine('public.header.contact_label', 'SENTINEL-CONTACT-BAR');

    foreach (['/', '/contact', '/about'] as $path) {
        $html = (string) $this->get($path)->assertOk()->getContent();

        foreach (['mailto:', 'tel:', 'SENTINEL-CONTACT-BAR', '<dl'] as $needle) {
            expect(str_contains($html, $needle))->toBeFalse("{$path} still has [{$needle}] with no contact details configured.");
        }
    }
});

it('renders nothing from either contact link component when its value is empty, whatever the caller does', function () {
    // The pages guard their own wrappers, so this is the components' guard on its
    // own: a link with nothing in it is not rendered.
    config(['public_site.contact.email' => '', 'public_site.contact.phone' => '  ']);

    expect(trim(Blade::render('<x-public.email-link class="x" />')))->toBe('')
        ->and(trim(Blade::render('<x-public.phone-link class="x" />')))->toBe('');

    config(['public_site.contact.email' => 'desk@example.test', 'public_site.contact.phone' => '+218 91']);

    expect(trim(Blade::render('<x-public.email-link class="x" />')))->toBe('<a href="mailto:desk@example.test" dir="ltr" class="x">desk@example.test</a>')
        ->and(trim(Blade::render('<x-public.phone-link class="x" />')))->toBe('<a href="tel:+21891" dir="ltr" class="x">+218 91</a>');
});

it('reads the public email, telephone and map link from the three public_site config keys', function () {
    config([
        'public_site.contact.email' => ' a@example.test ',
        'public_site.contact.phone' => '+1 (234) 567',
        'public_site.contact.map_url' => ' https://maps.example.test/x ',
    ]);

    $contact = PublicContact::fromConfig();

    expect($contact->email)->toBe('a@example.test')
        ->and($contact->phone)->toBe('+1 (234) 567')
        ->and($contact->telephoneHref)->toBe('tel:+1234567')
        ->and($contact->mapUrl)->toBe('https://maps.example.test/x');
});

it('links to the directions in a new tab with the safe rel, and embeds nothing', function () {
    $html = (string) $this->get('/contact')->assertOk()->getContent();

    preg_match_all('/<a\b[^>]*\bhref="https:\/\/maps\.example\.test\/place\/abc"[^>]*>/', $html, $matches);

    expect($matches[0])->toHaveCount(1)
        ->and($matches[0][0])->toContain('target="_blank"')->toContain('rel="noopener noreferrer"');

    foreach (['<iframe', '<embed', '<object', '<script'] as $embed) {
        expect(str_contains(strtolower($html), $embed))->toBeFalse("The contact page contains [{$embed}].");
    }
});

it('shows no address and no opening hours on the contact page', function () {
    $text = strtolower(strip_tags((string) $this->get('/contact')->assertOk()->getContent()));

    // The owner has not sent either, and the design's are placeholders.
    foreach (['address', 'opening hours', 'sunday', 'street', 'district'] as $word) {
        expect(str_contains($text, $word))->toBeFalse("The contact page mentions [{$word}].");
    }
});

it('shows no directions link when the map url is not set or is not a web link', function (mixed $mapUrl) {
    config(['public_site.contact.map_url' => $mapUrl]);

    $html = (string) $this->get('/contact')->assertOk()->getContent();

    expect(str_contains($html, 'target="_blank"'))->toBeFalse()
        ->and(str_contains($html, 'maps.example.test'))->toBeFalse()
        ->and(str_contains($html, 'javascript:'))->toBeFalse();
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['   '],
    // A value that is not a web link is never put in an href.
    'not a web url' => ['javascript:alert(1)'],
]);

it('links to home sections from the navigation and full pages from the footer', function () {
    $html = (string) $this->get('/')->assertOk()->getContent();

    $nav = publicLinksWithin($html, '<nav aria-label=', '</nav>');
    $footer = publicLinksWithin($html, '<footer', '</footer>');

    // The navigation is rendered twice from one list, narrow then wide, so each
    // link appears twice and the order is the list's order. The narrow menu's last
    // item is the student portal's sign-in (P4-T15), which the wide row does not repeat.
    $expected = ['#about', '#programmes', '#publications', '#verify', '#contact'];
    $portal = route('filament.student.auth.login');

    expect(array_values(array_unique($nav)))->toBe([...$expected, $portal])
        ->and($nav)->toHaveCount(count($expected) * 2 + 1)
        ->and($footer)->toContain(url('/about'))->toContain(url('/programmes'))->toContain(url('/publications'))->toContain(url('/contact'));
});

/**
 * The URL of every public GET route, keyed by route name.
 *
 * A real id for programmes.show, and a placeholder for any parameter this file
 * has no fixture for: a 404 on the sessionless group sets no cookie and is handed
 * no model either, so a route added later is held to the same properties without
 * an edit here.
 *
 * @return array<string, string>
 */
function publicGetRouteUrls(int $programmeId): array
{
    $urls = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'public.') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[$parameter] = $name === 'public.programmes.show' ? $programmeId : 'placeholder';
        }

        $urls[$name] = route($name, $parameters);
    }

    return $urls;
}

it('sets no cookie on any public GET route', function () {
    $course = Course::factory()->create();

    $urls = publicGetRouteUrls($course->id);

    expect(array_keys($urls))->toContain('public.home', 'public.about', 'public.programmes.index', 'public.programmes.show', 'public.contact');

    foreach ($urls as $name => $url) {
        $response = $this->get($url);

        expect($response->headers->getCookies())->toBe([], "{$name} set a cookie.")
            ->and($response->headers->has('Set-Cookie'))->toBeFalse("{$name} sent a Set-Cookie header.");
    }
});

/**
 * Every Eloquent model reachable from a value, as path => class.
 *
 * Walks arrays, the public properties of objects, and anything iterable (a
 * Collection, a ComponentAttributeBag), and remembers the objects it has seen so
 * a cycle ends. A named function with samples below, so it cannot quietly narrow.
 *
 * @param  array<int, bool>  $seen
 * @return array<string, string>
 */
function eloquentModelsWithin(mixed $value, string $path = 'data', array &$seen = []): array
{
    if ($value instanceof Model) {
        return [$path => $value::class];
    }

    $found = [];

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $found += eloquentModelsWithin($item, "{$path}[{$key}]", $seen);
        }

        return $found;
    }

    if (! is_object($value) || isset($seen[spl_object_id($value)])) {
        return $found;
    }

    $seen[spl_object_id($value)] = true;

    foreach (get_object_vars($value) as $name => $item) {
        $found += eloquentModelsWithin($item, "{$path}->{$name}", $seen);
    }

    if ($value instanceof Traversable) {
        foreach ($value as $key => $item) {
            $found += eloquentModelsWithin($item, "{$path}[{$key}]", $seen);
        }
    }

    return $found;
}

/**
 * The data of every view rendered while this request runs, layout, partials and
 * components included, as [view name, data] pairs.
 *
 * A composer on `*` sees each view as it renders. `View::composer()` cannot be
 * removed, so the listener checks a flag and goes quiet once the request is over.
 *
 * @return array<int, array{0: string, 1: array<string, mixed>}>
 */
function publicViewDataDuring(Closure $request): array
{
    $views = [];
    $recording = true;

    View::composer('*', function (Illuminate\Contracts\View\View $view) use (&$views, &$recording): void {
        if ($recording) {
            $views[] = [$view->name(), $view->getData()];
        }
    });

    $request();
    $recording = false;

    return $views;
}

it('finds the models the walk exists to find, and leaves everything else alone', function () {
    $model = Course::factory()->make();

    expect(eloquentModelsWithin($model))->toBe(['data' => Course::class])
        ->and(eloquentModelsWithin(['a' => ['b' => $model]]))->toBe(['data[a][b]' => Course::class])
        ->and(eloquentModelsWithin(['card' => (object) ['course' => $model]]))->toBe(['data[card]->course' => Course::class])
        ->and(eloquentModelsWithin(['rows' => new Collection([$model])]))->toHaveKey('data[rows][0]')
        ->and(eloquentModelsWithin(['attributes' => new ComponentAttributeBag(['course' => $model])]))->toHaveKey('data[attributes][course]');

    $cycle = new stdClass;
    $cycle->self = $cycle;

    // The projection, strings, null, and a cycle with nothing in it.
    expect(eloquentModelsWithin(['programme' => new PublicProgramme(1, 'Name', 'Text', 10, null, null), 'x' => 'y', 'n' => null, 'c' => $cycle]))->toBe([]);
});

it('hands no Eloquent model to any view on any public route', function () {
    $planned = Course::factory()->create(['name_en' => 'Walked Course']);
    Batch::factory()->for($planned)->create(['start_date' => now()->addDays(10)]);
    Course::factory()->create(['name_en' => 'Walked Fallback']);
    Course::factory()->inactive()->create();

    foreach (publicGetRouteUrls($planned->id) as $name => $url) {
        $views = publicViewDataDuring(fn () => $this->get($url));

        // The control: a walk over no views passes by finding nothing.
        expect($views)->not->toBeEmpty("{$name} rendered no view to walk.");

        $names = array_column($views, 0);

        if ($name === 'public.home' || $name === 'public.programmes.index') {
            // str_contains: toContain() is variadic, so a message there is read as a second needle.
            expect(str_contains(implode(' ', $names), 'programme-card'))->toBeTrue("{$name} did not render the card, so its data was not walked.");
        }

        foreach ($views as [$viewName, $data]) {
            expect(eloquentModelsWithin($data, $viewName))->toBe([], "{$name}: a view is handed an Eloquent model.");
        }
    }
});

/*
|--------------------------------------------------------------------------
| The home page's publications teaser (P4-T20b)
|--------------------------------------------------------------------------
|
| The home page is handed the latest published articles as `articles` view data,
| through PublicArticleCatalogue::latest(). What is asserted is the data the
| controller hands over, not the markup, so the teaser's design can change without
| these tests moving: published ones only, at most the limit, newest first (a tie
| on the issue date broken by the publication time), each a PublicArticle, and a
| query count that does not grow with the number of articles.
|
| Every expected slug list is written out by hand from dates chosen here, never
| sorted by code from the same rows, and the "unpublished" row carries a sentinel
| title and the newest issue date of all, so it would lead the list if the filter
| went missing.
*/

/**
 * Every SQL statement the application ran while $work ran.
 *
 * `DB::listen` cannot be unregistered, so the listener checks a flag and goes
 * quiet once the work is over.
 *
 * @return array<int, string>
 */
function homeTeaserSqlStatementsDuring(Closure $work): array
{
    $statements = [];
    $recording = true;

    DB::listen(function (QueryExecuted $query) use (&$statements, &$recording): void {
        if ($recording) {
            $statements[] = $query->sql;
        }
    });

    $work();
    $recording = false;

    return $statements;
}

/**
 * The home page's `articles` view data, as the controller handed it over.
 *
 * @return array<int, PublicArticle>
 */
function homeTeaserArticles(): array
{
    $articles = test()->get('/')->assertOk()->viewData('articles');

    expect($articles)->toBeArray();

    return $articles;
}

/**
 * The slugs of the home page's articles, in the order the page holds them.
 *
 * @return array<int, string>
 */
function homeTeaserSlugs(): array
{
    return array_map(fn (PublicArticle $article): string => $article->slug, homeTeaserArticles());
}

/**
 * Create a published article with a hand-chosen slug and issue date.
 *
 * @param  array<string, mixed>  $attributes
 */
function teaserArticle(string $slug, string $issuedOn, array $attributes = []): Article
{
    return Article::factory()->published()->create(['slug' => $slug, 'issued_on' => $issuedOn] + $attributes);
}

it('hands the home page published articles only', function () {
    teaserArticle('teaser-published-march', '2026-03-01');
    teaserArticle('teaser-published-january', '2026-01-01');

    // Newer than every published article, so a missing filter puts it first.
    Article::factory()->create([
        'slug' => 'teaser-sentinel-unpublished',
        'title_en' => 'SENTINEL-UNPUBLISHED-TITLE',
        'issued_on' => '2026-09-30',
    ]);

    $articles = homeTeaserArticles();

    expect(array_column($articles, 'slug'))->toBe(['teaser-published-march', 'teaser-published-january'])
        ->and(implode(' ', array_column($articles, 'title')))->not->toContain('SENTINEL-UNPUBLISHED-TITLE');
});

it('hands the home page at most three articles, the newest three', function () {
    teaserArticle('teaser-limit-2026-01', '2026-01-10');
    teaserArticle('teaser-limit-2026-05', '2026-05-10');
    teaserArticle('teaser-limit-2026-03', '2026-03-10');
    teaserArticle('teaser-limit-2026-04', '2026-04-10');
    teaserArticle('teaser-limit-2026-02', '2026-02-10');

    expect(homeTeaserSlugs())->toBe(['teaser-limit-2026-05', 'teaser-limit-2026-04', 'teaser-limit-2026-03']);
});

it('hands the home page its articles newest issue date first', function () {
    // Inserted out of order, so neither the id nor the insertion order is the answer.
    teaserArticle('teaser-order-middle', '2025-06-15');
    teaserArticle('teaser-order-oldest', '2024-02-01');
    teaserArticle('teaser-order-newest', '2026-08-20');

    expect(homeTeaserSlugs())->toBe(['teaser-order-newest', 'teaser-order-middle', 'teaser-order-oldest']);
});

it('breaks a tie on the issue date by the later publication time', function () {
    // The earlier-published row is inserted SECOND, so it has the higher id: a
    // tie-break that fell through to the id would put it first.
    teaserArticle('teaser-tie-published-later', '2026-04-10', ['published_at' => '2026-04-12 09:00:00']);
    teaserArticle('teaser-tie-published-earlier', '2026-04-10', ['published_at' => '2026-04-11 09:00:00']);
    teaserArticle('teaser-tie-issued-before', '2026-04-09', ['published_at' => '2026-04-20 09:00:00']);

    expect(homeTeaserSlugs())->toBe(['teaser-tie-published-later', 'teaser-tie-published-earlier', 'teaser-tie-issued-before']);
});

it('hands the home page each article as a PublicArticle projection', function () {
    teaserArticle('teaser-projection-one', '2026-02-02', ['title_en' => 'Teaser Projection One']);

    $articles = homeTeaserArticles();

    expect($articles)->toHaveCount(1)
        ->and($articles[0])->toBeInstanceOf(PublicArticle::class)
        ->and($articles[0]->slug)->toBe('teaser-projection-one')
        ->and($articles[0]->title)->toBe('Teaser Projection One')
        ->and($articles[0]->issuedOn)->toBe('2026-02-02');
});

it('runs the same number of queries for the home page with no articles, one or five', function () {
    $statements = [];

    $statements[0] = homeTeaserSqlStatementsDuring(fn () => test()->get('/')->assertOk());

    teaserArticle('teaser-count-1', '2026-01-01');
    $statements[1] = homeTeaserSqlStatementsDuring(fn () => test()->get('/')->assertOk());

    foreach (range(2, 5) as $number) {
        teaserArticle("teaser-count-{$number}", "2026-01-0{$number}");
    }

    $statements[5] = homeTeaserSqlStatementsDuring(fn () => test()->get('/')->assertOk());

    expect(count($statements[0]))->toBeGreaterThan(0)
        ->and(count($statements[1]))->toBe(count($statements[0]))
        ->and(count($statements[5]))->toBe(count($statements[0]));

    // And the articles are read in one statement, whatever there are.
    $articleReads = array_filter($statements[5], fn (string $sql): bool => str_contains($sql, '`articles`'));

    expect($articleReads)->toHaveCount(1);
});

it('returns nothing, and runs no query, for a limit below one', function (int $limit) {
    teaserArticle('teaser-bound-a', '2026-01-01');
    teaserArticle('teaser-bound-b', '2026-02-01');
    teaserArticle('teaser-bound-c', '2026-03-01');

    $catalogue = app(PublicArticleCatalogue::class);
    $result = null;

    $statements = homeTeaserSqlStatementsDuring(function () use ($catalogue, $limit, &$result): void {
        $result = $catalogue->latest($limit);
    });

    // Laravel's limit() ignores a negative value, so without the guard a
    // negative limit would read all three published rows.
    expect($result)->toBe([])
        ->and($statements)->toBe([]);
})->with([
    'zero' => [0],
    'minus one' => [-1],
]);

it('returns exactly the limit when there are more published articles than that', function () {
    teaserArticle('teaser-direct-oldest', '2026-01-01');
    teaserArticle('teaser-direct-newest', '2026-03-01');
    teaserArticle('teaser-direct-middle', '2026-02-01');

    $result = app(PublicArticleCatalogue::class)->latest(2);

    expect(array_map(fn (PublicArticle $article): string => $article->slug, $result))
        ->toBe(['teaser-direct-newest', 'teaser-direct-middle']);
});

/*
|--------------------------------------------------------------------------
| The home page, section by section (P4-T20b)
|--------------------------------------------------------------------------
|
| The teaser in the MARKUP: the data tests above prove what the controller hands the
| view, these prove what the page prints from it. Every title, slug and date below is
| written out by hand, and the unpublished article carries the newest date of all and a
| sentinel title, so a teaser that listed it would put it first.
*/

/**
 * The HTML of the home page's publications teaser section, or '' when there is none.
 */
function homeTeaserSection(string $html): string
{
    preg_match('/<section id="publications".*?<\/section>/s', $html, $match);

    return $match[0] ?? '';
}

it('prints the three newest published articles in the home teaser, in order, each linked to its page and its download', function () {
    teaserArticle('markup-fourth-oldest', '2025-03-01', ['title_en' => 'Teaser Fourth Oldest']);
    teaserArticle('markup-third', '2025-09-01', ['title_en' => 'Teaser Third']);
    teaserArticle('markup-second', '2026-01-15', ['title_en' => 'Teaser Second']);
    teaserArticle('markup-first', '2026-06-20', ['title_en' => 'Teaser First']);
    Article::factory()->create(['slug' => 'markup-unpublished', 'title_en' => 'SENTINEL-UNPUBLISHED-TEASER', 'issued_on' => '2026-09-30']);

    $html = (string) test()->get('/')->assertOk()->getContent();
    $section = homeTeaserSection($html);

    preg_match_all('/<h3\b[^>]*>\s*<a\b[^>]*class="([^"]*)"[^>]*>(.*?)<\/a>/s', $section, $titles);

    expect($section)->not->toBe('')
        ->and($titles[2])->toBe(['Teaser First', 'Teaser Second', 'Teaser Third'])
        // The design turns a title gold-dark on hover (it is a 44px target too).
        ->and(array_map(fn (string $classes): array => array_intersect(['tap-target', 'hover:text-gold-dark'], explode(' ', $classes)), $titles[1]))
        ->each->toBe(['tap-target', 'hover:text-gold-dark'])
        ->and(substr_count($section, '<article'))->toBe(3)
        ->and(str_contains($html, 'Teaser Fourth Oldest'))->toBeFalse('The fourth article is on the home page.')
        ->and(str_contains($html, 'SENTINEL-UNPUBLISHED-TEASER'))->toBeFalse('An unpublished article is on the home page.');

    // Hand-built URLs: the page and the download of each of the three.
    foreach (['markup-first', 'markup-second', 'markup-third'] as $slug) {
        expect($section)->toContain('href="'.url('/publications/'.$slug).'"')
            ->toContain('href="'.url('/publications/'.$slug.'/download').'"');
    }

    foreach (['markup-fourth-oldest', 'markup-unpublished'] as $slug) {
        expect(str_contains($html, '/publications/'.$slug))->toBeFalse("{$slug} is linked from the home page.");
    }
});

it('keeps the publications target and a localized empty state while nothing is published', function () {
    Article::factory()->create(['title_en' => 'SENTINEL-NOT-PUBLISHED-YET', 'issued_on' => '2026-09-30']);
    replacePublicLine('public.home.publications_title', 'SENTINEL-TEASER-HEADING');

    $html = (string) test()->get('/')->assertOk()->getContent();
    $section = homeTeaserSection($html);

    expect($section)->not->toBe('')
        ->and($section)->toContain(__('publications.public.empty_library'))
        ->and(str_contains($html, 'SENTINEL-NOT-PUBLISHED-YET'))->toBeFalse();

    teaserArticle('now-published', '2026-01-01', ['title_en' => 'Now Published']);

    $published = (string) test()->get('/')->assertOk()->getContent();

    // Published articles replace the empty state.
    expect(str_contains($published, 'id="publications"'))->toBeTrue()
        ->and(str_contains($published, 'SENTINEL-TEASER-HEADING'))->toBeTrue()
        ->and(homeTeaserSection($published))->not->toContain(__('publications.public.empty_library', [], 'en'));
});

it('lays the home page out as the design does, and points the hero at its own sections', function () {
    teaserArticle('layout-article', '2026-01-01');

    $html = (string) test()->get('/')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    // The sections in the order the design draws them. Only the hero carries no id.
    $positions = array_map(fn (string $id): int|false => strpos($html, 'id="'.$id.'"'), ['about', 'programmes', 'verify', 'publications', 'contact']);

    expect($positions)->each->not->toBeFalse()
        ->and($positions[0])->toBeLessThan($positions[1])
        ->and($positions[1])->toBeLessThan($positions[2])
        ->and($positions[2])->toBeLessThan($positions[3])
        ->and($positions[3])->toBeLessThan($positions[4]);

    // The hero's two buttons stay on the page: they scroll to the programmes and the verifier.
    $hero = $document->querySelector('main > section');

    expect($hero->querySelector('a[href="#programmes"]'))->not->toBeNull()
        ->and($hero->querySelector('a[href="#verify"]'))->not->toBeNull()
        ->and($document->querySelector('main #programmes'))->not->toBeNull()
        ->and($document->querySelector('main #verify'))->not->toBeNull();

    expect($document->querySelector('main #about a[href="'.url('/about').'"]'))->not->toBeNull()
        ->and($document->querySelector('main #contact a[href="'.url('/contact').'"]'))->not->toBeNull();

    // The seal is decoration (the h1 already names the centre) and both glows and both
    // circles are hidden from assistive technology.
    $seal = $hero->querySelector('img');

    expect($seal->getAttribute('alt'))->toBe('')
        ->and($seal->getAttribute('src'))->toBe(url('/images/brand/asclst-logo.jpg'))
        ->and(count($hero->querySelectorAll('div[aria-hidden="true"]')))->toBeGreaterThanOrEqual(5);
});

it('sets the verifier box placeholder back only as far as it can still be read', function () {
    $document = HTMLDocument::createFromString((string) test()->get('/')->assertOk()->getContent(), LIBXML_NOERROR);
    $classes = explode(' ', (string) $document->querySelector('#home-verify-reference')->getAttribute('class'));

    // The design draws the placeholder in cream at 55%. Over the lightest pixel behind the
    // box, swept from 320 to 2560px with the colour composited over the rendered page,
    // that is 3.36:1. 72% is the lowest whole step that reaches 4.5:1 everywhere (4.53:1
    // at worst; 71% is 4.46:1). A breakpoint variant would bring 55% back, so any class
    // naming the placeholder counts.
    expect(array_values(array_filter($classes, fn (string $class): bool => str_contains($class, 'placeholder:'))))
        ->toBe(['placeholder:text-cream/72']);
});

it('sets the emphasis phrase of the home heading in italic gold wherever it appears, and escapes every part', function (string $heading, string $expectedInnerHtml, ?string $phrase = null) {
    replacePublicLine('public.home.heading', $heading);

    // Most cases use the key's own phrase, "Legal Studies"; one gives the phrase itself markup.
    if ($phrase !== null) {
        replacePublicLine('public.home.heading_emphasis', $phrase);
    }

    preg_match('/<h1\b[^>]*>(.*?)<\/h1>/s', (string) test()->get('/')->assertOk()->getContent(), $match);

    // Written by hand from the phrase "Legal Studies", which is the key's default.
    expect(trim($match[1]))->toBe($expectedInnerHtml);
})->with([
    'in the middle' => ['Before Legal Studies After', 'Before <span class="text-gold-light italic">Legal Studies</span> After'],
    'at the start' => ['Legal Studies First', '<span class="text-gold-light italic">Legal Studies</span> First'],
    'at the end' => ['Last Legal Studies', 'Last <span class="text-gold-light italic">Legal Studies</span>'],
    'with markup around it' => ['A <i>& B</i> Legal Studies <u>C</u>', 'A &lt;i&gt;&amp; B&lt;/i&gt; <span class="text-gold-light italic">Legal Studies</span> &lt;u&gt;C&lt;/u&gt;'],
    'not in the name' => ['Plain Heading Only', 'Plain Heading Only'],
    // The phrase is escaped inside its span as well, not only the text around it.
    'with markup in the phrase itself' => ['Before <b>& After', 'Before <span class="text-gold-light italic">&lt;b&gt;&amp;</span> After', '<b>&'],
]);

it('takes the home heading from the centres name, with the phrase in it set apart', function () {
    config(['public_site.brand.name' => 'The Legal Studies Centre']);

    preg_match('/<h1\b[^>]*>(.*?)<\/h1>/s', (string) test()->get('/')->assertOk()->getContent(), $match);

    expect(trim($match[1]))->toBe('The <span class="text-gold-light italic">Legal Studies</span> Centre');
});

it('shows a contact row only for a detail that is set, and the directions card only while a map link is set', function () {
    replacePublicLine('public.contact.directions_title', 'SENTINEL-DIRECTIONS-TITLE');
    replacePublicLine('public.contact.phone_label', 'SENTINEL-PHONE-LABEL');
    replacePublicLine('public.contact.email_label', 'SENTINEL-EMAIL-LABEL');

    foreach (['/', '/contact'] as $path) {
        // Email and map only.
        config(['public_site.contact.phone' => '', 'public_site.contact.email' => 'desk@example.test', 'public_site.contact.map_url' => 'https://maps.example.test/place/abc']);

        $html = (string) test()->get($path)->assertOk()->getContent();

        expect(substr_count($html, 'mailto:desk@example.test'))->toBe(2, "{$path}: the header and the block each link the email")
            ->and(str_contains($html, 'tel:'))->toBeFalse("{$path}: a telephone row with no telephone")
            ->and(str_contains($html, 'SENTINEL-PHONE-LABEL'))->toBeFalse("{$path}: a telephone row with no telephone")
            ->and(substr_count($html, 'SENTINEL-EMAIL-LABEL'))->toBe(1, "{$path}: the email row")
            ->and(str_contains($html, 'SENTINEL-DIRECTIONS-TITLE'))->toBeTrue("{$path}: no directions card with a map link");

        // Telephone only, no map: no email row and no card.
        config(['public_site.contact.phone' => '+218 91 234 5678', 'public_site.contact.email' => '', 'public_site.contact.map_url' => '']);

        $html = (string) test()->get($path)->assertOk()->getContent();

        expect(substr_count($html, 'tel:+218912345678'))->toBe(2, "{$path}: the header and the block each link the telephone")
            ->and(str_contains($html, 'mailto:'))->toBeFalse("{$path}: an email row with no email")
            ->and(str_contains($html, 'SENTINEL-EMAIL-LABEL'))->toBeFalse("{$path}: an email row with no email")
            ->and(substr_count($html, 'SENTINEL-PHONE-LABEL'))->toBe(1, "{$path}: the telephone row")
            ->and(str_contains($html, 'SENTINEL-DIRECTIONS-TITLE'))->toBeFalse("{$path}: a directions card with no map link")
            ->and(str_contains($html, 'target="_blank"'))->toBeFalse();
    }
});

it('keeps the home contact target with a localized empty state when nothing is configured', function () {
    config(['public_site.contact.phone' => '', 'public_site.contact.email' => '', 'public_site.contact.map_url' => '']);

    $home = (string) test()->get('/')->assertOk()->getContent();
    $contact = (string) test()->get('/contact')->assertOk()->getContent();

    $section = HTMLDocument::createFromString($home, LIBXML_NOERROR)->querySelector('main #contact');

    expect($section)->not->toBeNull()
        ->and($section->textContent)->toContain(__('public.contact.empty'))
        ->and($section->querySelector('#home-contact-heading'))->not->toBeNull()
        ->and($section->querySelector('a[href^="mailto:"], a[href^="tel:"]'))->toBeNull()
        ->and(str_contains($contact, 'contact-heading'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The pages that borrow the library's header, and the components they share
|--------------------------------------------------------------------------
*/

it('opens About, Programmes, Contact and a programme with the navy header band, as a section and not a second banner', function () {
    $course = Course::factory()->create(['name_en' => 'Banner Course']);

    foreach (['/about', '/programmes', '/contact', '/programmes/'.$course->id] as $path) {
        $document = HTMLDocument::createFromString((string) test()->get($path)->assertOk()->getContent(), LIBXML_NOERROR);

        $h1s = $document->querySelectorAll('h1');
        $band = $h1s[0]->closest('section');

        // One h1, inside a navy section of <main>; the layout's <header> stays the only one.
        expect(count($h1s))->toBe(1, $path)
            ->and($band)->not->toBeNull("{$path}: the h1 is not in a section")
            ->and(explode(' ', $band->getAttribute('class')))->toContain('bg-navy')
            ->and($band->closest('main'))->not->toBeNull()
            ->and(count($document->querySelectorAll('header')))->toBe(1, $path);
    }
});

it('draws the left arrow the back link needs', function () {
    $svg = HTMLDocument::createFromString(Blade::render('<x-public.icon name="arrow-left" class="rtl:-scale-x-100" />'), LIBXML_NOERROR)->querySelector('svg');

    // The mirror of the right arrow's path, by hand.
    expect($svg->querySelector('path')->getAttribute('d'))->toBe('M19 12H5m6-6-6 6 6 6')
        ->and($svg->getAttribute('aria-hidden'))->toBe('true')
        ->and($svg->getAttribute('class'))->toContain('rtl:-scale-x-100');
});

it('draws the price in the display face with its currency as a smaller span after a real space', function () {
    $course = Course::factory()->create(['name_en' => 'Priced Course', 'total_hours' => 30]);
    Batch::factory()->for($course)->create(['start_date' => now()->addDays(10), 'price' => '1450.000']);

    $html = (string) test()->get('/')->assertOk()->getContent();

    // The amount exactly as held, one space, then the currency in its own span with no
    // margin of its own (a real space separates them).
    preg_match('/<li class="([^"]*)">1450\.000 <span class="([^"]*)">LYD<\/span><\/li>/', $html, $match);

    expect($match)->not->toBe([], 'The price is not printed as the amount, a space and the currency span.')
        ->and(explode(' ', $match[1]))->toContain('font-display')->toContain('text-price')
        ->and(explode(' ', $match[2]))->toContain('font-sans')->toContain('text-small')
        ->and(preg_match('/(^| )-?[mp][se]?-/', $match[2]))->toBe(0, 'The currency span carries its own margin.');
});

it('keeps every button variant the shell uses exactly as it was', function (string $variant, string $size, string $variantClasses, string $sizeClasses) {
    $link = HTMLDocument::createFromString(Blade::render('<x-public.button href="/x" variant="'.$variant.'" size="'.$size.'">Go</x-public.button>'), LIBXML_NOERROR)->querySelector('a');

    // The classes the component rendered before the squarer variants were added, written
    // out by hand: the base, the colour scheme, and the size.
    $expected = explode(' ', 'inline-flex items-center justify-center gap-2 rounded-pill font-semibold whitespace-nowrap '.$variantClasses.' '.$sizeClasses);
    $actual = explode(' ', $link->getAttribute('class'));

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected);
})->with(function () {
    $variants = [
        'primary' => 'bg-gold text-ink shadow-gold hover:bg-gold-light focus-visible:outline-gold-light',
        'ghost' => 'border border-cream/30 text-cream hover:bg-cream/10 focus-visible:outline-gold-light',
        'outline' => 'border border-navy/20 text-navy hover:bg-navy hover:text-cream',
        'navy' => 'bg-navy text-cream hover:bg-navy-hover',
    ];
    $sizes = [
        'md' => 'px-5 py-3 text-ui',
        'sm' => 'tap-target px-4 py-2.5 text-caption',
        'nav' => 'px-4.75 py-2.75 text-ui leading-[normal] max-lg:min-h-11',
    ];

    foreach ($variants as $variant => $variantClasses) {
        foreach ($sizes as $size => $sizeClasses) {
            yield "{$variant} {$size}" => [$variant, $size, $variantClasses, $sizeClasses];
        }
    }
});
