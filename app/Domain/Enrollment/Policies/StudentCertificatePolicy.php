<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Policies;

use App\Models\User;

/**
 * Who may read and act on the certificate register (design section 8.2).
 *
 * FIVE ABILITIES, AND THREE REFUSALS THAT ARE NOT ABILITIES
 * =========================================================
 * A certificate is issued, replaced or revoked. It is never created, updated or
 * deleted — those three are refused for everyone, super admin included, which is
 * what makes this register audit evidence rather than a working document.
 *
 * The corresponding permissions are deliberately NEVER SEEDED (T1), following
 * the reasoning `RolePermissionSeeder` already records for `create_charge` and
 * the activity log: seeding an ability nothing honours invites somebody to wire
 * it up later. `CertificatePolicyTest` makes the stronger statement anyway — it
 * creates `delete_student_certificate` inside the test, grants it, and proves
 * the refusal still stands. "The permission does not exist" and "the policy
 * refuses" are different claims, and only the second one survives someone
 * seeding the permission.
 *
 * NOT REGISTERED IN AppServiceProvider, ON PURPOSE
 * ------------------------------------------------
 * `Gate::guessPolicyName()` walks every namespace prefix longest-first
 * (Gate.php:721-724) and resolves this class from
 * `App\Domain\Enrollment\Models\StudentCertificate` unaided. Phase 2 measured
 * that nine of the eleven existing registrations are redundant and two are not,
 * so this one is left out and `CertificatePolicyTest` asserts the resolution
 * with `Gate::getPolicyFor()` rather than assuming it. An unregistered policy
 * that stopped resolving would fail silently, as a `false` on every check.
 *
 * PERMISSION-BASED, NEVER ROLE-BASED. Every method below asks `can()`. Which
 * roles hold which ability is `RolePermissionSeeder`'s business, and adding a
 * fifth role must not require editing this file.
 *
 * THE REGISTER IS STAFF-FACING. A student never reaches it through this policy
 * — their own certificate is `view_own_certificate` on the portal (T7), scoped
 * by `AuthenticatedStudent`, which is a different question from "may this actor
 * read the register".
 */
class StudentCertificatePolicy
{
    /** May this actor list the register? */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_student_certificate');
    }

    /** May this actor open one certificate? */
    public function view(User $user): bool
    {
        return $user->can('view_student_certificate');
    }

    /**
     * Issuing a certificate for a completed enrolment.
     *
     * Whether the enrolment is actually completed, and whether one is already
     * valid, are T5's business — decided under a lock, because a policy cannot
     * hold one. This answers only "is this actor entitled to issue at all".
     */
    public function issue(User $user): bool
    {
        return $user->can('issue_student_certificate');
    }

    /** Reissuing a corrected certificate, superseding the current one. */
    public function replace(User $user): bool
    {
        return $user->can('replace_student_certificate');
    }

    /** Withdrawing a certificate that should no longer stand. */
    public function revoke(User $user): bool
    {
        return $user->can('revoke_student_certificate');
    }

    /**
     * Refused unconditionally — a certificate is ISSUED, through T5's Action.
     *
     * `create` exists here so that a Filament resource, a generic ability check,
     * or anything else consulting the conventional CRUD verb gets a refusal
     * rather than falling through to a null policy result.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Refused unconditionally — an issued row is immutable.
     *
     * Only lifecycle fields transition, and only through T5's Actions, which
     * write them under a lock. There is no path by which a person edits a
     * certificate's snapshot, reference, enrolment or issuing actor.
     */
    public function update(User $user): bool
    {
        return false;
    }

    /**
     * Refused unconditionally — issued rows are never deleted.
     *
     * Belt and braces with the database: `student_certificates.enrollment_id`
     * restricts on delete, so the register cannot be emptied by removing an
     * enrolment either (T6 turns that into a translated business refusal).
     */
    public function delete(User $user): bool
    {
        return false;
    }
}
