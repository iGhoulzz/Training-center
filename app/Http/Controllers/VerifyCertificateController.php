<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Enrollment\Data\CertificateVerificationView;
use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Support\CentreCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The first thing in this system that serves the open internet (design section
 * 7, P3-T08).
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
         * THE REVOCATION DATE, AND ONLY THE DATE.
         *
         * Design §7.3 requires a revoked certificate to say "revoked on 4 March
         * 2026" — so the date crosses to the public page, while `revoked_by` and
         * `revocation_reason` never leave this method. The reason is internal and
         * frequently about a person; the date is the part the holder of a bad
         * certificate needs.
         *
         * Read only when the status is `revoked`. A `valid` row has no
         * `revoked_at` to localise, and the database refuses the combination
         * anyway (chk_student_certificates_revocation), so branching here keeps
         * the null out of CentreCalendar rather than relying on that constraint.
         */
        $revokedOn = $certificate->status === CertificateStatus::Revoked && $certificate->revoked_at !== null
            ? CentreCalendar::localise($certificate->revoked_at)->format('Y-m-d')
            : null;

        /*
         * NAMED, ONE BY ONE — NEVER `$certificate->toArray()`, NEVER THE MODEL
         * ITSELF HANDED TO THE VIEW. This is the entire enforcement mechanism
         * behind "a column added later cannot leak through the public surface
         * by default": whatever this table gains next, this projection carries
         * only what someone deliberately adds to it.
         */
        $view = new CertificateVerificationView(
            status: $certificate->status,
            studentName: $certificate->student_name,
            courseName: $certificate->course_name,
            completedOn: CentreCalendar::localise($certificate->completed_on)->format('Y-m-d'),
            issuedAt: CentreCalendar::localise($certificate->issued_at)->format('Y-m-d H:i'),
            centreName: (string) config('app.name'),
            revokedOn: $revokedOn,
        );

        return response()->view('verify.show', [
            'view' => $view,
            'statusMessageKey' => $this->statusMessageKey($certificate->status),
            'statusMessageReplacements' => $revokedOn === null ? [] : ['date' => $revokedOn],
        ]);
    }

    /**
     * Which sentence the page leads with, chosen HERE rather than in the view.
     *
     * The mapping began as a `match` inside an `@php` block in show.blade.php,
     * and LocalizationTest's gate caught it: the Blade detector strips `@php`
     * as a directive but leaves the block's BODY as raw text, so three
     * translation keys read to it as untranslated prose. The offence was
     * cosmetic and the detector was right anyway — presentation mapping is the
     * controller's job, and a view holding a `match` over an enum is view
     * logic.
     *
     * Deriving the key by concatenation — `'verify.status_message_'.$status->value`
     * — would also have silenced the detector, and is worse: the three keys
     * stop being greppable, so nothing connects lang/en/verify.php to the code
     * that reads it. They stay written out in full.
     */
    private function statusMessageKey(CertificateStatus $status): string
    {
        return match ($status) {
            CertificateStatus::Valid => 'verify.status_message_valid',
            CertificateStatus::Revoked => 'verify.status_message_revoked',
            CertificateStatus::Replaced => 'verify.status_message_replaced',
        };
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
