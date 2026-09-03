<?php

declare(strict_types=1);

use App\Domain\Staff\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

/*
 * RefreshDatabase is NOT applied globally, and that is deliberate rather than an
 * oversight (P1-T15, group 3 finding L8).
 *
 * FileLifecycleTransactionTest uses DatabaseTruncation instead, because
 * RefreshDatabase wraps every test in a transaction and that transaction is
 * precisely the machinery those tests exist to exercise. Applying both would
 * quietly defeat them. Laravel truncates before each case but not after the
 * final case in a file, so every truncation file must reset
 * RefreshDatabaseState::$migrated in its own afterAll hook. That makes the next
 * database test rebuild before it can observe committed residue.
 *
 * The cost is that isolation becomes something each author has to remember, on a
 * database every suite shares — a file that writes rows without opting in leaves
 * them for whichever file runs next, and the failure then surfaces somewhere
 * else entirely.
 *
 * ENFORCED RATHER THAN REMEMBERED: tests/Feature/DatabaseIsolationTest.php fails
 * the build when a test looks like it writes rows and names no isolation trait.
 * The commented line below stays as the record of what was considered.
 */
pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Real uploaded files for the staff file-lifecycle tests (P1-T06b)
|--------------------------------------------------------------------------
|
| The upload Actions validate the REAL mime type (mimetypes: reads the bytes),
| so the tests must hand them files whose content is genuine. Two constraints
| shape these helpers:
|
|   - UploadedFile::fake()->image() needs the GD extension, which is not
|     installed in this environment. So images are synthesised as raw PNG bytes
|     with gzcompress (zlib) — no GD, and getimagesize() reads the dimensions.
|   - Illuminate\Http\Testing\File::getMimeType() reports the mime from the
|     FILE NAME extension, not the content, so a fake could never prove that the
|     extension is ignored. A real Illuminate\Http\UploadedFile (test mode)
|     sniffs the actual bytes, which is exactly what must be validated.
*/

/**
 * A minimal but genuinely valid PNG of the given size, built without GD.
 *
 * finfo sniffs the \x89PNG signature as image/png and getimagesize() reads the
 * IHDR, so both the `image`/`mimetypes` and `dimensions` validation rules see a
 * real image.
 */
function makePngBytes(int $width = 100, int $height = 100): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
        .$type.$data.pack('N', crc32($type.$data));

    // 8-bit grayscale, no interlacing.
    $ihdr = pack('N', $width).pack('N', $height).chr(8).chr(0).chr(0).chr(0).chr(0);

    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        // Filter byte 0 (None) then one grayscale byte per pixel.
        $raw .= chr(0).str_repeat(chr(200), $width);
    }

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', $ihdr)
        .$chunk('IDAT', (string) gzcompress($raw, 6))
        .$chunk('IEND', '');
}

/**
 * Wrap real bytes in a real UploadedFile (test mode), so getMimeType() sniffs
 * the content rather than trusting the name.
 */
function uploadWithBytes(string $clientName, string $bytes): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'p1t06b_');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $clientName, null, null, true);
}

/** A real PNG upload of the given dimensions. */
function pngUpload(string $clientName = 'scan.png', int $width = 100, int $height = 100): UploadedFile
{
    return uploadWithBytes($clientName, makePngBytes($width, $height));
}

/**
 * A PNG shaped for Livewire's upload simulator.
 *
 * Livewire's test transport reads the public `name` property exposed by
 * Illuminate\Http\Testing\File, which a normal UploadedFile intentionally does
 * not have. The bytes are still a genuine PNG; direct Action tests use
 * pngUpload() when they need to prove content-derived MIME validation.
 */
function livewirePngUpload(
    string $clientName = 'scan.png',
    int $width = 100,
    int $height = 100,
): File {
    return UploadedFile::fake()->createWithContent(
        $clientName,
        makePngBytes($width, $height),
    );
}

/** A real (tiny) PDF upload. */
function pdfUpload(string $clientName = 'diploma.pdf'): UploadedFile
{
    return uploadWithBytes(
        $clientName,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );
}

/*
|--------------------------------------------------------------------------
| Statement capture, for proving locks and transactions
|--------------------------------------------------------------------------
|
| RefreshDatabase wraps every test in a transaction, so a lockForUpdate()
| emits "for update" whether or not the Action under test opened one of its
| own. Asserting the SQL alone therefore passes for an Action whose lock does
| not survive its own return. These record the DEPTH each statement ran at.
|
| See P1-T10d: with DB::transaction() deleted from AssignInstructorAction,
| every lock test still passed.
*/

/**
 * Begin recording every statement with the transaction depth it executed at.
 *
 * An ArrayObject rather than an array because the listener keeps filling the
 * same collection after this function returns, and an array would be a copy.
 *
 * @return ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}>
 */
function captureStatements(): ArrayObject
{
    /** @var ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}> $statements */
    $statements = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($statements): void {
        $statements->append([
            'sql' => strtolower($query->sql),
            'bindings' => $query->bindings,
            'level' => DB::transactionLevel(),
        ]);
    });

    return $statements;
}

/**
 * The locking reads taken against $table.
 *
 * @param  ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
 * @return array<int, array{sql: string, bindings: array<int, mixed>, level: int}>
 */
function locksOn(ArrayObject $statements, string $table): array
{
    return collect($statements)
        ->filter(fn (array $statement): bool => str_contains($statement['sql'], ' for update')
            && str_contains($statement['sql'], "from `{$table}`"))
        ->values()
        ->all();
}

/**
 * The statements that wrote $table — insert, update or delete.
 *
 * All three count: a test watching only for inserts stops meaning anything the
 * first time a row is changed rather than created.
 *
 * @param  ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
 * @return array<int, array{sql: string, bindings: array<int, mixed>, level: int}>
 */
function writesTo(ArrayObject $statements, string $table): array
{
    return collect($statements)
        ->filter(fn (array $statement): bool => preg_match(
            '/^(insert into|update|delete from) `'.preg_quote($table, '/').'`/',
            $statement['sql'],
        ) === 1)
        ->values()
        ->all();
}

/**
 * Every statement with its depth, for a failure message that says what DID run.
 *
 * @param  ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
 */
function describeStatements(ArrayObject $statements): string
{
    return $statements->count() === 0
        ? 'none'
        : collect($statements)
            ->map(fn (array $statement): string => "[level {$statement['level']}] {$statement['sql']}")
            ->implode(' | ');
}

/**
 * Assert a statement ran exactly one transaction level deeper than the caller,
 * against the expected row.
 *
 * THE DELTA, NOT THE ABSOLUTE LEVEL. Under RefreshDatabase the caller sits at
 * level 1 and in production at level 0; asserting `level === 1` would pass for
 * an Action that opens no transaction at all, which is precisely the bug this
 * exists to catch. baseline + 1 means the same thing in both places.
 *
 * @param  array{sql: string, bindings: array<int, mixed>, level: int}  $statement
 */
function expectOneLevelDeeper(array $statement, int $baseline, int $rowId, string $what): void
{
    expect($statement['level'])->toBe(
        $baseline + 1,
        "{$what} ran at transaction level {$statement['level']}, expected ".($baseline + 1)
        .' — one deeper than the caller. At the caller\'s own level the Action opened no '
        .'transaction, so the lock releases immediately and guards nothing. SQL: '.$statement['sql'],
    );

    // Only the integer-ish bindings: a write also binds timestamps, and
    // array_map('intval', ...) over a Carbon instance is a TypeError. in_array()
    // rather than expect()->toContain(), which is variadic and would read a
    // failure message as a second expected value.
    $ids = array_values(array_filter(array_map(
        fn (mixed $binding): ?int => is_int($binding) || (is_string($binding) && ctype_digit($binding))
            ? (int) $binding
            : null,
        $statement['bindings'],
    ), fn (?int $binding): bool => $binding !== null));

    expect(in_array($rowId, $ids, true))->toBeTrue(
        "{$what} did not bind row id {$rowId}, so it addressed something other than the row under "
        .'test. Integer bindings seen: '.($ids === [] ? 'none' : implode(', ', $ids))
        .'. SQL: '.$statement['sql'],
    );
}

/*
|--------------------------------------------------------------------------
| Source scanning for the architecture tests (moved here by P1-T14)
|--------------------------------------------------------------------------
|
| Two tests now scan PHP source for forbidden or required shapes:
| ActionBoundaryArchTest, for writes that bypass an Action, and
| LocalizationTest, for translation keys and hardcoded labels. Both must see
| code only — this codebase's comments quote the very patterns being searched
| for, so an unstripped scan reports its own documentation.
|
| It lives in Pest.php rather than in whichever test file needed it first,
| because Pest gives no guarantee about the order test files are loaded, and a
| helper defined in one of them is only sometimes there for the other.
*/

/**
 * The file's comments and docblocks, with all executable code removed.
 *
 * The exact inverse of appSourceWithoutComments(), for the mirror-image reason:
 * a test that checks what the comments CLAIM must see only comments, or the code
 * implementing the claim satisfies the search for it. P1-T15's docblock-drift
 * check needs this — "there is no deleteAny()" and `public function deleteAny()`
 * both contain the name.
 */
function appCommentsOnly(string $path): string
{
    $comments = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $comments .= $token[1]."\n";
        }
    }

    return $comments;
}

/**
 * Every model that records activity, found rather than listed.
 *
 * Lives here rather than in a test file because two of them need it now —
 * ActivityLogTest for the secret-exclusion scan and LocalizationTest for the
 * record-type labels — and Pest gives no guarantee about the order test files
 * are loaded, so a helper defined in one is only sometimes there for the other.
 *
 * @return array<int, class-string<Model>>
 */
function recordsActivityModels(): array
{
    $classes = [];

    /*
     * Fully qualified deliberately: this file imports Illuminate\Http\Testing\File
     * for the upload helpers above, so a bare `File` here resolves to that and
     * fails with "Method Illuminate\Http\Testing\File::allFiles does not exist".
     * Moving a helper into a file with a different import table is exactly how
     * that happens.
     */
    foreach (Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\'.str_replace(
            [app_path().DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR],
            ['', '', '\\'],
            (string) $file->getRealPath(),
        );

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        if (in_array(RecordsActivity::class, class_uses_recursive($class), true)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * A response body with Livewire's serialised state payload removed.
 *
 * WHY THIS EXISTS, AND IT IS NOT A CONVENIENCE.
 * ------------------------------------------------
 * A Livewire page embeds every public property in a `wire:snapshot` attribute
 * as JSON. `assertSee()` searches the RAW body, so it matches that payload just
 * as readily as the rendered HTML — which means an assertion that a page
 * "shows" a value passes even when the page renders nothing at all.
 *
 * Measured, not theorised: replacing the whole of `portal/my-enrollments`
 * with `<div>ENTIRE VIEW REMOVED BY PROBE</div>` left
 * "renders for a student holding view_own_enrollment" GREEN, because the course
 * code, batch code, status label and both dates were all still present in the
 * snapshot.
 *
 * Strip the payload and the assertion means what its name says. Note the
 * inverse does NOT need this: `assertDontSee()` over the raw body is STRICTER,
 * since it also proves the value never reached the payload — which is exactly
 * the guarantee PortalRowIsolationTest wants, and why those tests were sound
 * as written.
 */
function renderedWithoutLivewireState(TestResponse $response): string
{
    $body = $response->getContent();

    if (! is_string($body)) {
        return '';
    }

    // wire:snapshot and wire:effects carry the serialised state.
    return (string) preg_replace('/\swire:(snapshot|effects)="[^"]*"/i', ' ', $body);
}

/** The file's PHP source with all comments and docblocks removed. */
function appSourceWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}
