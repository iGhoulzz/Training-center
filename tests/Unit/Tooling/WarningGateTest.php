<?php

declare(strict_types=1);

use Tooling\Repo;

/*
|--------------------------------------------------------------------------
| The warning gate, proven rather than configured
|--------------------------------------------------------------------------
|
| P2-T00C turned PHPUnit warnings into failures, after CI spent a phase and a
| half reporting "success" on runs where 1347 of 1407 tests were warnings. A
| configuration change is exactly the kind of protection this repository refuses
| to take on trust: docs/ENGINEERING.md requires a guard to be watched failing,
| and a phpunit.xml attribute nobody has seen bite is a claim, not a gate.
|
| SO THE ATTRIBUTES ARE READ OUT OF THE REAL FILE, NOT MIRRORED HERE.
| A copy of the configuration would drift from the original and keep passing
| while the original lost the flag — the same failure mode as a hand-maintained
| list. The child run below is built from phpunit.xml's own root attributes, so
| deleting failOnWarning there makes the probe stop failing and this test go red.
|
| WHY A CHILD PROCESS, AND WHY IT CANNOT USE tests/bootstrap.php.
| The property under test is the exit code of a whole run, which no assertion
| inside a run can observe. The child therefore gets the real attributes but NOT
| the bootstrap: tests/bootstrap.php takes the machine-wide database lock this
| very process is already holding, so inheriting it would deadlock the suite
| against itself. The probe needs no database — it triggers a warning and
| asserts true.
*/

/**
 * The root element's attributes from the project's real phpunit.xml.
 *
 * @return array<string, string>
 */
function projectPhpunitAttributes(): array
{
    $xml = simplexml_load_file(Repo::root().'/phpunit.xml');

    expect($xml)->not->toBeFalse('phpunit.xml did not parse.');

    $attributes = [];

    foreach ($xml->attributes() ?? [] as $name => $value) {
        $attributes[(string) $name] = (string) $value;
    }

    return $attributes;
}

/**
 * A throwaway directory holding a phpunit.xml and one probe test.
 *
 * @param  array<string, string>  $attributes  Root attributes for the child config.
 * @return string The directory, which the caller removes.
 */
function makeWarningProbe(array $attributes, string $probeBody): string
{
    $directory = sys_get_temp_dir().'/warning-gate-'.bin2hex(random_bytes(6));

    mkdir($directory);

    /*
     * bootstrap is dropped for the deadlock reason in this file's header.
     * cacheDirectory and any source/coverage paths are relative to the project
     * root and meaningless here, so only the behavioural attributes survive.
     */
    unset($attributes['bootstrap'], $attributes['cacheDirectory']);

    $rendered = '';

    foreach ($attributes as $name => $value) {
        $rendered .= "\n         {$name}=\"{$value}\"";
    }

    file_put_contents($directory.'/phpunit.xml', <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit{$rendered}
        >
            <testsuites>
                <testsuite name="Probe">
                    <directory>.</directory>
                </testsuite>
            </testsuites>
        </phpunit>
        XML);

    file_put_contents($directory.'/ProbeTest.php', <<<PHP
        <?php

        declare(strict_types=1);

        use PHPUnit\\Framework\\TestCase;

        final class ProbeTest extends TestCase
        {
            public function testProbe(): void
            {
                {$probeBody}

                \$this->assertTrue(true);
            }
        }
        PHP);

    return $directory;
}

/**
 * Run PHPUnit over a probe directory.
 *
 * @return array{exitCode: int, output: string}
 */
function runWarningProbe(string $directory): array
{
    $process = proc_open(
        [PHP_BINARY, Repo::root().'/vendor/phpunit/phpunit/phpunit', '-c', $directory.'/phpunit.xml'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $directory,
    );

    expect($process)->toBeResource('Could not start the child PHPUnit process.');

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'output' => (string) $output];
}

/**
 * Remove the probe directory and everything the child run left in it.
 *
 * RECURSIVE, AND NOT glob(). The child PHPUnit writes a .phpunit.cache
 * directory beside the config, which glob() does not return — it skips
 * dot-entries — and rmdir() then fails on a non-empty directory. That failure
 * arrived as a PHP warning, which this branch's own gate turned into a test
 * failure: the first thing failOnWarning caught was this file.
 */
function removeWarningProbe(string $directory): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

/*
|--------------------------------------------------------------------------
| The configuration this depends on, pinned
|--------------------------------------------------------------------------
*/

it('keeps the warning gate and the issue details switched on in phpunit.xml', function () {
    $attributes = projectPhpunitAttributes();

    expect($attributes['failOnWarning'] ?? null)->toBe(
        'true',
        'phpunit.xml stopped failing on warnings. That is how 1347 of them went unnoticed.',
    );

    /*
     * displayDetailsOnAllIssues rather than the per-category flags, and the
     * distinction is the point: PHPUnit separates issues IT raises from issues
     * the code under test raises, so a list of displayDetailsOnTestsThatTrigger*
     * flags leaves displayDetailsOnPhpunitNotices and
     * displayDetailsOnPhpunitDeprecations false while reading as complete.
     */
    expect($attributes['displayDetailsOnAllIssues'] ?? null)->toBe(
        'true',
        'Without this, an issue is a badge with no message and no file:line.',
    );
});

it('creates the CI .env before anything that boots Laravel', function () {
    /*
     * THE OTHER HALF OF THE SAME FIX, AND ORDER IS THE WHOLE POINT.
     *
     * The warnings were phpdotenv reading a .env that CI did not have. The file
     * has to exist before the first step that boots the application, and that is
     * NOT the test run — `composer install` triggers package discovery, which
     * boots Laravel, as this workflow's own comment records. A step that created
     * the file after the install would look right and fix nothing.
     */
    $ci = (string) file_get_contents(Repo::root().'/.github/workflows/ci.yml');

    $envStep = strpos($ci, 'run: touch .env');
    $composerInstall = strpos($ci, 'run: composer install');
    $verify = strpos($ci, 'run: composer verify');

    expect($envStep)->not->toBeFalse(
        'CI no longer creates a .env, so every application boot warns again — 1347 of them last time.',
    );

    expect($composerInstall)->not->toBeFalse()
        ->and($verify)->not->toBeFalse();

    expect($envStep)->toBeLessThan(
        $composerInstall,
        'The .env step must precede composer install, which boots Laravel through package discovery.',
    );

    expect($envStep)->toBeLessThan($verify);
});

/*
|--------------------------------------------------------------------------
| The gate, watched failing and watched not failing
|--------------------------------------------------------------------------
*/

it('fails a run in which a test triggers a warning', function () {
    $directory = makeWarningProbe(
        projectPhpunitAttributes(),
        "trigger_error('deliberate probe warning', E_USER_WARNING);",
    );

    try {
        $result = runWarningProbe($directory);
    } finally {
        removeWarningProbe($directory);
    }

    expect($result['exitCode'])->not->toBe(
        0,
        "A test that triggers a warning exited 0. The gate is not gating.\n".$result['output'],
    );

    // displayDetailsOnAllIssues, proven the same way: the message reaches the
    // output rather than only a count.
    expect($result['output'])->toContain('deliberate probe warning');
});

it('passes the same probe once failOnWarning is removed, so the failure above is the gate and nothing else', function () {
    /*
     * THE MUTATION. Without this case the test above proves only that a run
     * containing a warning exits non-zero — which a syntax error, a missing
     * autoloader or a mistyped path would also produce. Flipping the one
     * attribute and watching the identical probe pass is what attributes the
     * failure to failOnWarning.
     */
    $attributes = projectPhpunitAttributes();
    $attributes['failOnWarning'] = 'false';

    $directory = makeWarningProbe(
        $attributes,
        "trigger_error('deliberate probe warning', E_USER_WARNING);",
    );

    try {
        $result = runWarningProbe($directory);
    } finally {
        removeWarningProbe($directory);
    }

    expect($result['exitCode'])->toBe(
        0,
        "The probe fails even with failOnWarning off, so the other case proves nothing about the gate.\n".$result['output'],
    );
});
