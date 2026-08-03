<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Domain\Staff\Support\ActivityEvent;
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

        $pendingIds = DB::transaction(function () use ($actor, $profile): array {
            // The component may have hydrated this profile before another
            // request replaced its photo. Delete what the row points at NOW.
            $lockedProfile = StaffProfile::query()
                ->lockForUpdate()
                ->findOrFail($profile->getKey());

            Gate::forUser($actor)->authorize('update', $lockedProfile);

            $path = $lockedProfile->profile_photo_path;

            // Nothing to remove. Authorization still happened above: whether a
            // profile has a photo must not decide whether the caller may ask.
            if (! is_string($path) || $path === '') {
                return [];
            }

            $ids = $this->files->record([[
                'disk' => UpdateStaffPhotoAction::DISK,
                'path' => $path,
            ]]);

            $lockedProfile->update(['profile_photo_path' => null]);

            // Explicit for the same reason as the replacement event: the only
            // column that moves is excluded from the diff, so without this a
            // removed photo is indistinguishable from one that was never there.
            activity()
                ->causedBy($actor)
                ->performedOn($lockedProfile)
                ->event(ActivityEvent::PHOTO_REMOVED)
                ->log(ActivityEvent::PHOTO_REMOVED);

            return $ids;
        });

        $this->files->dispatchPurges($pendingIds);
    }
}
