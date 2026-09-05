<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\StudentCertificate;
use App\Http\Middleware\VerificationResponseHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The three disclosure headers, on every route that can name a student
| (design section 6.5, P3-T08)
|--------------------------------------------------------------------------
|
| `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, and
| `X-Robots-Tag: noindex, nofollow, noarchive` apply to the lookup AND the
| submission — routes/web.php groups all three verify routes under one
| middleware declaration precisely so a route cannot carry the headers while
| its neighbour silently loses them, the same reasoning the private-file group
| already uses for session integrity.
*/

function assertCarriesDisclosureHeaders(TestResponse $response): void
{
    /*
     * Cache-Control is NOT compared as a literal string. Symfony's
     * ResponseHeaderBag intercepts every write to this specific header and
     * rebuilds its value from a directive map — HeaderBag::getCacheControlHeader()
     * calls ksort() on that map before joining it — so `private, no-store` as
     * WRITTEN by VerificationResponseHeaders is always read back alphabetised,
     * as `no-store, private`. Measured directly: asserting the literal string
     * this middleware sets failed this way even though the header was correct.
     * Order carries no meaning in RFC 7234's definition of this header, so the
     * property that actually matters — and the one design section 6.5 requires
     * — is that BOTH directives are present, which hasCacheControlDirective()
     * checks independently of how Symfony chose to order them.
     */
    expect($response->headers->hasCacheControlDirective('private'))->toBeTrue(
        'Missing the `private` Cache-Control directive on a verifier response.',
    );
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue(
        'Missing the `no-store` Cache-Control directive on a verifier response.',
    );

    // Neither of these is touched by Symfony's cache-control machinery, so an
    // exact match is the honest assertion for both.
    expect($response->headers->get('Referrer-Policy'))->toBe(
        'no-referrer',
        'Missing or wrong `Referrer-Policy` header on a verifier response.',
    );
    expect($response->headers->get('X-Robots-Tag'))->toBe(
        'noindex, nofollow, noarchive',
        'Missing or wrong `X-Robots-Tag` header on a verifier response.',
    );
}

it('carries the three disclosure headers on the lookup, for a valid certificate', function () {
    $certificate = StudentCertificate::factory()->create();

    $response = $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number]));

    $response->assertSuccessful();
    assertCarriesDisclosureHeaders($response);
});

it('carries the three disclosure headers on the lookup, even for a not-found result', function () {
    $response = $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']));

    $response->assertNotFound();
    assertCarriesDisclosureHeaders($response);
});

it('carries the three disclosure headers on the submission redirect', function () {
    $certificate = StudentCertificate::factory()->create();

    $response = $this->post(route('verify.certificates.submit'), ['reference' => $certificate->reference_number]);

    $response->assertRedirect();
    assertCarriesDisclosureHeaders($response);
});

it('carries the three disclosure headers on a submission that renders not-found directly', function () {
    $response = $this->post(route('verify.certificates.submit'), ['reference' => '']);

    $response->assertNotFound();
    assertCarriesDisclosureHeaders($response);
});

/*
|--------------------------------------------------------------------------
| The structural guarantee: the three routes cannot drift apart
|--------------------------------------------------------------------------
*/

it('applies the headers middleware and the named limiter to all three verify routes', function () {
    foreach (['verify.certificates.form', 'verify.certificates.submit', 'verify.certificates.show'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

        expect(in_array(VerificationResponseHeaders::class, $middleware, true))->toBeTrue(
            "{$name} does not carry VerificationResponseHeaders.",
        );

        expect(in_array('throttle:certificate-verification', $middleware, true))->toBeTrue(
            "{$name} does not carry the certificate-verification throttle.",
        );
    }
});

it('keeps both on the route GROUP, which is what a future verify route would inherit', function () {
    /*
     * Asserted against the source because the router flattens group
     * middleware into each route and cannot say where it came from — the test
     * above would stay green even if a refactor deleted the group and pasted
     * the same two middleware onto each of the three routes individually,
     * which would silently drop the guarantee for a fourth route nobody has
     * written yet. Comments are stripped first so mentioning the class in
     * prose cannot inflate the count (see appSourceWithoutComments).
     */
    $source = appSourceWithoutComments(base_path('routes/web.php'));

    expect(substr_count($source, 'VerificationResponseHeaders'))->toBe(
        2,
        'Expected exactly two mentions of VerificationResponseHeaders in routes/web.php — the '
        .'import and one group declaration. More than that means it is being applied per route, '
        .'so a route added outside the group would silently be unprotected.',
    );

    $declaresGroup = preg_match(
        '/Route(?:::|->)middleware\(\s*\[?[^)]*VerificationResponseHeaders::class[^)]*certificate-verification[^)]*\]?\s*\)\s*->group\(/',
        $source,
    ) === 1;

    expect($declaresGroup)->toBeTrue(
        'The verify route group is gone, or no longer carries both VerificationResponseHeaders '
        .'and the certificate-verification throttle together — a route added outside it would '
        .'silently lose the headers, the rate limit, or both.',
    );
});
