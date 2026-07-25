<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Remove a staff member's profile photo — the column and the bytes.
 *
 * Clearing the column alone would leave the image on disk forever, findable by
 * nobody and deletable by nobody.
 *
 * Authorizes `update` on the profile for the same reason UpdateStaffPhotoAction
 * does: removing a photo amends the employment record, it does not delete it.
 * The disk is read from UpdateStaffPhotoAction, which owns the fact that photos
 * live on the private disk (staff_profiles stores a path with no disk column).
 */
final class DeleteStaffPhotoAction
{
    public function __construct(
        private readonly FileLifecycleService $files,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, StaffProfile $profile): void
    {
        Gate::forUser($actor)->authorize('update', $profile);

        $path = $profile->profile_photo_path;

        // Nothing to remove. Still authorized above: whether a profile happens
        // to have a photo must not decide whether the caller was entitled to ask.
        if (! is_string($path) || $path === '') {
            return;
        }

        $pendingIds = DB::transaction(function () use ($profile, $path): array {
            $ids = $this->files->record([[
                'disk' => UpdateStaffPhotoAction::DISK,
                'path' => $path,
            ]]);

            $profile->update(['profile_photo_path' => null]);

            return $ids;
        });

        $this->files->dispatchPurges($pendingIds);
    }
}
