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
     * The database `phpunit.xml` pins, and the one the main checkout keeps.
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
     */
    public static function resolve(string $configured, bool $isLinkedWorktree, string $worktreeKey): string
    {
        // A value other than the shared literal was chosen on purpose, by CI, by
        // the Linux target, or by an operator naming a load database. Not ours to
        // rewrite.
        if ($configured !== self::SHARED) {
            return $configured;
        }

        // The main checkout keeps the documented name. Everything written about
        // this project's suite — the spec, the README, the runbook — names it,
        // and there is no second suite inside one checkout to conflict with.
        if (! $isLinkedWorktree) {
            return $configured;
        }

        return self::SHARED.'_'.substr($worktreeKey, 0, self::SUFFIX_LENGTH);
    }

    /**
     * Is this a linked worktree rather than the main checkout?
     *
     * `--git-dir` and `--git-common-dir` are the same directory in the main
     * checkout and differ in a linked worktree, which is exactly the distinction
     * the naming rule needs. Both are canonicalised, because Windows spells one
     * directory several ways.
     */
    public static function isLinkedWorktree(): bool
    {
        $gitDir = Repo::canonicalize(Repo::git(['rev-parse', '--path-format=absolute', '--git-dir']));
        $commonDir = Repo::canonicalize(Repo::git(['rev-parse', '--path-format=absolute', '--git-common-dir']));

        return $gitDir !== $commonDir;
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
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new RuntimeException("Refusing to use [{$database}] as a database name.");
        }
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
    public static function connectionFromEnvironment(string $root): array
    {
        $values = Dotenv::createArrayBacked($root)->safeLoad();

        $read = static function (string $key, string $default) use ($values): string {
            $fromProcess = getenv($key);

            if (is_string($fromProcess) && $fromProcess !== '') {
                return $fromProcess;
            }

            $fromFile = $values[$key] ?? null;

            return is_string($fromFile) && $fromFile !== '' ? $fromFile : $default;
        };

        return [
            'host' => $read('DB_HOST', '127.0.0.1'),
            'port' => $read('DB_PORT', '3306'),
            'username' => $read('DB_USERNAME', 'root'),
            'password' => $read('DB_PASSWORD', ''),
        ];
    }
}
