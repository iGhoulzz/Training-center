<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tooling\Repo;
use Tooling\SerialLock;
use Tooling\TestDatabase;

/*
|--------------------------------------------------------------------------
| The resolved database is the one being used
|--------------------------------------------------------------------------
|
| tests/Unit/Tooling/TestDatabaseTest.php pins the naming RULE. These two need the
| application, because the property that matters is not what the rule returns — it
| is that the running suite is connected to that database, and that the lock it
| holds is the lock for it.
|
| Without these, every guarantee about parallel worktrees could be true of a name
| nothing ever connected to.
*/

it('is connected to the database the bootstrap resolved', function () {
    expect(DB::connection()->getDatabaseName())->toBe(getenv('DB_DATABASE'));
});

it('holds the lock belonging to that database', function () {
    /*
     * Derived from the connection rather than from the environment, so a
     * mismatch between the two cannot pass: this asserts the lock file the
     * running process holds is the one named by the database it is talking to.
     */
    $expected = SerialLock::pathFor(Repo::lockKeyForDatabase(DB::connection()->getDatabaseName()));

    expect(SerialLock::isHeld())->toBeTrue()
        ->and(file_exists($expected))->toBeTrue(
            "The suite holds a lock, but not the one for [{$expected}].",
        );
});

it('runs a linked worktree against its own database, and the main checkout against the shared one', function () {
    /*
     * Whichever side this suite is on, the other must be untrue. Asserted as a
     * biconditional rather than as one case, because a rule that returned the
     * generated name everywhere would satisfy a single-sided test while breaking
     * CI and the Linux target.
     */
    $database = DB::connection()->getDatabaseName();

    TestDatabase::isLinkedWorktree()
        ? expect($database)->not->toBe(TestDatabase::SHARED)
        : expect($database)->toBe(TestDatabase::SHARED);
})->skip(
    fn (): bool => ! in_array(
        getenv('DB_DATABASE'),
        [TestDatabase::SHARED, TestDatabase::SHARED.'_'.substr(TestDatabase::worktreeKey(), 0, 8)],
        true,
    ),
    'CI and the Linux target select their own database, which this rule leaves alone.',
);
