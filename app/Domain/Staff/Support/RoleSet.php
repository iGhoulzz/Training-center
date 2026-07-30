<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * A set of roles resolved to persisted rows, identified by primary key.
 *
 * WHY THIS EXISTS (P1-T15, security finding 2).
 *
 * Role names arrive from the request as untrusted strings, and authorization
 * used to compare them in PHP with !==. The database does not agree with PHP
 * about what a role name is: the connection runs utf8mb4_unicode_ci, so
 * `where('name', 'Super_Admin')` matches the row named `super_admin`, which is
 * exactly how Spatie resolves a name before attaching it.
 *
 * The two comparisons therefore disagreed on the one question that matters.
 * `'Super_Admin' !== 'super_admin'` short-circuited UserPolicy::assignRole() to
 * "not the super-admin role, allow it", and syncRoles() then attached the
 * genuine super-admin row. An admin could make themselves a puppet super admin
 * with a capital letter.
 *
 * Identity is a row, not a spelling. Every submitted name is resolved here
 * once, against the same collation Spatie will use, and everything downstream —
 * the diff, the authorization, the write, the audit entry — works from the
 * resolved rows and their canonical names.
 *
 * This also fixes a quieter defect. The added/removed diff was computed by
 * array_diff over names, so submitting `['Admin']` for an account that already
 * held `admin` produced "removed admin, added Admin": an activity-log entry
 * describing a change that never happened, and a spurious trip through the
 * last-super-admin invariant. Diffing by key cannot express that.
 */
final class RoleSet
{
    /** @param  Collection<int|string, Role>  $roles  Keyed by primary key. */
    private function __construct(private readonly Collection $roles) {}

    /**
     * Resolve submitted role names to their persisted rows.
     *
     * Deduplication happens by KEY, not by string, so `['admin', 'ADMIN']` is
     * one role rather than two — the same collapse the database would perform,
     * made visible before anything is authorized.
     *
     * @param  array<int, string>  $names
     *
     * @throws RoleDoesNotExist when a submitted name matches no row. Spatie
     *                          would throw the same exception later; raising it
     *                          here means an unknown name never reaches a guard.
     */
    public static function resolve(array $names): self
    {
        $names = array_values(array_unique($names));

        if ($names === []) {
            /** @var Collection<int|string, Role> $empty */
            $empty = collect();

            return new self($empty);
        }

        $guard = (string) config('auth.defaults.guard');

        /** @var Collection<int|string, Role> $found */
        $found = Role::query()
            ->whereIn('name', $names)
            ->where('guard_name', $guard)
            ->get()
            ->keyBy(fn (Role $role): int|string => $role->getKey());

        foreach ($names as $name) {
            $matched = $found->contains(
                // Case-insensitive on purpose: this mirrors how the database
                // matched the name above, so the check reports "no such role"
                // only when the database also found nothing.
                fn (Role $role): bool => mb_strtolower((string) $role->name) === mb_strtolower($name),
            );

            if (! $matched) {
                // Both arguments are required in this version; named() has no
                // default for the guard.
                throw RoleDoesNotExist::named($name, $guard);
            }
        }

        return new self($found);
    }

    /**
     * The roles an account currently holds, read from the pivot.
     *
     * Read back through App\Models\Role rather than through the relation's own
     * result. Spatie's HasRoles::roles() is declared against its Role CONTRACT,
     * so the collection it returns is not statically known to hold this
     * application's model — and this class exists precisely to stop identity
     * being taken on trust. One extra keyed query is the cost of the guarantee.
     *
     * Names arriving from here are already canonical, because the pivot stores
     * the row and not a spelling of it.
     */
    public static function heldBy(User $user): self
    {
        $keyName = (new Role)->getKeyName();

        /** @var Collection<int|string, Role> $roles */
        $roles = Role::query()
            ->whereIn($keyName, $user->roles()->pluck($keyName)->all())
            ->get()
            ->keyBy(fn (Role $role): int|string => $role->getKey());

        return new self($roles);
    }

    /**
     * The canonical names, as stored. These are what syncRoles() and the
     * activity log receive — never the submitted spelling.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_values($this->roles->map(fn (Role $role): string => (string) $role->name)->all());
    }

    /** @return array<int, Role> */
    public function all(): array
    {
        return array_values($this->roles->all());
    }

    /** Roles in this set that are absent from $other, compared by key. */
    public function diff(self $other): self
    {
        return new self($this->roles->diffKeys($other->roles));
    }

    public function isEmpty(): bool
    {
        return $this->roles->isEmpty();
    }

    /** Does this set contain the super-admin row? By key, never by name. */
    public function containsSuperAdmin(): bool
    {
        return $this->roles->contains(fn (Role $role): bool => $role->isSuperAdmin());
    }
}
