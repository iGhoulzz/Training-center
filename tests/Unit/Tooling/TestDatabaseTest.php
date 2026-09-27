<?php

declare(strict_types=1);

use Tooling\Repo;
use Tooling\TestDatabase;

/*
|--------------------------------------------------------------------------
| Which database a run owns
|--------------------------------------------------------------------------
|
| The naming rule decides whether two worktrees can run at once and whether two
| runs against one database still take turns. Both failure directions are quiet:
| a name that never changes reinstates the queue, and a name that changes when it
| should not sends CI or the Linux target at a database nobody provisioned.
|
| resolve() is pure and injected, so every case here is exercised without a
| second worktree, a second machine, or a database.
*/

it('pins the shared name against phpunit.xml rather than a copy of it', function () {
    /*
     * A constant that drifted from phpunit.xml would send EVERY checkout to a
     * generated database — including the main one — and look like it was working.
     * The real file is the source of truth, so it is read.
     */
    $configuration = new SimpleXMLElement((string) file_get_contents(Repo::root().'/phpunit.xml'));

    $pinned = null;

    foreach ($configuration->php->env as $entry) {
        if ((string) $entry['name'] === 'DB_DATABASE') {
            $pinned = (string) $entry['value'];
        }
    }

    expect($pinned)->toBe(TestDatabase::SHARED);
});

it('never leaves a checkout on the bare shared name', function () {
    /*
     * THE MAIN CHECKOUT IS GENERATED TOO, AND THAT IS THE WHOLE POINT.
     *
     * While any worktree still runs the pre-change code it locks a key derived
     * from the git directory, against `training_center_test`. If a checkout on
     * THIS code also used that name it would lock a different key against the
     * same schema — two locks, one database, silent. Measured before the
     * reversal: bb3c1d0c… against 8f576955…
     */
    $resolved = TestDatabase::resolve(TestDatabase::SHARED, str_repeat('a', 64));

    expect($resolved)->toBe(TestDatabase::SHARED.'_aaaaaaaa')
        ->and($resolved)->not->toBe(TestDatabase::SHARED);
});

it('gives two checkouts two databases', function () {
    $first = TestDatabase::resolve(TestDatabase::SHARED, hash('sha256', 'C:/worktrees/one'));
    $second = TestDatabase::resolve(TestDatabase::SHARED, hash('sha256', 'C:/worktrees/two'));

    expect($first)->not->toBe($second);
});

it('leaves a deliberately selected database alone', function (string $configured) {
    /*
     * CI sets training_center_ci and the Linux target sets training_center_linux
     * as real environment variables, which PHPUnit's <env> does not override. If
     * the rule rewrote those, both would run against a database nothing created.
     */
    expect(TestDatabase::resolve($configured, str_repeat('b', 64)))->toBe($configured);
})->with([
    'CI' => ['training_center_ci'],
    'the Linux target' => ['training_center_linux'],
    'a load database' => ['training_center_performance'],
]);

it('generates a name MySQL can hold, and the shipped allowlist accepts', function () {
    $generated = TestDatabase::resolve(TestDatabase::SHARED, hash('sha256', Repo::root()));

    /*
     * The expectation does not come from the generator: 64 is MySQL's identifier
     * limit, and the pattern is the one config/performance.php ships, so this is
     * two independent sources agreeing rather than one source agreeing with itself.
     */
    $performance = require Repo::root().'/config/performance.php';

    expect(strlen($generated))->toBeLessThanOrEqual(64)
        ->and(preg_match((string) $performance['allowed_database_pattern'], $generated))->toBe(1);
});

it('refuses a name it could not have generated', function (string $database) {
    // The name reaches SQL by interpolation, so this is the guard that makes that
    // defensible, and it must reject rather than escape.
    expect(fn () => TestDatabase::assertSafeIdentifier($database))
        ->toThrow(RuntimeException::class, 'Refusing to use');
})->with([
    'empty' => [''],
    'a hyphen' => ['training-center-test'],
    'a backtick' => ['training_center_test`; DROP DATABASE training_center; --'],
    'a space' => ['training center test'],
    'too long for MySQL' => [str_repeat('a', 65)],
    // $ matches before a trailing newline in PCRE; \z is what refuses this.
    'a trailing newline' => ["training_center_test_deadbeef\n"],
]);

it('accepts the names it does generate', function () {
    TestDatabase::assertSafeIdentifier(TestDatabase::SHARED);
    TestDatabase::assertSafeIdentifier(TestDatabase::resolve(TestDatabase::SHARED, str_repeat('c', 64)));
})->throwsNoExceptions();

/*
 * The end-to-end half of this — that the suite is actually CONNECTED to the name
 * resolved above — needs the application, so it lives in
 * tests/Feature/Tooling/TestDatabaseConnectionTest.php. Unit tests here get no app.
 */
