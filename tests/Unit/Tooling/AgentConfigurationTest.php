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

function projectSource(string $relative): string
{
    return (string) file_get_contents(Repo::root().'/'.$relative);
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

it('keeps Boost rules disabled in both MCP processes', function () {
    $claude = json_decode(projectSource('.mcp.json'), true, flags: JSON_THROW_ON_ERROR);
    $codex = projectSource('.codex/config.toml');

    expect($claude['mcpServers']['laravel-boost']['env']['BOOST_RULES_ENABLED'] ?? null)->toBe('false')
        ->and($codex)->toContain('[mcp_servers.laravel-boost.env]')
        ->and($codex)->toContain('BOOST_RULES_ENABLED = "false"');
});

it('resolves both Boost MCP servers from nested directories', function () {
    $claude = json_decode(projectSource('.mcp.json'), true, flags: JSON_THROW_ON_ERROR);
    $codex = projectSource('.codex/config.toml');

    /*
     * THE PATH MUST BE RESOLVED INSIDE THE SERVER, NOT BY THE CONFIG.
     *
     * Two forms were tried and both fail:
     *
     *   ${CLAUDE_PROJECT_DIR}/artisan     — never expands. Claude Code sets
     *     that variable in the SPAWNED SERVER's environment, not its own, so at
     *     parse time it is unset and no server is offered at all:
     *     `claude mcp list` reports "Missing environment variables".
     *
     *   ${CLAUDE_PROJECT_DIR:-.}/artisan  — parses, then resolves `.` against
     *     the SESSION's working directory rather than the project root.
     *     Measured on Claude Code 2.1.177: connected from the repository root,
     *     failed from app/Domain.
     *
     * So the config carries no path at all. It runs a one-line `php -r` that
     * reads CLAUDE_PROJECT_DIR from its own environment — where it is genuinely
     * set — and requires the launcher, which derives everything else from
     * __DIR__.
     */
    $args = $claude['mcpServers']['laravel-boost']['args'] ?? [];

    expect($args[0] ?? null)->toBe('-r')
        ->and($args[1] ?? '')->toContain('CLAUDE_PROJECT_DIR')
        ->and($args[1] ?? '')->toContain('scripts/bin/boost-mcp.php')
        // The launcher must exist, or the server dies with a require error that
        // the client only reports as a failed connection.
        ->and(is_file(Repo::root().'/scripts/bin/boost-mcp.php'))->toBeTrue()
        // Project-config relative paths resolve from .codex/, so .. is the root.
        ->and($codex)->toContain('cwd = ".."');
});

it('tracks the lockfile and every selected Boost output path', function () {
    $tracked = explode("\n", Repo::git([
        'ls-files',
        'composer.lock',
        'boost.json',
        '.mcp.json',
        '.codex/config.toml',
        '.agents/skills',
        '.claude/skills',
    ]));

    expect($tracked)->toContain('composer.lock', 'boost.json', '.mcp.json', '.codex/config.toml')
        ->and(collect($tracked)->contains(fn (string $path): bool => str_starts_with($path, '.agents/skills/')))->toBeTrue()
        ->and(collect($tracked)->contains(fn (string $path): bool => str_starts_with($path, '.claude/skills/')))->toBeTrue();
});

it('does not track Boost output for unselected agents or project rules', function () {
    $tracked = Repo::git(['ls-files']);

    expect($tracked)
        ->not->toContain("\n.ai/rules/")
        ->not->toContain("\n.junie/")
        ->not->toContain("\n.cursor/");
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

it('routes Git hooks and CI through the Composer gates', function () {
    $preCommit = projectSource('.githooks/pre-commit');
    $prePush = projectSource('.githooks/pre-push');
    $ci = projectSource('.github/workflows/ci.yml');

    expect($preCommit)->toContain('composer verify:fast')
        ->and($prePush)->toContain('composer verify')
        ->and($ci)->toContain('run: composer verify');
});

it('always builds frontend assets before push and in CI', function () {
    expect(projectSource('.githooks/pre-push'))->toContain('npm run build')
        ->and(projectSource('.github/workflows/ci.yml'))->toContain('run: npm run build');
});
