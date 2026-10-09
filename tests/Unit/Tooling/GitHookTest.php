<?php

declare(strict_types=1);

use Tooling\Repo;

/**
 * Locate a POSIX shell for exercising the committed Git hooks.
 *
 * Git hooks run under `/bin/sh` on Unix and Git for Windows. Windows does not
 * normally put that shell on PATH, so derive it from Git's installation before
 * falling back to GitHub Desktop's bundled copy.
 */
function gitHookShell(): string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return '/bin/sh';
    }

    $gitExecutable = trim((string) shell_exec('where.exe git 2>NUL'));
    $firstGit = preg_split('/\R/', $gitExecutable)[0] ?? '';
    $gitRoot = dirname(dirname($firstGit));

    /*
     * usr/bin/sh.exe FIRST. bin/sh.exe is a launcher that prepends Git's own
     * mingw64/bin and usr/bin to PATH before starting the real shell, so the
     * sandbox's stand-in `git` and `uname` lose to the real ones while a
     * stand-in Git does not ship — `composer`, `npm` — still wins. With the
     * launcher first, the Unix-npm case failed on a Windows machine with the
     * stand-in npm.cmd's exit 91: real `uname` said MINGW. The plain shell
     * honours the PATH this harness hands it.
     */
    $candidates = [
        $gitRoot.'/usr/bin/sh.exe',
        $gitRoot.'/bin/sh.exe',
    ];

    $desktopCopies = glob((string) getenv('LOCALAPPDATA').'/GitHubDesktop/app-*/resources/app/git/usr/bin/sh.exe');

    foreach ([...$candidates, ...($desktopCopies ?: [])] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('No Git-compatible POSIX shell was found.');
}

/**
 * @param  array<string, string>  $environment
 * @return array{status: int, output: string}
 */
function runHook(array $environment): array
{
    $buffer = tmpfile();

    if ($buffer === false) {
        throw new RuntimeException('Unable to create the hook output buffer.');
    }

    /*
     * STDIN IS GIVEN, AND CLOSED, EXPLICITLY (found by the P35-T14 Linux run).
     *
     * Early on, the hook runs `tuples=$(cat)`, which reads stdin to EOF. With no
     * descriptor 0 here the hook inherited the test runner's own stdin. Under
     * `php artisan test` without a live terminal — on Windows as the gate runs,
     * and in CI — that is already closed, so the omission was invisible. Under
     * an interactive `docker compose exec` it is the terminal, where `cat` waits
     * for keyboard input forever and the whole suite hangs on this file. An
     * empty, closed pipe is what git itself hands the hook when there are no
     * refs to push.
     */
    $process = proc_open(
        [gitHookShell(), Repo::root().'/.githooks/pre-push'],
        [0 => ['pipe', 'r'], 1 => $buffer, 2 => $buffer],
        $pipes,
        Repo::root(),
        [...getenv(), ...$environment],
    );

    if (! is_resource($process)) {
        fclose($buffer);

        throw new RuntimeException('Unable to execute the pre-push hook.');
    }

    fclose($pipes[0]);

    $status = proc_close($process);
    rewind($buffer);
    $output = stream_get_contents($buffer);
    fclose($buffer);

    return ['status' => $status, 'output' => is_string($output) ? $output : ''];
}

/**
 * @param  array<string, string>  $commands
 * @return array{root: string, bin: string, marker: string}
 */
function fakeHookCommands(array $commands): array
{
    $root = sys_get_temp_dir().'/training-center-hook-'.bin2hex(random_bytes(8));
    $bin = $root.'/bin';
    $marker = $root.'/frontend-command';

    mkdir($bin, recursive: true);

    foreach ($commands as $name => $body) {
        $path = $bin.'/'.$name;
        $contents = PHP_OS_FAMILY === 'Windows' && str_ends_with($name, '.cmd')
            ? "@echo off\r\n{$body}\r\n"
            : "#!/bin/sh\n{$body}\n";

        file_put_contents($path, $contents);
        chmod($path, 0755);
    }

    return compact('root', 'bin', 'marker');
}

/**
 * A stand-in `git` for the hook sandbox.
 *
 * `rev-parse --show-toplevel` answers with the real root. `config --get` answers
 * with $HOOK_GATE when it is set and exits 1 when it is not, which is what real
 * git does for an unset key, so the hook's fallback to its default is exercised
 * rather than assumed.
 */
function fakeHookGit(): string
{
    return 'if [ "$1" = config ]; then [ -n "$HOOK_GATE" ] || exit 1; printf "%s\\n" "$HOOK_GATE"; exit 0; fi; printf "%s\\n" "$HOOK_ROOT"';
}

/**
 * Run pre-push in a sandbox whose composer records each invocation.
 *
 * @param  array<string, string>  $environment
 * @return array{status: int, output: string, composer: list<string>}
 */
function runPushGate(array $environment = []): array
{
    $sandbox = fakeHookCommands([
        'git' => fakeHookGit(),
        'php' => 'exit 0',
        'composer' => 'printf "%s\\n" "$*" >> "$HOOK_COMPOSER_LOG"; exit 0',
        'uname' => 'printf "Linux\\n"',
        'npm' => 'exit 0',
        'npm.cmd' => PHP_OS_FAMILY === 'Windows' ? 'exit /b 0' : 'exit 0',
    ]);
    $log = $sandbox['root'].'/composer.log';

    try {
        $result = runHook([
            'PATH' => $sandbox['bin'].PATH_SEPARATOR.(string) getenv('PATH'),
            'HOOK_ROOT' => Repo::root(),
            'HOOK_COMPOSER_LOG' => $log,
            ...$environment,
        ]);

        $calls = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return [...$result, 'composer' => $calls === false ? [] : $calls];
    } finally {
        removeHookSandbox($sandbox['root']);
    }
}

function removeHookSandbox(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

/*
|--------------------------------------------------------------------------
| The push gate switch (owner decision, 2026-10-09)
|--------------------------------------------------------------------------
|
| The full suite runs once, in CI. Pre-push runs `composer verify:push` by
| default, and a clone can opt back into the full gate with one git config key.
| These drive the real hook through a recording composer, so they prove which
| gate runs, not that the hook's text mentions it — `composer verify:push`
| contains the substring `composer verify`, which is exactly how a text check
| would agree with either policy.
*/

it('runs the fast push gate by default and never the full suite', function () {
    $result = runPushGate();

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['composer'])->toBe(['verify:push']);
});

it('runs the full suite when a clone opts back in', function () {
    $result = runPushGate(['HOOK_GATE' => 'full']);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['composer'])->toBe(['verify']);
});

it('accepts fast set explicitly', function () {
    $result = runPushGate(['HOOK_GATE' => 'fast']);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['composer'])->toBe(['verify:push']);
});

it('refuses an unrecognised gate value without running either gate', function () {
    $result = runPushGate(['HOOK_GATE' => 'Full']);

    expect($result['status'])->not->toBe(0)
        ->and($result['composer'])->toBe([])
        ->and($result['output'])->toContain('expected fast or full');
});

it('defines the push gate as validation plus the fast checks, and nothing else', function () {
    $scripts = json_decode((string) file_get_contents(Repo::root().'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['scripts'];

    expect($scripts['verify:push'])->toBe(['@composer validate --strict', '@verify:fast'])
        ->and($scripts['verify'])->toContain('@composer validate --strict', '@verify:fast', '@test:serial');
});

it('uses the Windows npm command when pre-push runs under Git for Windows', function () {
    $sandbox = fakeHookCommands([
        'git' => fakeHookGit(),
        'php' => 'exit 0',
        'composer' => 'exit 0',
        'uname' => 'printf "MINGW64_NT-10.0\\n"',
        'npm' => 'printf "npm" > "$HOOK_MARKER"; exit 91',
        'npm.cmd' => PHP_OS_FAMILY === 'Windows'
            ? '<nul set /p="npm.cmd %1 %2" > "%HOOK_MARKER%" & exit /b 0'
            : 'printf "npm.cmd %s %s" "$1" "$2" > "$HOOK_MARKER"; exit 0',
    ]);

    try {
        $result = runHook([
            'PATH' => $sandbox['bin'].PATH_SEPARATOR.(string) getenv('PATH'),
            'HOOK_ROOT' => Repo::root(),
            'HOOK_MARKER' => $sandbox['marker'],
        ]);

        expect($result['status'])->toBe(0, $result['output'])
            ->and(file_get_contents($sandbox['marker']))->toBe('npm.cmd run build');
    } finally {
        removeHookSandbox($sandbox['root']);
    }
});

it('keeps using the standard npm command on Unix', function () {
    $sandbox = fakeHookCommands([
        'git' => fakeHookGit(),
        'php' => 'exit 0',
        'composer' => 'exit 0',
        'uname' => 'printf "Linux\\n"',
        'npm' => 'printf "npm %s %s" "$1" "$2" > "$HOOK_MARKER"; exit 0',
        'npm.cmd' => PHP_OS_FAMILY === 'Windows'
            ? 'exit /b 91'
            : 'exit 91',
    ]);

    try {
        $result = runHook([
            'PATH' => $sandbox['bin'].PATH_SEPARATOR.(string) getenv('PATH'),
            'HOOK_ROOT' => Repo::root(),
            'HOOK_MARKER' => $sandbox['marker'],
        ]);

        expect($result['status'])->toBe(0, $result['output'])
            ->and(file_get_contents($sandbox['marker']))->toBe('npm run build');
    } finally {
        removeHookSandbox($sandbox['root']);
    }
});
