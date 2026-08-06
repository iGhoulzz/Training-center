<?php

declare(strict_types=1);

use Tooling\Gate;
use Tooling\Process;

/*
 * The gate's composition is asserted here so it survives later edits. Mutation
 * testing proved these checks were load-bearing on the day they were written;
 * these tests are what keep them so.
 */

it('runs pint in check mode, never as a fixer', function () {
    $pint = collect(Gate::fastChecks())->firstWhere('name', 'pint');

    expect($pint)->not->toBeNull()
        // --test or the gate rewrites files while checking them, which would
        // change what a commit hook is committing after it has been reviewed.
        ->and($pint['command'])->toContain('--test');
});

it('runs phpstan with the memory limit it needs', function () {
    $phpstan = collect(Gate::fastChecks())->firstWhere('name', 'phpstan');

    expect($phpstan)->not->toBeNull()
        // PHPStan OOMs on this codebase at PHP's default limit and reports the
        // crash rather than the limit, which reads as a broken install.
        ->and($phpstan['command'])->toContain('--memory-limit=1G');
});

/*
 * OBLIGATION: the Stop hook must never launch the suite.
 *
 * These checks run after every agent turn. The suite takes about five minutes
 * and serialises against every other worktree, so putting it here would make a
 * turn unusable and the hook would be switched off. Asserted on the canonical
 * list rather than by timing a run, so it cannot regress quietly.
 */
it('never includes the test suite in the fast gate', function () {
    $commands = collect(Gate::fastChecks())->pluck('command')->flatten()->implode(' ');

    expect($commands)
        ->not->toContain('artisan test')
        ->not->toContain('pest')
        ->not->toContain('phpunit')
        ->not->toContain('test:serial');
});

it('reports the first failing check and stops there', function () {
    // Exit-code propagation, on the primitive every gate stage is built from.
    // A runner that collapses failures to 1, or worse to 0, makes every other
    // guarantee here unverifiable.
    $failing = Process::capture([PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(3);']);

    expect($failing['status'])->toBe(3)
        ->and($failing['output'])->toContain('boom');

    $passing = Process::capture([PHP_BINARY, '-r', 'echo "fine";']);

    expect($passing['status'])->toBe(0)
        ->and($passing['output'])->toContain('fine');
});

it('merges stderr into captured output', function () {
    // A failing tool splits its message across both streams; handing an agent
    // only one half of a PHPStan failure is worse than handing it nothing.
    $result = Process::capture([PHP_BINARY, '-r', 'echo "out"; fwrite(STDERR, "err");']);

    expect($result['output'])->toContain('out')->toContain('err');
});
