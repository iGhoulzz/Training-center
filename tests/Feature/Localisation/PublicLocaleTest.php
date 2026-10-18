<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Publications\Models\Article;
use App\Http\Middleware\SetPublicLocale;
use App\Support\PublicRoute;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

// Livewire marks "a component rendered this request" in static state that outlives
// the test, and injects its script into the next public page rendered. Flushed
// before each test, as the other public test files do.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The public site in two languages (P4-T18a)
|--------------------------------------------------------------------------
|
| `/ar/...` is Arabic and the unprefixed path English, with no session either way:
| the language is in the URL because there is nothing else to carry it. These tests
| hold that, and the three things that make it true everywhere rather than on the
| pages somebody remembered to check:
|
|   - the Arabic routes are the English routes, registered again, so the two
|     groups carry the same middleware (compared from the router, not the route file);
|   - every link on an Arabic page stays under `/ar`, and on an English page stays out
|     of it, with the language switch the one deliberate exception;
|   - the language switch and the head's `hreflang` links reach the SAME page in the
|     other language, query string and parameters included.
|
| EVERY EXPECTED VALUE IS WRITTEN OUT BY HAND: the URIs below are literals, never
| derived from the route list they are compared with.
*/

/**
 * The public pages as the plan's route table gives them: the route's name without
 * its group prefix, its English URI, and its Arabic URI.
 *
 * @var array<string, array{0: string, 1: string}>
 */
const PUBLIC_LOCALE_PAGE_URIS = [
    'home' => ['/', 'ar'],
    'about' => ['about', 'ar/about'],
    'programmes.index' => ['programmes', 'ar/programmes'],
    'programmes.show' => ['programmes/{course}', 'ar/programmes/{course}'],
    'contact' => ['contact', 'ar/contact'],
    'publications.index' => ['publications', 'ar/publications'],
    'publications.show' => ['publications/{slug}', 'ar/publications/{slug}'],
    'publications.download' => ['publications/{slug}/download', 'ar/publications/{slug}/download'],
];

/**
 * Every route of the router whose name starts with a prefix, by name.
 *
 * @return array<string, RoutingRoute>
 */
function publicLocaleRoutesNamed(string $prefix): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (is_string($route->getName()) && str_starts_with($route->getName(), $prefix)) {
            $found[$route->getName()] = $route;
        }
    }

    ksort($found);

    return $found;
}

/**
 * The fixtures every parametric page needs, and the pages as label => English path.
 *
 * The article has a topic and downloads and the course an intake, so every part of
 * every page renders. Created once per test, in the test's transaction.
 *
 * @return array<string, string>
 */
function publicLocalePages(): array
{
    $course = Course::factory()->create(['name_en' => 'Locale Course', 'name_ar' => 'دورة اللغة']);
    Batch::factory()->for($course)->create(['start_date' => '2026-11-02', 'end_date' => '2027-01-30']);
    $article = Article::factory()->published()->create(['title_en' => 'Locale Article', 'title_ar' => 'مقال اللغة', 'slug' => 'locale-article', 'topic' => 'Quartz']);

    return [
        'home' => '/',
        'about' => '/about',
        'programmes' => '/programmes',
        'a programme' => "/programmes/{$course->getKey()}",
        'contact' => '/contact',
        'publications' => '/publications',
        'an article' => "/publications/{$article->slug}",
    ];
}

/** The Arabic URL path of an English one. */
function publicLocaleArabicPath(string $english): string
{
    return $english === '/' ? '/ar' : "/ar{$english}";
}

/** @return HTMLDocument the page, parsed. */
function publicLocaleDocument(TestResponse $response): HTMLDocument
{
    return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
}

/*
|--------------------------------------------------------------------------
| The routes
|--------------------------------------------------------------------------
*/

it('registers every public page twice: English unprefixed and Arabic under /ar, named ar.public.*', function () {
    $english = publicLocaleRoutesNamed('public.');
    $arabic = publicLocaleRoutesNamed('ar.public.');

    // The control: a selector that found nothing would let every loop below pass.
    expect(array_keys($english))->toEqualCanonicalizing(array_map(fn (string $name): string => "public.{$name}", array_keys(PUBLIC_LOCALE_PAGE_URIS)))
        ->and(array_keys($arabic))->toEqualCanonicalizing(array_map(fn (string $name): string => "ar.public.{$name}", array_keys(PUBLIC_LOCALE_PAGE_URIS)));

    foreach (PUBLIC_LOCALE_PAGE_URIS as $name => [$englishUri, $arabicUri]) {
        expect($english["public.{$name}"]->uri())->toBe($englishUri)
            ->and($arabic["ar.public.{$name}"]->uri())->toBe($arabicUri)
            ->and($arabic["ar.public.{$name}"]->methods())->toBe($english["public.{$name}"]->methods())
            ->and($arabic["ar.public.{$name}"]->getActionName())->toBe($english["public.{$name}"]->getActionName());
    }
});

it('gives both public groups the identical middleware list, compared from the router', function () {
    $english = publicLocaleRoutesNamed('public.');
    $arabic = publicLocaleRoutesNamed('ar.public.');

    foreach (array_keys(PUBLIC_LOCALE_PAGE_URIS) as $name) {
        // Both lists, and both exclusion lists: the four middleware the group removes
        // are what make the page sessionless.
        expect($arabic["ar.public.{$name}"]->gatherMiddleware())->toBe($english["public.{$name}"]->gatherMiddleware(), "ar.public.{$name} carries a different middleware list.")
            ->and($arabic["ar.public.{$name}"]->excludedMiddleware())->toBe($english["public.{$name}"]->excludedMiddleware(), "ar.public.{$name} removes different middleware.");
    }

    // The control, written by hand: the list is not empty, and it holds the limiter and
    // the locale middleware, so equal-and-empty cannot pass.
    expect($arabic['ar.public.home']->gatherMiddleware())->toContain('throttle:public-site')->toContain(SetPublicLocale::class);
});

it('serves 120 requests a minute across both prefixes together and refuses the next, English or Arabic', function () {
    for ($i = 1; $i <= 60; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/about')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/ar/about')->assertOk();
    }

    // One budget: 60 + 60 is the group's 120, whichever prefix spent them.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/about')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/ar/about')->assertStatus(429);
});

/*
|--------------------------------------------------------------------------
| The language, from the URL, with no session
|--------------------------------------------------------------------------
*/

it('renders every public page in Arabic under /ar and in English without it, and sets no cookie', function () {
    foreach (publicLocalePages() as $label => $path) {
        $arabic = $this->get(publicLocaleArabicPath($path))->assertOk();
        $english = $this->get($path)->assertOk();

        // The English request comes AFTER the Arabic one in the same process, so a
        // locale left standing by the first would show here.
        // str_contains, not toContain(): that is variadic, so a second argument would be a
        // second expected string rather than the failure message.
        expect(str_contains((string) $arabic->getContent(), '<html lang="ar" dir="rtl">'))->toBeTrue("{$label}: the Arabic page is not Arabic.")
            ->and(str_contains((string) $english->getContent(), '<html lang="en" dir="ltr">'))->toBeTrue("{$label}: the English page is not English.");

        foreach ([$arabic, $english] as $response) {
            expect($response->headers->getCookies())->toBe([], "{$label}: a page set a cookie.")
                ->and($response->headers->has('Set-Cookie'))->toBeFalse("{$label}: a page sent Set-Cookie.");
        }
    }
});

it('ignores a locale left standing from an earlier request and takes the language from the route alone', function () {
    // The process is long-lived in a worker: whatever the last request set is still
    // set. An English page must not inherit Arabic, nor an Arabic one English.
    app()->setLocale('ar');
    $this->get('/about')->assertOk()->assertSee('<html lang="en" dir="ltr">', false);

    app()->setLocale('en');
    $this->get('/ar/about')->assertOk()->assertSee('<html lang="ar" dir="rtl">', false);
});

it('sets the locale from the route name and reads no session', function (?string $routeName, string $expected) {
    app()->setLocale($expected === 'ar' ? 'en' : 'ar');

    $request = Request::create('/anything');
    $route = new RoutingRoute('GET', '/anything', fn (): string => 'x');

    if ($routeName !== null) {
        $route->name($routeName);
    }

    $request->setRouteResolver(fn (): RoutingRoute => $route);

    $reached = false;
    $response = (new SetPublicLocale)->handle($request, function () use (&$reached): Response {
        $reached = true;

        return new Response('ok');
    });

    expect($reached)->toBeTrue()
        ->and($response->getContent())->toBe('ok')
        ->and(app()->getLocale())->toBe($expected)
        // It never asked for a session: a request that was handed none still has none.
        ->and($request->hasSession())->toBeFalse();
})->with([
    'an Arabic public route' => ['ar.public.about', 'ar'],
    'an Arabic verifier route' => ['ar.verify.certificates.form', 'ar'],
    'an English public route' => ['public.about', 'en'],
    'an English verifier route' => ['verify.certificates.form', 'en'],
    'an unnamed route' => [null, 'en'],
    'a name that only contains ar.' => ['filament.ar.something', 'en'],
]);

/*
|--------------------------------------------------------------------------
| Every link stays in its language
|--------------------------------------------------------------------------
*/

/**
 * The path-and-query of every link a page offers that stays on this site, with what
 * each is: anchors and form actions. A fragment, a mailto, a tel and any other origin
 * are not paths of this site.
 *
 * @return list<array{0: string, 1: string}> [kind, path]
 */
function publicLocaleSiteLinks(HTMLDocument $document): array
{
    $origin = rtrim(url('/'), '/');
    $found = [];

    foreach ($document->querySelectorAll('a[href], form[action]') as $element) {
        $value = $element->getAttribute($element->localName === 'form' ? 'action' : 'href');

        if (str_starts_with($value, $origin.'/') || $value === $origin) {
            $found[] = [$element->localName === 'form' ? 'form' : 'link', substr($value, strlen($origin)) ?: '/'];
        }
    }

    return $found;
}

it('keeps every link of an Arabic page under /ar, the language switch and the student portal excepted', function () {
    $portal = parse_url(route('filament.student.auth.login'), PHP_URL_PATH);

    foreach (publicLocalePages() as $label => $path) {
        $document = publicLocaleDocument($this->get(publicLocaleArabicPath($path))->assertOk());

        // The switch's own link to the English page is the one link meant to leave.
        $switchHrefs = [];

        foreach ($document->querySelectorAll('[data-locale-switch] a') as $link) {
            $switchHrefs[] = $link->getAttribute('href');
        }

        expect($switchHrefs)->toHaveCount(1, "{$label}: the switch should hold exactly one link.");

        $checked = 0;

        foreach (publicLocaleSiteLinks($document) as [, $linkPath]) {
            if ($linkPath === $portal || url($linkPath) === $switchHrefs[0] || url($linkPath) === rtrim($switchHrefs[0], '/')) {
                continue;
            }

            $checked++;

            expect(str_starts_with($linkPath, '/ar') && (strlen($linkPath) === 3 || in_array($linkPath[3], ['/', '?', '#'], true)))
                ->toBeTrue("{$label}: the Arabic page links to {$linkPath}, which is not under /ar.");
        }

        // The control: a page whose links were all skipped would pass for checking none.
        expect($checked)->toBeGreaterThan(5);
    }
});

it('keeps every link of an English page out of /ar, the language switch excepted', function () {
    foreach (publicLocalePages() as $label => $path) {
        $document = publicLocaleDocument($this->get($path)->assertOk());
        $switchHref = $document->querySelector('[data-locale-switch] a')->getAttribute('href');

        $checked = 0;

        foreach (publicLocaleSiteLinks($document) as [, $linkPath]) {
            if (url($linkPath) === $switchHref) {
                continue;
            }

            $checked++;

            expect($linkPath === '/ar' || str_starts_with($linkPath, '/ar/') || str_starts_with($linkPath, '/ar?'))
                ->toBeFalse("{$label}: the English page links to {$linkPath}, which is Arabic.");
        }

        expect($checked)->toBeGreaterThan(5);
    }
});

it('points the home page\'s verifier box at the verifier\'s lookup in its own language', function () {
    $arabic = publicLocaleDocument($this->get('/ar')->assertOk())->querySelector('form');
    $english = publicLocaleDocument($this->get('/')->assertOk())->querySelector('form');

    expect($arabic->getAttribute('action'))->toBe(url('/ar/verify/lookup'))
        ->and($arabic->getAttribute('method'))->toBe('get')
        ->and($english->getAttribute('action'))->toBe(url('/verify/lookup'));
});

/*
|--------------------------------------------------------------------------
| The counterpart: the same page in the other language
|--------------------------------------------------------------------------
*/

it('links the switch and the head to the same page in the other language, parameters and query included', function (string $englishPath, string $arabicPath) {
    Course::factory()->create(['id' => 5, 'name_en' => 'Fifth']);
    Article::factory()->published()->create(['slug' => 'a-paper', 'topic' => 'Quartz', 'title_en' => 'A Paper']);

    $english = publicLocaleDocument($this->get($englishPath)->assertOk());
    $arabic = publicLocaleDocument($this->get($arabicPath)->assertOk());

    // The English page's link goes to the Arabic URL, and the Arabic page's to the English one.
    expect($english->querySelector('[data-locale-switch] a')->getAttribute('href'))->toBe(url($arabicPath))
        ->and($arabic->querySelector('[data-locale-switch] a')->getAttribute('href'))->toBe($englishPath === '/' ? url('/') : url($englishPath));

    // The head lists both languages, the same two URLs on either page.
    foreach ([$english, $arabic] as $document) {
        $alternates = [];

        foreach ($document->querySelectorAll('link[rel="alternate"]') as $link) {
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }

        expect($alternates)->toBe(['en' => $englishPath === '/' ? url('/') : url($englishPath), 'ar' => url($arabicPath)]);
    }
})->with([
    'the home page' => ['/', '/ar'],
    'a plain page' => ['/about', '/ar/about'],
    'a page with a parameter' => ['/programmes/5', '/ar/programmes/5'],
    'a page with a slug' => ['/publications/a-paper', '/ar/publications/a-paper'],
    // The query is sorted by key (Symfony normalises it), and these are already in order.
    'a page with a query' => ['/publications?q=paper&topic=Quartz', '/ar/publications?q=paper&topic=Quartz'],
    'a page with a page number' => ['/publications?page=2', '/ar/publications?page=2'],
]);

it('builds the counterpart from the route, so a page with no twin throws and never links to a 404', function () {
    Route::get('/english-only', fn (): string => 'x')->name('public.english-only');
    Route::getRoutes()->refreshNameLookups();

    $request = Request::create('/english-only');
    $request->setRouteResolver(fn (): RoutingRoute => Route::getRoutes()->match($request));

    expect(PublicRoute::counterpart('en', $request))->toBe(url('/english-only'))
        ->and(fn () => PublicRoute::counterpart('ar', $request))->toThrow(LogicException::class, 'ar.public.english-only');
});

it('reports no alternates for a request that is not on a named route', function () {
    expect(PublicRoute::alternates(Request::create('/nowhere')))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| No new script
|--------------------------------------------------------------------------
*/

it('loads no script on any Arabic page, the hero seal on the Arabic home page excepted', function () {
    foreach (publicLocalePages() as $label => $path) {
        $html = strtolower((string) $this->get(publicLocaleArabicPath($path))->assertOk()->getContent());

        // Only the hero seal's module (HeroSealTest), and only from a build.
        $withoutSeal = (string) preg_replace('/<script\b[^>]*\bsrc="[^"]*\/build\/assets\/public-seal-[^"\/]*\.js"[^>]*><\/script>/', '', $html);

        expect(str_contains($withoutSeal, '<script'))->toBeFalse("{$label}: the Arabic page loads a script.");
    }
});
