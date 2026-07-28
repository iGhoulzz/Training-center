<?php

declare(strict_types=1);

namespace App\Domain\Staff\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Authorization for the activity log. Read-only, for everybody, always.
 *
 * EVERY MUTATION ABILITY RETURNS FALSE EXPLICITLY
 * -----------------------------------------------
 * Not "is omitted so it fails closed" — actually written out, including the bulk
 * and soft-delete variants Filament consults. An omitted method and a method
 * returning false behave identically today; they read very differently to
 * somebody adding a feature, and only one of them makes the intent reviewable.
 * An audit trail that can be edited is not an audit trail.
 *
 * THE PERMISSION IS DELIBERATELY UNHONOURED
 * -----------------------------------------
 * Production seeds only view_any_activity and view_activity —
 * create_activity/update_activity/delete_activity are not created at all, which
 * is why `activity` is absent from RolePermissionSeeder's CRUD resource list.
 *
 * But a permission that does not exist proves nothing about a policy. So
 * ActivityAppendOnlyTest creates delete_activity itself, grants it to a super
 * admin, and asserts deletion is still refused. That proves this class IGNORES
 * the grant, rather than that nobody happens to hold it.
 *
 * APPLICATION-LEVEL, NOT DATABASE-LEVEL
 * -------------------------------------
 * A raw SQL DELETE against activity_log still works, and nothing here pretends
 * otherwise. What is enforced is that no policy, no UI control and no
 * application code path can remove or alter an entry — ActivityAppendOnlyTest
 * covers all three. Making it true in the database as well would need a trigger
 * or a restricted grant, which is a deployment decision this phase has not taken.
 */
class ActivityPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_activity');
    }

    public function view(User $actor, Activity $activity): bool
    {
        return $actor->can('view_activity');
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $actor, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Activity $activity): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Activity $activity): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, Activity $activity): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
