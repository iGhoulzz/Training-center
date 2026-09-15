<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The shared refusal boundary for tooling that rebuilds a performance database.
 *
 * A disposable name is not enough protection for a command that runs
 * migrate:fresh and writes thousands of append-only finance facts. Every caller
 * must pass all three independent checks here: never production, an exact
 * operator confirmation of the connected database, and a literal allowlist.
 * T11's performance-session tooling reuses this class rather than recreating a
 * weaker version of any check.
 */
final class PerformanceDatabaseGuard
{
    /**
     * @throws RuntimeException when the connected database is unsafe to rebuild.
     */
    public function assertSafe(string $confirmedDatabase): void
    {
        /*
         * First and unconditional. Do not even open a connection before this
         * refusal: a production process is never a valid performance loader,
         * irrespective of its database name or the operator's confirmation.
         */
        if (app()->isProduction()) {
            throw new RuntimeException('Performance dataset tooling is disabled in production.');
        }

        $connectedDatabase = DB::connection()->getDatabaseName();

        if ($confirmedDatabase !== $connectedDatabase) {
            throw new RuntimeException(
                "Confirmed database [{$confirmedDatabase}] does not exactly match connected database [{$connectedDatabase}].",
            );
        }

        $allowedDatabases = config('performance.allowed_databases');

        if (! is_array($allowedDatabases) || ! in_array($connectedDatabase, $allowedDatabases, true)) {
            throw new RuntimeException(
                "Connected database [{$connectedDatabase}] is not allowlisted for performance tooling.",
            );
        }
    }
}
