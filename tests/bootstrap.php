<?php

declare(strict_types=1);

/*
 * THE SAFETY BOUNDARY FOR THE TEST DATABASE, AND WHICH DATABASE THAT IS.
 *
 * Every suite begins by rebuilding its database. Two suites rebuilding ONE
 * database means two `migrate:fresh` calls into one schema; the loser fails in a
 * way that reads as a real defect and has cost real time.
 *
 * The boundary is therefore per database, not per machine: every checkout — the
 * main one included — resolves `training_center_test_<hash of its path>`, and the
 * lock is keyed on whichever database this run resolved. Two checkouts run at the
 * same time; two runs against one database still take turns. `Tooling\TestDatabase`
 * holds the naming rule, and why the main checkout is generated too.
 *
 * The lock is taken HERE, in the test process, rather than in a wrapper script
 * that spawns it. A wrapper cannot hold this safely on Windows: lock ownership
 * belongs to the acquiring process, so killing the wrapper frees the lock while
 * the suite it started is still connected. Acquiring in-process makes the holder
 * and the database client the same thing, so the lock is released exactly when
 * that process ends — cleanly, on crash, or on a forced kill.
 *
 * PARALLEL RUNS (`--parallel`) AND WHICH DATABASE EACH PROCESS LOCKS.
 *
 * Paratest boots this file once in the PARENT process and once in each WORKER.
 * The parent loads it first from Tests\CreatesApplication, before Laravel's
 * setup-process callbacks, which can drop worker databases; see that trait.
 * The parent is an ordinary run: it resolves and locks the run's database, which
 * is what makes a second run of the same checkout — serial or parallel — wait
 * until the first has finished, before any worker exists. Each worker then
 * resolves a database of its own, `{run database}_test_{TEST_TOKEN}`, and locks
 * THAT one. Workers of one run therefore never block each other, and a stray
 * process that reaches a worker's database still has to queue behind its holder.
 *
 * The parent holding the run's lock cannot block its own workers: they lock
 * different files.
 *
 * Referenced from `phpunit.xml` so it covers every entry point: `php artisan
 * test`, `vendor/bin/pest`, `vendor/bin/phpunit`, a focused `--filter` run, and
 * the `composer test:serial` alias alike. `test:serial` is a convenience name,
 * not the boundary.
 */

use Tooling\Repo;
use Tooling\SerialLock;
use Tooling\TestDatabase;

/*
 * Once per process. The parent of a parallel run requires this file from
 * Tests\CreatesApplication, before Laravel's setup-process callbacks run, and
 * ParaTest then loads it as PHPUnit's bootstrap. ParaTest uses `include_once`,
 * so the same path is already skipped. This guard covers a path spelled
 * differently, such as a `--bootstrap` argument. Without it, a second pass
 * would try to take a lock this process already holds, which SerialLock was
 * never written to support.
 */
if (defined('TRAINING_CENTER_TEST_BOOTSTRAPPED')) {
    return;
}

define('TRAINING_CENTER_TEST_BOOTSTRAPPED', true);

require __DIR__.'/../vendor/autoload.php';

/*
 * Resolve the database BEFORE the lock, because the lock is keyed on it, and
 * before Laravel boots, because the framework reads the environment once.
 *
 * CI and the Linux target select their database through a real environment
 * variable and keep it; every other checkout gets a generated name. So `putenv`
 * below runs exactly when a name was generated — never over a deliberate
 * selection.
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
 * `php artisan config:cache` writes the file, and a Laravel boot that finds it
 * never calls env() again — so the connection details are whatever was cached,
 * while the name resolved below is whatever the environment says now. The suite
 * would then migrate one database while holding the lock for another, and the
 * database it migrated could be `training_center` itself.
 *
 * The path is asked of TestDatabase rather than assumed, because `APP_CONFIG_CACHE`
 * moves it: checking only `bootstrap/cache/config.php` would leave that
 * configuration unguarded while looking guarded.
 *
 * And the override is resolved the way Laravel resolves it — process environment
 * first, then the env FILE Laravel will load — because a value living in
 * `.env.testing` is invisible to `getenv()` at this point, and would be honoured
 * by Laravel a moment later.
 *
 * `composer verify` clears it first, which is why this has never bitten; a direct
 * `php artisan test` or `vendor/bin/pest` does not.
 */
$cachedConfiguration = TestDatabase::cachedConfigPath(
    __DIR__.'/..',
    TestDatabase::environmentValue(__DIR__.'/..', 'APP_CONFIG_CACHE'),
);

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

/*
 * Is this process a parallel worker?
 *
 * Judged by the script that was started as well as the environment, because the
 * environment is inherited: a child a test spawns carries `PARATEST` and
 * `TEST_TOKEN` too, and must not be mistaken for a worker. See
 * TestDatabase::isWorkerEntryPoint().
 */
$isWorker = getenv('PARATEST') === '1'
    && TestDatabase::isWorkerEntryPoint((string) ($_SERVER['argv'][0] ?? ''));

if ($isWorker) {
    $token = getenv('TEST_TOKEN');

    if (! is_string($token) || $token === '') {
        fwrite(STDERR, "A parallel worker has no TEST_TOKEN, so it has no database of its own.\nDo not pass --no-test-tokens.\n");

        exit(1);
    }

    $database = TestDatabase::workerDatabase($database, $token);

    /*
     * Laravel's own parallel-testing database handling is switched off, because
     * this file has already given the worker its database. Left on, it would
     * derive `{this name}_test_{token}` and switch to it for every test that uses
     * a database trait — a second database, created beside this one, that the lock
     * does not cover — while a test without a trait stayed on this one.
     *
     * With it off, every test in the worker uses the one database that is locked.
     * Laravel's cache-prefix and compiled-view isolation stay on.
     */
    $_SERVER['LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = '1';
    $_ENV['LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = '1';
    putenv('LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES=1');
}

if ($database !== $configured) {
    putenv("DB_DATABASE={$database}");
    $_ENV['DB_DATABASE'] = $database;
    $_SERVER['DB_DATABASE'] = $database;

    TestDatabase::ensureExists($database, TestDatabase::connectionFromEnvironment(__DIR__.'/..'));

    // On stderr, not stdout: a machine-readable reporter must not be disturbed,
    // and a run must always be able to say which database it just rebuilt.
    // A worker stays quiet: there is one per process, and a worker's output is
    // collected by the parent as unexpected output.
    if (! $isWorker) {
        fwrite(STDERR, "Test database for this checkout: {$database}\n");
    }
}

SerialLock::acquireForProcess(SerialLock::pathFor(Repo::lockKeyForDatabase($database)));
