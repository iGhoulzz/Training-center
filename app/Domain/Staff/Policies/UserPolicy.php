<?php

declare(strict_types=1);

namespace App\Domain\Staff\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Authorization for staff account management. This policy answers the
 * authorization questions behind the escalation guards from section 5 of the
 * design spec:
 *
 *   1. An admin cannot create, edit, or delete a super admin.
 *   2. No user can modify their own roles or permissions.
 *   4. A role or permission write requires the assign_role ability.
 *      The student role is never assignable through the staff screens.
 *   5. (P4-T43) Nobody acts on ANOTHER super admin through the panel. Super
 *      admins are no longer peers: update (and with it deactivate, activate and
 *      every role change, which authorize through update), delete and
 *      resetPassword are all refused when the target is a super admin and is
 *      not the actor. A super admin still manages everyone below them.
 *   Self rules (P4-T43): nobody resets their own password through the
 *   administrator flow (resetPassword is refused on self - the self-service
 *   page asks for the current password and keeps the session), and the existing
 *   self rules stay: no self-deletion, no self-deactivation, no own role edits.
 *   A super admin may still edit their OWN profile (name, email, locale): the
 *   account is theirs, and the edit page offers no password, role or active
 *   state change on it.
 *
 * Why guard 5 exists: on 2026-11-01 one of two super admins reset his own
 * password from the list (the one-time password was lost with his session) and
 * then deleted the other super admin to see what the button did. Both were
 * locked out. Taking a peer's account is also an account-takeover primitive
 * (reset the password, or change the email and reset by mail), so another super
 * admin is as untouchable to a super admin as any super admin is to an admin.
 * Recovery of a genuinely locked-out account is a server-console job: the
 * audited staff:issue-temporary-password and staff:restore-user commands.
 *
 * The request-path Actions (SyncUserRolesAction, DeleteUserAction,
 * DeactivateUserAction, ActivateUserAction, ResetUserPasswordAction) authorize
 * through these methods via Gate::forUser($actor) before performing any write,
 * so the policy's answer is binding on every request-path mutation.
 *
 * Guard 3 (never lose the last active super admin) is NOT an authorization
 * question — the actor may be fully entitled, yet the operation must still be
 * refused. It is a system invariant enforced by
 * App\Domain\Staff\Services\SuperAdminInvariantService, which the Actions
 * delegate to for any population-reducing write.
 *
 * DELIBERATE EXCEPTION TO THE PERMISSION-ONLY RULE
 * ------------------------------------------------
 * The project's first non-negotiable is that authorization is permission-based,
 * never role-based: $user->can('delete_user'), never $user->hasRole('admin').
 *
 * mayActOn() and assignRole() below break that rule on purpose, and this is the
 * only place in the codebase where they should. What they express is *rank* —
 * "is this person a super admin" — which is not reducible to a permission
 * check. Encoding it as a permission would mean inventing a synthetic
 * `is_super_admin` ability that duplicates the role and can be granted
 * directly with givePermissionTo(), which is precisely the escalation these
 * guards exist to prevent. The role is the boundary, so the role is checked.
 *
 * Rank is resolved through User::isSuperAdmin() and Role::isSuperAdmin(), which
 * compare the super-admin role's immutable primary key against the account's
 * live role pivot — never a mutable, possibly-stale role name. See
 * App\Models\Role for why identity is keyed on the id and why the name/row are
 * frozen.
 *
 * P1-T15 made assignRole() honour that claim: it previously received a raw
 * request string and compared it to a constant, which the database's
 * case-insensitive collation defeated.
 *
 * A reviewer applying the permission-only rule mechanically should read this as
 * intentional, not an oversight. Every other check in this class is
 * permission-based.
 */
class UserPolicy
{
    /** The role every portal account holds. Issued by IssuePortalCredentialAction, never assigned here. */
    private const PORTAL_ROLE = 'student';

    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_user');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('view_user');
    }

    /**
     * Creating an account issues its first credential, so it requires
     * permission to do that as well as permission to create the row.
     *
     * The create form has no password field on purpose — an administrator
     * typing someone else's password is a credential they then know — so
     * CreateUser generates a temporary one through ResetUserPasswordAction.
     * Being able to create an account whose password you can see is the same
     * capability as resetting one, so it is gated the same way. Stating it
     * here means the UI hides "New user" from an actor who would only hit an
     * authorization failure at save time.
     */
    public function create(User $actor): bool
    {
        return $actor->can('create_user') && $actor->can('reset_user_password');
    }

    /**
     * Edit an account - and, because the Actions authorize it, activate or
     * deactivate it and change its roles.
     *
     * Another super admin is refused (guard 5): editing their email is as good
     * as taking the account. One's own account is allowed, so a super admin can
     * still correct their own name or locale.
     */
    public function update(User $actor, User $target): bool
    {
        if (! $actor->can('update_user')) {
            return false;
        }

        return $this->mayActOn($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        if (! $actor->can('delete_user')) {
            return false;
        }

        // Guard 2: no self-deletion.
        if ($actor->is($target)) {
            return false;
        }

        return $this->mayActOn($actor, $target);
    }

    /**
     * Guard 1: only a super admin may grant the super_admin role.
     *
     * The student role is not assignable at all (P4-T33, security finding): it
     * means "has a portal account", and the only thing that issues a portal
     * account is IssuePortalCredentialAction, which writes the role itself. Through
     * this ability - which SyncUserRolesAction asks for
     * every role it adds AND every role it removes - the role could be attached to
     * a staff account, or stripped from a portal one, by a crafted payload. The
     * refusal is for every rank, super admin included: it is not about who is
     * asking. The role picker asks this same ability, so it never offers student.
     */
    public function assignRole(User $actor, Role $role): bool
    {
        if (! $actor->can('assign_role')) {
            return false;
        }

        // The resolved row's canonical name, not a submitted spelling: callers
        // resolve names to rows before asking (RoleSet::resolve), so the database's
        // case-insensitive match cannot be used to slip past this comparison.
        if ($role->name === self::PORTAL_ROLE) {
            return false;
        }

        /*
         * A RESOLVED ROW, COMPARED BY KEY (P1-T15, security finding 2).
         *
         * This took an untrusted string and compared it with !==. The database
         * runs utf8mb4_unicode_ci, so it resolved 'Super_Admin' to the
         * super_admin row while PHP said the two were different — the guard
         * allowed the grant and Spatie attached the real role. An admin could
         * promote a puppet account with a capital letter.
         *
         * The caller now resolves names to rows before authorizing, and
         * Role::isSuperAdmin() compares primary keys. Spelling cannot enter the
         * decision, which is what the docblock above always claimed.
         */
        return ! $role->isSuperAdmin() || $actor->isSuperAdmin();
    }

    /**
     * Guard 2: nobody edits their own role assignments, regardless of rank.
     */
    public function modifyOwnRoles(User $actor, User $target): bool
    {
        return ! $actor->is($target);
    }

    /**
     * Issue a temporary password for ANOTHER account.
     *
     * Never one's own (P4-T43): the reset replaces the actor's password hash,
     * the session's stored hash no longer matches, and AuthenticateSession ends
     * the session before the one-time password is shown - the actor is locked
     * out holding a password nobody saw. The self-service PasswordChange page
     * is the way to change one's own password. Another super admin is refused
     * too (guard 5).
     */
    public function resetPassword(User $actor, User $target): bool
    {
        if (! $actor->can('reset_user_password')) {
            return false;
        }

        if ($actor->is($target)) {
            return false;
        }

        return $this->mayActOn($actor, $target);
    }

    /**
     * Guards 1 and 5: a super admin is acted on by nobody but themselves.
     *
     * Guard 1 (a non-super-admin may never act on a super admin) is the special
     * case of this where the actor is not a super admin; guard 5 extends it to
     * the other super admins, so the only super admin a super admin may touch is
     * their own account. The abilities that must also refuse the actor's own
     * account (delete, resetPassword) say so before asking this.
     *
     * Note this tests the target's *role*, not their permissions. A user holding
     * delete_user directly via givePermissionTo() still cannot reach a super
     * admin, and a user holding both admin and super_admin is treated as a
     * super admin in both directions.
     */
    private function mayActOn(User $actor, User $target): bool
    {
        if ($target->isSuperAdmin()) {
            return $actor->is($target);
        }

        return true;
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

    public function restore(User $authUser, User $record): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, User $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, User $record): bool
    {
        return false;
    }

    public function reorder(User $authUser): bool
    {
        return false;
    }
}
