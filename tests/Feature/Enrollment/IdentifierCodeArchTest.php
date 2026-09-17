<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Student;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Only CreateWithIdentifierCodeAction inserts students and batches
|--------------------------------------------------------------------------
|
| Every row is created with a generated or typed code by exactly one class. A
| fourth application writer that created these rows itself would skip
| generation silently: the column is NOT NULL, so it would either fail in
| production or, worse, carry whatever the caller happened to supply.
|
| WHAT THIS PROVES, AND WHAT IT DOES NOT
| --------------------------------------
| It is a fast early warning over the common shapes, in two halves:
|
|   1. Source. Outside the Action, no file in app/ spells a static creation
|      call on Student or Batch, a `new Student` / `new Batch`, or a
|      query-builder insert into their tables.
|   2. Filament. The only CreateRecord pages for these two models are
|      CreateStudent and CreateBatch, and each declares its own
|      handleRecordCreation() that hands the insert to the Action — because
|      CreateRecord's inherited version is a bare `new $model($data)` that the
|      source scan cannot see, living in vendor/.
|
| It is NOT a proof. An aliased import (`use ... Student as Pupil`), a model
| instance built through a variable class name, or a relationship `create()`
| from a parent model all behave identically at runtime and read differently to
| a scan. docs/ENGINEERING.md says it plainly: architecture tests are a warning
| that points at a file; the behavioural tests in IdentifierCodeTest are what
| prove each real creation path generates a code.
|
| Factories and seeders live in database/, not app/, and stay free to build
| explicit fixtures.
|
| No database: this file reads source and reflects over classes. It is listed
| in DatabaseIsolationTest's reviewed read-only set.
*/

/** The one application file allowed to create these rows. */
const IDENTIFIER_CODE_WRITER = 'Domain/Enrollment/Actions/CreateWithIdentifierCodeAction.php';

/**
 * PHP source reduced to code: comments removed, string literals blanked.
 *
 * A scanner must read what code DOES. Without this a docblock that merely
 * mentions `Student::create()` — EnrollAndCollect's history note does — would
 * be reported as a writer.
 */
function identifierCodeExecutableSource(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            $code .= $token;

            continue;
        }

        [$id, $text] = $token;

        $code .= match ($id) {
            T_COMMENT, T_DOC_COMMENT => '',
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE => "''",
            default => $text,
        };
    }

    return $code;
}

/**
 * Every student- or batch-creating shape in $source, as matched text.
 *
 * The table-name check reads the ORIGINAL source, because the table name is a
 * string literal the executable view has blanked out.
 *
 * @return list<string>
 */
function identifierCodeCreationShapes(string $source): array
{
    $code = identifierCodeExecutableSource($source);
    $found = [];

    preg_match_all(
        '/\b(?:Student|Batch)\s*::\s*(?:query\s*\(\s*\)\s*->\s*)?(?:create|createQuietly|forceCreate|forceCreateQuietly|firstOrCreate|updateOrCreate|insert|insertOrIgnore|insertGetId|upsert)\s*\(/',
        $code,
        $staticCalls,
    );
    preg_match_all('/\bnew\s+(?:Student|Batch)\b/', $code, $constructions);
    preg_match_all('/\btable\s*\(\s*[\'"](?:students|batches)[\'"]\s*\)/', $source, $tables);

    foreach ([...$staticCalls[0], ...$constructions[0], ...$tables[0]] as $match) {
        $found[] = preg_replace('/\s+/', '', $match);
    }

    return $found;
}

it('recognises every creation shape it claims to, in any spacing', function (string $sample) {
    expect(identifierCodeCreationShapes("<?php\n{$sample}"))->not->toBeEmpty();
})->with([
    'static create' => ['Student::create($data);'],
    'query create' => ['Batch::query()->create($data);'],
    'spaced query create' => ['Student :: query ( ) -> create ( $data );'],
    'force create' => ['Batch::forceCreate($data);'],
    'quiet create' => ['Student::createQuietly($data);'],
    'first or create' => ['Student::firstOrCreate([], $data);'],
    'update or create' => ['Batch::updateOrCreate([], $data);'],
    'builder insert' => ['Student::query()->insert($rows);'],
    'upsert' => ['Batch::upsert($rows, []);'],
    'constructor' => ['$s = new Student($data); $s->save();'],
    'bare constructor' => ['$b = new Batch;'],
    'table insert, single quotes' => ["DB::table('students')->insert(\$row);"],
    'table insert, double quotes' => ['DB::table("batches")->insert($row);'],
]);

it('does not mistake reads, other models or prose for a creation', function (string $sample) {
    expect(identifierCodeCreationShapes("<?php\n{$sample}"))->toBe([]);
})->with([
    'a read' => ['Student::query()->where("id", 1)->first();'],
    'an update' => ['$batch->update($data);'],
    'an enum' => ['StudentStatus::from($value); BatchStatus::cases();'],
    'another model' => ['Course::create($data); new StudentCertificate;'],
    'a docblock' => ["/** Student::create() used to live here. */\n\$x = 1;"],
    'a line comment' => ["// new Batch was the old way\n\$x = 1;"],
    'a string' => ['$message = "Student::create( is forbidden";'],
    'a table read' => ["DB::table('students_archive')->count();"],
]);

it('finds no student or batch creation in app/ outside the Action', function () {
    $offenders = [];
    $scanned = 0;

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        if ($relative === IDENTIFIER_CODE_WRITER) {
            continue;
        }

        foreach (identifierCodeCreationShapes($file->getContents()) as $shape) {
            $offenders[] = "{$relative}: {$shape}";
        }
    }

    expect($scanned)->toBeGreaterThan(100)
        ->and($offenders)->toBe([], "Students and batches are created only by CreateWithIdentifierCodeAction:\n".implode("\n", $offenders));
});

it('keeps the allowlisted writer real, so the exemption cannot outlive the file', function () {
    $path = app_path(IDENTIFIER_CODE_WRITER);

    // If the Action moved, this exemption would silently cover nothing, and a
    // replacement writer elsewhere would be reported — or, renamed into this
    // path, exempted. Pin both: it exists, and it is the class it names.
    expect(File::exists($path))->toBeTrue()
        ->and((new ReflectionClass(CreateWithIdentifierCodeAction::class))->getFileName())->toBe(realpath($path))
        ->and(identifierCodeCreationShapes(File::get($path)))->not->toBeEmpty();
});

it('routes every Filament create page for these models through the Action', function () {
    $pages = [];

    foreach (File::allFiles(app_path()) as $file) {
        if (! str_contains($file->getContents(), 'extends CreateRecord')) {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $file->getRelativePathname());

        if (! is_subclass_of($class, CreateRecord::class)) {
            continue;
        }

        $model = $class::getResource()::getModel();

        if (in_array($model, [Student::class, Batch::class], true)) {
            $pages[] = $class;
        }
    }

    sort($pages);

    expect($pages)->toBe([CreateBatch::class, CreateStudent::class]);

    foreach ($pages as $page) {
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $body = implode('', array_slice(
            file((string) $method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        expect($method->getDeclaringClass()->getName())->toBe($page, "{$page} inherits CreateRecord's bare insert.")
            ->and(identifierCodeExecutableSource("<?php\n{$body}"))->toContain('CreateWithIdentifierCodeAction::class');
    }
});
