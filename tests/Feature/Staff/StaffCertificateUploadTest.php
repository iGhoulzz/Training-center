<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Actions\UploadStaffCertificateAction;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * UploadStaffCertificateAction: storage identity is server-owned, the real mime
 * type is validated from the bytes, and a partial failure leaves nothing behind.
 *
 * These are the rules a client is most tempted to break: choosing where its file
 * lands, what it is called, and what type the server believes it to be. Each is
 * asserted against DISK state, not just the row.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');

    $this->actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->actor, 'admin');
    $this->actor = $this->actor->fresh();

    $this->profile = StaffProfile::factory()->create();

    $this->upload = fn (UploadedFile $file, array $metadata = []): StaffCertificate => app(UploadStaffCertificateAction::class)
        ->execute($this->actor, $this->profile, $file, [
            'title' => 'Teaching Diploma',
            'issued_on' => '2024-01-01',
            'expires_on' => '2030-01-01',
            ...$metadata,
        ]);
});

afterEach(function () {
    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

it('stores an uploaded certificate on the private disk under a generated path', function () {
    $certificate = ($this->upload)(pngUpload('scan.png'));

    expect($certificate->disk)->toBe('private')
        ->and($certificate->path)->toStartWith('staff-certificates/')
        // The stored name is a ULID, not the client's — never the uploaded name.
        ->and($certificate->path)->toMatch('/^staff-certificates\/[0-9A-Za-z]{26}\.png$/')
        ->and($certificate->path)->not->toContain('scan')
        // The client's own name survives only as data, for the download header.
        ->and($certificate->original_filename)->toBe('scan.png')
        ->and($certificate->title)->toBe('Teaching Diploma');

    // The bytes are actually on the private disk at the generated path.
    Storage::disk('private')->assertExists($certificate->path);
});

it('accepts a real PDF and derives the pdf extension from the validated mime', function () {
    $certificate = ($this->upload)(pdfUpload('diploma.pdf'));

    expect($certificate->path)->toMatch('/^staff-certificates\/[0-9A-Za-z]{26}\.pdf$/')
        ->and($certificate->original_filename)->toBe('diploma.pdf');

    Storage::disk('private')->assertExists($certificate->path);
});

it('derives disk, path, and original_filename from the file, ignoring crafted metadata', function () {
    // The heart of "storage identity is server-owned". A client that could set
    // these would choose which file on the server to overwrite (path), where it
    // is served from (disk), and what a later header claims it is called.
    $certificate = ($this->upload)(
        pngUpload('real-scan.png'),
        [
            'disk' => 'public',
            'path' => '../../../../etc/passwd',
            'original_filename' => '../../secret.pdf',
        ],
    );

    expect($certificate->disk)->toBe('private')
        ->and($certificate->path)->toStartWith('staff-certificates/')
        ->and($certificate->path)->not->toContain('..')
        ->and($certificate->path)->not->toContain('etc/passwd')
        // Taken from the UploadedFile's own client name, not the crafted value.
        ->and($certificate->original_filename)->toBe('real-scan.png');

    // Persisted state agrees: the crafted values reached neither the row nor disk.
    $certificate->refresh();
    expect($certificate->disk)->toBe('private');

    Storage::disk('private')->assertExists($certificate->path);
});

it('validates the real mime type, not the client-supplied extension', function () {
    // A text file wearing a .pdf name. mimetypes: reads the bytes, so the
    // disguise fails and nothing is written. This also proves a disallowed type
    // (text/plain) is refused.
    $disguised = uploadWithBytes('malware.pdf', 'just plain text, not a pdf at all');

    expect(fn () => ($this->upload)($disguised))->toThrow(ValidationException::class);

    expect(StaffCertificate::count())->toBe(0);
    Storage::disk('private')->assertDirectoryEmpty('/');
});

it('rejects a file larger than the maximum size', function () {
    // A genuine PNG (valid type and dimensions) padded past the size ceiling, so
    // the only rule it trips is `max`.
    $oversized = uploadWithBytes(
        'poster.png',
        makePngBytes(100, 100).str_repeat("\0", (UploadStaffCertificateAction::MAX_KILOBYTES + 1) * 1024),
    );

    expect(fn () => ($this->upload)($oversized))->toThrow(ValidationException::class);

    expect(StaffCertificate::count())->toBe(0);
    Storage::disk('private')->assertDirectoryEmpty('/');
});

it('refuses an actor without create_staff_certificate, storing nothing', function () {
    $viewer = User::factory()->create(['is_active' => true]);
    $viewer->givePermissionTo('view_staff_certificate');

    expect(fn () => app(UploadStaffCertificateAction::class)->execute(
        $viewer->fresh(),
        $this->profile,
        pngUpload('scan.png'),
        ['title' => 'Nope'],
    ))->toThrow(AuthorizationException::class);

    expect(StaffCertificate::count())->toBe(0);
    Storage::disk('private')->assertDirectoryEmpty('/');
});

it('writes no bytes when the provisional receipt cannot be committed', function () {
    $connection = FileLifecycleService::compensationConnectionName();
    $configurationKey = 'database.connections.'.$connection;
    $originalConfiguration = config($configurationKey);
    assert(is_array($originalConfiguration));

    $brokenConfiguration = $originalConfiguration;
    $brokenConfiguration['database'] = 'missing_'.Str::lower(Str::random(20));

    DB::purge($connection);
    config([$configurationKey => $brokenConfiguration]);

    try {
        expect(fn () => ($this->upload)(pngUpload('never-written.png')))
            ->toThrow(QueryException::class);
    } finally {
        DB::purge($connection);
        config([$configurationKey => $originalConfiguration]);
    }

    expect(StaffCertificate::count())->toBe(0);
    Storage::disk('private')->assertDirectoryEmpty('/');
});

it('records retryable cleanup when a row failure leaves newly written bytes unowned', function () {
    Queue::fake();

    // The write reaches storage first, then the missing parent makes the row
    // insert fail. Cleanup must be durable even before a worker can unlink it.
    $this->profile->delete();

    expect(fn () => ($this->upload)(pngUpload('scan.png')))
        ->toThrow(ModelNotFoundException::class);

    expect(StaffCertificate::count())->toBe(0);

    $files = Storage::disk('private')->allFiles('staff-certificates');
    expect($files)->toHaveCount(1);

    $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->sole();
    expect($pending->disk)->toBe('private')
        ->and($pending->path)->toBe($files[0]);

    Queue::assertPushed(
        PurgeDeletedFileJob::class,
        fn (PurgeDeletedFileJob $job): bool => $job->pendingFileDeletionId === (int) $pending->getKey()
            && $job->usesCompensationConnection,
    );

    (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();

    Storage::disk('private')->assertMissing($pending->path);
    expect(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->whereKey($pending->getKey())
        ->exists())->toBeFalse();
});

it('removes the written file when the row write fails, leaving no orphan', function () {
    // Force the row insert to fail AFTER the file is written: destroy the
    // profile so the staff_profile_id foreign key has nothing to point at. The
    // Action must delete the bytes it just wrote and re-raise — a file with no
    // row is personal data nobody can find or delete.
    $this->profile->delete();

    expect(fn () => ($this->upload)(pngUpload('scan.png')))
        ->toThrow(ModelNotFoundException::class);

    expect(StaffCertificate::count())->toBe(0);
    // The bytes written before the failed insert were cleaned up.
    Storage::disk('private')->assertDirectoryEmpty('/');
});

it('keeps a durable receipt and preserves the row exception when compensating storage deletion fails', function () {
    $path = null;
    $failingDisk = Mockery::mock(Filesystem::class);
    $failingDisk->shouldReceive('putFileAs')
        ->once()
        ->andReturnUsing(function (string $directory, mixed $file, string $name) use (&$path): string {
            $path = $directory.'/'.$name;

            return $path;
        });
    $failingDisk->shouldReceive('delete')
        ->withArgs(fn (string $deletedPath): bool => $deletedPath === $path)
        ->andReturn(false);
    Storage::shouldReceive('disk')->with('private')->andReturn($failingDisk);

    $this->profile->delete();

    $caught = null;
    $originalHandler = app(ExceptionHandler::class);
    $throwingHandler = Mockery::mock(ExceptionHandler::class);
    $throwingHandler->shouldReceive('report')
        ->andThrow(new RuntimeException('The logging transport is unavailable.'));
    app()->instance(ExceptionHandler::class, $throwingHandler);

    try {
        ($this->upload)(pngUpload('scan.png'));
    } catch (Throwable $exception) {
        $caught = $exception;
    } finally {
        app()->instance(ExceptionHandler::class, $originalHandler);
    }

    // Neither a failed compensating unlink nor a failed exception reporter may
    // replace the database/model failure that caused compensation. The receipt
    // carries the storage failure forward.
    expect($caught)->toBeInstanceOf(ModelNotFoundException::class);
    assert(is_string($path));

    $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->sole();
    expect($pending->path)->toBe($path)
        ->and($pending->attempts)->toBeGreaterThan(0)
        ->and($pending->last_error)->not->toBeNull();

    // This test deliberately leaves the bytes undeleted; remove only its receipt
    // so the independent connection cannot leak state into the next test.
    $pending->delete();
});

it('purges an uploaded certificate when an outer transaction rolls back its row', function () {
    Queue::fake();

    $startingLevel = DB::transactionLevel();
    $certificate = null;

    DB::beginTransaction();

    try {
        $certificate = ($this->upload)(pngUpload('rolled-back-certificate.png'));
        Storage::disk('private')->assertExists($certificate->path);

        DB::rollBack();
    } finally {
        while (DB::transactionLevel() > $startingLevel) {
            DB::rollBack();
        }
    }

    assert($certificate instanceof StaffCertificate);

    expect(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeFalse();
    Storage::disk('private')->assertExists($certificate->path);

    $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->where('path', $certificate->path)
        ->sole();
    Queue::assertPushed(
        PurgeDeletedFileJob::class,
        fn (PurgeDeletedFileJob $job): bool => $job->pendingFileDeletionId === (int) $pending->getKey()
            && $job->usesCompensationConnection,
    );

    (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();

    Storage::disk('private')->assertMissing($certificate->path);
    expect(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->whereKey($pending->getKey())
        ->exists())->toBeFalse();
});
