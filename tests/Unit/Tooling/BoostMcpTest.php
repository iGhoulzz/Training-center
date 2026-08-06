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
        array_merge(getenv(), [
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
        ]),
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

it('starts the Claude Boost MCP server from a nested directory', function () {
    $root = Repo::root();
    $result = initializeBoostMcp(
        [PHP_BINARY, $root.'/artisan', 'boost:mcp'],
        $root.'/app/Domain',
    );

    $response = json_decode(trim($result['stdout']), true, flags: JSON_THROW_ON_ERROR);

    expect($result['status'])->toBe(0, $result['stderr'])
        ->and($response['result']['serverInfo']['name'] ?? null)->not->toBeNull();
});

it('starts the Codex Boost MCP server from its project-root cwd', function () {
    $root = Repo::root();
    $result = initializeBoostMcp(
        [PHP_BINARY, 'artisan', 'boost:mcp'],
        // .codex/config.toml resolves its cwd = ".." from the .codex folder.
        $root,
    );

    $response = json_decode(trim($result['stdout']), true, flags: JSON_THROW_ON_ERROR);

    expect($result['status'])->toBe(0, $result['stderr'])
        ->and($response['result']['serverInfo']['name'] ?? null)->not->toBeNull();
});
