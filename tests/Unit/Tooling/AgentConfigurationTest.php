<?php

declare(strict_types=1);

use Tooling\Repo;

/*
 * "Both agents run the same checks" has to be a fact about the files, not an
 * intention. These assertions are what stop the two configurations drifting
 * into agreeing-today-and-not-tomorrow.
 */

/**
 * The file's own bytes. Assertions read the source rather than a re-encoding of
 * it, because json_encode escapes `/` as `\/` and a path assertion against that
 * fails for a reason that has nothing to do with the configuration.
 */
function configSource(string $relative): string
{
    $raw = (string) file_get_contents(Repo::root().'/'.$relative);

    expect(json_decode($raw, true))->toBeArray("{$relative} is not valid JSON");

    return $raw;
}

it('points both agents at one shared stop hook', function () {
    expect(configSource('.claude/settings.json'))->toContain('scripts/bin/agent-stop.php')
        ->and(configSource('.codex/hooks.json'))->toContain('scripts/bin/agent-stop.php');
});

it('resolves the hook path independently of the working directory', function () {
    // An agent may start in a subdirectory. A relative path would resolve to
    // nothing there and the hook would silently never run — the worst failure
    // mode available, because it looks exactly like a passing gate.
    expect(configSource('.claude/settings.json'))->toContain('CLAUDE_PROJECT_DIR')
        ->and(configSource('.codex/hooks.json'))->toContain('rev-parse');
});

it('commits both agents configuration', function () {
    // .gitignore blanket-ignored /.codex, which would have left Codex's half of
    // this unshareable while appearing configured on the machine that made it.
    $tracked = Repo::git(['ls-files', '.claude/settings.json', '.codex/hooks.json']);

    expect($tracked)->toContain('.claude/settings.json')
        ->and($tracked)->toContain('.codex/hooks.json');
});

it('never tracks the local Claude settings', function () {
    // settings.local.json holds machine-specific permissions. Committing it
    // would hand every agent on every machine one developer's approvals.
    expect(Repo::git(['ls-files', '.claude/settings.local.json']))->toBe('');
});

/*
 * THIS TEST EXISTS BECAUSE THE FAILURE HAPPENED.
 *
 * The precedence note added above the Boost block originally quoted Boost's
 * opening marker tag literally, to say which block it outranked. `boost:update`
 * finds its block by searching for that string, found the prose copy first, and
 * replaced everything from there to the end of the file — deleting the role,
 * non-negotiables, phase-discipline and gates sections from both agent files.
 *
 * Nothing else would have caught it. The files still looked plausible, both
 * agents still loaded them, and the missing rules were rules about how to work,
 * so their absence would have shown up as an agent quietly not following them.
 */
it('has exactly one boost marker pair per agent file', function () {
    // Assembled rather than written out, so this assertion cannot become the
    // very thing it is guarding against.
    $open = '<'.'laravel-boost-guidelines>';
    $close = '</'.'laravel-boost-guidelines>';

    foreach (['CLAUDE.md', 'AGENTS.md'] as $file) {
        $contents = (string) file_get_contents(Repo::root().'/'.$file);

        expect(substr_count($contents, $open))->toBe(1, "{$file} must contain exactly one opening Boost marker")
            ->and(substr_count($contents, $close))->toBe(1, "{$file} must contain exactly one closing Boost marker");
    }
});

it('keeps the precedence statement outside the boost block', function () {
    $open = '<'.'laravel-boost-guidelines>';

    foreach (['CLAUDE.md', 'AGENTS.md'] as $file) {
        $contents = (string) file_get_contents(Repo::root().'/'.$file);

        $precedence = strpos($contents, 'outranks the Laravel Boost guidelines block');
        $marker = strpos($contents, $open);

        expect($precedence)->not->toBeFalse("{$file} has lost its precedence statement");
        // Inside the block it would be erased by the next update, taking the
        // statement that our rules win along with it.
        expect($precedence)->toBeLessThan($marker, "{$file}'s precedence statement must precede the Boost block");
    }
});

it('keeps the git hooks executable', function () {
    // Windows does not carry the executable bit, so it has to be set in the
    // index explicitly or the hooks are inert for whoever clones next.
    $entries = Repo::git(['ls-files', '-s', '.githooks/']);

    expect($entries)->toContain('.githooks/pre-commit')
        ->and($entries)->toContain('.githooks/pre-push');

    foreach (explode("\n", trim($entries)) as $line) {
        expect($line)->toStartWith('100755');
    }
});
