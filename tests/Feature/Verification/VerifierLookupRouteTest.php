<?php

declare(strict_types=1);

use App\Http\Controllers\VerifyCertificateController;
use App\Http\Middleware\VerificationResponseHeaders;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The home page's read-only verifier box: GET verify/lookup (P4-T20b)
|--------------------------------------------------------------------------
|
| The home page's box is a `method="get"` form (owner, 2026-10-13), so it needs a
| GET route that takes `reference` from the query string. That route is the
| verifier's own, inside its group, and runs the POST's own submit(): there is no
| second copy of the reference rule, no second kind of miss, and nothing outside
| the group's headers and `certificate-verification` budget. A reference sent as
| an array gets that same miss on both routes (owner, 2026-10-16).
|
| Every expected value below is written out here: the reference is a literal drawn
| from CertificateReference::ALPHABET, the redirect target is built by hand from
| its uppercase form, and the disclosure headers are literals, so none of them is
| read back from the code under test.
*/

// Eight characters, every one in CertificateReference::ALPHABET (which has no I,
// L, O, 0 or 1). Typed in lower case with spaces round it below.
const LOOKUP_SENTINEL_REFERENCE = 'TC-2026-ABCDEFGH';

/**
 * Assert the three disclosure headers, written as literals.
 *
 * Its own name, because VerificationHeadersTest declares a global helper of its
 * own and Pest loads every test file into one process.
 */
function assertLookupResponseDisclosesNothing(TestResponse $response): void
{
    // Cache-Control is read back alphabetised by Symfony, so the directives are
    // checked one by one (see VerificationHeadersTest).
    expect($response->headers->hasCacheControlDirective('private'))->toBeTrue('Missing the `private` Cache-Control directive.');
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue('Missing the `no-store` Cache-Control directive.');
    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer', 'Missing or wrong `Referrer-Policy` header.');
    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive', 'Missing or wrong `X-Robots-Tag` header.');
}

/**
 * A response's header bag with the named per-request headers taken out, plus the
 * names of its cookies.
 *
 * The three exclusions are the only ones, each for a reason that is not about the
 * route:
 *  - `date` is the clock, read when the response is prepared.
 *  - `set-cookie` carries a fresh session id and token each time, so the cookie
 *    NAMES are compared instead (the second entry of the returned pair).
 *  - `x-ratelimit-remaining` counts down between the two requests, because both
 *    spend the same budget.
 *
 * @return array{0: array<string, array<int, string|null>>, 1: array<int, string>}
 */
function lookupComparableHeaders(TestResponse $response): array
{
    $headers = $response->headers->all();

    foreach (['date', 'set-cookie', 'x-ratelimit-remaining'] as $perRequest) {
        unset($headers[$perRequest]);
    }

    ksort($headers);

    $cookies = array_map(fn (Cookie $cookie): string => $cookie->getName(), $response->headers->getCookies());
    sort($cookies);

    return [$headers, $cookies];
}

it('redirects a well-formed reference to the result page exactly as the POST does', function () {
    $typed = '  '.strtolower(LOOKUP_SENTINEL_REFERENCE).'  ';

    $lookup = $this->get(route('verify.certificates.lookup', ['reference' => $typed]));
    $submission = $this->post(route('verify.certificates.submit'), ['reference' => $typed]);

    // Built by hand from the uppercase sentinel, never from the controller.
    $expected = url('/verify/certificates/TC-2026-ABCDEFGH');

    expect($lookup->getStatusCode())->toBe($submission->getStatusCode())
        ->and($lookup->headers->get('Location'))->toBe($submission->headers->get('Location'))
        ->and($lookup->headers->get('Location'))->toBe($expected);

    $lookup->assertRedirect($expected);
});

it('answers an empty, an absent and a malformed reference with the miss show() gives an unknown one', function (?string $reference) {
    $query = $reference === null ? [] : ['reference' => $reference];

    // A well-formed reference nobody holds: the miss every other path must equal.
    $unknown = $this->get(route('verify.certificates.show', ['reference' => LOOKUP_SENTINEL_REFERENCE]));
    $lookup = $this->get(route('verify.certificates.lookup', $query));

    $unknown->assertNotFound();
    $lookup->assertNotFound();

    expect($lookup->getContent())->toBe($unknown->getContent());

    [$unknownHeaders, $unknownCookies] = lookupComparableHeaders($unknown);
    [$lookupHeaders, $lookupCookies] = lookupComparableHeaders($lookup);

    // A comparison of two empty bags would pass, so the bag must hold what the
    // route is for.
    expect(array_keys($lookupHeaders))->toContain('cache-control', 'referrer-policy', 'x-robots-tag', 'content-type')
        ->and($lookupHeaders)->toBe($unknownHeaders)
        ->and($lookupCookies)->toBe($unknownCookies);
})->with([
    'empty' => [''],
    'absent' => [null],
    'malformed' => ['NOT-A-REFERENCE'],
]);

it('answers a reference sent as an array with the same miss, on the lookup and on the POST', function (string $method, string $uri, array $body) {
    // submit() once cast the input with (string), so an array was a warning and a
    // 500: a different answer for a different SHAPE of input, which the uniform
    // miss exists to deny. The lookup made it a link anyone can follow, so both
    // routes are held to the miss here (owner, 2026-10-16). The paths are written
    // by hand, so the array encoding is exactly the one a crafted URL would use.
    $unknown = $this->get(route('verify.certificates.show', ['reference' => LOOKUP_SENTINEL_REFERENCE]));
    $array = $method === 'GET' ? $this->get($uri) : $this->post($uri, $body);

    $unknown->assertNotFound();
    $array->assertNotFound();

    expect($array->getContent())->toBe($unknown->getContent());

    [$unknownHeaders, $unknownCookies] = lookupComparableHeaders($unknown);
    [$arrayHeaders, $arrayCookies] = lookupComparableHeaders($array);

    expect(array_keys($arrayHeaders))->toContain('cache-control', 'referrer-policy', 'x-robots-tag', 'content-type')
        ->and($arrayHeaders)->toBe($unknownHeaders)
        ->and($arrayCookies)->toBe($unknownCookies);
})->with([
    'lookup, a list' => ['GET', '/verify/lookup?reference[]=TC-2026-ABCDEFGH', []],
    'lookup, nested keys' => ['GET', '/verify/lookup?reference[a][b]=TC-2026-ABCDEFGH', []],
    'POST, a list' => ['POST', '/verify/certificates', ['reference' => ['TC-2026-ABCDEFGH']]],
]);

it('carries the verifier group\'s middleware, is a GET, stays off the reference path and runs submit()', function () {
    $route = Route::getRoutes()->getByName('verify.certificates.lookup');

    expect($route)->not->toBeNull('The lookup route is not registered.');

    $middleware = $route->gatherMiddleware();

    expect(in_array(VerificationResponseHeaders::class, $middleware, true))->toBeTrue('The lookup does not carry the verifier\'s response headers.')
        ->and(in_array('throttle:certificate-verification', $middleware, true))->toBeTrue('The lookup does not carry the certificate-verification throttle.')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->getActionName())->toBe(VerifyCertificateController::class.'@submit');

    // verify/certificates/{reference} is unconstrained on purpose, so a path under
    // it would be read as a reference.
    expect(str_starts_with($route->uri(), 'verify/certificates/'))->toBeFalse('The lookup path falls under verify/certificates/{reference}.');
});

it('carries the three disclosure headers on its redirect and on its miss', function () {
    assertLookupResponseDisclosesNothing(
        tap($this->get(route('verify.certificates.lookup', ['reference' => LOOKUP_SENTINEL_REFERENCE])))->assertRedirect(),
    );

    assertLookupResponseDisclosesNothing(
        tap($this->get(route('verify.certificates.lookup', ['reference' => 'NOT-A-REFERENCE'])))->assertNotFound(),
    );
});

it('spends the verifier\'s per-IP budget: nine lookups and the form make ten, and the eleventh lookup is refused', function () {
    for ($i = 0; $i < 9; $i++) {
        $this->get(route('verify.certificates.lookup', ['reference' => 'NOT-A-REFERENCE']))
            ->assertStatus(404);
    }

    $this->get(route('verify.certificates.form'))->assertOk();

    $this->get(route('verify.certificates.lookup', ['reference' => 'NOT-A-REFERENCE']))
        ->assertStatus(429);
});

it('spends the same budget the other way round: nine results and a lookup make ten, and the form is refused', function () {
    for ($i = 0; $i < 9; $i++) {
        $this->get(route('verify.certificates.show', ['reference' => LOOKUP_SENTINEL_REFERENCE]))
            ->assertStatus(404);
    }

    $this->get(route('verify.certificates.lookup', ['reference' => 'NOT-A-REFERENCE']))
        ->assertStatus(404);

    $this->get(route('verify.certificates.form'))->assertStatus(429);
});

it('draws the home page verifier as one read-only GET form to the lookup, with a labelled required reference and no token', function () {
    $html = (string) $this->get('/')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    $forms = $document->querySelectorAll('form');

    // Exactly one form on the home page, and a read-only one: the sessionless group
    // may hold nothing that submits data.
    expect(count($forms))->toBe(1);

    $form = $forms[0];

    // The action is written out by hand AND resolved by name, so neither a moved route
    // nor a hardcoded path can satisfy the test on its own.
    expect(strtolower($form->getAttribute('method')))->toBe('get')
        ->and($form->getAttribute('action'))->toBe(url('/verify/lookup'))
        ->and($form->getAttribute('action'))->toBe(route('verify.certificates.lookup'));

    $input = $form->querySelector('input[name="reference"]');

    expect($input)->not->toBeNull('The form has no input named reference.')
        ->and($input->hasAttribute('required'))->toBeTrue()
        ->and($input->getAttribute('id'))->not->toBe('');

    // A label that points at the input, by its id, from inside the form.
    $label = $form->querySelector('label[for="'.$input->getAttribute('id').'"]');

    expect($label)->not->toBeNull('No label points at the reference input.')
        ->and(trim((string) $label->textContent))->not->toBe('');

    // A sessionless page has no token to send.
    expect($document->querySelectorAll('input[name="_token"]')->length)->toBe(0)
        ->and(str_contains(strtolower($html), 'csrf'))->toBeFalse()
        ->and(str_contains(strtolower($html), '_token'))->toBeFalse();
});
