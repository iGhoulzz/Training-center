<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\DeleteStaffCertificateAction;
use App\Domain\Staff\Actions\DeleteStaffPhotoAction;
use App\Domain\Staff\Actions\DeleteStaffProfileAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Actions\UpdateStaffPhotoAction;
use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The physical file lifecycle: commit first, delete after, durably recorded.
 *
 * Every assertion here is about DISK state — a row leaving the database proves
 * nothing about a scanned national ID that is still sitting on the filesystem.
 * The queue runs synchronously in the test environment, so an Action dispatching
 * PurgeDeletedFileJob->afterCommit() actually removes the bytes once its
 * transaction commits; the tests that must observe the intermediate receipt fake
 * the queue to hold the job.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');

    $this->admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->admin, 'admin');
    $this->admin = $this->admin->fresh();

    $this->profile = StaffProfile::factory()->create();

    // A certificate with real bytes behind it on the private disk.
    $this->makeCertificate = function (?StaffProfile $profile = null, string $bytes = 'certificate-bytes'): StaffCertificate {
        $profile ??= $this->profile;
        $path = 'staff-certificates/'.Str::ulid()->toString().'.pdf';
        Storage::disk('private')->put($path, $bytes);

        return StaffCertificate::factory()->for($profile, 'staffProfile')->create([
            'disk' => 'private',
            'path' => $path,
        ]);
    };

    // Give a profile a real photo file, returning its path.
    $this->givePhoto = function (?StaffProfile $profile = null): string {
        $profile ??= $this->profile;
        $path = 'staff-photos/'.Str::ulid()->toString().'.png';
        Storage::disk('private')->put($path, makePngBytes());
        $profile->update(['profile_photo_path' => $path]);

        return $path;
    };

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };
});

afterEach(function () {
    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

/*
|--------------------------------------------------------------------------
| Certificate deletion
|--------------------------------------------------------------------------
*/

it('schedules a purge job after committing the certificate deletion', function () {
    Queue::fake();

    $certificate = ($this->makeCertificate)();

    app(DeleteStaffCertificateAction::class)->execute($this->admin, $certificate);

    // Row gone, and exactly one durable receipt written for the file.
    expect(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeFalse();

    $pending = PendingFileDeletion::sole();
    expect($pending->disk)->toBe('private')
        ->and($pending->path)->toBe($certificate->path);

    Queue::assertPushed(
        PurgeDeletedFileJob::class,
        fn (PurgeDeletedFileJob $job): bool => $job->pendingFileDeletionId === (int) $pending->getKey(),
    );
});

it('removes the certificate file from disk once the purge job runs for real', function () {
    // No Queue::fake here: the sync queue runs PurgeDeletedFileJob after the
    // Action's transaction commits, so this exercises the whole pipeline and
    // proves the bytes actually leave the disk.
    $certificate = ($this->makeCertificate)();
    Storage::disk('private')->assertExists($certificate->path);

    app(DeleteStaffCertificateAction::class)->execute($this->admin, $certificate);

    Storage::disk('private')->assertMissing($certificate->path);
    expect(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeFalse()
        // The receipt is consumed only after the bytes are confirmed gone.
        ->and(PendingFileDeletion::count())->toBe(0);
});

it('keeps the receipt and the row gone when the purge job fails to delete the bytes', function () {
    // Hold the job so we can run it against a failing disk deliberately.
    Queue::fake();

    $certificate = ($this->makeCertificate)();

    app(DeleteStaffCertificateAction::class)->execute($this->admin, $certificate);

    expect(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeFalse();
    $pending = PendingFileDeletion::sole();

    // The disk reports the delete failed (throw => false yields a false return).
    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::shouldReceive('disk')->with('private')->andReturn($failing);

    expect(fn () => (new PurgeDeletedFileJob((int) $pending->getKey()))->handle())
        ->toThrow(FileStorageException::class);

    // The throw is the retry mechanism: the receipt survives, records the
    // attempt, and the certificate row stays gone.
    $pending->refresh();
    expect($pending->exists)->toBeTrue()
        ->and($pending->attempts)->toBe(1)
        ->and($pending->last_error)->not->toBeNull()
        ->and(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeFalse();
});

it('refuses a certificate deletion by an actor without delete_staff_certificate', function () {
    $certificate = ($this->makeCertificate)();

    $viewer = ($this->userWith)('view_staff_certificate');

    expect(fn () => app(DeleteStaffCertificateAction::class)->execute($viewer, $certificate))
        ->toThrow(AuthorizationException::class);

    // Row and bytes both intact; no receipt written.
    expect(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeTrue()
        ->and(PendingFileDeletion::count())->toBe(0);
    Storage::disk('private')->assertExists($certificate->path);
});

/*
|--------------------------------------------------------------------------
| The purge job in isolation
|--------------------------------------------------------------------------
*/

it('deletes the bytes and its own receipt when run directly', function () {
    $path = 'staff-certificates/'.Str::ulid()->toString().'.pdf';
    Storage::disk('private')->put($path, 'orphan-bytes');

    $pending = PendingFileDeletion::query()->create([
        'disk' => 'private',
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);

    (new PurgeDeletedFileJob((int) $pending->getKey()))->handle();

    Storage::disk('private')->assertMissing($path);
    expect(PendingFileDeletion::whereKey($pending->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Profile deletion — cascade, split permission, and photo
|--------------------------------------------------------------------------
*/

it('removes every certificate file and the profile photo when a profile is deleted', function () {
    $photoPath = ($this->givePhoto)();
    $certOne = ($this->makeCertificate)($this->profile, 'first');
    $certTwo = ($this->makeCertificate)($this->profile, 'second');

    app(DeleteStaffProfileAction::class)->execute($this->admin, $this->profile->fresh());

    expect(StaffProfile::whereKey($this->profile->getKey())->exists())->toBeFalse()
        // Certificate rows went by cascade.
        ->and(StaffCertificate::whereKey($certOne->getKey())->exists())->toBeFalse()
        ->and(StaffCertificate::whereKey($certTwo->getKey())->exists())->toBeFalse()
        ->and(PendingFileDeletion::count())->toBe(0);

    // Every file the profile owned is gone from disk.
    Storage::disk('private')->assertMissing($photoPath);
    Storage::disk('private')->assertMissing($certOne->path);
    Storage::disk('private')->assertMissing($certTwo->path);
});

it('refuses to delete a profile with certificates when the actor lacks delete_staff_certificate', function () {
    // The split-permission decision: deleting a profile destroys its certificate
    // rows by cascade, so it demands delete_staff_certificate too whenever the
    // profile owns any. The refusal must leave rows AND files untouched.
    $photoPath = ($this->givePhoto)();
    $certificate = ($this->makeCertificate)();

    $actor = ($this->userWith)('view_staff_profile', 'delete_staff_profile');

    expect(fn () => app(DeleteStaffProfileAction::class)->execute($actor, $this->profile->fresh()))
        ->toThrow(AuthorizationException::class);

    expect(StaffProfile::whereKey($this->profile->getKey())->exists())->toBeTrue()
        ->and(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeTrue()
        ->and(PendingFileDeletion::count())->toBe(0);

    Storage::disk('private')->assertExists($photoPath);
    Storage::disk('private')->assertExists($certificate->path);
});

it('allows deleting a certificate-free profile with only delete_staff_profile, and removes its photo', function () {
    // The other branch: no certificates, so the certificate grant is not
    // required. The photo still goes — that removal needs no certificate grant.
    $photoPath = ($this->givePhoto)();

    $actor = ($this->userWith)('view_staff_profile', 'delete_staff_profile');

    app(DeleteStaffProfileAction::class)->execute($actor, $this->profile->fresh());

    expect(StaffProfile::whereKey($this->profile->getKey())->exists())->toBeFalse()
        ->and(PendingFileDeletion::count())->toBe(0);
    Storage::disk('private')->assertMissing($photoPath);
});

/*
|--------------------------------------------------------------------------
| Photo upload, replacement, and removal
|--------------------------------------------------------------------------
*/

it('deletes the previous photo only after the replacement is committed', function () {
    $oldPath = ($this->givePhoto)();
    Storage::disk('private')->assertExists($oldPath);

    app(UpdateStaffPhotoAction::class)->execute($this->admin, $this->profile->fresh(), pngUpload('new-avatar.png'));

    $newPath = $this->profile->fresh()->profile_photo_path;

    expect($newPath)->not->toBe($oldPath)
        ->and($newPath)->toStartWith('staff-photos/')
        ->and(PendingFileDeletion::count())->toBe(0);

    // The new file exists; the one it replaced has been purged.
    Storage::disk('private')->assertExists($newPath);
    Storage::disk('private')->assertMissing($oldPath);
});

it('replaces the current database photo even when two requests hold stale profile snapshots', function () {
    $originalPath = ($this->givePhoto)();
    $firstRequest = $this->profile->fresh();
    $secondRequest = $this->profile->fresh();

    app(UpdateStaffPhotoAction::class)->execute(
        $this->admin,
        $firstRequest,
        pngUpload('first-replacement.png'),
    );
    $firstReplacementPath = $this->profile->fresh()->profile_photo_path;

    app(UpdateStaffPhotoAction::class)->execute(
        $this->admin,
        $secondRequest,
        pngUpload('second-replacement.png'),
    );
    $secondReplacementPath = $this->profile->fresh()->profile_photo_path;

    expect($firstReplacementPath)->not->toBe($originalPath)
        ->and($secondReplacementPath)->not->toBe($firstReplacementPath)
        ->and(PendingFileDeletion::count())->toBe(0);

    // The second request must purge the photo that was current when it locked
    // the row, not the original path captured in its stale model snapshot.
    Storage::disk('private')->assertMissing($originalPath);
    Storage::disk('private')->assertMissing($firstReplacementPath);
    Storage::disk('private')->assertExists($secondReplacementPath);
});

it('leaves the existing photo untouched when a replacement upload is invalid', function () {
    // Below the minimum dimension: validation fails before anything is written,
    // so there is no orphan file and the profile still points at the old photo.
    $oldPath = ($this->givePhoto)();

    expect(fn () => app(UpdateStaffPhotoAction::class)->execute(
        $this->admin,
        $this->profile->fresh(),
        pngUpload('tiny.png', 32, 32),
    ))->toThrow(ValidationException::class);

    expect($this->profile->fresh()->profile_photo_path)->toBe($oldPath)
        ->and(PendingFileDeletion::count())->toBe(0)
        // No second file was written: the old photo is the only thing on disk.
        ->and(Storage::disk('private')->allFiles('staff-photos'))->toHaveCount(1);
    Storage::disk('private')->assertExists($oldPath);
});

it('removes the photo file when a photo is deleted', function () {
    $path = ($this->givePhoto)();

    app(DeleteStaffPhotoAction::class)->execute($this->admin, $this->profile->fresh());

    expect($this->profile->fresh()->profile_photo_path)->toBeNull()
        ->and(PendingFileDeletion::count())->toBe(0);
    Storage::disk('private')->assertMissing($path);
});

it('deletes the current photo when the delete request carries a stale profile snapshot', function () {
    ($this->givePhoto)();
    $staleDeleteRequest = $this->profile->fresh();

    app(UpdateStaffPhotoAction::class)->execute(
        $this->admin,
        $this->profile->fresh(),
        pngUpload('replacement-before-delete.png'),
    );
    $currentPath = $this->profile->fresh()->profile_photo_path;
    Storage::disk('private')->assertExists($currentPath);

    app(DeleteStaffPhotoAction::class)->execute($this->admin, $staleDeleteRequest);

    expect($this->profile->fresh()->profile_photo_path)->toBeNull()
        ->and(PendingFileDeletion::count())->toBe(0);
    Storage::disk('private')->assertMissing($currentPath);
});

it('deletes current profile files when the delete request carries a stale profile snapshot', function () {
    ($this->givePhoto)();
    $staleDeleteRequest = $this->profile->fresh();

    app(UpdateStaffPhotoAction::class)->execute(
        $this->admin,
        $this->profile->fresh(),
        pngUpload('replacement-before-profile-delete.png'),
    );
    $currentPath = $this->profile->fresh()->profile_photo_path;
    Storage::disk('private')->assertExists($currentPath);

    app(DeleteStaffProfileAction::class)->execute($this->admin, $staleDeleteRequest);

    expect(StaffProfile::whereKey($this->profile->getKey())->exists())->toBeFalse()
        ->and(PendingFileDeletion::count())->toBe(0);
    Storage::disk('private')->assertMissing($currentPath);
});

it('purges a newly stored photo when an outer transaction rolls back its database change', function () {
    Queue::fake();

    $originalPath = ($this->givePhoto)();
    $startingLevel = DB::transactionLevel();
    $newPath = null;

    DB::beginTransaction();

    try {
        app(UpdateStaffPhotoAction::class)->execute(
            $this->admin,
            $this->profile->fresh(),
            pngUpload('rolled-back-photo.png'),
        );

        $newPath = $this->profile->fresh()->profile_photo_path;
        expect($newPath)->not->toBe($originalPath);
        Storage::disk('private')->assertExists($newPath);

        DB::rollBack();
    } finally {
        while (DB::transactionLevel() > $startingLevel) {
            DB::rollBack();
        }
    }

    expect($newPath)->toBeString()
        ->and($this->profile->fresh()->profile_photo_path)->toBe($originalPath);
    Storage::disk('private')->assertExists($originalPath);
    Storage::disk('private')->assertExists($newPath);

    // Rollback created a durable cleanup receipt for the now-unowned upload.
    $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->where('path', $newPath)
        ->sole();
    Queue::assertPushed(
        PurgeDeletedFileJob::class,
        fn (PurgeDeletedFileJob $job): bool => $job->pendingFileDeletionId === (int) $pending->getKey()
            && $job->usesCompensationConnection,
    );

    (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();

    Storage::disk('private')->assertMissing($newPath);
    Storage::disk('private')->assertExists($originalPath);
    expect(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->whereKey($pending->getKey())
        ->exists())->toBeFalse();
});
