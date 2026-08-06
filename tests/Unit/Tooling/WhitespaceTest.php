<?php

declare(strict_types=1);

use Tooling\Whitespace;

/*
 * An untracked file has no committed side to compare against, so it is diffed
 * against an empty file.
 *
 * THE EXIT CODE IS USELESS HERE, and that is the whole point of these tests.
 * `git diff --no-index` returns 1 whenever its two inputs differ, and every
 * non-empty file differs from an empty one. A gate reading that exit code would
 * reject EVERY new file, clean or not — and a gate that always fails is one
 * that gets switched off within a day. The verdict has to come from the output.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ws-test-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('passes a clean untracked file', function () {
    $path = $this->dir.'/Clean.php';
    file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn 1;\n");

    // The regression that matters: this file differs from empty, so an
    // exit-code-based check calls it broken.
    expect(Whitespace::untrackedProblems($path))->toBeNull();
});

it('reports a trailing space in an untracked file', function () {
    $path = $this->dir.'/Trailing.php';
    file_put_contents($path, "<?php\n\n\$x = 1;   \n");

    expect(Whitespace::untrackedProblems($path))->toContain('trailing whitespace');
});

it('reports a space-before-tab indent', function () {
    $path = $this->dir.'/Indent.php';
    file_put_contents($path, "<?php\n\nif (true) {\n \t\$x = 1;\n}\n");

    expect(Whitespace::untrackedProblems($path))->not->toBeNull();
});

it('passes an empty untracked file', function () {
    $path = $this->dir.'/Empty.php';
    file_put_contents($path, '');

    expect(Whitespace::untrackedProblems($path))->toBeNull();
});

it('ignores a path that is not a readable file', function () {
    // A deleted-but-still-listed path must not crash the gate.
    expect(Whitespace::untrackedProblems($this->dir.'/does-not-exist.php'))->toBeNull()
        ->and(Whitespace::untrackedProblems($this->dir))->toBeNull();
});

it('reads a file whose name contains a space', function () {
    $path = $this->dir.'/a file.php';
    file_put_contents($path, "<?php\n\n\$x = 1;   \n");

    // Command arguments are passed as a list, never through a shell, so the
    // space needs no quoting and cannot split the argument.
    expect(Whitespace::untrackedProblems($path))->toContain('trailing whitespace');
});
