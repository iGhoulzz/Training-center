<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Every test that touches the database must isolate itself (P1-T15, L8)
|--------------------------------------------------------------------------
|
| tests/Pest.php deliberately does NOT apply RefreshDatabase globally, and the
| commented-out line has sat there since the scaffold. The reason is real:
| FileLifecycleTransactionTest needs DatabaseMigrations instead, because
| RefreshDatabase wraps every test in a transaction and that is precisely the
| machinery those tests exist to exercise. Applying both would defeat them.
|
| The cost is that isolation became a thing each author has to remember. Every
| suite shares one MySQL database, so a file that writes rows without opting in
| leaves them behind for whichever file runs next — and the failure surfaces
| somewhere else entirely, as a count that is one too high or a "unique
| constraint" on a row nobody in that file created. P1-T15 already burned time on
| cross-run interference of exactly this shape when two worktrees shared the
| database.
|
| So the omission is enforced rather than remembered. A file that looks like it
| touches the database and names no isolation trait fails here, with the fix
| spelled out.
*/

/** The isolation traits that make a file safe to write rows from. */
const ISOLATION_TRAITS = ['RefreshDatabase', 'DatabaseMigrations', 'DatabaseTransactions'];

/**
 * Does this test source look like it writes to the database?
 *
 * A named function with sample sets below rather than an inline regex, per the
 * rule T14 paid for: an inline pattern can only be tested against the codebase
 * as it happens to be today, which is exactly when it passes vacuously.
 *
 * Deliberately broad. A false positive costs one word in a test file; a false
 * negative costs somebody an afternoon chasing a failure in a file they did not
 * touch.
 */
function looksLikeItWritesToTheDatabase(string $source): bool
{
    $patterns = [
        '/::factory\s*\(/',
        '/->create\s*\(/',
        '/->createQuietly\s*\(/',
        '/->save\s*\(/',
        '/->saveQuietly\s*\(/',
        '/->update\s*\(/',
        '/->delete\s*\(/',
        '/\$this->seed\s*\(/',
        '/\bDB::(table|insert|statement|transaction)\s*\(/',
        '/::create\s*\(/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * PHP source with comments and string literals removed, leaving only code.
 *
 * appSourceWithoutComments() in tests/Pest.php strips comments alone, which is
 * right for its callers. This needs strings gone too: a scanner that searches
 * for "->create(" holds that text as data, and a detector reading raw source
 * cannot tell the search from the act.
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
            // A placeholder rather than nothing, so `->label('x')` does not
            // collapse into `->label()` and change what the code looks like.
            $code .= in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                ? "''"
                : ' ';

            continue;
        }

        $code .= $token[1];
    }

    return $code;
}

it('recognises the ways a test writes rows', function (string $sample) {
    // Deleting a rule from the detector fails here.
    expect(looksLikeItWritesToTheDatabase($sample))->toBeTrue();
})->with([
    // Isolated to ::factory( alone: the obvious sample, User::factory()->create(),
    // also matches the ->create( rule, so deleting this one broke nothing.
    '$builder = User::factory(3);',
    'User::factory()->create();',
    '$user->save();',
    '$profile->update([]);',
    '$record->delete();',
    '$this->seed(RolePermissionSeeder::class);',
    'DB::table("users")->insert([]);',
    'Permission::create(["name" => "x"]);',
    '$model->saveQuietly();',
]);

it('leaves tests that only read configuration alone', function (string $sample) {
    // Over-broadening the detector fails here: these are the shapes of the
    // config-only suites that legitimately need no isolation.
    expect(looksLikeItWritesToTheDatabase($sample))->toBeFalse();
})->with([
    'expect(config("backup.backup.name"))->toBe("training-center");',
    'expect($runbook)->toContain("BACKUP_S3_BUCKET");',
    '$result = firstPhysicalCssProperty($source);',
    'expect(scheduledBackupCommand("backup:run"))->not->toBeNull();',
]);

it('isolates every feature test that writes to the shared database', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('tests/Feature')) as $file) {
        if (! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }

        $path = (string) $file->getRealPath();
        $source = (string) file_get_contents($path);

        /*
         * CODE ONLY — comments and string literals stripped.
         *
         * Some tests in this suite are themselves source scanners, and their
         * regex literals spell out the very calls being looked for:
         * ActionBoundaryArchTest carries '/::factory\s*\(/' and
         * '/DB::\s*table\s*\(/' as data. Reading raw text flagged it as a
         * database writer when it never opens a connection at all.
         *
         * An exemption list would have hidden that rather than fixed it, and
         * would have exempted those files from the whole rule the day one of
         * them did start writing rows.
         */
        if (! looksLikeItWritesToTheDatabase(phpCodeWithoutStringsOrComments($source))) {
            continue;
        }

        $isolated = false;

        foreach (ISOLATION_TRAITS as $trait) {
            // uses(RefreshDatabase::class) at file scope. The trait name alone is
            // not enough — an import without the uses() call isolates nothing.
            if (preg_match('/\buses\s*\([^)]*'.$trait.'::class/', $source) === 1) {
                $isolated = true;

                break;
            }
        }

        if (! $isolated) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBeEmpty(
        'These tests write to the database without isolating themselves, so their rows survive '
        ."into whichever file runs next:\n  ".implode("\n  ", $offenders)
        ."\n\nAdd uses(RefreshDatabase::class) at the top of the file, or "
        .'DatabaseMigrations if the test needs to exercise real transactions.',
    );
});
