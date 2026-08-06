<?php

declare(strict_types=1);

use Tooling\Process;
use Tooling\Repo;
use Tooling\SerialLock;

/*
 * The lock key must be identical for every worktree of one repository, and
 * different for different repositories. Getting this wrong is invisible: every
 * worktree takes its own lock, every lock is granted immediately, and two
 * suites run into one database while the guard reports success.
 *
 * This is not hypothetical. `git rev-parse --git-common-dir` returns `.git` in
 * the main checkout and an absolute path in a linked worktree, so hashing it
 * directly produced exactly that failure. `--path-format=absolute` is the fix,
 * and these tests pin the canonicalisation that follows it.
 */

it('derives one key for the same repository spelled different ways', function () {
    $key = SerialLock::pathFor(Repo::lockKeyFor('C:/Users/User/Desktop/Training-center/.git'));

    expect(SerialLock::pathFor(Repo::lockKeyFor('C:\\Users\\User\\Desktop\\Training-center\\.git')))->toBe($key)
        ->and(SerialLock::pathFor(Repo::lockKeyFor('C:/Users/User/Desktop/Training-center/.git/')))->toBe($key);
});

it('folds case only where the filesystem does', function () {
    $lower = Repo::lockKeyFor('c:/users/user/desktop/training-center/.git');
    $upper = Repo::lockKeyFor('C:/Users/User/Desktop/Training-center/.git');

    Repo::isWindows()
        // Windows reaches one directory through many spellings; two keys there
        // means two locks on one database.
        ? expect($lower)->toBe($upper)
        // Linux paths differing only in case are genuinely different
        // directories, and folding them would serialise unrelated repositories.
        : expect($lower)->not->toBe($upper);
});

it('gives different repositories different keys', function () {
    expect(Repo::lockKeyFor('/srv/training-center/.git'))
        ->not->toBe(Repo::lockKeyFor('/srv/other-project/.git'));
});

it('puts the lock outside the repository', function () {
    // Inside the worktree it would be one more untracked file for the change
    // classifier to trip over, and it would not be shared between worktrees.
    $path = SerialLock::pathFor(str_repeat('a', 64));

    expect($path)->toStartWith(rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/'))
        ->and($path)->toEndWith('.lock');
});

/*
 * The suite currently running holds this lock, from tests/bootstrap.php. If it
 * does not, the boundary is not in place and every guarantee above is decorative.
 */
it('is held by the process running this test', function () {
    expect(SerialLock::isHeld())->toBeTrue();
});

it('refuses parallel execution explicitly', function () {
    $result = Process::capture([
        PHP_BINARY,
        Repo::root().'/vendor/bin/pest',
        '--configuration='.Repo::root().'/phpunit.xml',
        '--parallel',
        '--filter=a-test-that-does-not-exist',
    ], Repo::root());

    expect($result['status'])->not->toBe(0)
        ->and($result['output'])->toContain('Parallel testing is not supported in this repository.');
});
