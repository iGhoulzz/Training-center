<?php

declare(strict_types=1);

/*
 * pre-push refuses to certify a dirty worktree.
 *
 * The gate runs against the FILES ON DISK, but a push publishes COMMITS. Those
 * are the same thing only when the worktree is clean. Otherwise an uncommitted
 * local fix makes broken committed code pass — the reviewer pulls the branch,
 * runs the same gate, and it fails for them. That is precisely the failure a
 * pre-push gate exists to prevent, so a dirty tree is refused rather than
 * quietly certified.
 *
 * Untracked files count. A new, uncommitted class is exactly the kind of thing
 * that makes the tree pass and the pushed commits fail.
 */

use Tooling\ChangeSet;
use Tooling\Repo;

require __DIR__.'/../../vendor/autoload.php';

chdir(Repo::root());

$changes = ChangeSet::fromGit();

if ($changes->isEmpty()) {
    exit(0);
}

$lines = [];

foreach ($changes->staged as $path) {
    $lines[] = "  staged     {$path}";
}

foreach ($changes->unstagedTracked as $path) {
    $lines[] = "  unstaged   {$path}";
}

foreach ($changes->untracked as $path) {
    $lines[] = "  untracked  {$path}";
}

fwrite(STDERR, <<<'TXT'

Refusing to push: the worktree is not clean.

The gate checks the files on disk, but a push publishes commits. With
uncommitted changes present those are different things, and an uncommitted fix
here would certify commits that fail for whoever pulls them.


TXT);

fwrite(STDERR, implode("\n", $lines)."\n\nCommit or stash these, then push again.\n\n");

exit(1);
