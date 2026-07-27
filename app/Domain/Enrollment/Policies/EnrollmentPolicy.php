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
 * There is no deleteAny(): nothing registers a bulk action. Filament authorizes
 * a bulk action once against the *Any method and never consults the per-record
 * one, so leaving it undefined makes any bulk delete added later fail closed.
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
}
