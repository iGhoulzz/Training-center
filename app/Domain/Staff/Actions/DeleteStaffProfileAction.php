<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delete an employment record, and every file it owns.
 *
 * WHY THIS ACTION HAS TO EXIST
 * ----------------------------
 * `staff_certificates.staff_profile_id` is cascadeOnDelete, so the database
 * removes the certificate ROWS and leaves their BYTES. Deleting a profile
 * through Eloquent alone silently orphans every scanned document that profile
 * owned — exactly the personal data the centre has just decided it no longer
 * holds. The paths must be read BEFORE the cascade, because afterwards there is
 * nothing left to read them from.
 *
 * WHY IT ALSO DEMANDS THE CERTIFICATE GRANT
 * -----------------------------------------
 * `delete_staff_profile` and `delete_staff_certificate` are separate permissions
 * because a job title and a scanned national ID are different sensitivity
 * levels. The cascade collapses that distinction: an actor holding only the
 * profile grant would destroy certificate rows they have no permission to touch.
 *
 * So when the profile has certificates, each one is authorized individually
 * through StaffCertificatePolicy::delete() as well. A profile with no
 * certificates needs only `delete_staff_profile` — the extra grant is required
 * by what is actually being destroyed, not by the shape of the request.
 *
 * Every check runs before any write, so a refusal leaves both rows and files
 * exactly as they were.
 *
 * Certificates are authorized one by one rather than once against the
 * permission, so that a per-record rule added to StaffCertificatePolicy::delete()
 * later is honoured here without anyone having to remember this file.
 */
final class DeleteStaffProfileAction
{
    public function __construct(
        private readonly FileLifecycleService $files,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, StaffProfile $profile): void
    {
        Gate::forUser($actor)->authorize('delete', $profile);

        $certificates = $profile->certificates()->get();

        foreach ($certificates as $certificate) {
            Gate::forUser($actor)->authorize('delete', $certificate);
        }

        $files = $certificates
            ->map(fn (StaffCertificate $certificate): array => [
                'disk' => $certificate->disk,
                'path' => $certificate->path,
            ])
            ->all();

        $photoPath = $profile->profile_photo_path;

        if (is_string($photoPath) && $photoPath !== '') {
            $files[] = ['disk' => UpdateStaffPhotoAction::DISK, 'path' => $photoPath];
        }

        $pendingIds = DB::transaction(function () use ($profile, $files): array {
            $ids = $this->files->record($files);

            // Cascades to staff_certificates. The receipts above were written
            // first, so the intent to destroy those files is committed with the
            // same transaction that destroys their rows.
            $profile->delete();

            return $ids;
        });

        $this->files->dispatchPurges($pendingIds);
    }
}
