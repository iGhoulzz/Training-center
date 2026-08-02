<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Policies;

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Support\EnrollmentUpdateRule;
use App\Models\User;

/**
 * Authorization for enrolments.
 *
 * TWO UPDATE PERMISSIONS, AND NOT A ROLE CHECK ANYWHERE
 * -----------------------------------------------------
 * Spec line 110 grants staff "create/edit in own batches" while admins edit
 * everything. The obvious implementation — branch on hasAnyRole(['super_admin',
 * 'admin']) — is forbidden by spec line 100 and by CLAUDE.md's first
 * non-negotiable: role membership is data, and adding a fifth role must not
 * require a code change.
 *
 * So the distinction is carried by two permissions:
 *
 *   - update_enrollment                    unrestricted. super_admin, admin.
 *   - update_assigned_batch_enrollment     restricted to batches the actor is
 *                                          assigned to teach. staff.
 *
 * Anyone holding the first edits anything. Anyone holding only the second edits
 * an enrolment when — and only when — they appear on that batch's instructor
 * list. Someone holding neither edits nothing. A sixth role wanting either
 * behaviour is a seeder change, not a code change.
 *
 * WHY ASSIGNMENT AND NOT EMPLOYMENT TYPE
 * --------------------------------------
 * "Own batches" is answered by the batch_instructor pivot, never by
 * staff_profiles.employment_type. Employment type says what somebody IS; the
 * pivot says what they were actually put on. An administrative profile assigned
 * to teach one batch may edit that batch's enrolments, and an instructor
 * assigned to nothing may edit none — both correct, and neither expressible
 * through employment type. EnrollmentPolicyTest asserts exactly that pair.
 *
 * CREATION IS UNSCOPED
 * --------------------
 * create() checks the permission alone. Spec line 15 requires a front-desk
 * staffer to enrol a walk-in, and they teach nothing; scoping creation the way
 * update() is scoped would make that impossible. The asymmetry matches the
 * spec's own reasoning at line 122 — registering somebody is a front-desk act,
 * amending an existing record is not.
 *
 * deleteAny() is written out and refuses. Nothing registers a bulk action, and
 * Filament authorizes one ONCE against the *Any method without ever consulting
 * the per-record rule — so a bulk delete could not express the assigned-batch
 * scoping that update() turns on, which is the whole point of this policy.
 *
 * WRITING IT OUT IS WHAT MAKES THE REFUSAL REAL, and an earlier version of this
 * comment had it backwards: it said leaving the method undefined made a later
 * bulk delete "fail closed". It does the opposite. Filament resolves a MISSING
 * policy method to Response::allow() where the Gate resolves it to false, so an
 * unmentioned ability is denied everywhere a test would look and allowed
 * everywhere a user would click. See tests/Feature/PolicyAbilitySurfaceTest.php.
 */
class EnrollmentPolicy
{
    public function __construct(private readonly EnrollmentUpdateRule $rule) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_enrollment');
    }

    public function view(User $actor, Enrollment $enrollment): bool
    {
        return $actor->can('view_enrollment');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_enrollment');
    }

    /**
     * Withdrawal and deletion are the only updates phase 1 performs.
     *
     * The rule itself lives in EnrollmentUpdateRule, which WithdrawEnrollmentAction
     * also consults — under lock, where it binds. Stating it once means the panel
     * and the Action cannot drift into disagreeing about who may amend what.
     *
     * DATABASE-BACKED, NEVER A LOADED RELATION. See EnrollmentUpdateRule: a
     * loaded relationship is a mutable property that any holder of the model can
     * replace outright, so authorizing from it is authorizing from the caller's
     * own memory.
     *
     * THIS ANSWER IS NOT THE BINDING ONE. It reads without a lock, which is right
     * for deciding what to render and wrong for deciding a write —
     * WithdrawEnrollmentAction re-asks with locking: true after taking the batch
     * mutex, because under REPEATABLE READ an ordinary read is served from a
     * snapshot that predates the lock.
     */
    public function update(User $actor, Enrollment $enrollment): bool
    {
        return $this->rule->allows($actor, (int) $enrollment->batch_id);
    }

    public function delete(User $actor, Enrollment $enrollment): bool
    {
        return $actor->can('delete_enrollment');
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

    public function restore(User $authUser, Enrollment $record): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, Enrollment $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, Enrollment $record): bool
    {
        return false;
    }

    public function reorder(User $authUser): bool
    {
        return false;
    }
}
