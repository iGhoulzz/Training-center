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
use InvalidArgumentException;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'locale', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

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
     * Escalation guard 3, enforced at the model layer.
     *
     * This lives on the model rather than in UserPolicy because a policy only
     * covers code paths that consult it. This invariant must hold for seeders,
     * tinker, queued jobs, console commands and Filament bulk actions alike.
     *
     * Soft-deleted super admins are deliberately not counted as survivors: a
     * soft-deleted account cannot log in, so leaving one behind would still
     * lock the system out of role management. The default query already
     * excludes them via the SoftDeletes global scope.
     */
    public function assertNotLastSuperAdmin(): void
    {
        if (! $this->hasRole('super_admin')) {
            return;
        }

        $survivors = static::query()
            ->role('super_admin')
            ->where('is_active', true)
            ->whereKeyNot($this->getKey())
            ->count();

        if ($survivors === 0) {
            throw new LastSuperAdminException;
        }
    }

    /**
     * @param  string|int|array<int, mixed>|Role|Collection<int, mixed>|\BackedEnum  ...$roles
     */
    public function assignRole(...$roles): static
    {
        $adding = array_diff($this->resolveRoleNames($roles), $this->currentRoleNames());

        $this->guardRoleChange(array_values($adding), []);

        return $this->spatieAssignRole(...$roles);
    }

    /**
     * @param  string|int|array<int, mixed>|Role|Collection<int, mixed>|\BackedEnum  ...$role
     */
    public function removeRole(...$role): static
    {
        $removing = array_intersect($this->resolveRoleNames($role), $this->currentRoleNames());

        $this->guardRoleChange([], array_values($removing));

        return $this->spatieRemoveRole(...$role);
    }

    /**
     * @param  string|int|array<int, mixed>|Role|Collection<int, mixed>|\BackedEnum  ...$roles
     */
    public function syncRoles(...$roles): static
    {
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
    }

    /**
     * Guard 2 covers "roles or permissions". Direct permission grants bypass
     * roles entirely, so self-service permission changes are blocked too.
     *
     * @param  string|int|array<int, mixed>|Permission|Collection<int, mixed>|\BackedEnum  ...$permissions
     */
    public function givePermissionTo(...$permissions): static
    {
        $this->guardOwnPermissionChange();

        return $this->spatieGivePermissionTo(...$permissions);
    }

    /**
     * @param  string|int|array<int, mixed>|Permission|Collection<int, mixed>|\BackedEnum  ...$permissions
     */
    public function revokePermissionTo(...$permissions): static
    {
        $this->guardOwnPermissionChange();

        return $this->spatieRevokePermissionTo(...$permissions);
    }

    /**
     * @param  string|int|array<int, mixed>|Permission|Collection<int, mixed>|\BackedEnum  ...$permissions
     */
    public function syncPermissions(...$permissions): static
    {
        $this->guardOwnPermissionChange();

        return $this->spatieSyncPermissions(...$permissions);
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
         * hasRole('super_admin') answers false, and the guard waves the
         * deletion through.
         *
         * Worse, it does so intermittently: if the roles relation happened to
         * be loaded on the instance beforehand, hasRole() reads the stale
         * in-memory copy and the guard fires correctly. Whether the last super
         * admin is protected would otherwise depend on whether some earlier
         * line of code happened to touch $user->roles.
         *
         * The forceDeleting event fires at the top of forceDelete(), before any
         * detaching, so the role state it sees is intact.
         */
        static::forceDeleting(function (User $user): void {
            $user->assertNotLastSuperAdmin();
        });

        static::deleting(function (User $user): void {
            $user->assertNotLastSuperAdmin();
        });

        static::updating(function (User $user): void {
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
     * Escalation guards 1 and 2, enforced at the model layer.
     *
     * UserPolicy states the same two rules, but nothing forces a Filament form,
     * a controller or an action to consult a policy before writing the roles
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
        if (in_array('super_admin', $removing, true)) {
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

        // Guard 1: only a super admin may grant or revoke super_admin.
        if (
            (in_array('super_admin', $adding, true) || in_array('super_admin', $removing, true))
            && ! $actor->hasRole('super_admin')
        ) {
            throw RoleEscalationException::cannotGrantSuperAdmin();
        }
    }

    private function guardOwnPermissionChange(): void
    {
        $actor = Auth::user();

        if ($actor instanceof self && $actor->is($this)) {
            throw RoleEscalationException::cannotModifyOwnPermissions();
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
