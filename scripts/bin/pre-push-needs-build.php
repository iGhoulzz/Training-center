<?php

declare(strict_types=1);

/**
 * Decide whether the pre-push hook must run `npm run build`.
 *
 * Reads git's pre-push ref tuples on stdin — `<local ref> <local sha> <remote
 * ref> <remote sha>` per line — resolves which files the pushed commits touch,
 * and asks Tooling\FrontendBuildScope about them.
 *
 * EXIT CODES ARE THE ANSWER:
 *   0  build (a frontend input changed, or the range could not be resolved)
 *   1  skip  (the pushed commits touch no frontend input, or push nothing)
 *
 * EVERY UNCERTAIN CASE EXITS 0. A wrong skip hides a real build failure; a
 * wrong build only costs time. The bias is not negotiable, and the tests below
 * `tests/Unit/Tooling/` pin it.
 */

require __DIR__.'/../../vendor/autoload.php';

use Tooling\FrontendBuildScope;

/**
 * Git signals "no such object" with an all-zero sha. Matched by shape rather
 * than a 40-character literal, so this holds for SHA-256 repositories too.
 */
function isZeroSha(string $sha): bool
{
    return $sha !== '' && trim($sha, '0') === '';
}

/**
 * @return list<string>|null null when the range cannot be resolved
 */
function commitsFor(string $localSha, string $remoteSha): ?array
{
    $command = isZeroSha($remoteSha)
        // New branch on the remote: everything not already published.
        ? ['git', 'rev-list', $localSha, '--not', '--remotes=origin']
        : ['git', 'rev-list', $remoteSha.'..'.$localSha];

    $output = runGit($command);

    if ($output === null) {
        return null;
    }

    return array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $l): bool => $l !== ''));
}

/**
 * @param  list<string>  $command
 */
function runGit(array $command): ?string
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
        return null;
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 ? $stdout : null;
}

$tuples = (string) file_get_contents('php://stdin');

if (trim($tuples) === '') {
    fwrite(STDERR, "pre-push: no ref tuples on stdin; building assets to be safe.\n");
    exit(0);
}

$changed = [];

foreach (explode("\n", $tuples) as $line) {
    if (trim($line) === '') {
        continue;
    }

    $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    /*
     * A NON-EMPTY LINE THAT IS NOT A TUPLE IS AN UNCERTAIN CASE, NOT AN EMPTY
     * ONE. An earlier version skipped it, which meant garbage on stdin produced
     * "nothing changed" and a silent skip — the one outcome this script is
     * built to make impossible. Caught by driving the real detector with a
     * malformed line rather than by reading it.
     */
    if (count($parts) < 4) {
        fwrite(STDERR, "pre-push: unrecognised ref tuple on stdin; building assets to be safe.\n");
        exit(0);
    }

    [, $localSha, , $remoteSha] = $parts;

    // A deletion pushes the all-zero local sha: there is nothing to build.
    if (isZeroSha($localSha)) {
        continue;
    }

    $commits = commitsFor($localSha, $remoteSha);

    if ($commits === null) {
        fwrite(STDERR, "pre-push: could not resolve the pushed range; building assets to be safe.\n");
        exit(0);
    }

    foreach ($commits as $commit) {
        // --name-only per commit rather than a two-point diff, so a new branch
        // with no remote counterpart runs the same code path. Over-inclusive on
        // merges, which is the safe direction.
        $names = runGit(['git', 'show', '--name-only', '--pretty=format:', $commit]);

        if ($names === null) {
            fwrite(STDERR, "pre-push: could not read a pushed commit; building assets to be safe.\n");
            exit(0);
        }

        foreach (explode("\n", $names) as $name) {
            if (trim($name) !== '') {
                $changed[] = trim($name);
            }
        }
    }
}

$changed = array_values(array_unique($changed));

if ($changed === []) {
    echo "pre-push: the pushed refs introduce no commits; skipping npm run build.\n";
    exit(1);
}

$inputs = FrontendBuildScope::buildInputsIn($changed);

if ($inputs !== []) {
    echo 'pre-push: building assets — '.count($inputs).' frontend input(s) changed, first: '.$inputs[0]."\n";
    exit(0);
}

echo 'pre-push: no frontend inputs among '.count($changed)." changed file(s); skipping npm run build.\n";
echo "pre-push: CI builds every push regardless — see .github/workflows/ci.yml.\n";
exit(1);
