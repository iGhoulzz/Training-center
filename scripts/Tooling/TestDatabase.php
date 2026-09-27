<?php

declare(strict_types=1);

namespace Tooling;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Which database a suite run owns, so two worktrees stop queueing behind each other.
 *
 * WHAT CHANGED, AND WHAT DID NOT.
 *
 * The lock in SerialLock exists because every worktree ran `migrate:fresh`
 * against one database. That is still the only thing that makes two runs unsafe
 * together — so instead of weakening the lock, this class removes the sharing:
 * a linked worktree gets its own database, and the lock is keyed on the database
 * rather than on the checkout. Same database, still serialised. Different
 * databases, genuinely parallel.
 *
 * WHERE THE NAME COMES FROM, AND WHY THE ORDER MATTERS.
 *
 * `phpunit.xml` pins `DB_DATABASE` to the shared name, and **PHPUnit applies
 * `<env>` before it loads the bootstrap** — measured, not assumed, with a probe
 * whose only job was to report what the bootstrap could see. So by the time this
 * class runs, `getenv('DB_DATABASE')` is already set, and it is indistinguishable
 * from a real environment variable that PHPUnit declined to override.
 *
 * That is why the rule is written around the shared literal rather than around
 * emptiness: any other value is a deliberate selection — CI's
 * `training_center_ci`, the Linux target's `training_center_linux`, a load
 * database — and is left exactly alone.
 */
final class TestDatabase
{
    /**
     * The database `phpunit.xml` pins, and the stem every generated name is built
     * from. No checkout connects to this name itself; see resolve().
     *
     * Pinned against the real file by tests/Unit/Tooling/TestDatabaseTest.php
     * rather than trusted: a copy that drifts from phpunit.xml would send every
     * checkout to a per-worktree database and look like it was working.
     */
    public const SHARED = 'training_center_test';

    /**
     * MySQL identifiers are 64 characters. The suffix keeps the generated name
     * far inside that, and the length is asserted rather than eyeballed.
     */
    private const SUFFIX_LENGTH = 8;

    /**
     * Decide the database for this run.
     *
     * Pure, and injected with everything it depends on, so the decision can be
     * tested without a second worktree, a second machine, or a database.
     *
     * EVERY CHECKOUT GETS A GENERATED NAME, INCLUDING THE MAIN ONE, AND THAT IS
     * A DELIBERATE REVERSAL.
     *
     * The first version kept `training_center_test` in the main checkout because
     * every document names it. Review found what that costs: while a worktree
     * somewhere still runs the OLD code, it locks a key derived from the git
     * directory, and a main-checkout run on the new code locks a key derived from
     * the name — two different locks against one schema, silent, and in the free
     * direction. Measured on this machine: `bb3c1d0c…` against `8f576955…`.
     *
     * Generating everywhere removes the overlap instead of documenting it. No run
     * on this code ever connects to the bare shared name, so an un-rebased
     * worktree can only collide with another un-rebased worktree — on the old key,
     * which still serialises them correctly.
     */
    public static function resolve(string $configured, string $worktreeKey): string
    {
        // A value other than the shared literal was chosen on purpose, by CI, by
        // the Linux target, or by an operator naming a load database. Not ours to
        // rewrite.
        if ($configured !== self::SHARED) {
            return $configured;
        }

        return self::SHARED.'_'.substr($worktreeKey, 0, self::SUFFIX_LENGTH);
    }

    /**
     * A stable key for this worktree: the same run after run, different per worktree.
     */
    public static function worktreeKey(): string
    {
        return hash('sha256', Repo::canonicalize(Repo::root()));
    }

    /**
     * Create the database if it is missing, or fail loudly saying how to fix it.
     *
     * `migrate:fresh` does not create a schema, so a generated name would
     * otherwise fail deep inside the first test with a connection error. This
     * runs only for a name this class generated: CI and the Linux target provision
     * their own, and nothing here touches them.
     *
     * @param  array{host: string, port: string, username: string, password: string}  $connection
     */
    public static function ensureExists(string $database, array $connection): void
    {
        self::assertSafeIdentifier($database);

        $dsn = sprintf('mysql:host=%s;port=%s', $connection['host'], $connection['port']);

        try {
            $pdo = new PDO($dsn, $connection['username'], $connection['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $database,
            ));
        } catch (PDOException $exception) {
            throw new RuntimeException(
                "Unable to create the test database [{$database}] on {$connection['host']}:{$connection['port']}.\n"
                ."This worktree needs its own database so its suite can run beside another worktree's.\n"
                ."\n"
                ."Grant the suite user the right to create them, once, as a MySQL administrator.\n"
                ."The backslashes are required: MySQL treats a bare underscore as a wildcard, and\n"
                ."without them this grant would also cover training_center itself.\n"
                ."\n"
                ."  GRANT ALL PRIVILEGES ON `training\\_center\\_test\\_%`.*\n"
                ."    TO '{$connection['username']}'@'{$connection['host']}';\n"
                ."  FLUSH PRIVILEGES;\n"
                ."\n"
                ."Or create this one database by hand and re-run:\n"
                ."  CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
                ."\n"
                .'Original error: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * The name is interpolated into SQL, which no amount of care elsewhere makes
     * safe on its own. Generated names cannot contain anything else, so a failure
     * here means the generator changed, not that a caller was creative.
     */
    public static function assertSafeIdentifier(string $database): void
    {
        // \z, not $: PCRE's $ also matches before a trailing newline, so "name\n"
        // would pass this and reach CREATE DATABASE `name\n`. Nothing here can
        // introduce a backtick, but this method is what makes the interpolation
        // defensible, and "mostly anchored" is not a defence.
        if (preg_match('/^[A-Za-z0-9_]{1,64}\z/', $database) !== 1) {
            throw new RuntimeException("Refusing to use [{$database}] as a database name.");
        }
    }

    /**
     * The config cache file Laravel will actually read, override included.
     *
     * A cached configuration outranks everything the bootstrap decides: a boot
     * that finds one never calls env() again, so the suite would connect with
     * cached credentials while locking the name the environment resolved. Checking
     * only `bootstrap/cache/config.php` misses the case where `APP_CONFIG_CACHE`
     * moves it somewhere else, which is a supported Laravel configuration.
     *
     * MIRRORS Illuminate\Foundation\Application::normalizeCachePath(), including
     * its quirk: only a leading `/` or `\` counts as absolute, so `C:\…` is
     * treated as relative to the project root. Reproducing the quirk is the point
     * — this must name the file Laravel will read, not the file it ought to.
     */
    public static function cachedConfigPath(string $root, string|false|null $override): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (! is_string($override) || $override === '') {
            return $root.'/bootstrap/cache/config.php';
        }

        if (str_starts_with($override, '/') || str_starts_with($override, '\\')) {
            return $override;
        }

        return $root.'/'.$override;
    }

    /**
     * The connection details the creation above needs, read from `.env`.
     *
     * `phpunit.xml` pins the database and the driver, not the host or the
     * credentials: those reach the application through `.env`, which Laravel has
     * not loaded yet when the bootstrap runs. Parsed array-backed, deliberately —
     * it answers one question and puts nothing into the environment, so it cannot
     * change what the suite under test sees.
     *
     * @return array{host: string, port: string, username: string, password: string}
     */
    /**
     * The environment file Laravel will load.
     *
     * It reads `.env.{APP_ENV}` when that file exists and `.env` otherwise, so
     * anything decided from `.env` alone can be decided from the wrong file.
     */
    public static function environmentFile(string $root): string
    {
        $environment = getenv('APP_ENV');

        if (is_string($environment) && $environment !== '' && file_exists($root.'/.env.'.$environment)) {
            return '.env.'.$environment;
        }

        return '.env';
    }

    /**
     * One environment value, resolved the way Laravel resolves it.
     *
     * A real environment variable wins, because Laravel's repository is immutable
     * and will not overwrite one. Otherwise the value comes from the file above.
     *
     * READING ONLY getenv() IS WHAT MAKES A GUARD LOOK CLOSED WHILE IT IS OPEN:
     * a key set in `.env.testing` is invisible to this process until Laravel
     * loads that file, which happens after the bootstrap has already decided.
     * APP_CONFIG_CACHE is exactly such a key, and a cached configuration
     * outranks every decision made here.
     *
     * Parsed array-backed, deliberately: it answers a question and puts nothing
     * into the environment, so it cannot change what the suite under test sees.
     */
    public static function environmentValue(string $root, string $key): ?string
    {
        $fromProcess = getenv($key);

        if (is_string($fromProcess) && $fromProcess !== '') {
            return $fromProcess;
        }

        $values = Dotenv::createArrayBacked($root, self::environmentFile($root))->safeLoad();
        $fromFile = $values[$key] ?? null;

        return is_string($fromFile) && $fromFile !== '' ? $fromFile : null;
    }

    /**
     * The connection details the creation above needs.
     *
     * Read from the same file Laravel will read: creating a database on one
     * server while the suite connects to another is a green creation followed by
     * tests against something else entirely, which is why
     * TestDatabaseConnectionTest asks the server `select database()` rather than
     * trusting configuration.
     *
     * @return array{host: string, port: string, username: string, password: string}
     */
    public static function connectionFromEnvironment(string $root): array
    {
        $read = static fn (string $key, string $default): string => self::environmentValue($root, $key) ?? $default;

        return [
            'host' => $read('DB_HOST', '127.0.0.1'),
            'port' => $read('DB_PORT', '3306'),
            'username' => $read('DB_USERNAME', 'root'),
            'password' => $read('DB_PASSWORD', ''),
        ];
    }
}
