<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Policies;

use App\Domain\Enrollment\Models\Student;
use App\Models\User;

/**
 * Authorization for student records.
 *
 * Purely permission-based. There is no rank check and no ownership rule: a
 * student record carries no authorization weight, so nothing here can be an
 * escalation path the way a user account is.
 *
 * The grants are seeded by RolePermissionSeeder and the shape of them is a
 * decision the centre confirmed, not an accident:
 *
 *   - super_admin, admin: the full set.
 *   - staff:              view_any_student, view_student and create_student.
 *                         Front-desk staff answer questions about any student
 *                         who walks in, so the read is deliberately unscoped —
 *                         it is NOT limited to the batches they teach, and they
 *                         register walk-ins themselves. They may NOT update or
 *                         delete: create without update is the deliberate part,
 *                         because amending or removing an existing record is an
 *                         administrative act rather than front-desk work.
 *   - student:            nothing. The portal (phase 3) is a separate panel
 *                         with a separate guard, and this policy governs the
 *                         staff dashboard only.
 *
 * There is no deleteAny(): StudentResource registers no bulk actions. Filament
 * authorizes a bulk action once against the *Any method and never consults the
 * per-record one, so leaving it undefined makes any bulk delete added later
 * fail closed until someone decides the rule on purpose. See
 * docs/ENGINEERING.md, "Bulk actions cannot be authorized per record".
 */
class StudentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_student');
    }

    public function view(User $actor, Student $student): bool
    {
        return $actor->can('view_student');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_student');
    }

    public function update(User $actor, Student $student): bool
    {
        return $actor->can('update_student');
    }

    public function delete(User $actor, Student $student): bool
    {
        return $actor->can('delete_student');
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

    public function restore(User $authUser, Student $record): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, Student $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, Student $record): bool
    {
        return false;
    }

    public function reorder(User $authUser): bool
    {
        return false;
    }
}
