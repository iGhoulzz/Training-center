<?php

declare(strict_types=1);

namespace Tooling;

use RuntimeException;

/**
 * One machine-wide lock around a test database.
 *
 * Two suites rebuilding ONE database means two `migrate:fresh` calls into one
 * schema, and the loser fails in a way that looks like a real defect. Which
 * database a run owns is `Tooling\TestDatabase`'s decision — the main checkout
 * keeps `training_center_test`, a linked worktree gets its own — and the caller
 * passes the lock path for it, so two worktrees no longer queue behind each other.
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
     * The path actually held, so callers can be told apart rather than assumed
     * to agree. See the refusal in acquireForProcess().
     */
    private static ?string $heldPath = null;

    /**
     * Take the lock for this process, once.
     *
     * Idempotent by design: `tests/bootstrap.php` may be included more than once
     * across PHPUnit and Pest entry points, and a second acquisition attempt must
     * be a no-op rather than a second wait.
     *
     * THE PATH IS REQUIRED, AND THAT IS THE POINT. It used to default to a key
     * derived from the checkout, which quietly decided what this class protects.
     * The database is the thing at risk, only the caller knows which one this run
     * resolved, and a default here would be a second answer to that question —
     * free to drift from the real one and impossible to see drifting.
     */
    public static function acquireForProcess(string $path): void
    {
        if (self::$handle !== null) {
            /*
             * IDEMPOTENT MEANS "THE SAME LOCK AGAIN", NOT "NEVER MIND".
             *
             * This used to return on any second call, so asking for a DIFFERENT
             * path silently proceeded holding the wrong lock — and the caller
             * that does that is SeedPerformanceDatasetCommand, immediately before
             * migrate:fresh. One process, two databases, one lock: exactly the
             * shape this class exists to refuse. Today the command and the
             * bootstrap agree, and that agreement is now enforced rather than
             * assumed.
             */
            if (self::$heldPath !== $path) {
                throw new RuntimeException(
                    'This process already holds the suite lock for '.self::$heldPath
                    .", so it cannot also take {$path}. Two databases in one process is not a "
                    .'configuration this lock can protect.'
                );
            }

            return;
        }

        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the suite lock at {$path}.");
        }

        self::takeExclusiveLock($handle, $path);

        self::$handle = $handle;
        self::$heldPath = $path;
    }

    /**
     * Which lock this process holds, if any.
     *
     * Exists so a test can assert the lock matches the database in use. The
     * obvious alternative — checking the lock FILE exists — proves nothing: the
     * file is created on first acquisition and never removed, so a stale one from
     * a previous run satisfies it while the process holds something else entirely.
     */
    public static function heldPath(): ?string
    {
        return self::$heldPath;
    }

    /**
     * Block until the lock is genuinely held, or throw.
     *
     * THE SECOND flock() RESULT IS CHECKED, AND THAT IS THE POINT OF THIS
     * METHOD EXISTING SEPARATELY.
     *
     * A failed non-blocking attempt means "someone else holds it" only when
     * locking works at all. flock() also returns false when the stream simply
     * cannot be locked — a filesystem without lock support, a handle that is
     * not lockable. Ignoring the blocking call's result treated that case as
     * contention: it printed "waiting", returned, and marked the lock HELD, so
     * two suites could rebuild the same schema while the guard reported
     * success. **A lock that fails open is worse than no lock, because it is
     * trusted.**
     *
     * Extracted so the failure path can be tested with a stream that cannot be
     * locked; otherwise it is unreachable from outside and stays unverified.
     *
     * @param  resource  $handle
     */
    public static function takeExclusiveLock($handle, string $path): void
    {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return;
        }

        // Announced once, on stderr, so it cannot be mistaken for test output
        // and cannot corrupt a machine-readable reporter on stdout. A gate that
        // blocks silently for four minutes reads as a hang.
        fwrite(STDERR, "Waiting for the shared test database (another suite is running)...\n");

        if (flock($handle, LOCK_EX)) {
            return;
        }

        fclose($handle);

        throw new RuntimeException(
            "Unable to lock the shared test database at {$path}. Refusing to run: without this "
            .'lock two suites can rebuild the same schema at once.'
        );
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
        self::$heldPath = null;
    }

    public static function pathFor(string $key): string
    {
        return rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/training-center-suite-'.$key.'.lock';
    }
}
