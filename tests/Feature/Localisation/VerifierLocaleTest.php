<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Http\Middleware\SetPublicLocale;
use App\Http\Middleware\VerificationResponseHeaders;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Livewire's "a component rendered" flag outlives a test and would inject its own
// script into a page, which the no-script assertions of the verifier's suite count.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The certificate verifier in two languages (P4-T18a)
|--------------------------------------------------------------------------
|
| The verifier has a session, a CSRF token and a rate limit that the public pages do
| not, so its Arabic half is held to different things:
|
|   - the Arabic and the English journeys each STAY in their language through the form,
|     the submit, the result and "verify another", because every link and the one
|     redirect go through PublicRoute;
|   - the misses stay BYTE-IDENTICAL within each language across every way to miss, the
|     language switch on them included (it links to the other language's FORM, never to
|     the page that was asked for);
|   - the two prefixes draw on ONE rate-limit budget, because the limiter is keyed by the
|     client's IP and nothing else, and carry IDENTICAL middleware, compared from the
|     router.
|
| EVERY EXPECTED URL IS WRITTEN OUT BY HAND, from the reference this file mints.
*/

/** The two languages: the URL prefix, the language, the direction, and the other prefix. */
const VERIFIER_LOCALES = [
    'English' => ['', 'en', 'ltr', '/ar'],
    'Arabic' => ['/ar', 'ar', 'rtl', ''],
];

/** @return HTMLDocument the page, parsed. */
function verifierLocaleDocument(TestResponse $response): HTMLDocument
{
    return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
}

/**
 * The five ways to miss, as the response each gives under a prefix: a malformed GET,
 * an unknown GET, an empty POST, a malformed POST, and the home page's GET lookup.
 *
 * @return array<string, TestResponse>
 */
function verifierLocaleMisses(object $test, string $prefix): array
{
    $unknown = app(CertificateReference::class)->mint(now());

    return [
        'a malformed GET' => $test->get("{$prefix}/verify/certificates/not-a-real-reference"),
        'an unknown GET' => $test->get("{$prefix}/verify/certificates/{$unknown}"),
        'an empty POST' => $test->post("{$prefix}/verify/certificates", ['reference' => '']),
        'a malformed POST' => $test->post("{$prefix}/verify/certificates", ['reference' => 'not-a-real-reference']),
        'a lookup' => $test->get("{$prefix}/verify/lookup?reference=not-a-real-reference"),
    ];
}

/*
|--------------------------------------------------------------------------
| The routes
|--------------------------------------------------------------------------
*/

it('registers the four verifier routes twice, the Arabic ones named ar.verify.certificates.*', function () {
    $byName = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (is_string($route->getName()) && preg_match('/^(ar\.)?verify\.certificates\./', $route->getName()) === 1) {
            $byName[$route->getName()] = $route;
        }
    }

    // Written by hand: name => [methods, URI].
    $expected = [
        'verify.certificates.form' => [['GET', 'HEAD'], 'verify/certificates'],
        'verify.certificates.submit' => [['POST'], 'verify/certificates'],
        'verify.certificates.lookup' => [['GET', 'HEAD'], 'verify/lookup'],
        'verify.certificates.show' => [['GET', 'HEAD'], 'verify/certificates/{reference}'],
        'ar.verify.certificates.form' => [['GET', 'HEAD'], 'ar/verify/certificates'],
        'ar.verify.certificates.submit' => [['POST'], 'ar/verify/certificates'],
        'ar.verify.certificates.lookup' => [['GET', 'HEAD'], 'ar/verify/lookup'],
        'ar.verify.certificates.show' => [['GET', 'HEAD'], 'ar/verify/certificates/{reference}'],
    ];

    expect(array_keys($byName))->toEqualCanonicalizing(array_keys($expected));

    foreach ($expected as $name => [$methods, $uri]) {
        expect($byName[$name]->methods())->toBe($methods)
            ->and($byName[$name]->uri())->toBe($uri);
    }
});

it('gives the Arabic verifier routes the identical middleware list, compared from the router', function () {
    foreach (['form', 'submit', 'lookup', 'show'] as $name) {
        $english = Route::getRoutes()->getByName("verify.certificates.{$name}");
        $arabic = Route::getRoutes()->getByName("ar.verify.certificates.{$name}");

        expect($arabic->gatherMiddleware())->toBe($english->gatherMiddleware(), "ar.verify.certificates.{$name} carries a different middleware list.")
            ->and($arabic->excludedMiddleware())->toBe($english->excludedMiddleware());

        // The control, by hand: the list holds the three the group exists for, so an
        // equal pair of empty lists cannot pass.
        expect($arabic->gatherMiddleware())
            ->toContain(VerificationResponseHeaders::class)
            ->toContain('throttle:certificate-verification')
            ->toContain(SetPublicLocale::class);
    }
});

it('leaves the verifier its session and its CSRF token in both languages', function (string $label) {
    [$prefix] = VERIFIER_LOCALES[$label];

    $response = $this->get("{$prefix}/verify/certificates")->assertOk();
    $token = verifierLocaleDocument($response)->querySelector('form input[name="_token"]');

    expect(collect($response->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->all())->toContain(config('session.cookie'))
        ->and($token?->getAttribute('value'))->not->toBeEmpty();
})->with(array_keys(VERIFIER_LOCALES));

/*
|--------------------------------------------------------------------------
| The journeys
|--------------------------------------------------------------------------
*/

it('keeps the journey in its language through the form, the submit, the result and "verify another"', function (string $label) {
    [$prefix, $language, $direction, $other] = VERIFIER_LOCALES[$label];
    $certificate = StudentCertificate::factory()->create();
    $reference = $certificate->reference_number;
    $home = $prefix === '' ? url('/') : url($prefix);

    // 1. The form posts to its own language's submit URL, and links home in its language.
    $form = verifierLocaleDocument($this->get("{$prefix}/verify/certificates")->assertOk());

    expect($form->querySelector('form')->getAttribute('action'))->toBe(url("{$prefix}/verify/certificates"))
        ->and($form->querySelector('html')->getAttribute('lang'))->toBe($language)
        ->and($form->querySelector('html')->getAttribute('dir'))->toBe($direction)
        ->and($form->querySelector('header a')->getAttribute('href'))->toBe($home)
        ->and($form->querySelector('.back-link')->getAttribute('href'))->toBe($home);

    // 2. A valid reference redirects to the result under the same prefix, whichever door it came in by.
    $this->post("{$prefix}/verify/certificates", ['reference' => strtolower($reference)])
        ->assertRedirect(url("{$prefix}/verify/certificates/{$reference}"));
    $this->get("{$prefix}/verify/lookup?reference={$reference}")
        ->assertRedirect(url("{$prefix}/verify/certificates/{$reference}"));

    // 3. The result, in its language, with "verify another" back to its own form.
    $result = verifierLocaleDocument($this->get("{$prefix}/verify/certificates/{$reference}")->assertOk());

    expect($result->querySelector('html')->getAttribute('lang'))->toBe($language)
        ->and($result->querySelector('main a')->getAttribute('href'))->toBe(url("{$prefix}/verify/certificates"))
        ->and($result->querySelector('header a')->getAttribute('href'))->toBe($home);

    // 4. The miss, with "verify another" back to its own form too.
    $miss = verifierLocaleDocument($this->get("{$prefix}/verify/certificates/not-a-real-reference")->assertNotFound());

    expect($miss->querySelector('main a')->getAttribute('href'))->toBe(url("{$prefix}/verify/certificates"))
        ->and($miss->querySelector('html')->getAttribute('lang'))->toBe($language);
})->with(array_keys(VERIFIER_LOCALES));

it('links the result\'s switch to the same certificate in the other language and its miss\'s to the other form', function (string $label) {
    [$prefix, , , $other] = VERIFIER_LOCALES[$label];
    $reference = StudentCertificate::factory()->create()->reference_number;

    $result = verifierLocaleDocument($this->get("{$prefix}/verify/certificates/{$reference}")->assertOk());
    $miss = verifierLocaleDocument($this->get("{$prefix}/verify/certificates/not-a-real-reference")->assertNotFound());

    expect($result->querySelector('[data-locale-switch] a')->getAttribute('href'))->toBe(url("{$other}/verify/certificates/{$reference}"))
        ->and($miss->querySelector('[data-locale-switch] a')->getAttribute('href'))->toBe(url("{$other}/verify/certificates"));
})->with(array_keys(VERIFIER_LOCALES));

/*
|--------------------------------------------------------------------------
| The uniform miss, in each language
|--------------------------------------------------------------------------
*/

it('answers every way to miss with the same bytes at 404, in each language', function (string $label) {
    [$prefix, $language, , $other] = VERIFIER_LOCALES[$label];

    $misses = verifierLocaleMisses($this, $prefix);

    foreach ($misses as $shape => $response) {
        // The lookup of a malformed reference is a GET to /verify/lookup: a 404 too.
        expect($response->getStatusCode())->toBe(404, "{$shape} did not miss.");
    }

    $bodies = array_map(fn (TestResponse $response): string => (string) $response->getContent(), $misses);
    $first = reset($bodies);

    foreach ($bodies as $shape => $body) {
        expect($body)->toBe($first, "{$shape} differs from the others.");
    }

    // The bytes are this language's, and they point at the OTHER language's form and
    // nowhere else: a link to the submitted path is how the four would stop agreeing.
    $document = HTMLDocument::createFromString($first, LIBXML_NOERROR);

    expect($first)->toContain("<html lang=\"{$language}\"")
        ->and($document->querySelector('[data-locale-switch] a')->getAttribute('href'))->toBe(url("{$other}/verify/certificates"))
        ->and($first)->not->toContain('not-a-real-reference');
})->with(array_keys(VERIFIER_LOCALES));

it('does not let the two languages share a miss page', function () {
    $english = (string) $this->get('/verify/certificates/not-a-real-reference')->assertNotFound()->getContent();
    $arabic = (string) $this->get('/ar/verify/certificates/not-a-real-reference')->assertNotFound()->getContent();

    // The control for the comparisons above: they compare a language with itself, and
    // would pass just as well if the language never reached the page.
    expect($arabic)->not->toBe($english);
});

/*
|--------------------------------------------------------------------------
| One limiter budget, the disclosure headers, and the words
|--------------------------------------------------------------------------
*/

it('draws both prefixes on one budget of ten a minute and refuses the eleventh on either', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->get('/verify/certificates/not-a-real-reference')->assertNotFound();
        $this->get('/ar/verify/certificates/not-a-real-reference')->assertNotFound();
    }

    // Ten spent, five on each prefix. Had each prefix its own budget, either of these
    // would be a 404.
    $this->get('/verify/certificates/not-a-real-reference')->assertStatus(429);
    $this->get('/ar/verify/certificates/not-a-real-reference')->assertStatus(429);
});

it('keys the limiter on the client\'s address alone, whatever route the request is for', function () {
    $callback = RateLimiter::limiter('certificate-verification');

    $keys = fn (string $uri): array => array_map(
        fn ($limit): string => $limit->key,
        $callback(Request::create($uri, server: ['REMOTE_ADDR' => '203.0.113.7'])),
    );

    // Hand-written: a window name, a colon, the address. No route, no language.
    expect($keys('/verify/certificates'))->toBe(['minute:203.0.113.7', 'hour:203.0.113.7'])
        ->and($keys('/ar/verify/certificates/TC-2026-ABCDEFGH'))->toBe(['minute:203.0.113.7', 'hour:203.0.113.7']);
});

it('sends the three disclosure headers on every Arabic verifier response', function (string $path, int $status) {
    $reference = StudentCertificate::factory()->create()->reference_number;

    $response = $this->get(str_replace('{reference}', $reference, $path));

    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive');
})->with([
    'the form' => ['/ar/verify/certificates', 200],
    'a result' => ['/ar/verify/certificates/{reference}', 200],
    'a miss' => ['/ar/verify/certificates/not-a-real-reference', 404],
]);

it('says the status in the verifier\'s own words, not the staff register\'s', function (string $label) {
    [$prefix, $language] = VERIFIER_LOCALES[$label];

    // Sentinels in the verifier's catalogue, and a different one in the register's: the
    // page must print the first and never the second.
    app('translator')->load('*', 'verify', $language);
    app('translator')->load('*', 'certificates', $language);
    app('translator')->addLines(['verify.status_valid' => 'VERIFIER-VALID-WORD'], $language);
    app('translator')->addLines(['certificates.certificate_status.valid' => 'REGISTER-VALID-WORD'], $language);

    $reference = StudentCertificate::factory()->create()->reference_number;
    $html = (string) $this->get("{$prefix}/verify/certificates/{$reference}")->assertOk()->getContent();

    // The headline and the Status row.
    expect(substr_count($html, 'VERIFIER-VALID-WORD'))->toBe(2)
        ->and($html)->not->toContain('REGISTER-VALID-WORD');
})->with(array_keys(VERIFIER_LOCALES));
