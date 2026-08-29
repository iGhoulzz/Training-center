<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Support\RecordsActivity;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    use HasFactory, HasRoles, Notifiable, RecordsActivity, SoftDeletes;

    /**
     * Pin Spatie's permission lookups to the `web` guard (P3-T01).
     *
     * NOT DECORATION. The portal panel authenticates on the `student` guard, and
     * Filament's Authenticate middleware calls Auth::shouldUse() to select it —
     * which delegates to AuthManager::setDefaultDriver() and WRITES
     * config('auth.defaults.guard') (AuthManager.php:206-224).
     *
     * Spatie's Guard::getDefaultName() reads that config value and returns it
     * whenever it is among the guards whose provider matches this model. Both
     * `web` and `student` use the `users` provider, so `student` qualifies —
     * and every permission lookup inside a portal request would resolve against
     * guard_name = 'student', for which no rows exist. Every check would return
     * false, silently, reading like a permissions bug rather than a guard bug.
     *
     * This property short-circuits Guard::getNames() before it consults config
     * at all. Authentication on `student`, authorization on `web`.
     *
     * Pinned by GuardResolutionTest, which drives real panel requests — reading
     * config/auth.php back proves nothing about what Filament does to it
     * mid-request, which is the entire defect.
     */
    protected string $guard_name = 'web';

    /**
     * Panel access is permission-based, never role-based — and panel-aware.
     *
     * FAILS CLOSED. Until P3-T01 this ignored $panel entirely and answered
     * access_admin_panel for every panel, which would have admitted every staff
     * account to /portal the moment that panel existed. `default => false` means
     * a panel added later is refused until somebody decides otherwise, rather
     * than inheriting whichever branch happened to be written last.
     *
     * The portal additionally requires a linked, live student record. An account
     * holding the role with no record behind it has nothing a portal page could
     * show, so it is refused at the gate rather than left for
     * AuthenticatedStudent to throw on. Student uses SoftDeletes, so the
     * relationship below excludes a trashed record without saying so.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->can('access_admin_panel'),
            'student' => $this->can('access_student_portal') && $this->student()->exists(),
            default => false,
        };
    }

    /**
     * The student record this account belongs to, if it is a portal account.
     *
     * hasOne rather than hasMany because students.user_id carries a unique
     * index: one account, at most one student. Most accounts are staff and have
     * none, and most students have no account — the link is the exception in
     * both directions, which is why the column is nullable.
     *
     * @return HasOne<Student, $this>
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * The employment record for this account, if it has one.
     *
     * Optional by design: a super admin may have no employment record, and the
     * account and the job are separate things. Deleting the account cascades
     * the profile away at the database level.
     *
     * @return HasOne<StaffProfile, $this>
     */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
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
     * The account columns worth an audit diff.
     *
     * password and remember_token are absent by design and must stay absent: a
     * hash in an audit trail is a credential in a table many people can read.
     * A password change is recorded as its own semantic event instead — see
     * ResetUserPasswordAction — because excluding the column alone would leave
     * an empty diff that is suppressed, making the security event invisible.
     *
     * must_change_password IS listed here AND removed from the global exclusion
     * list, so it genuinely reaches the diff. It is a flag, not a secret, and
     * "who forced this account to rotate its password" is an audit question —
     * but listing it while the global list stripped it made this docblock claim
     * something the code did not do, which is worse than not auditing it.
     *
     * last_login_at is absent deliberately: it moves on every sign-in and would
     * bury real changes under one "user updated" per login. Logins are recorded
     * as auth events, which is where they belong.
     */
    public function auditedAttributes(): array
    {
        return [
            'name',
            'email',
            'locale',
            'is_active',
            'must_change_password',
        ];
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
