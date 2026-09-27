<?php

declare(strict_types=1);

/*
 * THE SAFETY BOUNDARY FOR THE TEST DATABASE, AND WHICH DATABASE THAT IS.
 *
 * Every suite begins by rebuilding its database. Two suites rebuilding ONE
 * database means two `migrate:fresh` calls into one schema; the loser fails in a
 * way that reads as a real defect and has cost real time.
 *
 * The boundary is therefore per database, not per machine: the main checkout
 * keeps `training_center_test`, a linked worktree gets a name of its own, and the
 * lock is keyed on whichever database this run resolved. Two worktrees run at the
 * same time; two runs against one database still take turns. `Tooling\TestDatabase`
 * holds the naming rule and the measurement that decided it.
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

use Tooling\Repo;
use Tooling\SerialLock;
use Tooling\TestDatabase;

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

/*
 * Resolve the database BEFORE the lock, because the lock is keyed on it, and
 * before Laravel boots, because the framework reads the environment once.
 *
 * Only a linked worktree changes anything here. The main checkout, CI and the
 * Linux target all keep the name they already had, so `putenv` below runs exactly
 * when a name was generated — never over a deliberate selection.
 */
$configured = getenv('DB_DATABASE');
$configured = is_string($configured) ? $configured : '';

$database = TestDatabase::resolve(
    $configured,
    TestDatabase::isLinkedWorktree(),
    TestDatabase::worktreeKey(),
);

if ($database !== $configured) {
    putenv("DB_DATABASE={$database}");
    $_ENV['DB_DATABASE'] = $database;
    $_SERVER['DB_DATABASE'] = $database;

    TestDatabase::ensureExists($database, TestDatabase::connectionFromEnvironment(__DIR__.'/..'));

    // On stderr, and only when the name is not the documented one: a run against
    // an unexpected database must say so, and a machine-readable reporter on
    // stdout must not be disturbed by it.
    fwrite(STDERR, "Test database for this worktree: {$database}\n");
}

SerialLock::acquireForProcess(SerialLock::pathFor(Repo::lockKeyForDatabase($database)));
