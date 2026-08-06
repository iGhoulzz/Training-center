<?php

declare(strict_types=1);

/*
 * The Stop hook, shared by Claude Code and Codex.
 *
 * ONE SCRIPT, BOTH AGENTS. Both support "exit 2, reason on stderr" to refuse a
 * stop, so no per-agent adapter is needed and the two cannot drift apart —
 * "both agents run the same checks" is literally the same file rather than two
 * configurations that agree today.
 *
 * Contract:
 *   exit 0  nothing to say; the agent may finish
 *   exit 2  refuse the stop; stderr goes back to the agent as the reason
 *
 * Nothing is written to stdout. Codex rejects plain text on stdout for this
 * event, and staying silent there is valid for both.
 */

use Tooling\ChangeSet;
use Tooling\Gate;
use Tooling\Repo;
use Tooling\Whitespace;

require __DIR__.'/../../vendor/autoload.php';

$payload = json_decode((string) file_get_contents('php://stdin'), true);
$payload = is_array($payload) ? $payload : [];

/*
 * LOOP BREAKER, AND IT MUST COME FIRST.
 *
 * Both agents set this once a turn has already been continued by a Stop hook.
 * Without this check a failure the agent cannot fix — a pre-existing PHPStan
 * error, a tool that is not installed — would refuse every stop forever and
 * burn the session. Checking it before doing any work also means the second
 * pass costs nothing.
 */
if (($payload['stop_hook_active'] ?? false) === true) {
    exit(0);
}

chdir(Repo::root());

$changes = ChangeSet::fromGit();

if ($changes->isEmpty()) {
    exit(0);
}

$whitespace = Whitespace::problems($changes);

if ($whitespace !== []) {
    fwrite(STDERR, "Whitespace errors introduced by this turn:\n\n".implode("\n", $whitespace)."\n");
    exit(2);
}

/*
 * Documentation-only turns stop here.
 *
 * "Documentation-only" is decided across staged, unstaged AND untracked files
 * together — a turn is not documentation because the part of it that happens to
 * be staged is.
 */
if ($changes->isDocumentationOnly()) {
    exit(0);
}

$result = Gate::captureFast();

if ($result['status'] !== 0) {
    // The real output, verbatim. An agent told only that "the gate failed"
    // cannot fix it and will guess.
    fwrite(STDERR, "The fast gate failed. Fix this before finishing:\n\n".$result['output']."\n");
    exit(2);
}

exit(0);
