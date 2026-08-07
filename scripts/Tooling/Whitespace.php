<?php

declare(strict_types=1);

namespace Tooling;

/**
 * Whitespace enforcement across all three change sets.
 */
final class Whitespace
{
    /**
     * @return list<string> the offending lines, empty when clean
     */
    public static function problems(ChangeSet $changes): array
    {
        $problems = [];

        // Staged and unstaged tracked content: git compares against a known side,
        // so a non-empty --check output is unambiguous.
        foreach ([['diff', '--cached', '--check'], ['diff', '--check']] as $args) {
            /** @var list<string> $args */
            $out = trim(Repo::gitResult($args)['stdout']);

            if ($out !== '') {
                $problems[] = $out;
            }
        }

        foreach ($changes->untracked as $path) {
            $found = self::untrackedProblems($path);

            if ($found !== null) {
                $problems[] = $found;
            }
        }

        return $problems;
    }

    /**
     * An untracked file has no committed side to diff against, so it is compared
     * with an empty file.
     *
     * THE EXIT CODE CANNOT BE USED HERE. `git diff --no-index` returns 1 whenever
     * the two inputs differ, and every non-empty file differs from an empty one —
     * so exit-code-as-verdict fails EVERY new file, clean or not, and a gate that
     * always fails is one that gets switched off. The verdict comes from the
     * output: `--check` prints one `path:line: message` per problem and prints
     * nothing when the content is fine.
     *
     * An empty temporary file is used rather than /dev/null, which does not exist
     * on Windows.
     */
    public static function untrackedProblems(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $empty = tempnam(sys_get_temp_dir(), 'ws-');

        if ($empty === false) {
            return null;
        }

        try {
            $out = trim(Repo::gitResult(['diff', '--no-index', '--check', $empty, $path])['stdout']);

            return $out === '' ? null : $out;
        } finally {
            @unlink($empty);
        }
    }
}
