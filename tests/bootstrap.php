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

    Every worker of one run would resolve the same database and queue behind the
    same lock, so a --parallel run would be slower than a serial one while
    appearing to be faster. Separate CHECKOUTS do run in parallel, because each
    resolves a database of its own.

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

/*
 * Two configurations would let this process create and lock one database while
 * Laravel connected to another, and both are caught here rather than three
 * hundred tests later.
 *
 * An absent DB_DATABASE means an entry point that did not read phpunit.xml. The
 * resolver would hand back an empty name, the lock would be keyed on nothing, and
 * Laravel would fall back to config/database.php's `laravel` default.
 *
 * A DB_URL outranks DB_DATABASE inside Laravel's MySQL connection, so the suite
 * would rebuild whatever that URL names while the lock protected the name below.
 */
if ($configured === '') {
    fwrite(STDERR, "DB_DATABASE is not set, so this run has no test database to lock.\nRun the suite through phpunit.xml, or set DB_DATABASE explicitly.\n");

    exit(1);
}

/*
 * A cached configuration outranks everything decided here.
 *
 * `php artisan config:cache` writes bootstrap/cache/config.php, and a Laravel
 * boot that finds it never calls env() again — so the connection details are
 * whatever was cached, while the name resolved below is whatever the environment
 * says now. The suite would then migrate one database while holding the lock for
 * another, and the database it migrated could be `training_center` itself.
 *
 * `composer verify` clears it first, which is why this has never bitten; a direct
 * `php artisan test` or `vendor/bin/pest` does not.
 */
$cachedConfiguration = __DIR__.'/../bootstrap/cache/config.php';

if (file_exists($cachedConfiguration)) {
    fwrite(STDERR, <<<TXT

    A cached configuration is present, and it outranks the test environment.

    {$cachedConfiguration}

    The suite would connect using the cached credentials while locking the
    database named by the environment — possibly rebuilding a database nothing
    here protects.

    Run `php artisan config:clear` and try again.

    TXT);

    exit(1);
}

$url = getenv('DB_URL');

if (is_string($url) && $url !== '') {
    fwrite(STDERR, "DB_URL is set, and it outranks DB_DATABASE.\nThe suite would rebuild the database in that URL while the lock protected another.\nUnset DB_URL for test runs.\n");

    exit(1);
}

$database = TestDatabase::resolve($configured, TestDatabase::worktreeKey());

if ($database !== $configured) {
    putenv("DB_DATABASE={$database}");
    $_ENV['DB_DATABASE'] = $database;
    $_SERVER['DB_DATABASE'] = $database;

    TestDatabase::ensureExists($database, TestDatabase::connectionFromEnvironment(__DIR__.'/..'));

    // On stderr, not stdout: a machine-readable reporter must not be disturbed,
    // and a run must always be able to say which database it just rebuilt.
    fwrite(STDERR, "Test database for this checkout: {$database}\n");
}

SerialLock::acquireForProcess(SerialLock::pathFor(Repo::lockKeyForDatabase($database)));
