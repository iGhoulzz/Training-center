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
| What the public verifier is forbidden to say (design section 6.5, P3-T08)
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

    $revoked = StudentCertificate::factory()
        ->revoked('Issued against a miscounted attendance record.')
        ->create(['replaces_certificate_id' => $predecessor->getKey()]);

    $response = $this->get(route('verify.certificates.show', ['reference' => $revoked->reference_number]));

    $response->assertSuccessful();
    $response->assertSee($revoked->status->label());
    $response->assertSee(__('verify.status_message_revoked'));

    expect($response->getContent())
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

it('requests no external origin to render the form, the lookup or the not-found page', function () {
    $certificate = StudentCertificate::factory()->create();

    $pages = [
        $this->get(route('verify.certificates.form')),
        $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number])),
        $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference'])),
    ];

    $forbidden = ['<script', '<link', '<img', '<iframe', '<object', '<embed', '@import'];

    foreach ($pages as $response) {
        $body = $response->getContent();

        foreach ($forbidden as $tag) {
            expect($body)->not->toContain(
                $tag,
                "Found `{$tag}` in a verifier page — it may load nothing but its own inline HTML and CSS.",
            );
        }
    }
});

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
