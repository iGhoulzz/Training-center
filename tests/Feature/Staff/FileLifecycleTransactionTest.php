<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Actions\UpdateStaffPhotoAction;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Transaction-manager regression outside RefreshDatabase's special wrapper.
 *
 * RefreshDatabase installs a testing transaction manager that deliberately
 * treats its wrapper differently. This test uses Laravel's production manager,
 * because the dangerous case was re-entering that manager from a root rollback
 * callback and accidentally firing after-commit work from the rolled-back save.
 */
uses(DatabaseTruncation::class);

// DatabaseTruncation has setup but no teardown. Without this reset, a following
// RefreshDatabase test would transact over the final case's committed rows.
afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

afterEach(function () {
    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

it('purges a nested rolled-back upload without firing discarded after-commit callbacks', function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');
    $actor = $actor->fresh();

    $originalPath = 'staff-photos/'.Str::ulid()->toString().'.png';
    Storage::disk('private')->put($originalPath, makePngBytes());
    $profile = StaffProfile::factory()->create([
        'profile_photo_path' => $originalPath,
    ]);

    $afterCommitRan = false;
    $newPath = null;
    $startingLevel = DB::transactionLevel();

    DB::beginTransaction(); // The root Filament-style transaction.
    DB::afterCommit(function () use (&$afterCommitRan): void {
        $afterCommitRan = true;
    });
    DB::beginTransaction(); // A second surrounding savepoint.

    try {
        app(UpdateStaffPhotoAction::class)->execute(
            $actor,
            $profile->fresh(),
            pngUpload('nested-rollback.png'),
        );

        $newPath = $profile->fresh()->profile_photo_path;
        assert(is_string($newPath));
        Storage::disk('private')->assertExists($newPath);

        // Commit only the child savepoint, then roll the root back. A rollback
        // callback attached to the child would already have been discarded.
        DB::commit();
        DB::rollBack();
    } finally {
        while (DB::transactionLevel() > $startingLevel) {
            DB::rollBack();
        }
    }

    assert(is_string($newPath));

    expect($afterCommitRan)->toBeFalse()
        ->and($profile->fresh()->profile_photo_path)->toBe($originalPath)
        ->and(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->count())->toBe(0);

    Storage::disk('private')->assertExists($originalPath);
    Storage::disk('private')->assertMissing($newPath);
});

it('persists database-queue cleanup when an intermediate savepoint rolls back and its root commits', function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');
    $actor = $actor->fresh();

    $originalPath = 'staff-photos/'.Str::ulid()->toString().'.png';
    Storage::disk('private')->put($originalPath, makePngBytes());
    $profile = StaffProfile::factory()->create([
        'profile_photo_path' => $originalPath,
    ]);

    $rootCommitted = false;
    $newPath = null;
    $startingLevel = DB::transactionLevel();
    $originalQueueConnection = config('queue.default');
    config(['queue.default' => 'database']);

    try {
        DB::beginTransaction();
        DB::afterCommit(function () use (&$rootCommitted): void {
            $rootCommitted = true;
        });
        DB::beginTransaction();

        try {
            app(UpdateStaffPhotoAction::class)->execute(
                $actor,
                $profile->fresh(),
                pngUpload('middle-savepoint-rollback.png'),
            );

            $newPath = $profile->fresh()->profile_photo_path;
            assert(is_string($newPath));
            Storage::disk('private')->assertExists($newPath);

            // Undo only the intermediate savepoint. Its rollback callback must
            // enqueue cleanup through the independent connection while the root
            // transaction is still open. A job inserted through the primary
            // connection would remain invisible to this second connection until
            // root commit, so this pre-commit assertion detects that bug.
            DB::rollBack();

            expect(FileLifecycleService::compensationQueueConnectionName())
                ->toBe('file_lifecycle_compensation_queue')
                ->and(config('queue.connections.file_lifecycle_compensation_queue.connection'))
                ->toBe(FileLifecycleService::compensationConnectionName())
                ->and(DB::connection(FileLifecycleService::compensationConnectionName())
                    ->table('jobs')
                    ->count())->toBeGreaterThan(0);

            DB::commit();
        } finally {
            while (DB::transactionLevel() > $startingLevel) {
                DB::rollBack();
            }
        }

        assert(is_string($newPath));

        expect($rootCommitted)->toBeTrue()
            ->and($profile->fresh()->profile_photo_path)->toBe($originalPath)
            ->and(DB::connection(FileLifecycleService::compensationConnectionName())
                ->table('jobs')
                ->count())->toBeGreaterThan(0);

        // A database worker would consume this. Run the job body directly so
        // the test also proves the persisted receipt still leads to cleanup.
        $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
            ->where('path', $newPath)
            ->sole();
        (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();

        Storage::disk('private')->assertExists($originalPath);
        Storage::disk('private')->assertMissing($newPath);
        expect(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
            ->whereKey($pending->getKey())
            ->exists())->toBeFalse();
    } finally {
        config(['queue.default' => $originalQueueConnection]);
        DB::connection(FileLifecycleService::compensationConnectionName())
            ->table('jobs')
            ->delete();
    }
});

it('keeps bytes when a current locking ownership read finds a committed owner', function () {
    Storage::fake('private');

    $path = 'staff-photos/'.Str::ulid()->toString().'.png';
    Storage::disk('private')->put($path, makePngBytes());
    StaffProfile::factory()->create(['profile_photo_path' => $path]);

    $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->create([
        'disk' => 'private',
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if ($query->connectionName === FileLifecycleService::compensationConnectionName()) {
            $queries[] = $query->sql;
        }
    });

    (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();

    Storage::disk('private')->assertExists($path);
    expect(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->whereKey($pending->getKey())
        ->exists())->toBeFalse()
        ->and(collect($queries)->contains(
            fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        ))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The reconciliation sweep, end to end (P1-T15, domain-integrity finding 2)
|--------------------------------------------------------------------------
|
| PendingFileDeletionSweepTest covers which receipts a run selects and how it
| dispatches them. These two cover what actually happens to the BYTES, and they
| belong here rather than there: the job's ownership read runs on an independent
| connection, so under RefreshDatabase's wrapping transaction it cannot see rows
| the test has created and would report every file unowned. DatabaseTruncation
| commits for real and clears rows before cases, which is the only way this pair
| can distinguish an owned file from an orphan without rebuilding every table.
|
| They are a pair on purpose. Either one alone passes for a broken sweep — the
| first for one that dispatches nothing at all, the second for one that ignores
| ownership entirely.
*/

it('leaves bytes a committed row still owns when the sweep reaches their receipt', function () {
    Storage::fake('private');

    $path = 'staff-photos/'.Str::ulid()->toString().'.png';
    Storage::disk('private')->put($path, makePngBytes());
    StaffProfile::factory()->create(['profile_photo_path' => $path]);

    // A provisional upload receipt whose cancellation never landed: the owner
    // committed, the receipt did not go away, and it has now aged into the
    // sweep's window. Nothing on the row distinguishes it from an ordinary
    // deletion receipt, which is why the sweep must never assume.
    $pending = PendingFileDeletion::query()->create([
        'disk' => 'private',
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);
    DB::table('pending_file_deletions')
        ->where('id', $pending->getKey())
        ->update(['created_at' => now()->subDay()]);

    // The queue runs synchronously here, so the dispatched job executes inline.
    $this->artisan('files:sweep-pending-deletions')->assertSuccessful();

    Storage::disk('private')->assertExists($path);
    expect(PendingFileDeletion::whereKey($pending->getKey())->exists())->toBeFalse();
});

it('is harmless to run the same purge twice', function () {
    /*
     * HANDOFF GUARANTEE 2.
     *
     * The sweep re-dispatches a receipt whose original job may still be queued,
     * and it stamps a receipt only after the dispatch returns — so a stamp that
     * fails after a successful dispatch produces a second job for the same
     * receipt on the next run. Duplicate execution therefore has to be a
     * non-event, not merely unlikely.
     *
     * Both shapes are covered, because they take different branches: the second
     * job may find the receipt already gone, or may find it still present with
     * the bytes already unlinked. The second is the one worth pinning — it
     * depends on Storage::delete() reporting success for a file that is already
     * absent, which is the assumption PurgeDeletedFileJob's own comment rests on
     * when it treats a false return as a real failure.
     */
    Storage::fake('private');

    $path = 'staff-certificates/'.Str::ulid()->toString().'.pdf';
    Storage::disk('private')->put($path, 'orphaned-certificate-bytes');

    $pending = PendingFileDeletion::query()->create([
        'disk' => 'private',
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);

    // Shape one: the receipt is gone by the time the duplicate runs.
    (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();
    (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();

    Storage::disk('private')->assertMissing($path);
    expect(PendingFileDeletion::whereKey($pending->getKey())->exists())->toBeFalse();

    // Shape two: the receipt is still there, but the bytes are already gone.
    $survivor = PendingFileDeletion::query()->create([
        'disk' => 'private',
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);

    (new PurgeDeletedFileJob((int) $survivor->getKey(), true))->handle();

    expect(PendingFileDeletion::whereKey($survivor->getKey())->exists())->toBeFalse(
        'A purge for bytes that are already absent was treated as a failure, so a duplicate '
        .'job leaves its receipt behind and the sweep re-dispatches it forever.',
    );
});

it('destroys bytes no committed row owns when the sweep reaches their receipt', function () {
    // The control. Without it, a sweep that dispatched nothing, or one whose
    // ownership check reported everything owned, would pass the test above.
    Storage::fake('private');

    $path = 'staff-certificates/'.Str::ulid()->toString().'.pdf';
    Storage::disk('private')->put($path, 'orphaned-certificate-bytes');

    $pending = PendingFileDeletion::query()->create([
        'disk' => 'private',
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);
    DB::table('pending_file_deletions')
        ->where('id', $pending->getKey())
        ->update(['created_at' => now()->subDay()]);

    $this->artisan('files:sweep-pending-deletions')->assertSuccessful();

    Storage::disk('private')->assertMissing($path);
    expect(PendingFileDeletion::whereKey($pending->getKey())->exists())->toBeFalse();
});
