<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
