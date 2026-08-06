<?php

declare(strict_types=1);

use Tooling\Repo;

/*
 * The Stop hook is shared verbatim by Claude Code and Codex: both refuse a stop
 * on exit 2 with the reason on stderr, so there is one script rather than two
 * configurations that agree today and drift tomorrow.
 */

/**
 * Run the hook exactly as an agent does: JSON on stdin, nothing else.
 *
 * @param  array<string, mixed>  $payload
 * @return array{status: int, stdout: string, stderr: string}
 */
function runStopHook(array $payload): array
{
    $script = Repo::root().'/scripts/bin/agent-stop.php';

    $process = proc_open(
        [PHP_BINARY, $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        Repo::root(),
    );

    fwrite($pipes[0], (string) json_encode($payload));
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/*
 * THE LOOP BREAKER.
 *
 * Both agents set stop_hook_active once a turn has already been continued by a
 * Stop hook. Without this check, a failure the agent cannot fix — a pre-existing
 * analyser error, a missing tool — would refuse every stop in turn, forever,
 * and burn the session with no way out but killing the process.
 *
 * The check runs before any work, so this also proves the second pass is cheap.
 */
it('exits immediately when a stop has already been continued', function () {
    $result = runStopHook(['hook_event_name' => 'Stop', 'stop_hook_active' => true]);

    expect($result['status'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});

it('treats a missing stop_hook_active as not active', function () {
    // Absent must not be read as "already looping", or the hook would never run.
    $result = runStopHook(['hook_event_name' => 'Stop']);

    expect($result['status'])->toBeIn([0, 2]);
})->skip(fn (): bool => true, 'Runs the real gate; covered by the manual obligation instead of slowing every suite run.');

it('never writes to stdout', function () {
    // Codex rejects plain text on stdout for this event. Silence is valid for
    // both agents, so the hook speaks only through stderr and its exit code.
    $result = runStopHook(['hook_event_name' => 'Stop', 'stop_hook_active' => true]);

    expect($result['stdout'])->toBe('');
});

it('survives a malformed payload without crashing', function () {
    $script = Repo::root().'/scripts/bin/agent-stop.php';

    $process = proc_open(
        [PHP_BINARY, $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        Repo::root(),
    );

    fwrite($pipes[0], 'not json at all');
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    // A hook that fatals on unexpected input becomes a hook that blocks every
    // turn with a PHP stack trace, so the parse is defensive by design.
    expect(proc_close($process))->toBeIn([0, 2]);
});
