<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The public verifier's three routes (design section 6.5, P3-T08)
|--------------------------------------------------------------------------
|
| GET /verify/certificates renders the form. POST /verify/certificates
| normalizes a complete reference and redirects to the lookup — it never
| performs the lookup itself. GET /verify/certificates/{reference} is the
| only route that ever touches the database.
|
| THE ROUTE SEGMENT IS UNCONSTRAINED, ON PURPOSE. A `->where()` regex on the
| route would make a malformed value fail to match at all, and Laravel would
| render its OWN 404 page instead of the verifier's — a signal telling a
| caller "that shape was wrong" rather than "no such certificate", which
| design section 6.5 forbids. Every test below that feeds a malformed value
| to the GET route therefore also proves the route matched and the
| CONTROLLER decided, not the router.
*/

beforeEach(function () {
    // 11:00 in Tripoli, nowhere near a calendar boundary in either timezone —
    // see ReplaceCertificateTest for why the clock is frozen at all.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', 'UTC'));
});

/*
|--------------------------------------------------------------------------
| The form
|--------------------------------------------------------------------------
*/

it('renders the verification form', function () {
    $response = $this->get(route('verify.certificates.form'));

    $response->assertSuccessful();
    $response->assertSee(__('verify.form_heading'));
    $response->assertSee(__('verify.submit_button'));
});

/*
|--------------------------------------------------------------------------
| The submission — normalize and redirect, never look up
|--------------------------------------------------------------------------
*/

it('redirects a well-formed submission to the lookup route', function () {
    $reference = app(CertificateReference::class)->mint(now());

    $response = $this->post(route('verify.certificates.submit'), ['reference' => $reference]);

    $response->assertRedirect(route('verify.certificates.show', ['reference' => $reference]));
});

it('normalizes case and surrounding whitespace before redirecting', function () {
    $reference = app(CertificateReference::class)->mint(now());

    $response = $this->post(route('verify.certificates.submit'), [
        'reference' => '  '.mb_strtolower($reference).'  ',
    ]);

    $response->assertRedirect(route('verify.certificates.show', ['reference' => $reference]));
});

it('renders the not-found result directly for an empty submission, without redirecting', function () {
    $response = $this->post(route('verify.certificates.submit'), ['reference' => '']);

    $response->assertNotFound();
    $response->assertSee(__('verify.not_found_heading'));
    // The property under test: no Location header at all, not merely a
    // Location that happens to differ from the lookup route.
    expect($response->headers->has('Location'))->toBeFalse(
        'An empty submission redirected instead of rendering the not-found result directly.',
    );
});

it('renders the not-found result directly for a malformed submission, without redirecting', function () {
    $response = $this->post(route('verify.certificates.submit'), ['reference' => 'not-a-real-reference']);

    $response->assertNotFound();
    $response->assertSee(__('verify.not_found_heading'));
    expect($response->headers->has('Location'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The lookup
|--------------------------------------------------------------------------
*/

it('renders the certificate for a valid reference', function () {
    $certificate = StudentCertificate::factory()->create([
        'student_name' => 'Amina Zarrouk',
        'course_name' => 'Welding Level 1',
        'completed_on' => '2026-05-01',
        'issued_at' => Carbon::parse('2026-05-02 20:30:00', 'UTC'),
    ]);

    $response = $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number]));

    $response->assertSuccessful();
    $response->assertSee('Amina Zarrouk');
    $response->assertSee('Welding Level 1');
    // Localised on the centre's calendar (Africa/Tripoli, UTC+2) — see
    // CentreCalendar. 2026-05-02 20:30 UTC is 2026-05-02 22:30 in Tripoli.
    $response->assertSee('2026-05-01');
    $response->assertSee('2026-05-02 22:30');
    $response->assertSee((string) config('app.name'));
});

it('accepts a lowercase reference in the URL for the lookup too', function () {
    $certificate = StudentCertificate::factory()->create();

    $response = $this->get(route('verify.certificates.show', [
        'reference' => mb_strtolower($certificate->reference_number),
    ]));

    $response->assertSuccessful();
});

it('renders the not-found result for a well-formed but unknown reference', function () {
    $unknown = app(CertificateReference::class)->mint(now());

    $response = $this->get(route('verify.certificates.show', ['reference' => $unknown]));

    $response->assertNotFound();
    $response->assertSee(__('verify.not_found_heading'));
});

it('renders the not-found result for a malformed reference, and never Laravel\'s own 404 page', function () {
    $response = $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']));

    // The route matched (it always does — the segment is unconstrained) and
    // the CONTROLLER decided. A route constraint bouncing this to Laravel's
    // own NotFoundHttpException page would still be a 404 status, so the body
    // is what proves which one actually rendered.
    $response->assertNotFound();
    $response->assertSee(__('verify.not_found_heading'));
});
