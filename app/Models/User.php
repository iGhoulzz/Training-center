<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Exceptions\RoleEscalationException;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

use function Illuminate\Support\enum_value;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'locale', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes {
        // forceDelete arrives from the SoftDeletes trait and is flattened into
        // this class, so there is no parent implementation to defer to. The
        // guarded override below wraps this alias in a transaction.
        SoftDeletes::forceDelete as private frameworkForceDelete;
    }

    /*
     * The role and permission writers are aliased rather than called through
     * parent::, because they arrive via a trait and are flattened into this
     * class — there is no parent implementation to defer to. The guarded
     * public overrides below delegate to these aliases.
     */
    use HasRoles {
        assignRole as private spatieAssignRole;
        removeRole as private spatieRemoveRole;
        syncRoles as private spatieSyncRoles;
        givePermissionTo as private spatieGivePermissionTo;
        revokePermissionTo as private spatieRevokePermissionTo;
        syncPermissions as private spatieSyncPermissions;
    }

    /**
     * Re-entrancy latch for the role guards.
     *
     * Spatie's syncRoles() finishes by calling assignRole(), which would
     * re-enter the guard against a role set this object has already emptied and
     * report a spurious change. syncRoles() validates the real diff itself and
     * latches this for the duration of the parent call.
     */
    private bool $withinGuardedRoleSync = false;

    /**
     * Panel access is permission-based, never role-based.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->can('access_admin_panel');
    }

    /**
     * Limit a query to accounts that have not been deactivated.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Is this account a super admin?
     *
     * Resolved by the super-admin role's primary key, read fresh from the pivot
     * every call. Two deliberate choices, both remediation fixes:
     *
     *   - By key, not name (finding 6): a check that compared the loaded role's
     *     name could be desynced from the id Spatie actually writes by mutating
     *     an unsaved name in memory. The key cannot be spoofed that way.
     *   - Fresh, not the loaded relation (finding 6): assertNotLastSuperAdmin
     *     read a stale in-memory roles relation, which let the last super admin
     *     be deleted when that relation happened to be empty. Querying the pivot
     *     directly ignores any stale copy on the instance.
     */
    public function isSuperAdmin(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $superAdminId = Role::superAdminId();

        return $superAdminId !== null
            && $this->roles()->whereKey($superAdminId)->exists();
    }

    /**
     * Escalation guard 3, enforced at the model layer.
     *
     * This lives on the model rather than in UserPolicy because a policy only
     * covers code paths that consult it. This invariant must hold for seeders,
     * tinker, queued jobs, console commands and Filament bulk actions alike.
     *
     * The check and the write it protects run inside a transaction (see the
     * guarded delete/save/removeRole/syncRoles overrides), and this method locks
     * the super-admin role row before counting survivors, so two concurrent
     * removals serialize instead of both passing an unlocked count (finding 7).
     *
     * Soft-deleted super admins are deliberately not counted as survivors: a
     * soft-deleted account cannot log in, so leaving one behind would still
     * lock the system out of role management. The default query already
     * excludes them via the SoftDeletes global scope.
     */
    public function assertNotLastSuperAdmin(): void
    {
        if (! $this->isSuperAdmin()) {
            return;
        }

        Role::lockSuperAdminRow();

        $survivors = static::query()
            ->role(Role::SUPER_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($this->getKey())
            ->count();

        if ($survivors === 0) {
            throw new LastSuperAdminException;
        }
    }

    /**
     * @param  string|int|array<int, mixed>|RoleContract|Collection<int, mixed>|\BackedEnum  ...$roles
     */
    public function assignRole(...$roles): static
    {
        $adding = array_diff($this->resolveRoleNames($roles), $this->currentRoleNames());

        $this->guardRoleChange(array_values($adding), []);

        return $this->spatieAssignRole(...$roles);
    }

    /**
     * @param  string|int|array<int, mixed>|RoleContract|Collection<int, mixed>|\BackedEnum  ...$role
     */
    public function removeRole(...$role): static
    {
        // Wrapped in a transaction so guard 3's row lock (taken in
        // assertNotLastSuperAdmin) is held across the detach that follows it.
        return DB::transaction(function () use ($role): static {
            $removing = array_intersect($this->resolveRoleNames($role), $this->currentRoleNames());

            $this->guardRoleChange([], array_values($removing));

            return $this->spatieRemoveRole(...$role);
        });
    }

    /**
     * @param  string|int|array<int, mixed>|RoleContract|Collection<int, mixed>|\BackedEnum  ...$roles
     */
    public function syncRoles(...$roles): static
    {
        return DB::transaction(function () use ($roles): static {
            $target = $this->resolveRoleNames($roles);
            $current = $this->currentRoleNames();

            $this->guardRoleChange(
                array_values(array_diff($target, $current)),
                array_values(array_diff($current, $target)),
            );

            $this->withinGuardedRoleSync = true;

            try {
                return $this->spatieSyncRoles(...$roles);
            } finally {
                $this->withinGuardedRoleSync = false;
            }
        });
    }

    /**
     * Guard 2 covers "roles or permissions". Direct permission grants bypass
     * roles entirely, so self-service permission changes are blocked too.
     *
     * @param  string|int|array<int, mixed>|Permission|Collection<int, mixed>|\BackedEnum  ...$permissions
     */
    public function givePermissionTo(...$permissions): static
    {
        $this->guardPermissionChange();

        return $this->spatieGivePermissionTo(...$permissions);
    }

    /**
     * @param  string|int|array<int, mixed>|Permission|Collection<int, mixed>|\BackedEnum  ...$permissions
     */
    public function revokePermissionTo(...$permissions): static
    {
        $this->guardPermissionChange();

        return $this->spatieRevokePermissionTo(...$permissions);
    }

    /**
     * @param  string|int|array<int, mixed>|Permission|Collection<int, mixed>|\BackedEnum  ...$permissions
     */
    public function syncPermissions(...$permissions): static
    {
        $this->guardPermissionChange();

        return $this->spatieSyncPermissions(...$permissions);
    }

    /**
     * Guard the destructive write in a transaction so guard 3's row lock spans
     * the delete. delete() comes from the parent (Model), so parent:: reaches
     * it; forceDelete() comes from the SoftDeletes trait, so it is reached
     * through the frameworkForceDelete alias instead.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    public function forceDelete(): ?bool
    {
        return DB::transaction(fn (): ?bool => $this->frameworkForceDelete());
    }

    /**
     * Only deactivation can reduce the active super-admin population, so only
     * that case needs the transaction that lets guard 3's lock span the write.
     * Every other save is left untouched.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty('is_active') && $this->is_active === false) {
            return DB::transaction(fn (): bool => parent::save($options));
        }

        return parent::save($options);
    }

    protected static function booted(): void
    {
        /*
         * forceDeleting must be hooked separately, and it is not belt and
         * braces — without it guard 3 does not hold for forceDelete().
         *
         * Spatie's bootHasRoles() registers its own "deleting" listener that
         * detaches every role when isForceDeleting() is true. Trait boot
         * methods run before booted(), so that listener is registered first and
         * therefore runs first. By the time the "deleting" hook below is
         * reached during a force delete, the pivot rows are already gone,
         * isSuperAdmin() answers false, and the guard waves the deletion
         * through.
         *
         * The forceDeleting event fires at the top of forceDelete(), before any
         * detaching, so the role state it sees is intact.
         */
        static::forceDeleting(function (User $user): void {
            $user->assertActorOutranksForWrite();
            $user->assertNotLastSuperAdmin();
        });

        static::deleting(function (User $user): void {
            $user->assertActorOutranksForWrite();
            $user->assertNotLastSuperAdmin();
        });

        static::updating(function (User $user): void {
            $user->assertActorOutranksForWrite();

            if ($user->isDirty('is_active') && $user->is_active === false) {
                $user->assertNotLastSuperAdmin();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Escalation guards 1, 2, and 4, enforced at the model layer for role
     * writes.
     *
     * UserPolicy states guards 1 and 2, but nothing forces a Filament form, a
     * controller or an action to consult a policy before writing the roles
     * relation — Spatie's assignRole() answers to no gate. This is the backstop
     * that makes the policy's answer binding.
     *
     * When there is no authenticated actor the actor-dependent guards are
     * skipped: seeders, queued jobs, console commands and tests legitimately
     * assign roles with nobody logged in, and there is no principal to
     * authorize against. Guard 3 below is a system invariant and applies
     * unconditionally.
     *
     * @param  array<int, string>  $adding
     * @param  array<int, string>  $removing
     */
    private function guardRoleChange(array $adding, array $removing): void
    {
        if ($this->withinGuardedRoleSync) {
            return;
        }

        // Guard 3: stripping super_admin from the last active super admin
        // leaves the system with nobody able to manage roles — the same end
        // state as deleting or deactivating them, so the same rule applies.
        // It holds unconditionally, including on the CLI path.
        if (in_array(Role::SUPER_ADMIN, $removing, true)) {
            $this->assertNotLastSuperAdmin();
        }

        $actor = Auth::user();

        if (! $actor instanceof self) {
            return;
        }

        if ($adding === [] && $removing === []) {
            return;
        }

        // Guard 2: nobody edits their own roles, regardless of rank.
        if ($actor->is($this)) {
            throw RoleEscalationException::cannotModifyOwnRoles();
        }

        // Guard 4: an authenticated role write requires the assign_role
        // ability. Without this a staff account assigned admin to another user
        // directly through the model layer.
        if (! $actor->can('assign_role')) {
            throw RoleEscalationException::cannotAssignRoles();
        }

        // Guard 1: only a super admin may grant or revoke super_admin.
        if (
            (in_array(Role::SUPER_ADMIN, $adding, true) || in_array(Role::SUPER_ADMIN, $removing, true))
            && ! $actor->isSuperAdmin()
        ) {
            throw RoleEscalationException::cannotGrantSuperAdmin();
        }
    }

    /**
     * Guard 2 and guard 4 for direct permission writes.
     *
     * Nobody edits their own permissions (guard 2), and an authenticated actor
     * changing another account's permissions needs the assign_role ability
     * (guard 4) — the same capability that gates role writes, and one only
     * super admins hold. The CLI path (seeders, factories) is exempt, matching
     * the role guards.
     */
    private function guardPermissionChange(): void
    {
        $actor = Auth::user();

        if (! $actor instanceof self) {
            return;
        }

        if ($actor->is($this)) {
            throw RoleEscalationException::cannotModifyOwnPermissions();
        }

        if (! $actor->can('assign_role')) {
            throw RoleEscalationException::cannotManagePermissions();
        }
    }

    /**
     * Guard 1, enforced at the model layer for direct account writes.
     *
     * The delete/forceDelete/update hooks previously enforced only guard 3, so
     * an authenticated admin could update or soft-delete a super admin directly
     * (finding 5). A non-super-admin actor may not write a super admin account.
     * Self-writes are allowed here — guard 2 and the policy handle those — and
     * the CLI path (no principal) is exempt, matching the role guards.
     */
    private function assertActorOutranksForWrite(): void
    {
        $actor = Auth::user();

        if (! $actor instanceof self || $actor->is($this)) {
            return;
        }

        if ($this->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw RoleEscalationException::cannotManageSuperAdmin();
        }
    }

    /** @return array<int, string> */
    private function currentRoleNames(): array
    {
        return $this->exists
            ? $this->roles()->pluck('name')->all()
            : [];
    }

    /**
     * Resolve variadic role arguments to role names.
     *
     * Mirrors Spatie's getStoredRole() resolution exactly so the guards cannot
     * drift from what the parent implementation actually writes. Unresolvable
     * arguments throw rather than being skipped — a guard that silently ignores
     * input it does not understand fails open, which is the one thing it must
     * never do.
     *
     * A passed Role object is resolved by its primary key, not its in-memory
     * name (finding 6): Spatie detaches by key, so reading a mutable name off
     * the object could hide a super_admin removal from guard 3.
     *
     * @param  array<int, mixed>  $roles
     * @return array<int, string>
     */
    private function resolveRoleNames(array $roles): array
    {
        /** @var array<int, string> $names */
        $names = collect($roles)
            ->flatten()
            ->map(function (mixed $role): string {
                $role = enum_value($role);

                if ($role instanceof RoleContract && $role->getKey() !== null) {
                    $roleClass = $this->getRoleClass();

                    /** @var object{name: string} $resolved */
                    $resolved = $roleClass::findById($role->getKey(), $this->getDefaultGuardName());

                    return $resolved->name;
                }

                if (is_object($role)) {
                    /** @var object{name: string} $role */
                    return $role->name;
                }

                if (is_int($role) || PermissionRegistrar::isUid($role)) {
                    $roleClass = $this->getRoleClass();

                    /** @var object{name: string} $resolved */
                    $resolved = $roleClass::findById($role, $this->getDefaultGuardName());

                    return $resolved->name;
                }

                if (is_string($role)) {
                    return $role;
                }

                throw new InvalidArgumentException(
                    'Escalation guard could not resolve a role argument to a name.',
                );
            })
            ->unique()
            ->values()
            ->all();

        return $names;
    }
}
