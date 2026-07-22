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
     * Filament authorizes a bulk action once, against this method, and never
     * consults delete() for the selected rows — so a *Any method that merely
     * repeats a permission check silently discards any per-record protection
     * delete() applies. delete() has none to discard here, so the permission is
     * the whole answer.
     *
     * If a per-record rule is ever added to delete(), this method must be
     * revisited in the same edit. See docs/ENGINEERING.md, "Bulk actions cannot
     * be authorized per record", for the incident that established the rule.
     */
    public function deleteAny(User $actor): bool
    {
        return $actor->can('delete_staff_profile');
    }
}
