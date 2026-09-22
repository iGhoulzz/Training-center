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

    $candidates = [
        $gitRoot.'/bin/sh.exe',
        $gitRoot.'/usr/bin/sh.exe',
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

it('uses the Windows npm command when pre-push runs under Git for Windows', function () {
    $sandbox = fakeHookCommands([
        'git' => 'printf "%s\\n" "$HOOK_ROOT"',
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
        'git' => 'printf "%s\\n" "$HOOK_ROOT"',
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
