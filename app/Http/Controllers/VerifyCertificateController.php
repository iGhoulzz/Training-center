<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Enrollment\Data\CertificateVerificationView;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Support\CentreCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The first thing in this system that serves the open internet (design section
 * 6.5, P3-T08).
 *
 * THREE ROUTES, ONE CONTROLLER, ONE NOT-FOUND PATH.
 * ---------------------------------------------------
 * `form()` renders the empty form. `submit()` takes what a person typed,
 * normalizes it, and — if and only if it is shaped like a real reference —
 * redirects to `show()`, which does the actual lookup. Every way this can fail
 * (blank, malformed, or simply unknown) ends at the SAME private method,
 * `notFound()`, so the four cases the plan requires to be byte-identical
 * really do run the same code.
 *
 * THE SHAPE IS CHECKED HERE, NEVER BY A ROUTE `->where()` CONSTRAINT.
 * ---------------------------------------------------
 * A route constraint that refused a malformed segment would mean the route
 * never matches, and Laravel would render ITS OWN 404 page — visibly
 * different from this controller's, and therefore a signal telling a caller
 * "that shape was wrong" as opposed to "no such certificate exists". The
 * `{reference}` segment is unconstrained; every possible value reaches
 * `show()`, and this class decides what to do with it.
 *
 * "OMITTED" MEANS AN EMPTY POST, AND IT DOES NOT REDIRECT BACK.
 * ---------------------------------------------------
 * A validation error redirecting back to the form is itself a signal about
 * the input — it would tell a caller their submission was malformed rather
 * than simply unverifiable. `submit()` with a blank or malformed field
 * renders the same not-found result at 404 as every other miss, directly,
 * with no redirect at all.
 */
final class VerifyCertificateController extends Controller
{
    public function form(): View
    {
        return view('verify.form');
    }

    public function submit(Request $request): RedirectResponse|Response
    {
        $reference = $this->normalize((string) $request->input('reference', ''));

        if (! $this->isWellFormed($reference)) {
            return $this->notFound();
        }

        return redirect()->route('verify.certificates.show', ['reference' => $reference]);
    }

    public function show(string $reference): Response
    {
        $reference = $this->normalize($reference);

        if (! $this->isWellFormed($reference)) {
            return $this->notFound();
        }

        $certificate = StudentCertificate::query()
            ->where('reference_number', $reference)
            ->first();

        if (! $certificate instanceof StudentCertificate) {
            return $this->notFound();
        }

        /*
         * NAMED, ONE BY ONE — NEVER `$certificate->toArray()`, NEVER THE MODEL
         * ITSELF HANDED TO THE VIEW. This is the entire enforcement mechanism
         * behind "a column added later cannot leak through the public surface
         * by default": whatever this table gains next, this projection stays
         * exactly six fields until a person deliberately adds a seventh line
         * here.
         */
        $view = new CertificateVerificationView(
            status: $certificate->status,
            studentName: $certificate->student_name,
            courseName: $certificate->course_name,
            completedOn: CentreCalendar::localise($certificate->completed_on)->format('Y-m-d'),
            issuedAt: CentreCalendar::localise($certificate->issued_at)->format('Y-m-d H:i'),
            centreName: (string) config('app.name'),
        );

        return response()->view('verify.show', ['view' => $view]);
    }

    /**
     * Trim and uppercase — CertificateReference::ALPHABET is uppercase-only,
     * so a reference copied with stray whitespace or typed in lowercase still
     * resolves, matching what a person reading it off a printed certificate
     * would expect.
     */
    private function normalize(string $reference): string
    {
        return mb_strtoupper(trim($reference));
    }

    /**
     * Validated against the SAME public constant `CertificateReference::mint()`
     * draws from, never against a hand-copied character class that could drift
     * from it — `TC-{4-digit year}-{8 characters from the alphabet}`.
     */
    private function isWellFormed(string $reference): bool
    {
        return preg_match($this->shapePattern(), $reference) === 1;
    }

    private function shapePattern(): string
    {
        return '/^TC-\d{4}-['.preg_quote(CertificateReference::ALPHABET, '/').']{8}$/';
    }

    /**
     * The one rendering of "this did not verify" — malformed, blank, or
     * genuinely unknown all arrive here, and this is the only place the view
     * is rendered from. No submitted value is ever passed to it.
     */
    private function notFound(): Response
    {
        return response()->view('verify.not-found', [], 404);
    }
}
