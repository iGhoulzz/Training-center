<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Domain\Publications\Models\Article;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Rendering the student panel's login page marks "a Livewire component rendered
// this request" in static state that outlives the test, and the next public page
// would then carry Livewire's script. Flushed before each test and after it.
beforeEach(function (): void {
    Livewire::flushState();
});

afterEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The student portal's entry on the public site (P4-T15, folded into T20a)
|--------------------------------------------------------------------------
|
| A LINK, NEVER A FORM. The design draws an email-and-password modal. A public page
| holds no session, so a form posted from it would carry no CSRF token, and a second
| sign-in path is exactly what the portal should not grow. The header's pill, the
| narrow menu's last item and the footer's link all go to the student panel's own
| login page.
|
| The expected URL is the route's, resolved BY NAME in this file: the views get it
| from the panel, and the two agreeing is the assertion.
*/

/** The student panel's login URL, from the route's name. */
function portalEntryLoginUrl(): string
{
    return route('filament.student.auth.login', ['locale' => 'en']);
}

/**
 * Every public page worth rendering, as label => path, with the fixtures the
 * parametric ones need.
 *
 * @return array<string, string>
 */
function portalEntryPages(): array
{
    $course = Course::factory()->create();
    $article = Article::factory()->published()->create();

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

/** A page's DOM. */
function portalEntryDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/**
 * Replace one public translation line with a sentinel for the rest of this test.
 *
 * The group is loaded FIRST: `addLines()` writes into the translator's loaded table
 * without reading the file, and a group already marked loaded is never read, so
 * adding one line to an unloaded group would blank every other key in it.
 */
function portalEntryReplaceLine(string $key, string $value): void
{
    app('translator')->load('*', 'public', 'en');
    app('translator')->addLines([$key => $value], 'en');
}

/**
 * Every link on this page that goes to the portal's sign-in page.
 *
 * @return array<int, Element>
 */
function portalEntryLinksTo(HTMLDocument $document, string $url, string $within = ''): array
{
    $links = [];

    foreach ($document->querySelectorAll(trim($within.' a')) as $link) {
        if ($link->getAttribute('href') === $url) {
            $links[] = $link;
        }
    }

    return $links;
}

it('puts the portal link in the header bar, as the last item of the narrow menu, and in the footer, on every public page', function () {
    portalEntryReplaceLine('public.portal.link', 'SENTINEL-PORTAL');

    $login = portalEntryLoginUrl();

    foreach (portalEntryPages() as $label => $path) {
        $document = portalEntryDocument((string) $this->get($path)->assertOk()->getContent());

        // The pill in the bar: in the header, and not inside the navigation.
        $inNavigation = portalEntryLinksTo($document, $login, 'header nav');
        $inHeader = portalEntryLinksTo($document, $login, 'header');

        expect($inHeader)->toHaveCount(2, "{$label}: the header should link to the portal twice (the pill and the menu item).")
            ->and($inNavigation)->toHaveCount(1, "{$label}: the menu holds one portal link.");

        // The menu's is the LAST item of the list the menu opens.
        $menuItems = $document->querySelectorAll('header nav #public-mobile-menu > li');
        $last = $menuItems[count($menuItems) - 1];

        expect($last->querySelector('a')?->getAttribute('href'))->toBe($login, "{$label}: the portal link is not the menu's last item.");

        // The footer's plain link.
        $inFooter = portalEntryLinksTo($document, $login, 'footer');

        expect($inFooter)->toHaveCount(1, "{$label}: the footer should link to the portal once.");

        foreach ([...$inHeader, ...$inFooter] as $link) {
            expect(trim((string) preg_replace('/\s+/', ' ', $link->textContent)))->toBe('SENTINEL-PORTAL');
        }
    }
});

it('hides the pill below 640px and the menu item from 640px, so the portal is reachable at every width', function () {
    $document = portalEntryDocument((string) $this->get('/')->assertOk()->getContent());
    $login = portalEntryLoginUrl();

    $pill = collect(portalEntryLinksTo($document, $login, 'header'))
        ->first(fn (Element $link): bool => $link->closest('nav') === null);
    $menuItem = collect(portalEntryLinksTo($document, $login, 'header nav'))->first();

    // The wrapper around each carries the breakpoint: `hidden sm:block` for the pill
    // (shown from 640px) and `sm:hidden` for the menu item (shown below it).
    expect(explode(' ', (string) $pill->parentNode->getAttribute('class')))->toContain('hidden')->toContain('sm:block')
        ->and(explode(' ', (string) $menuItem->parentNode->getAttribute('class')))->toContain('sm:hidden');

    // The menu itself is for narrow screens only, from 1024px up the links are a row.
    expect(explode(' ', (string) $document->querySelector('header nav #public-mobile-menu')->parentNode->getAttribute('class')))->toContain('lg:hidden');
});

it('links each form to the student panel login route, which renders', function () {
    $login = portalEntryLoginUrl();

    expect(Route::has('filament.student.auth.login'))->toBeTrue()
        ->and($login)->toBe(url('/portal/login?locale=en'));

    // The page the link opens is the panel's own sign-in, not a copy of it.
    $this->get($login)->assertOk();
});

it('prints the design copy about a login needing an email, from its own key', function () {
    portalEntryReplaceLine('public.portal.note', 'SENTINEL-PORTAL-NOTE');

    $footer = portalEntryDocument((string) $this->get('/about')->assertOk()->getContent())->querySelector('footer');

    expect($footer->textContent)->toContain('SENTINEL-PORTAL-NOTE');
});

it('ships the design copy, which says why a student may have no login', function () {
    // The design's own sign-in note, so a student with no email on their record
    // learns that the registrar issues a login when they enrol and needs one.
    expect(trans('public.portal.note'))->toContain('No login yet?')->toContain('needs an email on your student record');
});

it('holds no sign-in form and no form that submits data on any public page', function () {
    $sawTheSearchForm = false;

    foreach (portalEntryPages() as $label => $path) {
        $document = portalEntryDocument((string) $this->get($path)->assertOk()->getContent());

        expect(count($document->querySelectorAll('input[type="password"]')))->toBe(0, "{$label} has a password field.")
            ->and(count($document->querySelectorAll('input[name="_token"]')))->toBe(0, "{$label} carries a CSRF token.")
            ->and(count($document->querySelectorAll('[formmethod], [formaction]')))->toBe(0, "{$label} has a control that overrides its form.");

        // The only kind of form a public page may hold is a read-only GET form, such
        // as T13's search, which only writes its fields into the URL. PublicSessionlessTest
        // scans every view's source for the same rule; this is the rendered page's side of it.
        foreach ($document->querySelectorAll('form') as $form) {
            expect(strtolower($form->getAttribute('method')))->toBe('get', "{$label} has a form that is not a read-only GET form.");

            $sawTheSearchForm = $sawTheSearchForm || $label === 'publications';
        }
    }

    // The control: a check over pages that held no form at all would pass for
    // having looked at none.
    expect($sawTheSearchForm)->toBeTrue('The publications search form was not seen, so the form check ran over nothing.');
});

/**
 * The layout, every file under resources/views/public/ and every public component,
 * as absolute paths.
 *
 * @return array<int, string>
 */
function portalEntryViewFiles(): array
{
    $files = [resource_path('views/layouts/public.blade.php')];

    foreach ([resource_path('views/public'), resource_path('views/components/public')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $files[] = (string) $file->getRealPath();
        }
    }

    return $files;
}

it('builds the portal URL in one component and names the portal nowhere else', function () {
    $builders = [];
    $namers = [];

    foreach (portalEntryViewFiles() as $path) {
        // A Blade comment is not rendered and may explain the rule.
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', (string) file_get_contents($path));
        $name = basename($path);

        if (preg_match('/getLoginUrl|getPanel\s*\(|filament\s*\(/', $code) === 1) {
            $builders[] = $name;
        }

        // The panel's path or route name written out: a hardcoded sign-in URL.
        if (preg_match('#/portal\b|filament\.student|[\'"]student[\'"]#', $code) === 1) {
            $namers[] = $name;
        }
    }

    // The control: the scan has to see the component it expects to find.
    expect(array_map('basename', portalEntryViewFiles()))->toContain('portal-link.blade.php');

    expect($builders)->toBe(['portal-link.blade.php'], 'Only portal-link builds the portal URL; T18 adds the locale there once.')
        ->and($namers)->toBe(['portal-link.blade.php'], 'Only portal-link may name the student panel.');
});
