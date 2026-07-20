<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\User;

/**
 * The safe way to set a staff account's roles from the UI.
 *
 * READ THIS BEFORE BUILDING THE UserResource (Task 5, P1-T05)
 * -----------------------------------------------------------
 * Filament's `Select::make('roles')->relationship('roles')` persists by calling
 * the relation's sync()/detach() directly. That path writes the model_has_roles
 * pivot straight through Eloquent and NEVER calls User::syncRoles(), so every
 * escalation guard — guard 1 (only a super admin grants super_admin), guard 2
 * (no self-edits), guard 3 (keep one super admin), guard 4 (assign_role
 * required) — is bypassed. This was confirmed finding 3 of the security review.
 *
 * The UserResource role field must therefore be detached from the relationship
 * writer and routed through this action instead. The shape Task 5 should use:
 *
 *     Select::make('roles')
 *         ->multiple()
 *         ->relationship('roles', 'name') // for options + hydration only
 *         ->dehydrated(false)             // do NOT let Filament persist it
 *         ->saveRelationshipsUsing(null); // and do NOT sync the relation
 *
 * then, in the resource's handleRecordCreation / handleRecordUpdate (or an
 * after-save hook), collect the selected role names and call:
 *
 *     app(SyncUserRolesAction::class)->execute($user, $selectedRoleNames);
 *
 * Because this routes through User::syncRoles(), the guards fire exactly as
 * they do everywhere else, and a rejected change surfaces as a 403.
 */
final class SyncUserRolesAction
{
    /**
     * @param  array<int, string>  $roles  Role names to become the account's exact set.
     */
    public function execute(User $user, array $roles): void
    {
        $user->syncRoles($roles);
    }
}
