<?php

declare(strict_types=1);

namespace Tooling;

use RuntimeException;

/**
 * Locating the repository, and deriving a lock key that is stable across worktrees.
 */
final class Repo
{
    /**
     * The working tree root.
     *
     * Every entry point calls this and chdirs, because an agent may start in a
     * subdirectory and a hook may run from anywhere.
     */
    public static function root(): string
    {
        return self::normalizeSeparators(self::git(['rev-parse', '--show-toplevel']));
    }

    /**
     * A key identifying the repository as a whole, shared by every worktree of it.
     *
     * `--path-format=absolute` IS LOAD-BEARING. Measured on this repo:
     *
     *   main checkout   `git rev-parse --git-common-dir` -> `.git`
     *   linked worktree `git rev-parse --git-common-dir` -> C:/…/Training-center/.git
     *
     * Hashing that raw value gives every worktree its own lock, so the guard
     * would be satisfied while two suites ran into the same database — the exact
     * failure it exists to prevent. With `--path-format=absolute` the main
     * checkout and every linked worktree agree.
     */
    public static function lockKey(): string
    {
        return self::lockKeyFor(self::git(['rev-parse', '--path-format=absolute', '--git-common-dir']));
    }

    /**
     * Split out from lockKey() so it can be tested without a second worktree:
     * the canonicalisation is the part that has to be right.
     */
    public static function lockKeyFor(string $commonDir): string
    {
        return hash('sha256', self::canonicalize($commonDir));
    }

    /**
     * Reduce a path to one spelling.
     *
     * Windows reaches the same directory through `C:\` and `c:/`, through 8.3
     * short names, and through substituted drives. realpath() resolves what it
     * can; separator folding and a case fold on Windows handle the rest. Any
     * pair of spellings that survives this comparison unequal is two locks.
     */
    public static function canonicalize(string $path): string
    {
        $path = self::normalizeSeparators($path);

        $real = realpath($path);

        if ($real !== false) {
            $path = self::normalizeSeparators($real);
        }

        $path = rtrim($path, '/');

        // Windows and macOS are case-insensitive; Linux is not, and folding case
        // there would collide two genuinely different repositories.
        if (self::isWindows()) {
            $path = mb_strtolower($path);
        }

        return $path;
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    private static function normalizeSeparators(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    /**
     * @param  list<string>  $args
     */
    public static function git(array $args): string
    {
        $result = self::gitResult($args);

        if ($result['status'] !== 0) {
            throw new RuntimeException(sprintf(
                'git %s failed (%d): %s',
                implode(' ', $args),
                $result['status'],
                trim($result['stderr']),
            ));
        }

        return trim($result['stdout']);
    }

    /**
     * git, without treating a non-zero status as fatal.
     *
     * `git diff --check` reports whitespace through BOTH its exit code and its
     * output, and the exit code is ambiguous: `--no-index` returns 1 for "the
     * files differ" whether or not any whitespace is wrong. Callers that need to
     * tell those apart have to read the output, so they need the raw result.
     *
     * @param  list<string>  $args
     * @return array{status: int, stdout: string, stderr: string}
     */
    public static function gitResult(array $args): array
    {
        $process = proc_open(
            ['git', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to run git.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'status' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
