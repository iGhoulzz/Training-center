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

    /*
    |--------------------------------------------------------------------------
    | Dormant Filament abilities (P1-T15, security finding 1)
    |--------------------------------------------------------------------------
    |
    | THESE ARE NOT REDUNDANT AND MUST NOT BE DELETED AS DEAD CODE.
    |
    | Filament and Laravel disagree about a MISSING policy method. Laravel's Gate
    | returns false (Illuminate/Auth/Access/Gate.php: "if (! is_callable(...)) {
    | return false; }"). Filament's get_authorization_response() consults the
    | Gate only when method_exists($policy, $action); otherwise, with strict
    | authorization off — the default, and this panel never enables it — and no
    | Gate::before callback registered, it falls through to Response::allow().
    | See vendor/filament/filament/src/helpers.php.
    |
    | So an ability this policy simply does not mention is DENIED everywhere a
    | test would look and ALLOWED everywhere a user would click. Writing them out
    | is what makes the answer real.
    |
    | They return false because these operations do not exist in phase 1, not
    | because of who is asking: no restore, force-delete or bulk control is
    | rendered anywhere. The danger is the next person to add the standard
    | Filament soft-delete idiom to a resource and inherit an open door.
    |
    | The *Any abilities are refused for a second reason as well: Filament
    | authorizes a bulk action ONCE against them and never consults the
    | per-record rule, so any protection expressed per record would be skipped.
    */

    public function restore(User $authUser, StaffProfile $record): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, StaffProfile $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, StaffProfile $record): bool
    {
        return false;
    }

    public function reorder(User $authUser): bool
    {
        return false;
    }
}
