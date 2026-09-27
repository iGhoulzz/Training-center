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
    /*
     * ASKED OF THE SERVER, NOT OF THE CONFIGURATION.
     *
     * `getDatabaseName()` returns what config says, which is the same value this
     * would compare it against — two readings of one variable agreeing with each
     * other. It cannot catch the case that matters: the bootstrap creating a
     * database on one server while Laravel connects to another, which is possible
     * because the two read their credentials from different places.
     *
     * `select database()` is answered by the connection itself.
     */
    $live = DB::selectOne('select database() as name');

    expect($live?->name)->toBe(getenv('DB_DATABASE'))
        ->and($live?->name)->toBe(DB::connection()->getDatabaseName());
});

it('holds the lock belonging to that database', function () {
    /*
     * ASSERTED AGAINST THE PATH ACTUALLY HELD, NOT AGAINST A FILE EXISTING.
     *
     * The first version of this test checked file_exists() on the expected path,
     * which cannot fail: lock files are created on first use and never deleted,
     * so one from any earlier run satisfies it while this process holds something
     * else entirely. Review caught it, and a retired key's file from six days
     * earlier was still sitting in the temp directory as proof.
     *
     * The expectation is built from the live CONNECTION, so a bootstrap that
     * resolved one database and locked another fails here.
     */
    $expected = SerialLock::pathFor(Repo::lockKeyForDatabase(DB::connection()->getDatabaseName()));

    expect(SerialLock::isHeld())->toBeTrue()
        ->and(SerialLock::heldPath())->toBe($expected);
});

it('never runs a checkout against the bare shared database', function () {
    /*
     * The transition hazard, asserted where it would actually bite. While any
     * worktree still runs the pre-change code, it locks a key derived from the
     * git directory against `training_center_test`. A checkout on this code that
     * connected to that same name would hold a different lock over one schema.
     *
     * This asserts the connection, not the rule, so it fails if resolution is
     * correct but never reaches Laravel.
     */
    expect(DB::connection()->getDatabaseName())->not->toBe(TestDatabase::SHARED);
})->skip(
    fn (): bool => getenv('DB_DATABASE') !== TestDatabase::SHARED.'_'.substr(TestDatabase::worktreeKey(), 0, 8),
    'CI and the Linux target select their own database, which this rule leaves alone.',
);
