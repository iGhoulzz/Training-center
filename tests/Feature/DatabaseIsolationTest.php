<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Every feature test must declare how it isolates itself (P1-T15, L8)
|--------------------------------------------------------------------------
|
| tests/Pest.php deliberately does NOT apply RefreshDatabase globally.
| FileLifecycleTransactionTest uses DatabaseMigrations instead, because
| RefreshDatabase wraps every test in a transaction and that transaction is
| precisely the machinery those tests exist to exercise; applying both would
| quietly defeat them.
|
| The cost is that isolation becomes something each author has to remember, on a
| database every suite shares. A file that writes rows without opting in leaves
| them for whichever file runs next, and the failure then surfaces somewhere
| else entirely — as a count one too high, or a unique-constraint violation on a
| row nobody in that file created.
|
| FAIL-CLOSED, AND THAT IS THE POINT (review of L8). The first version guessed
| whether a file wrote to the database by looking for known write calls, which is
| fail-open by construction: forceDelete(), restore(), attach(), sync(), upsert()
| and any Action that writes on the caller's behalf all escaped it, and so would
| every write API added after the list was written.
|
| So the question is inverted. Every feature test declares a trait, OR is named
| below as reviewed read-only. A new file is an offender until somebody decides
| which it is — and that decision is visible in a diff rather than inferred from
| a regex.
*/

/** The isolation traits that make a file safe to write rows from. */
const ISOLATION_TRAITS = ['RefreshDatabase', 'DatabaseMigrations', 'DatabaseTransactions'];

/**
 * Feature tests that touch no database at all, reviewed one by one.
 *
 * These assert over configuration, source text, schedules and documentation.
 * Adding a file here is a deliberate act: it means somebody has read it and
 * confirmed it writes nothing. Adding one that later starts writing rows is the
 * failure mode this list carries, which is why the list is short, explicit, and
 * checked below for entries that no longer exist.
 *
 * @var array<int, string>
 */
const READ_ONLY_FEATURE_TESTS = [
    'BackupConfigurationTest.php',
    'ExampleTest.php',
    'PolicyAbilitySurfaceTest.php',
    'Staff/ActionBoundaryArchTest.php',
    'Staff/FileLifecycleConfigurationTest.php',
];

it('names only read-only tests that still exist', function () {
    /*
     * A stale entry is worse than none: it exempts a filename that a future test
     * could be given, silently. The same freshness rule the logical-CSS
     * exemption already carries.
     */
    foreach (READ_ONLY_FEATURE_TESTS as $relative) {
        expect(file_exists(base_path("tests/Feature/{$relative}")))->toBeTrue(
            "{$relative} is listed as read-only but no longer exists. Remove the entry.",
        );
    }
});

it('confirms the read-only list is not simply everything', function () {
    /*
     * The control. If the list grew to cover every file, the guard below would
     * pass vacuously while enforcing nothing — so the suite must contain more
     * isolated tests than exempted ones.
     */
    $total = 0;

    foreach (File::allFiles(base_path('tests/Feature')) as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php')) {
            $total++;
        }
    }

    expect(count(READ_ONLY_FEATURE_TESTS))->toBeLessThan(
        $total / 2,
        'More than half the feature suite is exempted from database isolation, which means '
        .'this guard is no longer guarding much.',
    );
});

it('requires every feature test to declare an isolation trait or be reviewed read-only', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('tests/Feature')) as $file) {
        if (! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }

        $path = (string) $file->getRealPath();
        /*
         * Both sides normalised to forward slashes before the prefix is removed.
         * base_path('tests/Feature') joins with '/', while getRealPath() returns
         * all backslashes on Windows, so a naive str_replace matches neither and
         * every file reads as an offender.
         */
        $normalise = fn (string $value): string => str_replace(DIRECTORY_SEPARATOR, '/', $value);

        $relative = ltrim(
            str_replace($normalise(base_path('tests/Feature')), '', $normalise($path)),
            '/',
        );

        if (in_array($relative, READ_ONLY_FEATURE_TESTS, true)) {
            continue;
        }

        $source = (string) file_get_contents($path);
        $isolated = false;

        foreach (ISOLATION_TRAITS as $trait) {
            // uses(RefreshDatabase::class) at file scope. An import alone
            // isolates nothing.
            if (preg_match('/\buses\s*\([^)]*'.$trait.'::class/', $source) === 1) {
                $isolated = true;

                break;
            }
        }

        if (! $isolated) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty(
        'These feature tests neither isolate themselves nor declare that they touch no '
        ."database, so any row they write survives into whichever file runs next:\n  "
        .implode("\n  ", $offenders)
        ."\n\nAdd uses(RefreshDatabase::class) at the top of the file — or "
        .'DatabaseMigrations if it must exercise real transactions — or, if it genuinely '
        .'writes nothing, add it to READ_ONLY_FEATURE_TESTS after reading it.',
    );
});
