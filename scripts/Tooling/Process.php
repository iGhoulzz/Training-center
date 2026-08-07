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
     * Run a command and collect everything it printed, stdout and stderr
     * interleaved as the child emitted them.
     *
     * THE STREAMS ARE MERGED AT proc_open, NOT CONCATENATED AFTERWARDS, AND
     * THAT IS A DEADLOCK FIX RATHER THAN A TIDINESS ONE.
     *
     * Two separate pipes drained one after the other hang the moment a child
     * writes more to the second stream than its pipe buffer holds — roughly
     * 64 KiB. The child blocks writing stderr, the parent blocks reading
     * stdout, and neither moves. Measured: a child writing 1 MiB to stderr
     * never returned and had to be killed.
     *
     * The Stop hook runs through this method, so that deadlock meant a noisy
     * PHPStan failure could hang an agent for the hook's full 300-second
     * timeout — a gate failing in the slowest possible way, on exactly the
     * input it exists to report.
     *
     * Merging also makes the ordering claim true. Concatenating the two
     * captures turned a child that wrote "err" then "out" into "outerr".
     *
     * @param  list<string>  $command
     * @return array{status: int, output: string}
     */
    public static function capture(array $command, ?string $cwd = null): array
    {
        /*
         * Both streams are given the SAME temporary file, rather than a pipe
         * each. A file has no fixed-size buffer, so the child can never block
         * waiting for a reader — there is nothing left to deadlock on, and no
         * draining order to get wrong. It is also what makes the interleaving
         * genuine: the kernel writes both streams into one file as they arrive.
         */
        $buffer = tmpfile();

        if ($buffer === false) {
            throw new RuntimeException('Unable to open a buffer for: '.($command[0] ?? '?'));
        }

        $process = proc_open($command, [1 => $buffer, 2 => $buffer], $pipes, $cwd);

        if (! is_resource($process)) {
            fclose($buffer);

            throw new RuntimeException('Unable to start: '.($command[0] ?? '?'));
        }

        $status = proc_close($process);

        rewind($buffer);
        $output = stream_get_contents($buffer);
        fclose($buffer);

        return [
            'status' => $status,
            'output' => is_string($output) ? $output : '',
        ];
    }
}
