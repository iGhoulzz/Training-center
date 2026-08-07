<?php

declare(strict_types=1);

/*
 * THE SAFETY BOUNDARY FOR THE SHARED TEST DATABASE.
 *
 * Every worktree of this repository runs against one MySQL database,
 * `training_center_test`, and every suite begins by rebuilding it. Two suites at
 * once means two `migrate:fresh` calls into one schema; the loser fails in a way
 * that reads as a real defect and has cost real time.
 *
 * The lock is taken HERE, in the test process, rather than in a wrapper script
 * that spawns it. A wrapper cannot hold this safely on Windows: lock ownership
 * belongs to the acquiring process, so killing the wrapper frees the lock while
 * the suite it started is still connected. Acquiring in-process makes the holder
 * and the database client the same thing, so the lock is released exactly when
 * that process ends — cleanly, on crash, or on a forced kill.
 *
 * Referenced from `phpunit.xml` so it covers every entry point: `php artisan
 * test`, `vendor/bin/pest`, `vendor/bin/phpunit`, a focused `--filter` run, and
 * the `composer test:serial` alias alike. `test:serial` is a convenience name,
 * not the boundary.
 */

use Tooling\SerialLock;

require __DIR__.'/../vendor/autoload.php';

/*
 * Parallel execution is refused, not silently serialised.
 *
 * Every worker would boot this file and queue behind the same lock, so a
 * `--parallel` run would take longer than a serial one while looking like it was
 * doing the opposite. Refusing is the honest answer: someone reading "parallel"
 * in their command should not get sequential execution with extra steps.
 */
$parallel = false;

foreach ($_SERVER['argv'] ?? [] as $argument) {
    if ($argument === '--parallel' || str_starts_with((string) $argument, '--parallel=')) {
        $parallel = true;

        break;
    }
}

if ($parallel || getenv('LARAVEL_PARALLEL_TESTING') !== false) {
    fwrite(STDERR, <<<'TXT'

    Parallel testing is not supported in this repository.

    All worktrees share one test database, and this bootstrap serialises access
    to it. Under --parallel every worker would simply queue behind that lock, so
    the run would be slower than a serial one while appearing to be faster.

    Run the suite without --parallel.

    TXT);

    exit(1);
}

SerialLock::acquireForProcess();
