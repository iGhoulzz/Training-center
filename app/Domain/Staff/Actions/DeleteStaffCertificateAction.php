<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Remove a credential — the row and the bytes.
 *
 * Deleting the row alone would leave a scanned national ID on disk with nothing
 * in the database recording its existence: unfindable, undeletable, and personal
 * data the centre no longer has grounds to hold.
 *
 * The receipt is written in the same transaction as the row removal; the unlink
 * happens afterwards, in a retrying job. See FileLifecycleService for why that
 * ordering is not negotiable.
 *
 * The disk is read from the row, not assumed, because the column exists so that
 * moving to different storage later does not orphan the rows already written.
 */
final class DeleteStaffCertificateAction
{
    public function __construct(
        private readonly FileLifecycleService $files,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, StaffCertificate $certificate): void
    {
        Gate::forUser($actor)->authorize('delete', $certificate);

        $pendingIds = DB::transaction(function () use ($actor, $certificate): array {
            // Read storage identity from the locked database row, not from a
            // Livewire component's potentially stale model snapshot.
            $lockedCertificate = StaffCertificate::query()
                ->lockForUpdate()
                ->findOrFail($certificate->getKey());

            Gate::forUser($actor)->authorize('delete', $lockedCertificate);

            $ids = $this->files->record([[
                'disk' => $lockedCertificate->disk,
                'path' => $lockedCertificate->path,
            ]]);

            $lockedCertificate->delete();

            return $ids;
        });

        $this->files->dispatchPurges($pendingIds);
    }
}
