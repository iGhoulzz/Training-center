<?php

declare(strict_types=1);

namespace Tooling;

use RuntimeException;

/**
 * Running a child command without losing its exit code.
 *
 * Commands are always passed as an argument LIST, never a string. A string is
 * handed to a shell, and this repository lives at a path that has no spaces
 * today but will not always; more importantly, shell quoting differs between
 * Git Bash, cmd.exe and PowerShell, and the hooks here run under all three.
 */
final class Process
{
    /**
     * Run a command with its output going straight to this process's streams.
     *
     * @param  list<string>  $command
     */
    public static function stream(array $command, ?string $cwd = null): int
    {
        $process = proc_open(
            $command,
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $cwd,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start: '.($command[0] ?? '?'));
        }

        return proc_close($process);
    }

    /**
     * Run a command and collect everything it printed.
     *
     * stdout and stderr are merged deliberately. A failing tool splits its
     * message across both — PHPStan prints its summary on one and its errors on
     * the other — and a caller handing the result to an agent needs the whole
     * message, in order, not half of it.
     *
     * @param  list<string>  $command
     * @return array{status: int, output: string}
     */
    public static function capture(array $command, ?string $cwd = null): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start: '.($command[0] ?? '?'));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'status' => proc_close($process),
            'output' => (is_string($stdout) ? $stdout : '').(is_string($stderr) ? $stderr : ''),
        ];
    }
}
