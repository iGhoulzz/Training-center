<?php

declare(strict_types=1);

namespace Tooling;

use RuntimeException;

/**
 * One machine-wide lock around the shared test database.
 *
 * Every worktree of this repository runs its suite against `training_center_test`.
 * Two suites at once means two `migrate:fresh` calls into one schema, and the
 * loser fails in a way that looks like a real defect.
 *
 * THE LOCK IS TAKEN BY THE TEST PROCESS ITSELF, from `tests/bootstrap.php`.
 *
 * The obvious design — a wrapper script that locks, then spawns the suite — was
 * built and then discarded, because it does not hold on Windows. Measured here:
 * the child does receive the inherited descriptor (it opens, fstats and flocks
 * fine), but Windows ties lock OWNERSHIP to the acquiring process, so killing
 * the wrapper released the lock while the test child was still running and still
 * connected to the database. That is the failure the lock exists to prevent, and
 * it fails silently in the free direction. The same code works on POSIX, which
 * is what made it a trap: green on Linux CI, broken on the development machine.
 *
 * Acquiring inside the test process removes the failure mode instead of guarding
 * it. The holder is the thing that touches the database, so the lock is released
 * exactly when that process ends — cleanly, on crash, or on a forced kill — with
 * no stale state and nothing to reap.
 */
final class SerialLock
{
    /**
     * Held for the lifetime of the process. The static reference is what keeps
     * the handle from being garbage-collected and the lock from being dropped
     * mid-suite; releasing is the operating system's job at exit.
     *
     * @var resource|null
     */
    private static $handle = null;

    /**
     * Take the lock for this process, once.
     *
     * Idempotent by design: `tests/bootstrap.php` may be included more than once
     * across PHPUnit and Pest entry points, and a second acquisition attempt must
     * be a no-op rather than a second wait.
     */
    public static function acquireForProcess(?string $path = null): void
    {
        if (self::$handle !== null) {
            return;
        }

        $path ??= self::pathFor(Repo::lockKey());

        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the suite lock at {$path}.");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            // Announced once, on stderr, so it cannot be mistaken for test output
            // and cannot corrupt a machine-readable reporter on stdout. A gate
            // that blocks silently for four minutes reads as a hang.
            fwrite(STDERR, "Waiting for the shared test database (another suite is running)...\n");
            flock($handle, LOCK_EX);
        }

        self::$handle = $handle;
    }

    public static function isHeld(): bool
    {
        return self::$handle !== null;
    }

    /**
     * Testing seam. Production code never releases — the process exit does.
     */
    public static function releaseForTesting(): void
    {
        if (self::$handle === null) {
            return;
        }

        flock(self::$handle, LOCK_UN);
        fclose(self::$handle);
        self::$handle = null;
    }

    public static function pathFor(string $key): string
    {
        return rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/training-center-suite-'.$key.'.lock';
    }
}
