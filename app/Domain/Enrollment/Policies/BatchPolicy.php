<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Policies;

use App\Domain\Enrollment\Models\Batch;
use App\Models\User;

/**
 * Authorization for batches.
 *
 * Permission-based, with exactly one record-dependent rule — assignInstructor()
 * — and that rule comes straight from the spec.
 *
 * The grants are seeded by RolePermissionSeeder and the shape is deliberate:
 *
 *   - super_admin, admin: the full set, plus assign_instructor. Scheduling an
 *                         intake and deciding who teaches it are administrative
 *                         acts.
 *   - staff:              view_any_batch and view_batch only. Front-desk staff
 *                         answer "when does the next English B1 start"; they do
 *                         not schedule intakes and they do not assign teaching
 *                         hours, so they hold no create, update, delete or
 *                         assign_instructor.
 *   - student:            nothing. The portal (phase 3) is a separate panel with
 *                         a separate guard; this policy governs the staff
 *                         dashboard only.
 *
 * WHERE THE STATUS GATE APPLIES, AND WHERE IT DOES NOT
 * ----------------------------------------------------
 * Spec section 6: "A batch's status gates what may be edited: completed and
 * cancelled batches reject new enrolments and instructor changes." That
 * enumeration is the rule, and assignInstructor() below implements it.
 *
 * update() deliberately does NOT gate on acceptsEnrollments(), which is a
 * divergence from the Task 9 plan text. Gating it would make a closed batch
 * permanently unopenable — including its own status column — so a mis-clicked
 * "completed" could never be corrected from the application at all, and fixing
 * a typo in a finished batch's dates would require raw SQL. The spec asks for
 * enrolments and instructor changes to be refused, not for the record to be
 * frozen, and the narrower reading is also the recoverable one. P1-T10 and
 * P1-T11 enforce the refusals on their own writes, where the money-adjacent
 * consequences actually live.
 *
 * delete() is permission-only for now. The "refuse a batch that has enrolments"
 * rule belongs to P1-T11, which owns the enrollments table, and it will live in
 * the foreign key rather than here for the same reason the course refusal does:
 * a policy check races an enrolment created between check and delete, and a
 * constraint cannot be raced.
 *
 * There is no deleteAny(): BatchResource registers no bulk actions. Filament
 * authorizes a bulk action once against the *Any method and never consults the
 * per-record one, so leaving it undefined makes any bulk delete added later fail
 * closed until someone decides the rule on purpose. See docs/ENGINEERING.md,
 * "Bulk actions cannot be authorized per record".
 */
class BatchPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_batch');
    }

    public function view(User $actor, Batch $batch): bool
    {
        return $actor->can('view_batch');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_batch');
    }

    public function update(User $actor, Batch $batch): bool
    {
        return $actor->can('update_batch');
    }

    public function delete(User $actor, Batch $batch): bool
    {
        return $actor->can('delete_batch');
    }

    /**
     * Assigning an instructor, or changing their hours (P1-T10).
     *
     * The one record-dependent rule here, and the spec is explicit about it: a
     * completed or cancelled batch rejects instructor changes. Reassigning who
     * taught a finished course is rewriting history, and from phase 2 it is
     * rewriting what somebody is owed.
     *
     * `assign_instructor` is a custom ability, not part of the CRUD set, because
     * it is not "editing a batch" — it decides whose name and hours go against
     * teaching that the centre pays for.
     */
    public function assignInstructor(User $actor, Batch $batch): bool
    {
        return $actor->can('assign_instructor') && $batch->acceptsEnrollments();
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

    public function restore(User $authUser, Batch $record): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, Batch $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, Batch $record): bool
    {
        return false;
    }

    public function reorder(User $authUser): bool
    {
        return false;
    }
}
