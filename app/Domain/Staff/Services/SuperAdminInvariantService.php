<?php

declare(strict_types=1);

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Guards the one invariant that no authorization check can express and no
 * surface may violate: at least one active super admin must always exist.
 *
 * Losing the last super admin — by deleting them, deactivating them, or
 * stripping their super_admin role — leaves the system with nobody able to
 * manage roles, and that state is unrecoverable through the application. Unlike
 * the escalation guards (which are authorization questions answered by
 * policies), this is a business invariant that holds regardless of who the
 * actor is.
 *
 * Every population-reducing mutation runs through protect(): the mutation and
 * the survivor check execute inside ONE transaction that first takes a
 * pessimistic lock on the super_admin role row. The lock serializes concurrent
 * reducers so they cannot both pass an unlocked count; the shared transaction
 * makes the check-and-write atomic, so a violation rolls the mutation back and
 * leaves the database exactly as it was.
 *
 * DECLARED TRUST BOUNDARY: this protects the application write path (Actions).
 * It does NOT — and cannot, at the ORM layer — protect raw SQL, query-builder
 * bulk writes, or manual tinker sessions. Those are trusted administrative
 * operations. True database-wide enforcement would require MySQL triggers,
 * which are out of scope. See docs/ENGINEERING.md.
 */
final class SuperAdminInvariantService
{
    /**
     * Run a population-reducing mutation under the super-admin invariant.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $mutation
     * @return TReturn
     *
     * @throws LastSuperAdminException if no active super admin would remain.
     */
    public function protect(Closure $mutation): mixed
    {
        return DB::transaction(function () use ($mutation) {
            // Serialize against any other reducer before we read the count.
            Role::lockSuperAdminRow();

            $result = $mutation();

            $this->assertActiveSuperAdminRemains();

            return $result;
        });
    }

    /**
     * At least one active, non-deleted super admin must survive the mutation.
     *
     * Read AFTER the mutation, inside the locked transaction, so a violation
     * rolls the whole thing back. Soft-deleted accounts are excluded by the
     * SoftDeletes global scope, and deactivated ones by the is_active filter —
     * neither can log in to manage roles, so neither counts as a survivor.
     */
    private function assertActiveSuperAdminRemains(): void
    {
        $survivors = User::query()
            ->role(Role::SUPER_ADMIN)
            ->where('is_active', true)
            ->count();

        if ($survivors === 0) {
            throw new LastSuperAdminException;
        }
    }
}
