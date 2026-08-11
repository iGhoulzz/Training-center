<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Every feature test must declare how it isolates itself (P1-T15, L8)
|--------------------------------------------------------------------------
|
| tests/Pest.php deliberately does NOT apply RefreshDatabase globally.
| FileLifecycleTransactionTest uses DatabaseTruncation instead, because
| RefreshDatabase wraps every test in a transaction and that transaction is
| precisely the machinery those tests exist to exercise; applying both would
| quietly defeat them. Truncation clears before a case, not after a file's last
| case, so its declaration is incomplete without the enforced after-file reset
| that makes the next database test rebuild before observing committed residue.
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
const ISOLATION_TRAITS = [
    'RefreshDatabase',
    'DatabaseMigrations',
    'DatabaseTruncation',
    'DatabaseTransactions',
];

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
    // This file. It reads source and asserts over it; it opens no connection.
    // Listed explicitly because it used to slip through on its own error message.
    'DatabaseIsolationTest.php',
    'BackupConfigurationTest.php',
    'ExampleTest.php',
    'PolicyAbilitySurfaceTest.php',
    'Staff/ActionBoundaryArchTest.php',
    'Staff/FileLifecycleConfigurationTest.php',
];

/**
 * PHP source with comments and string literals removed, leaving only code.
 *
 * THIS FILE EXEMPTED ITSELF WITHOUT IT (review of L8). The failure message below
 * contains the words "Add uses(RefreshDatabase::class)", and a raw-text search
 * for that call found them — so the guard read its own error message as proof
 * that it was isolated. A scanner must look at what the code DOES, never at what
 * it says.
 */
function phpCodeWithoutStringsOrComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            $code .= $token;

            continue;
        }

        $skipped = [
            T_COMMENT,
            T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_INLINE_HTML,
        ];

        if (in_array($token[0], $skipped, true)) {
            $code .= ' ';

            continue;
        }

        $code .= $token[1];
    }

    return $code;
}

/**
 * Does this test file actually CALL uses() with an isolation trait?
 *
 * Extracted so the decision has samples of its own. Left inline, the only thing
 * exercising it was the repository as it happens to be today — and this file is
 * on the read-only list, so the very case it was fixed for (a mention rather
 * than a call) stopped being covered the moment the list entry was added.
 */
function declaresIsolationTrait(string $source): bool
{
    // Code only. A mention in a comment or a string is not the call, which is
    // how this file previously exempted itself with its own error message.
    $code = phpCodeWithoutStringsOrComments($source);

    foreach (ISOLATION_TRAITS as $trait) {
        if (preg_match('/\buses\s*\([^)]*'.$trait.'::class/', $code) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Does a DatabaseTruncation file close its after-file isolation boundary?
 *
 * Laravel's trait has setup only. The final test's committed rows survive until
 * another truncation or migration runs, so a following RefreshDatabase test
 * would otherwise transact over them. Files using another isolation trait have
 * no truncation-specific obligation and return true here.
 */
function declaresCompleteTruncationBoundary(string $source): bool
{
    $code = phpCodeWithoutStringsOrComments($source);

    if (preg_match('/\buses\s*\([^)]*DatabaseTruncation::class/', $code) !== 1) {
        return true;
    }

    return preg_match(
        '/\bafterAll\s*\([\s\S]*?RefreshDatabaseState\s*::\s*\$migrated\s*=\s*false\s*;[\s\S]*?\)\s*;/',
        $code,
    ) === 1;
}

it('accepts a real isolation declaration', function (string $sample) {
    expect(declaresIsolationTrait($sample))->toBeTrue();
})->with([
    "<?php\nuses(RefreshDatabase::class);\n",
    "<?php\nuses(DatabaseMigrations::class);\n",
    "<?php\nuses(DatabaseTruncation::class);\n",
    "<?php\nuses(DatabaseTransactions::class);\n",
    "<?php\nuses(RefreshDatabase::class, WithFaker::class);\n",
    "<?php\nuses( RefreshDatabase::class );\n",
]);

it('refuses a mention that is not a call', function (string $sample) {
    // Every one of these is text ABOUT the call. Over-broadening here is what
    // let this file off, so each is an independent sample.
    expect(declaresIsolationTrait($sample))->toBeFalse();
})->with([
    "<?php\n// Add uses(RefreshDatabase::class) at the top of the file.\n",
    "<?php\n/** uses(RefreshDatabase::class) */\n",
    "<?php\n// uses(DatabaseTruncation::class);\n",
    "<?php\n\$hint = 'uses(RefreshDatabase::class)';\n",
    "<?php\n\$hint = 'uses(DatabaseTruncation::class)';\n",
    "<?php\n\$hint = \"uses(RefreshDatabase::class)\";\n",
    // An import without the call isolates nothing.
    "<?php\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\n",
    "<?php\nit('x', function () {});\n",
]);

it('requires DatabaseTruncation to reset migration state after its file', function (string $sample, bool $expected) {
    expect(declaresCompleteTruncationBoundary($sample))->toBe($expected);
})->with([
    'complete boundary' => [
        "<?php\nuses(DatabaseTruncation::class);\nafterAll(function (): void { RefreshDatabaseState::\$migrated = false; });\n",
        true,
    ],
    'trait without reset' => ["<?php\nuses(DatabaseTruncation::class);\n", false],
    'reset named only in prose' => [
        "<?php\nuses(DatabaseTruncation::class);\n// afterAll(fn () => RefreshDatabaseState::\$migrated = false);\n",
        false,
    ],
    'other isolation trait' => ["<?php\nuses(RefreshDatabase::class);\n", true],
]);

it('does not let a file claim isolation from its own prose', function () {
    /*
     * The regression for the self-exemption above, stated as a property rather
     * than as a special case: a mention of the call in a comment or a string is
     * not the call.
     */
    $mention = "<?php\n// Add uses(RefreshDatabase::class) at the top.\n\$m = 'uses(RefreshDatabase::class)';\n";
    $real = "<?php\nuses(RefreshDatabase::class);\n";

    expect(str_contains(phpCodeWithoutStringsOrComments($mention), 'RefreshDatabase'))->toBeFalse()
        ->and(str_contains(phpCodeWithoutStringsOrComments($real), 'RefreshDatabase'))->toBeTrue();
});

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
    $incompleteTruncationBoundaries = [];

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

        if (! declaresIsolationTrait((string) file_get_contents($path))) {
            $offenders[] = $relative;

            continue;
        }

        if (! declaresCompleteTruncationBoundary((string) file_get_contents($path))) {
            $incompleteTruncationBoundaries[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty(
        'These feature tests neither isolate themselves nor declare that they touch no '
        ."database, so any row they write survives into whichever file runs next:\n  "
        .implode("\n  ", $offenders)
        ."\n\nAdd uses(RefreshDatabase::class) at the top of the file — or "
        .'DatabaseTruncation if it must exercise real committed transactions — or, if it genuinely '
        .'writes nothing, add it to READ_ONLY_FEATURE_TESTS after reading it.',
    );

    expect($incompleteTruncationBoundaries)->toBeEmpty(
        'These feature tests use DatabaseTruncation without closing its after-file boundary, '
        ."so their final committed rows can leak into the next RefreshDatabase test:\n  "
        .implode("\n  ", $incompleteTruncationBoundaries)
        ."\n\nAdd an afterAll hook that sets RefreshDatabaseState::\$migrated to false.",
    );
});
