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
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
uses(DatabaseMigrations::class);

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
