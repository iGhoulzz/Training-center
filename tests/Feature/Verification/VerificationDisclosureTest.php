<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Domain\Finance\Models\Charge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| DatabaseMigrations, NOT RefreshDatabase — the ALTER TABLE below is why.
|--------------------------------------------------------------------------
|
| RefreshDatabase wraps each test in a transaction and rolls it back at the
| end. MySQL DDL — the Schema::table() calls the first test uses to prove the
| projection really is a projection — commits implicitly, which would commit
| that transaction early: every row this file created before the ALTER would
| survive into whichever test runs next, on the one MySQL database every
| worktree shares (tests/bootstrap.php). DatabaseMigrations runs a full
| migrate:fresh before each test and migrate:rollback after, so neither the
| schema mutation nor anything committed alongside it can leak — the same
| reasoning FileLifecycleTransactionTest documents for DatabaseTruncation.
*/
uses(DatabaseMigrations::class);

/*
|--------------------------------------------------------------------------
| What the public verifier is forbidden to say (design section 7.3, P3-T08)
|--------------------------------------------------------------------------
|
| VerifyCertificateController builds CertificateVerificationView by naming six
| properties off the model, never by handing the model — or its toArray() — to
| the view. Every test in this file proves a way that boundary could otherwise
| be crossed: a column added later, a revocation reason, a successor
| reference, an external asset, or a piece of the student's own record that
| was never meant to travel past an authenticated screen.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', 'UTC'));
});

/*
|--------------------------------------------------------------------------
| The projection is a projection
|--------------------------------------------------------------------------
*/

it('never leaks a column added to student_certificates after this projection was written', function () {
    $certificate = StudentCertificate::factory()->create();

    Schema::table('student_certificates', function (Blueprint $table): void {
        $table->string('leaked_later_column')->nullable();
    });

    try {
        DB::table('student_certificates')
            ->where('id', $certificate->getKey())
            ->update(['leaked_later_column' => 'SENTINEL-COLUMN-ADDED-LATER']);

        $response = $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number]));

        $response->assertSuccessful();
        expect($response->getContent())->not->toContain('SENTINEL-COLUMN-ADDED-LATER');
    } finally {
        // Belt and braces: this file's DatabaseMigrations trait already
        // rebuilds the whole schema after this test, so a later test could
        // not observe this column even without this — but leaving the ALTER
        // this test made unreverted for the rest of THIS test's own teardown
        // is not something to rely on the framework for when a single
        // dropColumn() says it directly.
        Schema::table('student_certificates', function (Blueprint $table): void {
            $table->dropColumn('leaked_later_column');
        });
    }
});

/*
|--------------------------------------------------------------------------
| Revoked and replaced state their status and nothing more
|--------------------------------------------------------------------------
*/

it('states a revoked certificate is revoked without its reason or any replacement reference', function () {
    // The revoked row is ITSELF a replacement of an earlier certificate —
    // replaces_certificate_id is orthogonal to status, so this is a real
    // shape the register can hold, and the property under test is that the
    // predecessor's reference never appears either.
    $predecessor = StudentCertificate::factory()->replaced()->create();

    /*
     * THE TWO DATES ARE DELIBERATELY DIFFERENT, AND FAR APART.
     *
     * Design section 7.3 requires the page to state "revoked on <date>". If the
     * view rendered `issued_at` under a revocation label the page would still
     * look right, and a fixture where the two coincide could never tell that
     * apart from the correct behaviour. Issued in January, revoked in March.
     */
    $revoked = StudentCertificate::factory()
        ->revoked('Issued against a miscounted attendance record.')
        ->create([
            'replaces_certificate_id' => $predecessor->getKey(),
            'issued_at' => Carbon::parse('2026-01-09 08:00:00', 'UTC'),
            'revoked_at' => Carbon::parse('2026-03-04 10:30:00', 'UTC'),
        ]);

    $response = $this->get(route('verify.certificates.show', ['reference' => $revoked->reference_number]));

    $response->assertSuccessful();
    $response->assertSee($revoked->status->label());
    $response->assertSee(__('verify.status_message_revoked', ['date' => '2026-03-04']));

    expect($response->getContent())
        ->toContain('2026-03-04')
        ->not->toContain('Issued against a miscounted attendance record.')
        ->not->toContain($predecessor->reference_number);
});

it('states a replaced certificate is superseded without naming its successor', function () {
    $original = StudentCertificate::factory()->replaced()->create();

    // The forward pointer lives on the NEW row (design section 6.4) — the
    // successor is what could theoretically be joined to and named, so it is
    // what this test creates and asserts absent.
    $successor = StudentCertificate::factory()->create([
        'replaces_certificate_id' => $original->getKey(),
    ]);

    $response = $this->get(route('verify.certificates.show', ['reference' => $original->reference_number]));

    $response->assertSuccessful();
    $response->assertSee($original->status->label());
    $response->assertSee(__('verify.status_message_replaced'));

    expect($response->getContent())->not->toContain($successor->reference_number);
});

/*
|--------------------------------------------------------------------------
| Nothing distinguishes a typo from a non-existent certificate
|--------------------------------------------------------------------------
*/

it('renders byte-identical bodies at 404 for a malformed GET, an unknown GET, an empty POST and a malformed POST', function () {
    $unknown = app(CertificateReference::class)->mint(now());

    $malformedGet = $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']));
    $unknownGet = $this->get(route('verify.certificates.show', ['reference' => $unknown]));
    $emptyPost = $this->post(route('verify.certificates.submit'), ['reference' => '']);
    $malformedPost = $this->post(route('verify.certificates.submit'), ['reference' => 'not-a-real-reference']);

    foreach ([$malformedGet, $unknownGet, $emptyPost, $malformedPost] as $response) {
        $response->assertNotFound();
    }

    // Achievable ONLY because verify.not-found carries no CSRF token, no
    // error bag and echoes no submitted value — a page with a CSRF token
    // differs between any two renders, let alone four independent requests.
    expect($malformedGet->getContent())
        ->toBe($unknownGet->getContent())
        ->toBe($emptyPost->getContent())
        ->toBe($malformedPost->getContent());
});

/*
|--------------------------------------------------------------------------
| No third-party origin, ever
|--------------------------------------------------------------------------
*/

/**
 * Every URL the rendered page would fetch from, link to, or import.
 *
 * WHY THIS ENUMERATES RATHER THAN FORBIDS A LIST OF TAGS.
 * -------------------------------------------------------
 * The first version of this check asserted the absence of seven strings —
 * `<script`, `<link`, `<img`, `<iframe`, `<object`, `<embed`, `@import`. Every
 * one of those is a true statement about the page and none of them is the
 * property design section 7.4 actually requires, which is that the page fetches
 * NOTHING from a third-party origin. Cross-review demonstrated the gap by
 * adding `background-image: url(https://tracker.example/pixel)` to the inline
 * CSS: zero of the seven tokens match, the check stays green, and the page
 * hands the certificate reference to a tracker through the Referer header.
 * `<video src>`, `<source srcset>` and `<a href>` are the same shape.
 *
 * A blacklist can only be as complete as the last person to think about it. So
 * this pulls out the URLs and asks where they point.
 *
 * @return list<string> the offending URLs, empty when the page is self-contained
 */
function verifierExternalOrigins(string $html, string $appHost): array
{
    $urls = [];

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    /*
     * Every HTML attribute whose value is a URL the browser acts on. `srcset`
     * carries a comma-separated list with density descriptors mixed in; the
     * descriptors are not URLs and classify as relative, which is harmless.
     */
    $urlAttributes = ['src', 'href', 'srcset', 'poster', 'data', 'action', 'formaction', 'background', 'cite'];

    foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
        foreach ($urlAttributes as $attribute) {
            if (! $element->hasAttribute($attribute)) {
                continue;
            }

            foreach (preg_split('/[,\s]+/', $element->getAttribute($attribute), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
                $urls[] = $candidate;
            }
        }

        // A style ATTRIBUTE carries CSS too, and carries it per element.
        if ($element->hasAttribute('style')) {
            $urls = array_merge($urls, verifierCssUrls($element->getAttribute('style')));
        }
    }

    // <style> blocks are text, not attributes, so the loop above cannot see them
    // — and this page's entire stylesheet lives in one.
    foreach ($document->getElementsByTagName('style') as $style) {
        $urls = array_merge($urls, verifierCssUrls((string) $style->textContent));
    }

    $external = [];

    foreach ($urls as $url) {
        $url = trim($url);

        // A fragment, an inline datum, or nothing at all: no request leaves.
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'data:')) {
            continue;
        }

        /*
         * PROTOCOL-RELATIVE IS EXTERNAL. `//tracker.example/pixel` has no scheme,
         * so parse_url() reports a host and it would be easy to treat as a path.
         * The browser fetches it from tracker.example.
         */
        if (str_starts_with($url, '//')) {
            $external[] = $url;

            continue;
        }

        $host = parse_url($url, PHP_URL_HOST);

        // No host means relative — same origin by construction.
        if ($host === null || $host === false) {
            continue;
        }

        if (strcasecmp((string) $host, $appHost) !== 0) {
            $external[] = $url;
        }
    }

    return array_values(array_unique($external));
}

/**
 * The URLs inside a block of CSS — `url(...)` in any quoting style, and `@import`.
 *
 * @return list<string>
 */
function verifierCssUrls(string $css): array
{
    $found = [];

    if (preg_match_all('/url\(\s*[\'"]?([^\'")]+)/i', $css, $matches) !== false) {
        $found = array_merge($found, $matches[1]);
    }

    if (preg_match_all('/@import\s+(?:url\(\s*)?[\'"]([^\'"]+)/i', $css, $matches) !== false) {
        $found = array_merge($found, $matches[1]);
    }

    return array_values($found);
}

it('requests no external origin to render the form, the lookup or the not-found page', function () {
    $certificate = StudentCertificate::factory()->create();

    $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    $pages = [
        'form' => $this->get(route('verify.certificates.form')),
        'lookup' => $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number])),
        'not-found' => $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference'])),
    ];

    foreach ($pages as $name => $response) {
        $external = verifierExternalOrigins((string) $response->getContent(), $appHost);

        expect($external)->toBeEmpty(
            "The {$name} page reaches a third-party origin, which can receive the certificate "
            ."reference through the request or the Referer header (design section 7.4):\n  "
            .implode("\n  ", $external),
        );
    }
});

it('detects each external-origin shape this guard covers', function (string $html) {
    /*
     * Without this, narrowing the extractor above leaves a guard that finds
     * nothing and passes forever — the same reasoning ActivityLogTest gives for
     * its own sample list. Every entry below is a real way a page reaches a
     * third-party origin, and the CSS one is the case cross-review used to
     * defeat the previous tag blacklist.
     */
    expect(verifierExternalOrigins($html, 'training-center.test'))->not->toBeEmpty();
})->with([
    'inline CSS background' => '<html><head><style>body { background-image: url(https://tracker.example/pixel); }</style></head><body></body></html>',
    'CSS @import' => '<html><head><style>@import "https://fonts.example/face.css";</style></head><body></body></html>',
    'style attribute' => '<html><body><div style="background: url(\'https://tracker.example/p.gif\')"></div></body></html>',
    'protocol-relative script' => '<html><body><script src="//cdn.example/a.js"></script></body></html>',
    'video poster' => '<html><body><video poster="https://cdn.example/poster.jpg"></video></body></html>',
    'source srcset' => '<html><body><picture><source srcset="https://cdn.example/i.webp 2x"></picture></body></html>',
    'external link' => '<html><body><a href="https://analytics.example/away">x</a></body></html>',
]);

it('leaves a self-contained page alone', function (string $html) {
    // Over-broadening the guard fails here: each of these is something the
    // verifier pages legitimately do.
    expect(verifierExternalOrigins($html, 'training-center.test'))->toBeEmpty();
})->with([
    'same-origin absolute link' => '<html><body><a href="http://training-center.test/verify/certificates">x</a></body></html>',
    'root-relative link' => '<html><body><a href="/verify/certificates">x</a></body></html>',
    'fragment' => '<html><body><a href="#main">x</a></body></html>',
    'inline data URI' => '<html><body><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw="></body></html>',
    'inline CSS with no url()' => '<html><head><style>body { background: #f3f4f6; }</style></head><body></body></html>',
]);

/*
|--------------------------------------------------------------------------
| Nothing that identifies or bills the student ever leaves the projection
|--------------------------------------------------------------------------
*/

it('exposes no financial figure, date of birth, national ID, phone, email or address', function () {
    $student = Student::factory()->create([
        'email' => 'leak-canary@example.test',
        'phone' => '0912345678',
        'national_id' => '199999999999',
        'date_of_birth' => '1990-01-01',
        'address' => 'CANARY STREET, HOUSE 42',
    ]);
    $course = Course::factory()->create();
    $batch = Batch::factory()->for($course)->active()->create();
    $enrollment = Enrollment::factory()->for($student)->for($batch)->completed()->create();

    Charge::factory()->for($enrollment)->create(['list_price' => '1234.500']);

    $certificate = StudentCertificate::factory()->for($enrollment)->create();

    $response = $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number]));
    $response->assertSuccessful();

    $body = $response->getContent();

    expect($body)
        ->not->toContain('leak-canary@example.test')
        ->not->toContain('0912345678')
        ->not->toContain('199999999999')
        ->not->toContain('1990-01-01')
        ->not->toContain('CANARY STREET')
        ->not->toContain('1234.500')
        ->not->toContain('1234.5');
});
