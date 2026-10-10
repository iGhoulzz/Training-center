<?php

declare(strict_types=1);

use App\Domain\Publications\Actions\CreateArticleAction;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A stale provisional upload receipt must never unlink a file a live article owns.
 *
 * The receipt written before an upload is cancelled after the owning transaction
 * commits. If that cancellation fails, the receipt outlives the commit and the
 * hourly sweep dispatches the CHECKED purge job for it, which unlinks the file
 * unless it can find an owner. PurgeDeletedFileJob::isOwned() therefore has to
 * know every kind of row that owns a file on the private disk, articles included.
 *
 * DatabaseTruncation, not RefreshDatabase: the ownership check is a locking read
 * on an independent connection, which cannot see a row the test's own wrapping
 * transaction has not committed (see FileLifecycleTransactionTest).
 */
uses(DatabaseTruncation::class);

// DatabaseTruncation has setup but no teardown. Without this reset, a following
// RefreshDatabase test would transact over this file's final committed rows.
afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');

    $this->creator = User::factory()->create(['is_active' => true]);
    $this->creator->givePermissionTo('create_article');
    $this->creator = $this->creator->fresh();
});

afterEach(function () {
    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

it('keeps the PDF of a live article when a stale upload receipt is purged', function () {
    $article = app(CreateArticleAction::class)->execute($this->creator, [
        'title_en' => 'Owned file',
        'description_en' => 'x',
        'topic' => 'Welding',
        'authors' => 'Nobody',
        'issued_on' => '2025-01-01',
    ], pdfUpload());

    $receipt = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->create([
        'disk' => 'private',
        'path' => $article->path,
        'attempts' => 0,
        'last_error' => null,
    ]);

    (new PurgeDeletedFileJob((int) $receipt->getKey(), true))->handle();

    Storage::disk('private')->assertExists($article->path);
    expect(PendingFileDeletion::count())->toBe(0);
});

it('still purges a receipt for a path no article owns', function () {
    // The control: the ownership check spares owned files, not every file.
    Storage::disk('private')->put('publications/orphan.pdf', 'bytes');

    $receipt = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->create([
        'disk' => 'private',
        'path' => 'publications/orphan.pdf',
        'attempts' => 0,
        'last_error' => null,
    ]);

    (new PurgeDeletedFileJob((int) $receipt->getKey(), true))->handle();

    Storage::disk('private')->assertMissing('publications/orphan.pdf');
    expect(PendingFileDeletion::count())->toBe(0);
});
