<?php

declare(strict_types=1);

namespace App\Domain\Staff\Policies;

use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;

/**
 * Authorization for staff employment records.
 *
 * Purely permission-based, with no rank check, and that is deliberate rather
 * than an omission. UserPolicy carries the escalation guards because a user
 * account holds roles, and reaching a super admin's account is an escalation
 * path. A staff profile holds a phone number, a job title, and a hire date — no
 * authorization weight whatsoever — so editing a super admin's job title
 * confers nothing, and inventing a rank rule here would imply a protection the
 * data does not need.
 *
 * The permissions are seeded by RolePermissionSeeder: super_admin and admin
 * hold the full set, staff and student hold none of it.
 *
 * The Filament resource that consumes this policy is built in a later task.
 * The policy lands now so that work inherits a decided boundary instead of
 * writing one under deadline.
 */
class StaffProfilePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_staff_profile');
    }

    public function view(User $actor, StaffProfile $profile): bool
    {
        return $actor->can('view_staff_profile');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_staff_profile');
    }

    public function update(User $actor, StaffProfile $profile): bool
    {
        return $actor->can('update_staff_profile');
    }

    public function delete(User $actor, StaffProfile $profile): bool
    {
        return $actor->can('delete_staff_profile');
    }

    /**
     * Bulk delete is closed outright (P1-T06b).
     *
     * Filament authorizes a bulk delete once against this method and never
     * consults delete() for the selected rows. Deleting a staff profile is no
     * longer a plain row removal: DeleteStaffProfileAction collects each
     * certificate and photo path, writes a pending_file_deletions receipt in the
     * same transaction as the delete, requires delete_staff_certificate whenever
     * the profile owns certificates, and schedules the bytes for removal. A bulk
     * delete would bypass every part of that — orphaning certificate files on
     * disk and skipping the per-profile certificate grant.
     *
     * So this returns false unconditionally rather than the permission: even were
     * a bulk action mistakenly registered, Filament would render none.
     * StaffProfileResource registers none regardless. Delete one profile at a
     * time, through the Action. See docs/ENGINEERING.md, "Bulk actions cannot be
     * authorized per record".
     */
    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
