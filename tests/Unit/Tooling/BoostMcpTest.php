<?php

declare(strict_types=1);

use Tooling\Repo;

/**
 * Start Boost exactly as the committed agent configurations do and complete an
 * MCP initialize exchange. A process merely staying open is not enough: a PHP
 * error can also leave a launcher waiting while no MCP server exists.
 *
 * @param  list<string>  $command
 * @return array{status: int, stdout: string, stderr: string}
 */
function initializeBoostMcp(array $command, string $cwd): array
{
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        /*
         * MERGED, not replaced. Passing only this one variable hands the child
         * an otherwise empty environment. That survives locally, where the
         * worktree has a .env for Laravel to read, and fails in CI, where there
         * is none and APP_KEY, the database credentials and PATH all arrive
         * through the job environment. The child then aborts before any MCP
         * server exists, and the test reports a broken server when the real
         * fault is a missing environment.
         */
        /*
         * CLAUDE_PROJECT_DIR IS REMOVED, NOT SUPPLIED.
         *
         * An earlier version injected it, so the launcher always had a project
         * root handed to it and the test passed no matter what the config did.
         * The real client does not give the `php -r` snippet a usable root — the
         * measured result was `Connected` from the repository root and `Failed
         * to connect` from app/Domain — so injecting it tested a situation that
         * does not occur.
         *
         * Stripped rather than merely omitted: this suite may itself be run from
         * inside a Claude Code session, where the parent process has the
         * variable set and array_merge(getenv(), ...) would pass it straight
         * through, quietly restoring the false positive.
         */
        array_diff_key(array_merge(getenv(), [
            'BOOST_RULES_ENABLED' => 'false',
            /*
             * Boost disables itself under APP_ENV=testing, twice over:
             * BoostServiceProvider::shouldRun() bails when
             * app()->runningUnitTests() is true — which is exactly
             * environment('testing') — and again unless the environment is
             * `local` or app.debug is set. Inherited, the child would report
             * "There are no commands defined in the boost namespace", which
             * reads as a broken install rather than a deliberate gate.
             *
             * `local` is also the honest value: an MCP server only ever runs in
             * a developer's local environment. Set for THIS CHILD ONLY — the
             * suite must stay in `testing`, and APP_DEBUG in particular changes
             * how exceptions render, which several feature tests assert on.
             */
            'APP_ENV' => 'local',
        ]), ['CLAUDE_PROJECT_DIR' => true]),
    );

    expect(is_resource($process))->toBeTrue('Unable to start the Boost MCP process.');

    $request = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'tooling-test', 'version' => '1.0'],
        ],
    ];

    fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR)."\n");
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * Expand a value the way Claude Code expands `.mcp.json`: `${VAR}` from the
 * environment, `${VAR:-default}` falling back to the default.
 *
 * CLAUDE_PROJECT_DIR is deliberately NOT supplied. Claude sets it in the
 * spawned server's environment, not its own, so at parse time it is unset and
 * the default is what actually gets used — which is why the bare `${VAR}` form
 * produced "Missing environment variables: CLAUDE_PROJECT_DIR" and the server
 * never loaded.
 */
function expandMcpValue(string $value): string
{
    return (string) preg_replace_callback(
        '/\$\{([A-Z_][A-Z0-9_]*)(?::-([^}]*))?\}/i',
        function (array $match): string {
            $actual = getenv($match[1]);

            return $actual !== false && $actual !== '' ? $actual : ($match[2] ?? '');
        },
        $value,
    );
}

/**
 * The command exactly as the committed .mcp.json defines it.
 *
 * Derived rather than hardcoded: a test that spells out its own path proves the
 * server can start, not that the SHIPPED CONFIGURATION starts it. That is the
 * difference that let a broken `${CLAUDE_PROJECT_DIR}` reference sit here
 * passing.
 *
 * @return list<string>
 */
function committedClaudeMcpCommand(): array
{
    $config = json_decode(
        (string) file_get_contents(Repo::root().'/.mcp.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $server = $config['mcpServers']['laravel-boost'];

    return array_map(
        expandMcpValue(...),
        [$server['command'], ...$server['args']],
    );
}

/*
 * THE WORKING DIRECTORY IS NESTED ON PURPOSE.
 *
 * An earlier version of this test passed the repository root as the child's
 * cwd, on the assumption that Claude Code starts MCP servers there. It does
 * not — it uses the session's working directory. Measured against Claude Code
 * 2.1.177 with the previous `${CLAUDE_PROJECT_DIR:-.}/artisan` configuration:
 * connected from the repository root, FAILED from app/Domain. Passing $root
 * here reproduced the passing case and nothing else, so the test agreed with
 * the assumption instead of checking it.
 *
 * Starting nested, with CLAUDE_PROJECT_DIR supplied exactly as Claude supplies
 * it to a spawned server, is the case that was actually broken.
 */
it('starts the Claude Boost MCP server from a nested working directory', function () {
    $result = initializeBoostMcp(committedClaudeMcpCommand(), Repo::root().'/app/Domain');

    $response = json_decode(trim($result['stdout']), true, flags: JSON_THROW_ON_ERROR);

    expect($result['status'])->toBe(0, $result['stderr'])
        ->and($response['result']['serverInfo']['name'] ?? null)->not->toBeNull();
});

it('starts the Codex Boost MCP server from its committed configuration', function () {
    $config = (string) file_get_contents(Repo::root().'/.codex/config.toml');

    // Derived from the committed file, for the same reason as the Claude case:
    // a hardcoded command would keep passing after the config broke.
    preg_match('/^command\s*=\s*"([^"]+)"/m', $config, $command);
    preg_match('/^args\s*=\s*\[([^\]]*)\]/m', $config, $args);
    preg_match('/^cwd\s*=\s*"([^"]+)"/m', $config, $cwd);

    expect($command[1] ?? null)->not->toBeNull()
        ->and($cwd[1] ?? null)->toBe('..');

    $arguments = array_map(
        static fn (string $value): string => trim(trim($value), '"'),
        explode(',', $args[1] ?? ''),
    );

    $result = initializeBoostMcp(
        [$command[1], ...$arguments],
        // Codex resolves a project config's relative cwd from .codex/, so ".."
        // is the repository root.
        Repo::root().'/.codex/'.$cwd[1],
    );

    $response = json_decode(trim($result['stdout']), true, flags: JSON_THROW_ON_ERROR);

    expect($result['status'])->toBe(0, $result['stderr'])
        ->and($response['result']['serverInfo']['name'] ?? null)->not->toBeNull();
});
