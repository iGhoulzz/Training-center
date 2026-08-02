<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Staff\Actions\UploadStaffCertificateAction;
use App\Domain\Staff\Models\StaffCertificate;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way bytes leave the private disk.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * The 'private' disk has no URL and registers no route (config/filesystems.php).
 * That is the point: a staff certificate carries a full name, a national ID
 * number, and a date of birth, and any address that serves it without asking who
 * is asking is a permanent leak.
 *
 * AUTHORIZED PER REQUEST, NOT PER LINK
 * ------------------------------------
 * Every request runs StaffCertificatePolicy::view() against THIS record for THIS
 * actor. A signed URL is deliberately not used as the gate: a signature proves
 * the link was not tampered with, not that whoever holds it is entitled to the
 * document. A signed link pasted into a chat would still open. Revoking
 * view_staff_certificate must stop the next download, and only a per-request
 * check does that.
 *
 * A guest is refused with 403 rather than redirected. There is no route named
 * `login` in this application — the Filament panel owns its own login page — so
 * a redirect would depend on a route that does not exist. 403 is also the honest
 * answer for a document endpoint: the resource is not public, and its existence
 * is not something to advertise by bouncing to a sign-in form.
 *
 * STREAMED, NEVER REDIRECTED
 * --------------------------
 * The response streams from the disk. Redirecting to a storage path would hand
 * out an address that is either unreachable (this disk has none) or, worse,
 * reachable without this check.
 */
final class StaffCertificateDownloadController extends Controller
{
    /**
     * The disks a certificate row is allowed to name.
     *
     * An allowlist rather than a straight comparison against the Action's
     * constant, because the `disk` column exists precisely so that moving to a
     * different disk later does not orphan the rows already written. Adding one
     * here is then a deliberate act, visible in review, and anything not named
     * fails closed.
     *
     * @var array<int, string>
     */
    private const SERVEABLE_DISKS = [UploadStaffCertificateAction::DISK];

    public function __invoke(Request $request, StaffCertificate $certificate): StreamedResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->is_active) {
            abort(403);
        }

        Gate::forUser($actor)->authorize('view', $certificate);

        $path = $certificate->path;

        /*
         * THE EXACT GENERATED SHAPE, NOT MERELY A CONTAINED PATH.
         *
         * UploadStaffCertificateAction generates every path from a ULID, so a
         * wrong value cannot arrive through the application. This guards the
         * case where one arrives some other way — a manual database edit, a
         * restored backup, a future importer.
         *
         * An earlier version asked only whether the path stayed inside the disk
         * root, and that was not enough (P1-T15, round-two review). THE PRIVATE
         * DISK IS SHARED. Staff photos live beside certificates today, and the
         * pending_file_deletions migration states that phase 2 receipts and
         * phase 3 student certificates will reuse the same disk. A certificate
         * row naming `staff-photos/{ULID}.png` is perfectly contained, passes
         * the disk check, and made this route authorize the CERTIFICATE and
         * stream the PHOTO — readable by an actor holding view_staff_certificate
         * and no profile permission at all. Once the later namespaces exist the
         * same hole reaches a financial receipt or a student's document across a
         * permission boundary that was never consulted.
         *
         * StaffProfilePhotoController has always required its own generated
         * shape for exactly this reason; the asymmetry was the defect.
         */
        if (! $this->isGeneratedCertificatePath($path)) {
            abort(404);
        }

        /*
         * The disk is guarded for the same reason the path is, and the two are
         * not independent: the check above proves the path cannot escape THE
         * DISK ROOT, and until this existed the row itself chose which root that
         * was. `staff-certificates/x.pdf` is perfectly contained under every
         * root there is, so a foreign disk did not BREAK the containment rule —
         * it moved the boundary the rule was measured against.
         *
         * Unlike a traversal, which Flysystem refuses as a second line of
         * defence, reading a contained path from the wrong disk is a completely
         * legitimate filesystem operation that nothing downstream objects to.
         * This is the only place it can be refused.
         *
         * The stored value arrives the same way a traversing path would —
         * UploadStaffCertificateAction sets `disk` server-side and never from
         * request input, so anything else means a manual database edit, a
         * restored backup, or a future importer.
         */
        if (! in_array($certificate->disk, self::SERVEABLE_DISKS, true)) {
            abort(404);
        }

        $disk = Storage::disk($certificate->disk);

        if (! $disk->exists($path)) {
            // The row outlived its file — a purge that ran when it should not
            // have, or a restore that brought back rows without bytes. Not a
            // server error, and not something to leak detail about.
            abort(404);
        }

        return $disk->download($path, $certificate->original_filename, [
            'Cache-Control' => 'private, no-store',
            // The stored types are PDF and raster images, but a browser that
            // sniffs its way to something else would be executing content the
            // centre uploaded on the centre's own origin.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Is this exactly a path UploadStaffCertificateAction would have generated?
     *
     * Containment first — absolute paths, Windows drive prefixes, null bytes and
     * any `..` segment under either separator. Flysystem's local adapter refuses
     * traversal too; this runs first so the refusal is the application's own and
     * is identical on every driver.
     *
     * Then the shape, which is what makes the private disk's shared namespace
     * safe: exactly two segments, the first being this feature's own directory,
     * the second a ULID with an extension the upload Action actually writes.
     *
     * THE EXTENSION LIST IS DERIVED, NOT COPIED. A hand-maintained alternation
     * here would silently start 404ing a whole credential type the day somebody
     * widens what the Action accepts — a failure that looks like missing files
     * rather than like a stale regex. Reading the Action's own map means the two
     * cannot disagree.
     */
    private function isGeneratedCertificatePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return false;
        }

        if (preg_match('#^[A-Za-z]:#', $path) === 1) {
            return false;
        }

        $segments = preg_split('#[\\\\/]+#', $path) ?: [];

        if (in_array('..', $segments, true)) {
            return false;
        }

        // The count is checked first, so the offsets below are known to exist —
        // no null-coalescing, which PHPStan correctly reports as dead once the
        // array is narrowed to exactly two elements.
        if (
            count($segments) !== 2
            || $segments[0] !== UploadStaffCertificateAction::DIRECTORY
        ) {
            return false;
        }

        $extensions = implode('|', array_map(
            static fn (string $extension): string => preg_quote($extension, '#'),
            array_unique(array_values(UploadStaffCertificateAction::EXTENSIONS)),
        ));

        // Crockford base32, 26 characters — the same alphabet
        // StaffProfilePhotoController requires of a generated photo name.
        return preg_match(
            '#^[0-9A-HJKMNP-TV-Z]{26}\.(?:'.$extensions.')$#i',
            $segments[1],
        ) === 1;
    }
}
