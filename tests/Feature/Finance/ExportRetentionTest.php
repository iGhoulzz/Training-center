<?php

declare(strict_types=1);

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\Domain\Finance\Exports\PrepareReportCsvExport;
use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Exports\ReportSnapshot;
use App\Domain\Finance\Exports\StudentPaymentHistoryExporter;
use App\Domain\Finance\Jobs\GenerateReportPdfJob;
use App\Domain\Finance\Models\Charge;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Enums\PathKind;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Jobs\CreateXlsxFile;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function (): void {
    $this->travelTo('2026-09-01 00:00:00');

    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->admin, 'admin');
    $this->admin->refresh();
});

afterEach(function (): void {
    $this->travelBack();
    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

it('defines explicit path kinds for ordinary files and generated directories', function (): void {
    expect(enum_exists(PathKind::class))->toBeTrue();
});

it('records one directory receipt when CSV bytes are generated', function (): void {
    Storage::fake('local');

    [$export, $options] = retentionExport($this->admin);

    (new PrepareReportCsvExport(
        $export,
        EloquentSerializeFacade::serialize(Charge::query()),
        ['student_name' => 'Student'],
        $options,
    ))->handle();

    expect(PendingFileDeletion::query()->count())->toBe(1);
});

it('schedules the generated CSV directory for deletion after seven days', function (): void {
    Storage::fake('local');

    [$export, $options] = retentionExport($this->admin);

    (new PrepareReportCsvExport(
        $export,
        EloquentSerializeFacade::serialize(Charge::query()),
        ['student_name' => 'Student'],
        $options,
    ))->handle();

    $receipt = PendingFileDeletion::query()->sole();

    expect($receipt->disk)->toBe('local')
        ->and($receipt->path)->toBe($export->getFileDirectory())
        ->and($receipt->path_kind)->toBe(PathKind::Directory)
        ->and($receipt->delete_after)->toEqual(now()->addDays(7));
});

it('does not duplicate the directory receipt when XLSX bytes follow the CSV bytes', function (): void {
    Storage::fake('local');

    [$export, $options] = retentionExport($this->admin);

    (new PrepareReportCsvExport(
        $export,
        EloquentSerializeFacade::serialize(Charge::query()),
        ['student_name' => 'Student'],
        $options,
    ))->handle();
    (new CreateXlsxFile($export, ['student_name' => 'Student'], $options))->handle();

    expect(PendingFileDeletion::query()->count())->toBe(1)
        ->and(PendingFileDeletion::query()->sole()->path)->toBe($export->getFileDirectory());
});

it('records one file receipt only after PDF bytes are successfully written', function (): void {
    Storage::fake('private');

    $job = new GenerateReportPdfJob(retentionSnapshot(), $this->admin->getKey());
    $job->handle();

    expect(PendingFileDeletion::query()->count())->toBe(1)
        ->and(PendingFileDeletion::query()->sole()->path)
        ->toBe("financial-reports/{$this->admin->getKey()}/{$job->reference}.pdf");
});

it('schedules the generated PDF file for deletion after seven days', function (): void {
    Storage::fake('private');

    $job = new GenerateReportPdfJob(retentionSnapshot(), $this->admin->getKey());
    $job->handle();

    $receipt = PendingFileDeletion::query()->sole();

    expect($receipt->disk)->toBe('private')
        ->and($receipt->path)->toBe("financial-reports/{$this->admin->getKey()}/{$job->reference}.pdf")
        ->and($receipt->path_kind)->toBe(PathKind::File)
        ->and($receipt->delete_after)->toEqual(now()->addDays(7));
});

it('does not record a receipt when PDF storage fails', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('private')->andReturn($filesystem);

    $job = new GenerateReportPdfJob(retentionSnapshot(), $this->admin->getKey());

    expect(fn (): mixed => $job->handle())->toThrow(RuntimeException::class, 'The report PDF could not be stored.');
    expect(PendingFileDeletion::query()->count())->toBe(0);
});

it('does not schedule a receipt when the CSV row write fails', function (): void {
    [$export, $options] = retentionExport($this->admin);
    $filesystem = Mockery::mock(Filesystem::class);
    $directory = $export->getFileDirectory();

    $filesystem->shouldReceive('put')
        ->once()
        ->with($directory.DIRECTORY_SEPARATOR.'headers.csv', Mockery::type('string'), Filesystem::VISIBILITY_PRIVATE)
        ->andReturnTrue();
    $filesystem->shouldReceive('put')
        ->once()
        ->with(
            $directory.DIRECTORY_SEPARATOR.str_pad('1', 16, '0', STR_PAD_LEFT).'.csv',
            Mockery::type('string'),
            Filesystem::VISIBILITY_PRIVATE,
        )
        ->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('local')->andReturn($filesystem);

    $job = new PrepareReportCsvExport(
        $export,
        EloquentSerializeFacade::serialize(Charge::query()),
        ['student_name' => 'Student'],
        $options,
    );

    expect(fn (): mixed => $job->handle())->toThrow(RuntimeException::class, 'The report CSV could not be stored.');
    expect(PendingFileDeletion::query()->count())->toBe(0);
});

it('refuses to schedule a directory outside the Filament export prefix', function (): void {
    expect(fn (): int => app(FileLifecycleService::class)->scheduleDeletion(
        'local',
        'staff-certificates/not-an-export',
        PathKind::Directory,
        now()->addDays(7)->toImmutable(),
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses to schedule the export disk root as a directory', function (): void {
    expect(fn (): int => app(FileLifecycleService::class)->scheduleDeletion(
        'local',
        '',
        PathKind::Directory,
        now()->addDays(7)->toImmutable(),
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses to schedule a directory containing a traversal segment', function (): void {
    expect(fn (): int => app(FileLifecycleService::class)->scheduleDeletion(
        'local',
        'filament_exports/../staff-certificates/not-an-export',
        PathKind::Directory,
        now()->addDays(7)->toImmutable(),
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a dot segment that Flysystem would normalize to the export root', function (): void {
    expect(fn (): int => app(FileLifecycleService::class)->scheduleDeletion(
        'local',
        'filament_exports/.',
        PathKind::Directory,
        now()->addDays(7)->toImmutable(),
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses an empty segment in an otherwise nested export directory', function (): void {
    expect(fn (): int => app(FileLifecycleService::class)->scheduleDeletion(
        'local',
        'filament_exports//export',
        PathKind::Directory,
        now()->addDays(7)->toImmutable(),
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses to schedule an export directory on another disk', function (): void {
    expect(fn (): int => app(FileLifecycleService::class)->scheduleDeletion(
        'private',
        'filament_exports/export',
        PathKind::Directory,
        now()->addDays(7)->toImmutable(),
    ))->toThrow(InvalidArgumentException::class);
});

it('removes an expired generated export directory and its contents during the sweep', function (): void {
    Storage::fake('local');

    $directory = 'filament_exports/expired-export';
    Storage::disk('local')->put($directory.'/headers.csv', 'headers');
    Storage::disk('local')->put($directory.'/0000000000000001.csv', 'rows');

    $receiptId = app(FileLifecycleService::class)->scheduleDeletion(
        'local',
        $directory,
        PathKind::Directory,
        now()->subSecond()->toImmutable(),
    );
    DB::table('pending_file_deletions')
        ->where('id', $receiptId)
        ->update(['created_at' => now()->subHours(2)]);

    $this->artisan('files:sweep-pending-deletions')->assertSuccessful();

    Storage::disk('local')->assertMissing($directory.'/headers.csv');
    Storage::disk('local')->assertMissing($directory.'/0000000000000001.csv');
    expect(PendingFileDeletion::query()->whereKey($receiptId)->exists())->toBeFalse();
});

/** @return array{0: Export, 1: array{snapshot: array<string, mixed>}} */
function retentionExport(User $requester): array
{
    $export = new Export([
        'exporter' => StudentPaymentHistoryExporter::class,
        'file_disk' => 'local',
        'file_name' => 'retention-report',
        'total_rows' => 1,
    ]);
    $export->user()->associate($requester);
    $export->save();

    return [$export, ['snapshot' => retentionSnapshot()]];
}

/** @return array<string, mixed> */
function retentionSnapshot(): array
{
    return (new ReportSnapshot(
        kind: ReportKind::StudentPaymentHistory,
        title: 'Retention report',
        locale: 'en',
        filters: ['Period' => '2026-08'],
        dataset: new ReportDataset(
            ['student_name' => 'Student'],
            [[
                'carrier_id' => 1,
                'cells' => ['student_name' => 'Retention Student'],
            ]],
        ),
    ))->toArray();
}
