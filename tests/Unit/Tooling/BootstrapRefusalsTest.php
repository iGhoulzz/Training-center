<?php

declare(strict_types=1);

use Tooling\Process;
use Tooling\Repo;
use Tooling\TestDatabase;

/*
|--------------------------------------------------------------------------
| What tests/bootstrap.php refuses to run against
|--------------------------------------------------------------------------
|
| Each refusal exists because the configuration would let this process CREATE
| and LOCK one database while Laravel connected to another — and then rebuild
| whatever it reached, which can be `training_center` itself.
|
| They can only be observed from outside: by the time any test runs, the
| bootstrap has already decided. So each case starts a real child run, the way
| `refuses parallel execution explicitly` does, and asserts the child died with
| the reason rather than proceeding.
|
| The child is given a --filter matching nothing. A refusal must happen before
| any test would, so "no tests executed" and "refused" are distinguishable only
| by the exit status and the message, which is exactly what is asserted.
*/

/**
 * @param  array<string, string>  $environment
 * @return array{status: int, output: string}
 */
function runBootstrapWith(array $environment): array
{
    $restore = [];

    /*
     * A FAILING REFUSAL MUST FAIL, NOT HANG.
     *
     * Without this the child resolves the same database as the suite running it,
     * so a refusal that does not fire leaves the child blocked on the lock this
     * process holds — forever, with no output. The first version did exactly that.
     *
     * Naming a database of its own gives the child a different lock. It never
     * connects to it and never creates it: a value other than the shared literal
     * is a deliberate selection, which the bootstrap leaves alone, and the filter
     * below matches no test.
     */
    $environment += ['DB_DATABASE' => 'training_center_test_bootstrap_probe'];

    foreach ($environment as $name => $value) {
        $existing = getenv($name);
        $restore[$name] = is_string($existing) ? $existing : null;
        putenv("{$name}={$value}");
    }

    try {
        return Process::capture([
            PHP_BINARY,
            Repo::root().'/vendor/bin/pest',
            '--configuration='.Repo::root().'/phpunit.xml',
            '--filter=a-test-that-does-not-exist',
        ], Repo::root());
    } finally {
        foreach ($restore as $name => $value) {
            $value === null ? putenv($name) : putenv("{$name}={$value}");
        }
    }
}

it('refuses to run when a cached configuration would outrank the environment', function () {
    /*
     * THE PATH IS RELATIVE ON PURPOSE, AND THE FIRST VERSION OF THIS TEST WAS
     * WRONG FOR IT.
     *
     * It passed a Windows absolute path, `C:/…/Temp/…`. Laravel — and therefore
     * the guard that mirrors it — counts only a leading `/` or `\` as absolute,
     * so that value resolves against the project root, the child found no cache,
     * did not refuse, and blocked on the lock its parent was holding. A test that
     * hangs rather than fails is the worst way to learn this.
     *
     * A root-relative path is what Laravel resolves identically on both
     * platforms, so it is what this uses.
     */
    $relative = 'storage/framework/testing/config-cache-'.bin2hex(random_bytes(6)).'.php';
    $cache = Repo::root().'/'.$relative;

    if (! is_dir(dirname($cache))) {
        mkdir(dirname($cache), recursive: true);
    }

    file_put_contents($cache, '<?php return [];');

    try {
        $result = runBootstrapWith(['APP_CONFIG_CACHE' => $relative]);

        expect($result['status'])->not->toBe(0)
            ->and($result['output'])->toContain('A cached configuration is present');
    } finally {
        @unlink($cache);
    }
});

it('refuses to run when DB_URL would outrank the resolved database', function () {
    $result = runBootstrapWith(['DB_URL' => 'mysql://root@127.0.0.1:3306/some_other_database']);

    expect($result['status'])->not->toBe(0)
        ->and($result['output'])->toContain('DB_URL is set');
});

it('resolves the config cache path the way Laravel does', function (string|false|null $override, string $expected) {
    /*
     * The expectation is written out rather than derived, and it reproduces
     * Laravel's Windows quirk deliberately: `C:\…` does not start with / or \,
     * so Laravel treats it as relative to the project root. A "corrected"
     * version here would check a file Laravel never reads.
     */
    expect(TestDatabase::cachedConfigPath('C:/project', $override))->toBe($expected);
})->with([
    'unset' => [false, 'C:/project/bootstrap/cache/config.php'],
    'empty' => ['', 'C:/project/bootstrap/cache/config.php'],
    'absolute posix' => ['/var/cache/config.php', '/var/cache/config.php'],
    'relative' => ['storage/config.php', 'C:/project/storage/config.php'],
    'windows drive letter, relative to Laravel' => ['C:\\cache\\config.php', 'C:/project/C:\\cache\\config.php'],
]);
