<?php

declare(strict_types=1);

use Tooling\Process;
use Tooling\Repo;
use Tooling\SerialLock;

/*
 * The lock key must be identical for every run against ONE database, and
 * different for different databases. Getting this wrong is invisible in the
 * dangerous direction: every run takes its own lock, every lock is granted
 * immediately, and two suites rebuild one schema while the guard reports success.
 *
 * IT USED TO KEY ON THE CHECKOUT, which was wrong twice over — see
 * Repo::lockKeyForDatabase. Two worktrees of one clone queued needlessly, and two
 * separate clones on one machine did NOT queue while sharing
 * `training_center_test`. The name is the thing that decides.
 */

it('derives one key for one database, whatever ran the suite', function () {
    expect(Repo::lockKeyForDatabase('training_center_test'))
        ->toBe(Repo::lockKeyForDatabase('training_center_test'));
});

it('gives different databases different keys', function () {
    $shared = Repo::lockKeyForDatabase('training_center_test');

    expect(Repo::lockKeyForDatabase('training_center_test_deadbeef'))->not->toBe($shared)
        ->and(Repo::lockKeyForDatabase('training_center_ci'))->not->toBe($shared)
        ->and(SerialLock::pathFor(Repo::lockKeyForDatabase('training_center_test_deadbeef')))
        ->not->toBe(SerialLock::pathFor($shared));
});

/*
 * Canonicalisation no longer feeds the lock key, but it still decides a
 * worktree's identity, so the spellings that used to break the key are pinned
 * where they now matter.
 */
it('reduces one directory spelled several ways to one worktree identity', function () {
    $canonical = Repo::canonicalize('C:/Users/User/Desktop/Training-center');

    expect(Repo::canonicalize('C:\\Users\\User\\Desktop\\Training-center'))->toBe($canonical)
        ->and(Repo::canonicalize('C:/Users/User/Desktop/Training-center/'))->toBe($canonical);
});

it('folds case only where the filesystem does', function () {
    $lower = Repo::canonicalize('c:/users/user/desktop/training-center');
    $upper = Repo::canonicalize('C:/Users/User/Desktop/Training-center');

    Repo::isWindows()
        // Windows reaches one directory through many spellings; two identities
        // there would give one worktree two databases.
        ? expect($lower)->toBe($upper)
        // Linux paths differing only in case are genuinely different
        // directories, and folding them would give two worktrees one database.
        : expect($lower)->not->toBe($upper);
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

/*
 * REGRESSION: THE LOCK USED TO FAIL OPEN.
 *
 * The blocking flock() result was discarded. A failed non-blocking attempt was
 * read as contention, so an unlockable stream printed "waiting", returned, and
 * the suite proceeded as though it held the lock — letting two suites rebuild
 * the same schema while the guard reported success.
 *
 * That is worse than having no lock, because the answer is trusted: everything
 * downstream, including the two-worktree evidence, assumes a held lock means
 * exclusive access.
 *
 * php://memory cannot be flock()ed, so both calls return false — the exact
 * shape of a real lock failure.
 */
it('refuses to run when the lock cannot be taken at all', function () {
    $unlockable = fopen('php://memory', 'c');

    expect(fn () => SerialLock::takeExclusiveLock($unlockable, '/tmp/unlockable.lock'))
        ->toThrow(RuntimeException::class, 'Refusing to run');
});

/*
 * IDEMPOTENT MEANS "THE SAME LOCK AGAIN", NOT "NEVER MIND".
 *
 * acquireForProcess() returned on any second call, so asking for a DIFFERENT
 * path proceeded holding the wrong lock. The caller that does that is
 * SeedPerformanceDatasetCommand, immediately before migrate:fresh — one process,
 * two databases, one lock.
 *
 * The suite already holds its own lock, so these exercise the second-call path
 * exactly as production hits it.
 */
it('accepts a second acquire for the path it already holds', function () {
    SerialLock::acquireForProcess((string) SerialLock::heldPath());
})->throwsNoExceptions();

it('refuses a second acquire for a different path', function () {
    $other = SerialLock::pathFor(Repo::lockKeyForDatabase('a_database_this_process_does_not_hold'));

    expect(fn () => SerialLock::acquireForProcess($other))
        ->toThrow(RuntimeException::class, 'already holds the suite lock')
        // The lock it holds is unchanged by the refusal; a throw that released or
        // replaced it would be worse than the bug.
        ->and(SerialLock::heldPath())->not->toBe($other);
});

it('returns quietly when the lock is free', function () {
    $path = sys_get_temp_dir().'/lock-free-'.bin2hex(random_bytes(6)).'.lock';
    $handle = fopen($path, 'c');

    // The success path must stay silent and must not throw, or every suite run
    // would announce itself.
    SerialLock::takeExclusiveLock($handle, $path);

    flock($handle, LOCK_UN);
    fclose($handle);
    @unlink($path);
})->throwsNoExceptions();

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
