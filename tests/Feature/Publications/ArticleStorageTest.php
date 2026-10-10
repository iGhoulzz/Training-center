<?php

declare(strict_types=1);

use App\Domain\Publications\Actions\CreateArticleAction;
use App\Domain\Publications\Actions\UpdateArticleAction;
use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Where an article's PDF lives and how it is replaced.
 *
 * The file goes on the private disk under publications/, through the lifecycle the
 * staff certificates use, and never on the public disk. A replaced file is
 * scheduled for deletion only after the new path is committed. Every assertion
 * here is about DISK state: a row pointing at the right place proves nothing about
 * where the bytes actually are.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');
    // Faked so that "nothing landed on the public disk" is a statement about an
    // empty directory this test owns, not about whatever storage/app/public holds.
    Storage::fake('public');

    $this->creator = User::factory()->create(['is_active' => true]);
    $this->creator->givePermissionTo('create_article');
    $this->creator = $this->creator->fresh();

    $this->editor = User::factory()->create(['is_active' => true]);
    $this->editor->givePermissionTo('update_article');
    $this->editor = $this->editor->fresh();

    $this->metadata = [
        'title_en' => 'Reading Technical Drawings',
        'description_en' => 'How to read orthographic projections.',
        'topic' => 'Engineering',
        'authors' => 'Omar Fathi',
        'issued_on' => '2025-06-20',
    ];

    $this->createArticle = fn (string $name = 'drawings.pdf'): Article => app(CreateArticleAction::class)
        ->execute($this->creator, $this->metadata, pdfUpload($name));

    $this->replaceFile = fn (Article $article, string $name = 'revised.pdf'): Article => app(UpdateArticleAction::class)
        ->execute($this->editor, $article, $this->metadata, pdfUpload($name));
});

afterEach(function () {
    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

/**
 * A private disk that reports every write as failed.
 *
 * The disk is configured with throw => false, so a write that fails returns false
 * rather than raising. The deletion half stays working: the provisional receipt
 * written before the bytes is purged on the sync queue, and that cleanup must
 * still be able to run.
 */
function articleFailThePrivateDisk(): void
{
    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('putFileAs')->andReturn(false);
    $failing->shouldReceive('delete')->andReturn(true);
    $failing->shouldReceive('exists')->andReturn(false);

    Storage::set('private', $failing);
}

/*
|--------------------------------------------------------------------------
| Where the PDF is stored
|--------------------------------------------------------------------------
*/

it('stores the PDF on the private disk under publications/ and nowhere else', function () {
    $article = ($this->createArticle)();

    expect($article->disk)->toBe('private')
        ->and($article->path)->toMatch('/^publications\/[0-9A-Za-z]{26}\.pdf$/')
        // The stored name is a ULID, never the uploader's.
        ->and($article->path)->not->toContain('drawings');

    Storage::disk('private')->assertExists($article->path);
    expect(Storage::disk('private')->allFiles())->toBe([$article->path])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('stores the bytes the uploader sent', function () {
    $upload = pdfUpload('drawings.pdf');
    $sent = (string) file_get_contents($upload->getPathname());

    $article = app(CreateArticleAction::class)->execute($this->creator, $this->metadata, $upload);

    expect(Storage::disk('private')->get($article->path))->toBe($sent);
});

it('keeps the private disk off the web: no URL of its own and a root outside public/', function () {
    // The properties that make "stored on the private disk" mean "has no address".
    // StaffCertificateTest asserts the same for the disk as configured; asserting
    // it here ties the article's claim to the disk it names.
    $config = config('filesystems.disks.private');

    expect($config)->toBeArray()
        ->and($config)->not->toHaveKey('url')
        ->and($config['root'])->not->toStartWith(public_path())
        ->and(array_keys((array) config('filesystems.links')))->not->toContain($config['root']);
});

/*
|--------------------------------------------------------------------------
| Replacing the PDF
|--------------------------------------------------------------------------
*/

it('writes the new file, repoints the article and schedules the old file for deletion', function () {
    // Hold the purge job so the intermediate state can be observed.
    Queue::fake();

    $article = ($this->createArticle)('first.pdf');
    $oldPath = $article->path;

    $updated = ($this->replaceFile)($article, 'second.pdf');

    expect($updated->path)->not->toBe($oldPath)
        ->and($updated->path)->toMatch('/^publications\/[0-9A-Za-z]{26}\.pdf$/')
        ->and($updated->original_filename)->toBe('second.pdf')
        ->and($updated->disk)->toBe('private')
        ->and($article->fresh()->path)->toBe($updated->path);

    // Exactly one durable receipt, for the file that was replaced.
    $pending = PendingFileDeletion::sole();
    expect($pending->disk)->toBe('private')
        ->and($pending->path)->toBe($oldPath);

    Queue::assertPushed(
        PurgeDeletedFileJob::class,
        fn (PurgeDeletedFileJob $job): bool => $job->pendingFileDeletionId === (int) $pending->getKey(),
    );

    // Commit first, destroy after: both files exist until the job runs.
    Storage::disk('private')->assertExists($oldPath);
    Storage::disk('private')->assertExists($updated->path);
});

it('removes the replaced file from disk once the purge job runs for real', function () {
    // No Queue::fake: the sync queue runs PurgeDeletedFileJob after the Action's
    // transaction commits, so this exercises the whole pipeline.
    $article = ($this->createArticle)('first.pdf');
    $oldPath = $article->path;

    $updated = ($this->replaceFile)($article, 'second.pdf');

    Storage::disk('private')->assertMissing($oldPath);
    Storage::disk('private')->assertExists($updated->path);

    expect(Storage::disk('private')->allFiles())->toBe([$updated->path])
        // The receipt is consumed only after the bytes are confirmed gone.
        ->and(PendingFileDeletion::count())->toBe(0);
});

it('reads the file to replace from the locked row, not from the caller\'s stale copy', function () {
    Queue::fake();

    $article = ($this->createArticle)('first.pdf');
    $original = $article->path;

    // Two editors hold the same snapshot of the article.
    $staleOne = Article::query()->findOrFail($article->getKey());
    $staleTwo = Article::query()->findOrFail($article->getKey());

    $afterFirst = ($this->replaceFile)($staleOne, 'second.pdf');
    $afterSecond = ($this->replaceFile)($staleTwo, 'third.pdf');

    // The second replacement purges the FIRST replacement's file, not the original
    // a second time: each file is scheduled exactly once.
    expect(PendingFileDeletion::query()->orderBy('id')->pluck('path')->all())
        ->toBe([$original, $afterFirst->path])
        ->and($article->fresh()->path)->toBe($afterSecond->path);
});

it('logs a replacement that changes nothing else', function () {
    $article = ($this->createArticle)('first.pdf');
    $oldPath = $article->path;

    // Identical details and an identical file name: the only thing that moves is
    // the stored path. Without `path` in the audit allowlist the diff would be
    // empty, dontLogEmptyChanges() would suppress it, and the document readers
    // download would change with no trace of who changed it.
    $updated = ($this->replaceFile)($article, 'first.pdf');

    $activity = Activity::query()
        ->where('subject_type', Article::class)
        ->where('subject_id', $article->getKey())
        ->where('event', 'updated')
        ->sole();

    expect((int) $activity->causer_id)->toBe((int) $this->editor->getKey())
        ->and($activity->attribute_changes['old']['path'])->toBe($oldPath)
        ->and($activity->attribute_changes['attributes']['path'])->toBe($updated->path);
});

it('keeps the old file and the old row when the editor lacks permission', function () {
    $article = ($this->createArticle)('first.pdf');
    $oldPath = $article->path;

    $outsider = User::factory()->create(['is_active' => true]);
    $outsider->givePermissionTo('view_article', 'publish_article');

    expect(fn () => app(UpdateArticleAction::class)->execute(
        $outsider->fresh(),
        $article,
        $this->metadata,
        pdfUpload('second.pdf'),
    ))->toThrow(AuthorizationException::class);

    expect($article->fresh()->path)->toBe($oldPath)
        ->and(Storage::disk('private')->allFiles())->toBe([$oldPath])
        ->and(PendingFileDeletion::count())->toBe(0);
});

it('keeps the old file when the replacement is not a PDF', function () {
    $article = ($this->createArticle)('first.pdf');
    $oldPath = $article->path;

    expect(fn () => app(UpdateArticleAction::class)->execute(
        $this->editor,
        $article,
        $this->metadata,
        uploadWithBytes('second.pdf', 'plain text wearing a pdf name'),
    ))->toThrow(ValidationException::class);

    expect($article->fresh()->path)->toBe($oldPath)
        ->and(Storage::disk('private')->allFiles())->toBe([$oldPath])
        ->and(PendingFileDeletion::count())->toBe(0);
});

it('keeps the article pointing at its old file when the disk refuses the new one', function () {
    $article = ($this->createArticle)('first.pdf');
    $oldPath = $article->path;

    articleFailThePrivateDisk();

    expect(fn () => ($this->replaceFile)($article, 'second.pdf'))
        ->toThrow(FileStorageException::class);

    // Nothing half-applied: the row still names the file that exists, and the
    // receipt written before the failed write has been purged rather than left
    // behind as a durable instruction to delete something.
    $fresh = $article->fresh();

    expect($fresh->path)->toBe($oldPath)
        ->and($fresh->original_filename)->toBe('first.pdf')
        ->and(PendingFileDeletion::count())->toBe(0);
});

it('writes no row when the disk refuses the first file of a new article', function () {
    articleFailThePrivateDisk();

    expect(fn () => ($this->createArticle)())->toThrow(FileStorageException::class);

    expect(Article::count())->toBe(0)
        ->and(PendingFileDeletion::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The public disk is never used
|--------------------------------------------------------------------------
*/

/**
 * Every way this domain's source could name the public disk, as matched text.
 *
 * Comments are stripped first — this domain's own docblocks say "never the public
 * disk" — but string literals are kept, because 'public' IS a string literal.
 *
 * @return list<string>
 */
function articlePublicDiskShapes(string $source): array
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

            continue;
        }

        $code .= $token;
    }

    preg_match_all(
        '/\b(?:disk|drive|fake|persistentFake)\s*\(\s*[\'"]public[\'"]|[\'"]disk[\'"]\s*=>\s*[\'"]public[\'"]|->disk\s*\(\s*[\'"]public[\'"]/',
        $code,
        $matches,
    );

    return array_map(fn (string $match): string => preg_replace('/\s+/', '', $match) ?? $match, $matches[0]);
}

it('recognises every way of naming the public disk that it claims to', function (string $sample) {
    expect(articlePublicDiskShapes("<?php\n{$sample}"))->not->toBeEmpty();
})->with([
    'facade, single quotes' => ["Storage::disk('public')->put(\$p, \$b);"],
    'facade, double quotes' => ['Storage::disk("public")->put($p, $b);'],
    'facade, spaced' => ["Storage :: disk ( 'public' ) -> put(\$p, \$b);"],
    'drive alias' => ["Storage::drive('public')->put(\$p, \$b);"],
    'a Filament upload field' => ["FileUpload::make('f')->disk('public');"],
    'a config array' => ["['disk' => 'public', 'path' => \$p]"],
    'the test double' => ["Storage::fake('public');"],
]);

it('does not mistake prose, the private disk or a variable for the public disk', function (string $sample) {
    expect(articlePublicDiskShapes("<?php\n{$sample}"))->toBe([]);
})->with([
    'the private disk' => ["Storage::disk('private')->put(\$p, \$b);"],
    'a constant' => ['Storage::disk(self::DISK)->put($p, $b);'],
    'a docblock' => ["/** Never Storage::disk('public'). */\n\$x = 1;"],
    'a line comment' => ["// Storage::disk('public') is forbidden\n\$x = 1;"],
    'a public method' => ['$this->publicStatus();'],
]);

it('finds no use of the public disk anywhere in the publications domain', function () {
    $offenders = [];
    $scanned = 0;

    foreach (File::allFiles(app_path('Domain/Publications')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;

        foreach (articlePublicDiskShapes($file->getContents()) as $shape) {
            $offenders[] = str_replace('\\', '/', $file->getRelativePathname()).': '.$shape;
        }
    }

    // A scan that matches nothing passes every assertion built on it: the domain
    // has a model, four Actions, a counter, a policy, a resource and its pages.
    expect($scanned)->toBeGreaterThanOrEqual(12)
        ->and($offenders)->toBe([], "The publications domain must never use the public disk:\n".implode("\n", $offenders));
});
