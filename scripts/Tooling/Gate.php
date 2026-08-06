<?php

declare(strict_types=1);

namespace Tooling;

/**
 * The single definition of what the fast gate runs.
 *
 * Claude's Stop hook, Codex's Stop hook, the pre-commit hook, the pre-push hook,
 * `composer verify:fast` and CI all reach this list. Nothing restates it.
 * A duplicated command list is how two agents end up held to different
 * standards, and how CI ends up green on a rule the developer machine dropped.
 */
final class Gate
{
    /**
     * THE FULL TEST SUITE IS DELIBERATELY ABSENT.
     *
     * These run after every agent turn, so they must stay in the seconds range.
     * The suite takes about five minutes and serialises against every other
     * worktree; running it here would make each turn unusable. It belongs to
     * pre-push and CI, which is where it is.
     *
     * @return list<array{name: string, command: list<string>}>
     */
    public static function fastChecks(): array
    {
        return [
            [
                'name' => 'pint',
                // --test, never a rewrite: a gate that edits your files while
                // checking them cannot be run from a commit hook without
                // changing what you are committing.
                'command' => [PHP_BINARY, 'vendor/bin/pint', '--test'],
            ],
            [
                'name' => 'phpstan',
                // --memory-limit=1G is required; PHPStan OOMs on this codebase
                // at PHP's default and reports it as a crash, not a limit.
                'command' => [PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--memory-limit=1G', '--no-progress'],
            ],
        ];
    }

    /**
     * Run the fast gate, streaming output straight through.
     *
     * @return int the first non-zero status, or 0
     */
    public static function runFast(): int
    {
        foreach (self::fastChecks() as $check) {
            $status = Process::stream($check['command'], Repo::root());

            if ($status !== 0) {
                return $status;
            }
        }

        return 0;
    }

    /**
     * Run the fast gate, capturing output instead of streaming it.
     *
     * The Stop hooks need the real failing output as a string so they can hand
     * it back to the agent verbatim. A summary would tell the agent something
     * failed without telling it what, which is worse than not checking.
     *
     * @return array{status: int, output: string}
     */
    public static function captureFast(): array
    {
        $collected = '';

        foreach (self::fastChecks() as $check) {
            $result = Process::capture($check['command'], Repo::root());
            $collected .= $result['output'];

            if ($result['status'] !== 0) {
                return ['status' => $result['status'], 'output' => trim($collected)];
            }
        }

        return ['status' => 0, 'output' => trim($collected)];
    }
}
