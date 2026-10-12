<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Support\PublicContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

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
        'app.name' => 'Test Centre',
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

    // The header's link carries the header's classes, the page's its own.
    expect($html)->toContain('<a href="mailto:desk@example.test" dir="ltr" class="hover:text-gold-400 focus-visible:outline-gold-400">')
        ->toContain('<a href="mailto:desk@example.test" dir="ltr" class="underline hover:text-navy-700">')
        ->toContain('<a href="tel:+218912345678" dir="ltr" class="hover:text-gold-400 focus-visible:outline-gold-400">')
        ->toContain('<a href="tel:+218912345678" dir="ltr" class="underline hover:text-navy-700">');
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

it('links to about, programmes and contact from the navigation and the footer', function () {
    $html = (string) $this->get('/')->assertOk()->getContent();

    $nav = publicLinksWithin($html, '<nav aria-label=', '</nav>');
    $footer = publicLinksWithin($html, '<footer', '</footer>');

    // The navigation is rendered twice from one list, narrow then wide, so each
    // link appears twice and the order is the list's order.
    $expected = [url('/about'), url('/programmes'), url('/verify/certificates'), url('/contact')];

    expect(array_values(array_unique($nav)))->toBe($expected)
        ->and($nav)->toHaveCount(count($expected) * 2)
        ->and($footer)->toContain(url('/about'))->toContain(url('/programmes'))->toContain(url('/contact'));
});

it('sets no cookie on any public GET route', function () {
    // A real id for programmes.show, and a placeholder for any parameter this
    // file has no fixture for: a 404 on the sessionless group sets no cookie
    // either, so a route added later is held to the property without an edit here.
    $course = Course::factory()->create();

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'public.') && in_array('GET', $route->methods(), true));

    expect($routes->map(fn (RoutingRoute $route): string => (string) $route->getName())->all())
        ->toContain('public.home', 'public.about', 'public.programmes.index', 'public.programmes.show', 'public.contact');

    foreach ($routes as $route) {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[$name] = $route->getName() === 'public.programmes.show' ? $course->id : 'placeholder';
        }

        $response = $this->get(route($route->getName(), $parameters));

        expect($response->headers->getCookies())->toBe([], "{$route->getName()} set a cookie.")
            ->and($response->headers->has('Set-Cookie'))->toBeFalse("{$route->getName()} sent a Set-Cookie header.");
    }
});
