<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| The production deploy workflow's trust boundary
|--------------------------------------------------------------------------
|
| .github/workflows/deploy.yml is the only thing that may reach the production
| host, and deploy/receive.sh is the only thing that host will accept from it.
| These tests pin the properties that keep a red, absent or superseded commit
| away from that host, and the key and the archive from being usable for
| anything else. Every expected literal below is written by hand; none is read
| back from the file it checks.
|
| The guard, CI check, tip check and packaging steps are also EXECUTED: their
| `run:` scripts are lifted out of the workflow and run under bash with a stub
| `gh`, so "a failed CI run stops the deploy" is observed, not only read.
*/

const DEPLOY_SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
const DEPLOY_OTHER_SHA = 'ffffffffffffffffffffffffffffffffffffffff';

function deployRoot(): string
{
    return dirname(__DIR__, 3);
}

function deployWorkflowSource(): string
{
    return file_get_contents(deployRoot().'/.github/workflows/deploy.yml');
}

/** @return array<string, mixed> */
function deployWorkflow(): array
{
    return Yaml::parse(deployWorkflowSource());
}

/** @return list<array<string, mixed>> */
function deploySteps(string $job): array
{
    return deployWorkflow()['jobs'][$job]['steps'];
}

/** @return array<string, mixed> */
function deployStep(string $job, string $name): array
{
    foreach (deploySteps($job) as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    throw new RuntimeException("No step named '{$name}' in job '{$job}'.");
}

function deployScript(string $job, string $name): string
{
    return deployStep($job, $name)['run'];
}

/** Position of a step in its job, so ordering can be asserted. */
function deployPosition(string $job, string $name): int
{
    foreach (deploySteps($job) as $index => $step) {
        if (($step['name'] ?? null) === $name) {
            return $index;
        }
    }

    throw new RuntimeException("No step named '{$name}' in job '{$job}'.");
}

/** A shell command with its backslash line continuations joined. */
function deployJoined(string $script): string
{
    return preg_replace('/[ \t]*\\\\\R\s*/', ' ', $script);
}

function deployBash(): string
{
    return PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash';
}

/** GNU tar reads `C:/x` as host `C`; Git Bash wants `/c/x`. */
function deployPosix(string $path): string
{
    $path = str_replace('\\', '/', $path);

    return PHP_OS_FAMILY === 'Windows' ? preg_replace_callback('#^([A-Za-z]):#', fn (array $m): string => '/'.strtolower($m[1]), $path) : $path;
}

function deployRemoveTree(string $path): void
{
    if (! is_dir($path) || is_link($path)) {
        @unlink($path);

        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            deployRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

function deployTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir().'/deploy-workflow-'.bin2hex(random_bytes(6));
    mkdir($directory);

    return $directory;
}

/**
 * Runs a lifted workflow script under bash in a throwaway directory.
 *
 * @param  array<string, string>  $environment
 * @return array{0: int, 1: string, 2: string} exit code, combined output, directory
 */
function deployRunBash(string $script, array $environment = [], ?string $directory = null): array
{
    $directory ??= deployTemporaryDirectory();
    file_put_contents($directory.'/step.sh', str_replace("\r\n", "\n", $script));
    $process = new Process([deployBash(), $directory.'/step.sh'], $directory, $environment + ['MSYS_NO_PATHCONV' => '1']);
    $process->setTimeout(120)->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput(), $directory];
}

/** A stub `gh` that records its arguments and answers from MOCK_* variables. */
function deployGhStub(): string
{
    return <<<'SH'
    set -euo pipefail
    gh() {
        printf '%s\n' "$*" >> "$GH_CALLS"
        case "$*" in
            *"/jobs"*) printf '%s\n' "${MOCK_VERIFY_COUNT:-0}" ;;
            *"actions/workflows/ci.yml/runs"*) printf '%s' "${MOCK_RUN_ID:-}" ;;
            *"git/ref/heads/main"*) printf '%s' "${MOCK_TIP:-}" ;;
        esac
    }
    SH."\n";
}

/**
 * @param  array<string, string>  $environment
 * @return array{0: int, 1: string, 2: string}
 */
function deployRunWithGh(string $script, array $environment): array
{
    $directory = deployTemporaryDirectory();
    touch($directory.'/gh-calls');
    touch($directory.'/github-output');

    return deployRunBash(deployGhStub().$script, $environment + [
        'GH_CALLS' => $directory.'/gh-calls',
        'GITHUB_OUTPUT' => $directory.'/github-output',
        'GH_REPO' => 'example/training-center',
        'GH_TOKEN' => 'not-a-real-token',
    ], $directory);
}

/** One raw ustar entry, so link members can be built where symlink() is not permitted. */
function deployTarEntry(string $name, string $type, string $content = '', string $linkName = ''): string
{
    $header = str_pad($name, 100, "\0")
        .sprintf('%07o', 0644)."\0".sprintf('%07o', 0)."\0".sprintf('%07o', 0)."\0"
        .sprintf('%011o', strlen($content))."\0".sprintf('%011o', 0)."\0"
        .'        '.$type.str_pad($linkName, 100, "\0").'ustar'."\0".'00'.str_pad('', 247, "\0");
    $checksum = array_sum(unpack('C*', $header));
    $header = substr($header, 0, 148).sprintf('%06o', $checksum)."\0 ".substr($header, 156);

    return $header.str_pad($content, (int) (ceil(strlen($content) / 512) * 512), "\0");
}

/** @param  list<string>  $entries */
function deployWriteArchive(string $path, array $entries): void
{
    file_put_contents($path, gzencode(implode('', $entries).str_repeat("\0", 1024)));
}

// ---------------------------------------------------------------------------
// Triggers and permissions
// ---------------------------------------------------------------------------

it('is started by hand only and never by a push, a pull request, a schedule or another workflow', function (): void {
    $workflow = deployWorkflow();
    $triggers = $workflow['on'] ?? $workflow[true];

    expect(array_keys($triggers))->toBe(['workflow_dispatch']);

    foreach (['pull_request', 'push:', 'schedule:', 'workflow_run:', 'workflow_call:', 'repository_dispatch:'] as $forbidden) {
        expect(deployWorkflowSource())->not->toContain($forbidden);
    }
});

it('grants the token read access only, and actions:read only to the job that reads CI runs', function (): void {
    $workflow = deployWorkflow();

    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['jobs']['guard']['permissions'])->toBe(['contents' => 'read', 'actions' => 'read']);
    expect($workflow['jobs']['build']['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['jobs']['deploy']['permissions'])->toBe(['contents' => 'read']);
    expect(deployWorkflowSource())->not->toMatch('/:\s*write\b/');
});

it('has exactly a guard, a build and a deploy job, chained so a refusal stops everything after it', function (): void {
    $jobs = deployWorkflow()['jobs'];

    expect(array_keys($jobs))->toBe(['guard', 'build', 'deploy']);
    expect($jobs['guard'])->not->toHaveKey('needs');
    expect($jobs['build']['needs'])->toBe('guard');
    expect($jobs['deploy']['needs'])->toBe(['guard', 'build']);
});

it('never interpolates an expression into a shell script', function (): void {
    foreach (deployWorkflow()['jobs'] as $job => $definition) {
        foreach ($definition['steps'] as $step) {
            if (isset($step['run'])) {
                expect($step['run'])->not->toContain('${{', "{$job}: {$step['name']}");
            }
        }
    }
});

// ---------------------------------------------------------------------------
// Guard: main only, and a green CI run for that exact SHA
// ---------------------------------------------------------------------------

it('refuses every ref but refs/heads/main', function (string $ref, bool $accepted): void {
    [$code, $output] = deployRunBash(deployScript('guard', 'Refuse any ref but main'), ['REF' => $ref]);

    expect($code === 0)->toBe($accepted, $output);
    expect(deployStep('guard', 'Refuse any ref but main')['env']['REF'])->toBe('${{ github.ref }}');
})->with([
    'main' => ['refs/heads/main', true],
    'a feature branch' => ['refs/heads/feature/x', false],
    'a branch that only starts with main' => ['refs/heads/main-hotfix', false],
    'a branch that only ends with main' => ['refs/heads/not-main', false],
    'a tag' => ['refs/tags/main', false],
    'a pull request ref' => ['refs/pull/1/merge', false],
    'empty' => ['', false],
]);

it('resolves the SHA of the dispatch itself and refuses anything that is not a full lowercase SHA', function (string $sha, bool $accepted): void {
    [$code, $output, $directory] = deployRunWithGh(deployScript('guard', 'Resolve the exact SHA'), ['SHA' => $sha]);

    expect($code === 0)->toBe($accepted, $output);
    expect(file_get_contents($directory.'/github-output'))->toBe($accepted ? "sha={$sha}\n" : '');
    deployRemoveTree($directory);
})->with([
    'a full SHA' => [DEPLOY_SHA, true],
    'a short SHA' => ['a1b2c3d', false],
    'an uppercase SHA' => ['A1B2C3D4E5F60718293A4B5C6D7E8F9012345678', false],
    'a SHA with a suffix' => [DEPLOY_SHA.'0', false],
    'a branch name' => ['main', false],
    'empty' => ['', false],
]);

it('takes the SHA from the dispatching commit and exports it as the guard output', function (): void {
    expect(deployStep('guard', 'Resolve the exact SHA')['env']['SHA'])->toBe('${{ github.sha }}');
    expect(deployWorkflow()['jobs']['guard']['outputs'])->toBe(['sha' => '${{ steps.resolve.outputs.sha }}']);
    expect(deployStep('guard', 'Resolve the exact SHA')['id'])->toBe('resolve');
});

it('requires a successful ci.yml verify run for that exact SHA on main, and stops on anything else', function (string $runId, string $verified, bool $accepted): void {
    [$code, $output, $directory] = deployRunWithGh(deployScript('guard', 'Require a green CI run for that SHA'), [
        'SHA' => DEPLOY_SHA, 'MOCK_RUN_ID' => $runId, 'MOCK_VERIFY_COUNT' => $verified,
    ]);
    $calls = file_get_contents($directory.'/gh-calls');

    expect($code === 0)->toBe($accepted, $output);
    expect($calls)->toContain('actions/workflows/ci.yml/runs', 'head_sha='.DEPLOY_SHA, 'branch=main');
    if (! $accepted) {
        expect($output)->toContain('::error::');
    }
    if ($runId === '') {
        expect($calls)->not->toContain('/jobs');
    }
    deployRemoveTree($directory);
})->with([
    'no run for the SHA (missing, pending, failed or cancelled)' => ['', '0', false],
    'a run, but its verify job did not succeed' => ['123456', '0', false],
    'a run whose verify job succeeded' => ['123456', '1', true],
]);

it('filters CI runs by SHA, branch, conclusion and trigger, and checks the verify job', function (): void {
    $script = deployJoined(deployScript('guard', 'Require a green CI run for that SHA'));

    expect($script)->toContain(
        'head_sha=${SHA}',
        'branch=main',
        'status=success',
        '.head_sha == "\'"$SHA"\'"',
        '.head_branch == "main"',
        '.conclusion == "success"',
        '(.event == "push" or .event == "workflow_dispatch")',
        '.name == "verify"',
    );
    expect(deployStep('guard', 'Require a green CI run for that SHA')['env'])->toBe([
        'GH_TOKEN' => '${{ github.token }}',
        'GH_REPO' => '${{ github.repository }}',
        'SHA' => '${{ steps.resolve.outputs.sha }}',
    ]);
});

it('keeps the guard free of code, secrets and the production environment', function (): void {
    $guard = deployWorkflow()['jobs']['guard'];

    expect($guard)->not->toHaveKey('environment');
    expect(json_encode($guard))->not->toContain('actions/checkout', 'secrets.', 'ssh');
});

// ---------------------------------------------------------------------------
// Build: an ephemeral, secret-free, non-production build of that exact SHA
// ---------------------------------------------------------------------------

it('builds without secrets and outside the production environment', function (): void {
    $build = deployWorkflow()['jobs']['build'];

    expect($build)->not->toHaveKey('environment');
    expect(json_encode($build))->not->toContain('secrets.', 'vars.', 'PROD_SSH');
    expect($build['runs-on'])->toBe('ubuntu-latest');
});

it('checks out the guarded SHA and not whatever main has become', function (): void {
    $checkout = deployStep('build', 'Check out the exact SHA');

    expect($checkout['uses'])->toBe('actions/checkout@v4');
    expect($checkout['with'])->toBe(['ref' => '${{ needs.guard.outputs.sha }}', 'persist-credentials' => false]);
    expect(deployScript('build', 'Confirm the checkout is that SHA'))->toContain('git rev-parse HEAD');
});

it('boots Laravel at install time only as a disposable testing application', function (): void {
    $environment = deployWorkflow()['jobs']['build']['env'];

    expect($environment)->toMatchArray([
        'SHA' => '${{ needs.guard.outputs.sha }}',
        'APP_ENV' => 'testing',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ]);
    foreach (['BACKUP_ALERT_EMAIL', 'HORIZON_ALERT_EMAIL', 'MAIL_FROM_ADDRESS'] as $variable) {
        expect(filter_var($environment[$variable], FILTER_VALIDATE_EMAIL))->not->toBeFalse($variable);
    }
    expect($environment)->not->toHaveKey('APP_KEY');

    $key = deployScript('build', 'Generate a disposable APP_KEY');
    expect($key)->toContain('openssl rand -base64 32', 'APP_KEY=base64:', '>> "$GITHUB_ENV"');
    expect(deployPosition('build', 'Generate a disposable APP_KEY'))->toBeLessThan(deployPosition('build', 'Install locked production dependencies'));
});

it('uses the same PHP and extensions as CI', function (): void {
    $php = deployStep('build', 'Set up PHP');

    expect($php['uses'])->toBe('shivammathur/setup-php@v2');
    expect($php['with'])->toMatchArray([
        'php-version' => '8.4',
        'extensions' => 'mbstring, pdo_mysql, zip, intl, bcmath, gd, redis',
        'coverage' => 'none',
        'tools' => 'composer:v2',
    ]);
});

it('installs locked production dependencies with Composer scripts enabled', function (): void {
    $script = deployScript('build', 'Install locked production dependencies');

    expect($script)->toContain('composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative');
    expect($script)->not->toContain('--no-scripts');
    expect(deployPosition('build', 'Install locked production dependencies'))->toBeLessThan(deployPosition('build', 'Build the Vite assets'));
});

it('builds the frontend from the lockfile', function (): void {
    expect(deployScript('build', 'Build the Vite assets'))->toBe("npm ci\nnpm run build\n");
    expect(deployStep('build', 'Set up Node')['with'])->toMatchArray(['node-version' => '22']);
});

it('republishes the Filament assets and fails when any required output is absent', function (): void {
    $script = deployScript('build', 'Publish and verify the Filament assets');

    expect($script)->toContain('php artisan filament:assets', 'public/build/manifest.json', 'js:js css:css fonts:woff2', 'public/$directory/filament', '-name "*.$extension"', 'exit 1');
    expect(deployPosition('build', 'Publish and verify the Filament assets'))->toBeGreaterThan(deployPosition('build', 'Build the Vite assets'));
});

it('runs the Filament asset assertion against a tree that is missing each required output', function (string $missing): void {
    $directory = deployTemporaryDirectory();
    $files = ['public/build/manifest.json', 'public/js/filament/a.js', 'public/css/filament/a.css', 'public/fonts/filament/a.woff2'];
    foreach (array_diff($files, [$missing]) as $file) {
        @mkdir(dirname($directory.'/'.$file), 0777, true);
        file_put_contents($directory.'/'.$file, 'fixture');
    }
    // `php artisan filament:assets` is replaced by a no-op: this isolates the assertion.
    $script = str_replace('php artisan filament:assets', 'true', deployScript('build', 'Publish and verify the Filament assets'));
    [$code, $output] = deployRunBash('cd '.deployPosix($directory)."\n".$script, [], $directory);

    expect($code)->toBe(1, $output);
    deployRemoveTree($directory);
})->with([
    'the Vite manifest' => ['public/build/manifest.json'],
    'the Filament JavaScript' => ['public/js/filament/a.js'],
    'the Filament CSS' => ['public/css/filament/a.css'],
    'the Filament fonts' => ['public/fonts/filament/a.woff2'],
]);

it('accepts a tree that has every required asset', function (): void {
    $directory = deployTemporaryDirectory();
    foreach (['public/build/manifest.json', 'public/js/filament/a.js', 'public/css/filament/a.css', 'public/fonts/filament/a.woff2'] as $file) {
        @mkdir(dirname($directory.'/'.$file), 0777, true);
        file_put_contents($directory.'/'.$file, 'fixture');
    }
    $script = str_replace('php artisan filament:assets', 'true', deployScript('build', 'Publish and verify the Filament assets'));
    [$code, $output] = deployRunBash('cd '.deployPosix($directory)."\n".$script, [], $directory);

    expect($code)->toBe(0, $output);
    deployRemoveTree($directory);
});

it('strips runner-generated caches and writes the SHA manifest before packaging', function (): void {
    expect(deployScript('build', 'Remove runner-generated caches'))->toContain('rm -f bootstrap/cache/*.php', 'rm -f public/hot');
    expect(deployScript('build', 'Write the REVISION manifest'))->toBe("printf '%s\\n' \"\$SHA\" > REVISION");

    $order = ['Remove runner-generated caches', 'Write the REVISION manifest', 'Stage the release tree', 'Package the release', "Check the archive against release.sh's rules", 'Upload the release archive'];
    $positions = array_map(fn (string $name): int => deployPosition('build', $name), $order);
    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);
    expect(deployPosition('build', 'Publish and verify the Filament assets'))->toBeLessThan($positions[0]);
});

it('stages an explicit allowlist, so dev-only trees, secrets and links cannot be packaged', function (): void {
    $script = deployJoined(deployScript('build', 'Stage the release tree'));

    expect($script)->toContain(' app artisan bootstrap composer.json composer.lock config database lang public resources routes vendor REVISION ');
    expect($script)->toContain(
        "--exclude='.env'",
        "--exclude='.env.*'",
        "--exclude='.git'",
        "--anchored --exclude='vendor/bin'",
        "--exclude='public/storage'",
        "--exclude='public/hot'",
        "--exclude='bootstrap/cache/*.php'",
    );
    preg_match('/ (app artisan [^|]+REVISION) /', $script, $allowlist);
    foreach (['tests', 'docs', 'node_modules', 'storage', '.github', '.git', '.env', 'deploy', 'scripts', 'docker', 'phpunit.xml', 'package.json'] as $leftOut) {
        expect(explode(' ', $allowlist[1]))->not->toContain($leftOut);
    }
    expect($script)->toContain('if [ -n "$(find "$stage" -type l -print -quit)" ]; then', 'exit 1');
});

it('packages a tar.gz without links, with no hard links, and records its checksum', function (): void {
    $script = deployJoined(deployScript('build', 'Package the release'));

    expect($script)->toContain('tar --hard-dereference', '-czf "$RUNNER_TEMP/release.tar.gz"', 'sha256sum');
    expect(deployWorkflow()['jobs']['build']['outputs'])->toBe(['archive_sha256' => '${{ steps.package.outputs.sha256 }}']);
    expect(deployStep('build', 'Package the release')['id'])->toBe('package');
});

it('checks the archive against the same forbidden members release.sh refuses', function (): void {
    $patterns = '.env|.env.*|.env/|.env/*|*/.env|*/.env.*|.git|.git/*|storage|storage/*|node_modules|node_modules/*|tests|tests/*|docs|docs/*|public/storage|public/storage/*';
    $script = deployScript('build', "Check the archive against release.sh's rules");

    expect($script)->toContain($patterns);
    expect(file_get_contents(deployRoot().'/deploy/release.sh'))->toContain($patterns);
    expect($script)->toContain('bootstrap/cache/.gitignore', 'exit 1');
});

it('uploads the archive as a short-lived artifact named for the SHA, failing if it is absent', function (): void {
    $upload = deployStep('build', 'Upload the release archive');

    expect($upload['uses'])->toBe('actions/upload-artifact@v4');
    expect($upload['with'])->toBe([
        'name' => 'release-${{ needs.guard.outputs.sha }}',
        'path' => '${{ runner.temp }}/release.tar.gz',
        'retention-days' => 1,
        'if-no-files-found' => 'error',
    ]);
});

// ---------------------------------------------------------------------------
// Build output, observed: stage, package and validate a fixture tree
// ---------------------------------------------------------------------------

/** A repository-shaped tree: everything the release needs, and one of every thing it must leave out. */
function deployFixtureTree(string $directory): void
{
    $files = [
        'app/Models/User.php', 'artisan', 'bootstrap/app.php', 'bootstrap/cache/.gitignore', 'bootstrap/cache/packages.php',
        'bootstrap/cache/services.php', 'composer.json', 'composer.lock', 'config/app.php', 'database/migrations/m.php',
        'lang/en/messages.php', 'public/index.php', 'public/build/manifest.json', 'public/js/filament/a.js',
        'public/css/filament/a.css', 'public/fonts/filament/a.woff2', 'public/hot', 'public/storage/upload.txt',
        'resources/views/welcome.blade.php', 'routes/web.php', 'vendor/autoload.php', 'vendor/bin/pest',
        'vendor/pkg/src/A.php', 'vendor/pkg/.env.example', 'vendor/pkg/tests/ATest.php',
        '.env', '.env.production', '.env.example', '.git/HEAD', '.github/workflows/ci.yml', 'tests/Unit/X.php',
        'docs/README.md', 'node_modules/x/index.js', 'storage/logs/laravel.log', 'deploy/release.sh', 'scripts/bin/x.php',
        'docker/dev-linux/Dockerfile', 'phpunit.xml', 'package.json', 'README.md', 'REVISION',
    ];
    foreach ($files as $file) {
        @mkdir(dirname($directory.'/'.$file), 0777, true);
        file_put_contents($directory.'/'.$file, $file === 'REVISION' ? DEPLOY_SHA."\n" : 'fixture '.$file);
    }
}

/** @return array{0: int, 1: string, 2: string} */
function deployRunPackaging(string $directory, array $steps = ['Stage the release tree', 'Package the release']): array
{
    $runner = $directory.'/runner';
    @mkdir($runner);
    $script = 'cd '.deployPosix($directory.'/repo')."\n";
    foreach ($steps as $name) {
        $script .= deployScript('build', $name)."\n";
    }

    return deployRunBash($script, [
        'RUNNER_TEMP' => deployPosix($runner),
        'SHA' => DEPLOY_SHA,
        'GITHUB_OUTPUT' => $runner.'/github-output',
        'GITHUB_STEP_SUMMARY' => $runner.'/step-summary',
    ], $directory);
}

/** @return list<string> */
function deployArchiveFiles(string $archive): array
{
    $process = new Process([deployBash(), '-c', 'tar -tzf '.escapeshellarg(deployPosix($archive)).' | grep -v "/$" | sed "s|^\./||" | sort']);
    $process->run();

    return array_values(array_filter(explode("\n", trim($process->getOutput()))));
}

it('packages exactly the runtime tree: nothing secret, dev-only, linked or generated', function (): void {
    $directory = deployTemporaryDirectory();
    mkdir($directory.'/repo');
    deployFixtureTree($directory.'/repo');
    [$code, $output] = deployRunPackaging($directory);

    expect($code)->toBe(0, $output);
    expect(deployArchiveFiles($directory.'/runner/release.tar.gz'))->toBe([
        'REVISION',
        'app/Models/User.php',
        'artisan',
        'bootstrap/app.php',
        'bootstrap/cache/.gitignore',
        'composer.json',
        'composer.lock',
        'config/app.php',
        'database/migrations/m.php',
        'lang/en/messages.php',
        'public/build/manifest.json',
        'public/css/filament/a.css',
        'public/fonts/filament/a.woff2',
        'public/index.php',
        'public/js/filament/a.js',
        'resources/views/welcome.blade.php',
        'routes/web.php',
        'vendor/autoload.php',
        'vendor/pkg/src/A.php',
        'vendor/pkg/tests/ATest.php',
    ]);
    expect(file_get_contents($directory.'/runner/github-output'))->toMatch('/^sha256=[0-9a-f]{64}$/m');

    // The same digest the operator must approve on the host is shown in the summary and the log.
    preg_match('/^sha256=([0-9a-f]{64})$/m', file_get_contents($directory.'/runner/github-output'), $digest);
    expect(hash_file('sha256', $directory.'/runner/release.tar.gz'))->toBe($digest[1]);
    $summary = file_get_contents($directory.'/runner/step-summary');
    expect($summary)->toContain($digest[1], DEPLOY_SHA, '/srv/training-center/shared/archive-approvals/'.DEPLOY_SHA);
    expect($output)->toContain('Release archive sha256: '.$digest[1]);
    deployRemoveTree($directory);
});

it('fails the build when the staged tree holds any symlink', function (): void {
    $directory = deployTemporaryDirectory();
    mkdir($directory.'/repo');
    deployFixtureTree($directory.'/repo');

    if (! @symlink($directory.'/repo/composer.json', $directory.'/repo/vendor/pkg/link')) {
        deployRemoveTree($directory);
        $this->markTestSkipped('This host cannot create symlinks; CI (Linux) runs this case.');
    }

    [$code, $output] = deployRunPackaging($directory);

    expect($code)->not->toBe(0, $output);
    expect($output)->toContain('::error::');
    expect(is_file($directory.'/runner/release.tar.gz'))->toBeFalse();
    deployRemoveTree($directory);
});

it('refuses an archive carrying a member release.sh would refuse', function (string $name, string $type, string $link): void {
    $directory = deployTemporaryDirectory();
    deployWriteArchive($directory.'/release.tar.gz', [
        deployTarEntry('./REVISION', '0', DEPLOY_SHA."\n"),
        deployTarEntry($name, $type, $type === '0' ? 'x' : '', $link),
    ]);
    [$code, $output] = deployRunBash(deployScript('build', "Check the archive against release.sh's rules"), ['RUNNER_TEMP' => deployPosix($directory), 'SHA' => DEPLOY_SHA], $directory);

    expect($code)->toBe(1, $output);
    expect($output)->toContain('::error::');
    deployRemoveTree($directory);
})->with([
    'a symlink' => ['vendor/bin/pest', '2', '../x'],
    'a hard link' => ['vendor/x', '1', 'REVISION'],
    'an env file' => ['.env', '0', ''],
    'a nested env file' => ['vendor/pkg/.env.example', '0', ''],
    'a git member' => ['.git/HEAD', '0', ''],
    'a test tree' => ['tests/ATest.php', '0', ''],
    'the docs' => ['docs/a.md', '0', ''],
    'node_modules' => ['node_modules/a.js', '0', ''],
    'storage' => ['storage/logs/laravel.log', '0', ''],
    'the public storage link target' => ['public/storage/a', '0', ''],
    'a generated cache' => ['bootstrap/cache/config.php', '0', ''],
    'path traversal' => ['../escape', '0', ''],
    'an absolute path' => ['/etc/passwd', '0', ''],
]);

it('refuses an archive whose REVISION names another commit', function (): void {
    $directory = deployTemporaryDirectory();
    deployWriteArchive($directory.'/release.tar.gz', [deployTarEntry('./REVISION', '0', DEPLOY_OTHER_SHA."\n")]);
    [$code, $output] = deployRunBash(deployScript('build', "Check the archive against release.sh's rules"), ['RUNNER_TEMP' => deployPosix($directory), 'SHA' => DEPLOY_SHA], $directory);

    expect($code)->toBe(1, $output);
    expect($output)->toContain('::error::');
    deployRemoveTree($directory);
});

it('accepts an archive made only of what release.sh accepts', function (): void {
    $directory = deployTemporaryDirectory();
    deployWriteArchive($directory.'/release.tar.gz', [
        deployTarEntry('./REVISION', '0', DEPLOY_SHA."\n"),
        deployTarEntry('bootstrap/cache/.gitignore', '0', '*'),
        deployTarEntry('vendor/pkg/tests/ATest.php', '0', 'x'),
    ]);
    [$code, $output] = deployRunBash(deployScript('build', "Check the archive against release.sh's rules"), ['RUNNER_TEMP' => deployPosix($directory), 'SHA' => DEPLOY_SHA], $directory);

    expect($code)->toBe(0, $output);
    deployRemoveTree($directory);
});

// ---------------------------------------------------------------------------
// Deploy: approval, serialisation, a second tip check, one pinned SSH call
// ---------------------------------------------------------------------------

it('runs only in the protected production environment, serialised and never cancelled mid-flight', function (): void {
    $workflow = deployWorkflow();

    expect($workflow['jobs']['deploy']['environment'])->toBe('production');
    expect($workflow['jobs']['deploy']['concurrency'])->toBe(['group' => 'production-deploy', 'cancel-in-progress' => false]);
    expect($workflow)->not->toHaveKey('concurrency');
    foreach (['guard', 'build'] as $job) {
        expect($workflow['jobs'][$job])->not->toHaveKey('environment');
        expect($workflow['jobs'][$job])->not->toHaveKey('concurrency');
    }
    expect(deployWorkflowSource())->not->toContain('cancel-in-progress: true');
});

it('references secrets and variables in the deploy job only, and only the ones it needs', function (): void {
    $workflow = deployWorkflow();

    foreach (['guard', 'build'] as $job) {
        expect(json_encode($workflow['jobs'][$job]))->not->toMatch('/secrets\.|vars\.|PROD_/');
    }
    preg_match_all('/\b(secrets|vars)\.([A-Z_]+)/', deployWorkflowSource(), $matches);
    $references = array_unique($matches[0]);
    sort($references);

    expect($references)->toBe([
        'secrets.PROD_SSH_HOST',
        'secrets.PROD_SSH_KEY',
        'secrets.PROD_SSH_PORT',
        'secrets.PROD_SSH_USER',
        'vars.PROD_SSH_KNOWN_HOSTS',
    ]);
    expect(deployWorkflowSource())->not->toContain('secrets: inherit');
});

it('uses only first-party actions in the job that holds the deploy key', function (): void {
    foreach (deploySteps('deploy') as $step) {
        if (isset($step['uses'])) {
            expect($step['uses'])->toStartWith('actions/');
        }
    }
});

it('verifies the downloaded archive against the checksum the build job reported', function (): void {
    $download = deployStep('deploy', 'Download the release archive');

    expect($download['uses'])->toBe('actions/download-artifact@v4');
    expect($download['with'])->toBe(['name' => 'release-${{ needs.guard.outputs.sha }}', 'path' => '${{ runner.temp }}/download']);

    $check = deployStep('deploy', 'Confirm the archive is the one that was built');
    expect($check['env'])->toBe(['EXPECTED_SHA256' => '${{ needs.build.outputs.archive_sha256 }}']);
    expect($check['run'])->toContain('sha256sum', 'exit 1');
});

it('stops when the downloaded archive does not match the reported checksum', function (string $expected, bool $accepted): void {
    $directory = deployTemporaryDirectory();
    mkdir($directory.'/download');
    file_put_contents($directory.'/download/release.tar.gz', 'archive-bytes');
    [$code, $output] = deployRunBash(deployScript('deploy', 'Confirm the archive is the one that was built'), [
        'RUNNER_TEMP' => deployPosix($directory), 'EXPECTED_SHA256' => $expected,
    ], $directory);

    expect($code === 0)->toBe($accepted, $output);
    deployRemoveTree($directory);
})->with([
    'the checksum of exactly these bytes' => ['0c982986710a026635603031674053ca851fc0e3ea760094a34f59b84f7f6da6', true],
    'the checksum of other bytes' => ['1c982986710a026635603031674053ca851fc0e3ea760094a34f59b84f7f6da6', false],
    'no checksum reported' => ['', false],
]);

it('confirms main still points at the SHA immediately before the transfer, and stops otherwise', function (string $tip, bool $accepted): void {
    [$code, $output, $directory] = deployRunWithGh(deployScript('deploy', 'Confirm main has not moved'), ['SHA' => DEPLOY_SHA, 'MOCK_TIP' => $tip]);

    expect($code === 0)->toBe($accepted, $output);
    expect(file_get_contents($directory.'/gh-calls'))->toContain('repos/example/training-center/git/ref/heads/main');
    if (! $accepted) {
        expect($output)->toContain('::error::');
    }
    deployRemoveTree($directory);
})->with([
    'main is still the SHA' => [DEPLOY_SHA, true],
    'main has moved on' => [DEPLOY_OTHER_SHA, false],
    'the API gave no answer' => ['', false],
]);

it('orders the deploy so the key is installed and the tip checked only just before the one SSH call', function (): void {
    $order = [
        'Download the release archive',
        'Confirm the archive is the one that was built',
        'Install the deploy key and the pinned host key',
        'Confirm main has not moved',
        'Stream the release to the host',
        'Remove the key material',
    ];
    $positions = array_map(fn (string $name): int => deployPosition('deploy', $name), $order);
    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);
    expect($positions[4] - $positions[3])->toBe(1);
    expect(deployStep('deploy', 'Remove the key material')['if'])->toBe('always()');
});

it('pins the host key from the protected variable and never scans for one', function (): void {
    $install = deployStep('deploy', 'Install the deploy key and the pinned host key');

    expect(deployWorkflowSource())->not->toContain('ssh-keyscan');
    expect($install['env'])->toBe([
        'PROD_SSH_KEY' => '${{ secrets.PROD_SSH_KEY }}',
        'KNOWN_HOSTS' => '${{ vars.PROD_SSH_KNOWN_HOSTS }}',
    ]);
    expect($install['run'])->toContain('umask 077', 'chmod 600', 'exit 1');
    expect($install['run'])->not->toContain('ssh-keyscan');
});

it('refuses to run with an empty key or an empty pinned host key', function (string $key, string $hosts): void {
    $directory = deployTemporaryDirectory();
    [$code, $output] = deployRunBash(deployScript('deploy', 'Install the deploy key and the pinned host key'), [
        'PROD_SSH_KEY' => $key, 'KNOWN_HOSTS' => $hosts, 'RUNNER_TEMP' => deployPosix($directory), 'GITHUB_ENV' => $directory.'/github-env',
    ], $directory);

    expect($code)->not->toBe(0, $output);
    expect($output)->toContain('::error::');
    expect($output)->not->toContain('BEGIN KEY');
    deployRemoveTree($directory);
})->with([
    'no key' => ['', '[host.example]:2222 ssh-ed25519 AAAAC3Nza'],
    'no pinned host key' => ['-----BEGIN KEY-----', ''],
    'a blank pinned host key' => ['-----BEGIN KEY-----', "  \n"],
]);

it('writes the key 0600 and the pinned host key to files that only the ssh step reads', function (): void {
    $directory = deployTemporaryDirectory();
    [$code, $output] = deployRunBash(deployScript('deploy', 'Install the deploy key and the pinned host key'), [
        'PROD_SSH_KEY' => "-----BEGIN KEY-----\nabc\n-----END KEY-----",
        'KNOWN_HOSTS' => '[host.example]:2222 ssh-ed25519 AAAAC3Nza',
        'RUNNER_TEMP' => deployPosix($directory),
        'GITHUB_ENV' => $directory.'/github-env',
    ], $directory);

    expect($code)->toBe(0, $output);
    expect($output)->not->toContain('BEGIN KEY');
    $env = file_get_contents($directory.'/github-env');
    expect($env)->toContain('KEY_FILE=', 'KNOWN_HOSTS_FILE=');
    expect(trim(file_get_contents($directory.'/known_hosts')))->toBe('[host.example]:2222 ssh-ed25519 AAAAC3Nza');
    expect(file_get_contents($directory.'/deploy-key'))->toBe("-----BEGIN KEY-----\nabc\n-----END KEY-----\n");
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(substr(sprintf('%o', fileperms($directory.'/deploy-key')), -4))->toBe('0600');
    }
    deployRemoveTree($directory);
});

it('makes exactly one ssh call, strict about the host key, carrying only the SHA as its argument', function (): void {
    $step = deployStep('deploy', 'Stream the release to the host');
    $script = deployJoined($step['run']);

    expect($step['env'])->toMatchArray([
        'PROD_SSH_USER' => '${{ secrets.PROD_SSH_USER }}',
        'PROD_SSH_HOST' => '${{ secrets.PROD_SSH_HOST }}',
        'PROD_SSH_PORT' => '${{ secrets.PROD_SSH_PORT }}',
        'SHA' => '${{ needs.guard.outputs.sha }}',
    ]);
    expect($script)->toContain(
        '-o StrictHostKeyChecking=yes',
        '-o UserKnownHostsFile="$KNOWN_HOSTS_FILE"',
        '-o GlobalKnownHostsFile=/dev/null',
        '-o BatchMode=yes',
        '-o IdentitiesOnly=yes',
        '-i "$KEY_FILE"',
        '-p "$PROD_SSH_PORT"',
    );
    expect($script)->toMatch('/^ssh .* "\$PROD_SSH_USER@\$PROD_SSH_HOST" "\$SHA" < "\$RUNNER_TEMP\/download\/release\.tar\.gz"$/m');
    expect(preg_match_all('/^\s*ssh\s/m', $script))->toBe(1);

    foreach (['scp', 'rsync', 'sftp', 'StrictHostKeyChecking=no', 'StrictHostKeyChecking=accept-new', 'set -x', 'ssh -v', 'echo "$PROD_SSH', 'printf "$PROD_SSH'] as $forbidden) {
        expect(deployWorkflowSource())->not->toContain($forbidden);
    }
});

it('removes the key material even when an earlier step failed', function (): void {
    $step = deployStep('deploy', 'Remove the key material');

    expect($step['if'])->toBe('always()');
    expect($step['run'])->toContain('rm -f', 'deploy-key', 'known_hosts');
});

// ---------------------------------------------------------------------------
// deploy/receive.sh: the forced command on the host
// ---------------------------------------------------------------------------

it('keeps receive.sh a strict, capped, self-cleaning forced command', function (): void {
    $script = file_get_contents(deployRoot().'/deploy/receive.sh');

    expect($script)->toStartWith("#!/usr/bin/env bash\nset -euo pipefail\numask 0027\n");
    expect($script)->toContain(
        'SSH_ORIGINAL_COMMAND',
        '^[0-9a-f]{40}$',
        '[[ ! -t 0 ]]',
        'root=/srv/training-center',
        'release_bin=/usr/local/bin/training-center-release',
        'max_bytes=314572800',
        'incoming="$root/shared/incoming"',
        '! -L "$incoming"',
        'mktemp -- "$incoming/receive.XXXXXXXXXX"',
        'timeout --signal=KILL "$timeout_seconds" head -c "$((max_bytes + 1))"',
        '(( size <= max_bytes ))',
        'timeout_seconds=900',
        'lock="$incoming/.receive.lock"',
        '[[ ! -L "$lock" ]]',
        'exec 7> "$lock"',
        'flock -n 7',
        'another upload is in progress',
        'approvals="$root/shared/archive-approvals"',
        '[[ ! -L "$1" && "$(stat -c %u -- "$1")" == 0 ]]',
        '(( (8#$mode & 0022) == 0 ))',
        '[[ "$digest" =~ ^[0-9a-f]{64}$ ]]',
        'sha256sum -- "$tempfile"',
        '[[ "$actual_digest" == "$(approved_digest)" ]]',
        'trap cleanup EXIT',
        'rm -f -- "$tempfile"',
        '"$release_bin" "$sha" "$tempfile"',
        'exit "$status"',
    );
    expect($script)->not->toMatch('/\beval\b|\bsource\b|\bsudo\b|\bsh -c\b|\bbash -c\b/');
    expect($script)->not->toMatch('/mkdir|mv |cp |ln -s|chmod|chown/');
});

it('takes the lock before writing, then checks the approved digest before the release call', function (): void {
    $script = file_get_contents(deployRoot().'/deploy/receive.sh');
    $order = [
        'approved_digest > /dev/null',
        'flock -n 7',
        'tempfile=$(mktemp',
        'timeout --signal=KILL',
        'actual_digest=$(sha256sum',
        '[[ "$actual_digest" == "$(approved_digest)" ]]',
        '"$release_bin" "$sha" "$tempfile"',
    ];
    $positions = array_map(fn (string $needle): int|false => strpos($script, $needle), $order);
    $sorted = $positions;
    sort($sorted);

    expect(in_array(false, $positions, true))->toBeFalse();
    expect($positions)->toBe($sorted);
});

it('honours the test overrides only outside an ssh session', function (): void {
    $script = file_get_contents(deployRoot().'/deploy/receive.sh');

    expect($script)->toContain(
        'if [[ -z "${SSH_CONNECTION:-}" ]]; then',
        'root=${TC_RECEIVE_ROOT:-$root}',
        'release_bin=${TC_RELEASE_BIN:-$release_bin}',
        'max_bytes=${TC_RECEIVE_MAX_BYTES:-$max_bytes}',
        'timeout_seconds=${TC_RECEIVE_TIMEOUT_SECONDS:-$timeout_seconds}',
    );
});

it('refuses, cleans up and propagates exit codes as its disposable-host harness proves', function (): void {
    $process = new Process([deployBash(), 'deploy/tests/receive-smoke.sh'], deployRoot(), ['MSYS_NO_PATHCONV' => '1']);
    $process->setTimeout(180)->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
    expect($process->getOutput())->toContain(
        'PASS: valid SHA and archive reach the release script, temp file removed',
        'PASS: input exactly at the size cap is accepted',
        'PASS: refused archive-over-cap',
        'PASS: refused symlinked incoming directory',
        'PASS: failing release propagates its exit code and cleans up',
        'PASS: refused environment overrides under an ssh session',
        'PASS: a hang-up lets the running release finish, then cleans up',
        'PASS: refused a forged archive under an approved SHA (wrong digest)',
        'PASS: refused an archive with no digest approval',
        'PASS: refused a symlinked approval',
        'PASS: refused an approval with mode 664',
        'PASS: refused an approval not owned by root',
        'PASS: the approved digest reaches the release script',
        'PASS: a second upload during a running release is refused at once, the first is untouched',
        'PASS: a stalled upload is killed at the deadline and cleaned up; a second one is refused meanwhile',
    );
    expect($process->getOutput())->not->toContain('FAIL');
});
