<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
use Spatie\Permission\Traits\HasRoles;

/**
 * The staff/user account model.
 *
 * DELIBERATELY THIN (P1-T04c)
 * ---------------------------
 * Task 4 and its first remediation defended super-admin escalation by
 * overriding every Spatie/Eloquent write method on this model. That produced a
 * fat model, an endless whack-a-mole of new bypass methods, and a direct
 * violation of `docs/ENGINEERING.md` ("No business logic in models").
 *
 * All actor-aware, security-sensitive writes now live in explicit domain
 * Actions under `app/Domain/Staff/Actions/`, which authorize via
 * `Gate::forUser($actor)` and delegate population-shrinking mutations to
 * `App\Domain\Staff\Services\SuperAdminInvariantService`. This model holds
 * configuration only: traits, casts, relationships, scopes, and the single
 * fresh super-admin identity query the policies read.
 *
 * The one identity helper that remains — isSuperAdmin() — is a read, not a
 * write, and is the sole permitted role check (see UserPolicy and
 * docs/ENGINEERING.md for why rank cannot be reduced to a permission).
 */
#[Fillable(['name', 'email', 'password', 'locale', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

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
     *   - By key, not name: a check that compared the loaded role's name could
     *     be desynced from the id Spatie actually writes by mutating an unsaved
     *     name in memory. The key cannot be spoofed that way.
     *   - Fresh, not the loaded relation: reading a stale in-memory roles
     *     relation let the last super admin be deleted when that relation
     *     happened to be empty. Querying the pivot directly ignores any stale
     *     copy on the instance.
     *
     * This is the only role check the codebase permits, and it is a read. Every
     * actor-aware decision that depends on it lives in a Policy or an Action.
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
}
