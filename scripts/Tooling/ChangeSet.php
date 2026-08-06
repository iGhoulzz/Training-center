<?php

declare(strict_types=1);

namespace Tooling;

/**
 * What has changed, split into the three sets a gate has to consider.
 *
 * `git diff --check` sees UNSTAGED TRACKED changes only. It misses everything
 * staged and every untracked file, so a gate built on it alone will report a
 * clean tree while a whole new directory of unformatted code sits beside it, and
 * will call a commit "documentation-only" on the strength of the one file it
 * happened to look at.
 */
final class ChangeSet
{
    /**
     * @param  list<string>  $staged
     * @param  list<string>  $unstagedTracked
     * @param  list<string>  $untracked
     */
    public function __construct(
        public readonly array $staged,
        public readonly array $unstagedTracked,
        public readonly array $untracked,
    ) {}

    public static function fromGit(): self
    {
        return self::parse(Repo::git([
            'status',
            '--porcelain=v1',
            '-z',
            '--untracked-files=all',
        ]));
    }

    /**
     * Parse `git status --porcelain=v1 -z --untracked-files=all`.
     *
     * EVERY FLAG IS LOAD-BEARING.
     *
     * `-z` because the default output QUOTES any path containing a space, a
     * quote or a non-ASCII byte — `"lang/ar/\330\247.php"` — and a classifier
     * matching on `.php` then misses it. With -z the path is literal and
     * NUL-terminated.
     *
     * `--untracked-files=all` because the default collapses a new directory to a
     * single entry ending in `/`. A new `scripts/` folder would be one line, its
     * contents never examined, and "documentation-only" would be decided without
     * seeing the code inside it.
     *
     * `=v1` to pin the format; v2 is a different grammar entirely.
     */
    public static function parse(string $raw): self
    {
        $staged = [];
        $unstaged = [];
        $untracked = [];

        // The trailing NUL yields one empty tail field; drop it rather than
        // treating an empty path as a change.
        $fields = explode("\0", $raw);

        for ($i = 0; $i < count($fields); $i++) {
            $entry = $fields[$i];

            if ($entry === '') {
                continue;
            }

            // "XY path" — two status columns, one space, then the literal path.
            $x = $entry[0];
            $y = $entry[1] ?? ' ';
            $path = substr($entry, 3);

            if ($path === '') {
                continue;
            }

            if ($x === '?' && $y === '?') {
                $untracked[] = $path;

                continue;
            }

            // A rename or copy carries its ORIGIN as a second NUL-terminated
            // field. Not consuming it here would leave the origin path to be
            // read as the next status entry, where `substr($entry, 3)` would
            // silently chop three characters off a real filename.
            if ($x === 'R' || $x === 'C') {
                $i++;
            }

            if ($x !== ' ') {
                $staged[] = $path;
            }

            if ($y !== ' ') {
                $unstaged[] = $path;
            }
        }

        return new self($staged, $unstaged, $untracked);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(array_unique([...$this->staged, ...$this->unstagedTracked, ...$this->untracked]));
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * True only when ALL THREE sets are documentation.
     *
     * The point of checking every set is that a commit is not documentation-only
     * because its staged half is.
     */
    public function isDocumentationOnly(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        foreach ($this->all() as $path) {
            if (! self::isDocumentation($path)) {
                return false;
            }
        }

        return true;
    }

    public static function isDocumentation(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_ends_with(mb_strtolower($path), '.md')
            || str_starts_with($path, 'docs/');
    }
}
