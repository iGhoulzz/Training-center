<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Policies;

use App\Domain\Enrollment\Models\Course;
use App\Models\User;

/**
 * Authorization for the course catalogue.
 *
 * Purely permission-based. A course carries no authorization weight of its own,
 * so there is no rank check and no ownership rule here.
 *
 * The grants are seeded by RolePermissionSeeder and the shape is deliberate:
 *
 *   - super_admin, admin: the full set. The catalogue is what the centre sells,
 *                         so defining it is an administrative act.
 *   - staff:              view_any_course and view_course only. Front-desk staff
 *                         answer "do you run English B1, and how many hours is
 *                         it" all day; they do not decide what the centre
 *                         offers, so they hold NO create, update or delete.
 *   - student:            nothing. The portal (phase 3) is a separate panel with
 *                         a separate guard; this policy governs the staff
 *                         dashboard only.
 *
 * There is no deleteAny(): CourseResource registers no bulk actions. Filament
 * authorizes a bulk action once against the *Any method and never consults the
 * per-record one, so leaving it undefined makes any bulk delete added later fail
 * closed until someone decides the rule on purpose. See docs/ENGINEERING.md,
 * "Bulk actions cannot be authorized per record".
 *
 * delete() does NOT check for existing batches. That refusal belongs to the
 * database — batches.course_id is restrictOnDelete — because a policy check
 * races: the batch can be created between the check passing and the delete
 * running. The constraint cannot be raced, and CourseTest asserts it fires.
 */
class CoursePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_course');
    }

    public function view(User $actor, Course $course): bool
    {
        return $actor->can('view_course');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_course');
    }

    public function update(User $actor, Course $course): bool
    {
        return $actor->can('update_course');
    }

    public function delete(User $actor, Course $course): bool
    {
        return $actor->can('delete_course');
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

    public function deleteAny(User $authUser): bool
    {
        return false;
    }

    public function restore(User $authUser, Course $record): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, Course $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, Course $record): bool
    {
        return false;
    }

    public function reorder(User $authUser): bool
    {
        return false;
    }
}
