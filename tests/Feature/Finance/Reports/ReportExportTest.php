<?php

declare(strict_types=1);

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Exports\DailyTenderReportExporter;
use App\Domain\Finance\Exports\OutstandingAgedReportExporter;
use App\Domain\Finance\Exports\PaymentMethodReportExporter;
use App\Domain\Finance\Exports\PrepareReportCsvExport;
use App\Domain\Finance\Exports\ProfitReportExporter;
use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportExporter;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Exports\ReportSnapshot;
use App\Domain\Finance\Exports\RevenueReportExporter;
use App\Domain\Finance\Exports\StudentPaymentHistoryExporter;
use App\Domain\Finance\Exports\WageCostReportExporter;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Finance\Jobs\GenerateReportPdfJob;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Jobs\CreateXlsxFile;
use Filament\Actions\Exports\Models\Export;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->admin = ($this->actorWith)('admin');
    $this->staff = ($this->actorWith)('staff');
});

it('publishes the storage required by queued exports and their notifications', function () {
    expect(Schema::hasTable('exports'))->toBeTrue()
        ->and(Schema::hasTable('imports'))->toBeTrue()
        ->and(Schema::hasTable('failed_import_rows'))->toBeTrue()
        ->and(Schema::hasTable('notifications'))->toBeTrue();
});

it('scopes the native export query to the requesting users permissions', function () {
    $fixture = reportHistoryFixture('Safe', 'Student');
    $options = reportSnapshotOptions(
        StudentPaymentHistoryExporter::kind(),
        $fixture['charge'],
        ['student_name' => $fixture['student']->full_name],
    );

    $adminRows = StudentPaymentHistoryExporter::scopeToReport(
        Charge::query(),
        $options,
        $this->admin,
    )->count();

    $staffRows = StudentPaymentHistoryExporter::scopeToReport(
        Charge::query(),
        $options,
        $this->staff,
    )->count();

    expect($adminRows)->toBe(1)
        ->and($staffRows)->toBe(0);
});

it('refuses a native export after its requester loses panel access', function () {
    $fixture = reportHistoryFixture('Deactivated', 'Exporter');
    $options = reportSnapshotOptions(
        StudentPaymentHistoryExporter::kind(),
        $fixture['charge'],
        ['student_name' => $fixture['student']->full_name],
    );
    $this->admin->update(['is_active' => false]);

    $rows = StudentPaymentHistoryExporter::scopeToReport(
        Charge::query(),
        $options,
        $this->admin,
    )->count();

    expect($rows)->toBe(0);
});

dataset('report exporter adapters', [
    [RevenueReportExporter::class, ReportKind::Revenue, Batch::class],
    [OutstandingAgedReportExporter::class, ReportKind::OutstandingAged, Charge::class],
    [PaymentMethodReportExporter::class, ReportKind::PaymentMethod, PaymentTender::class],
    [DailyTenderReportExporter::class, ReportKind::DailyTender, PaymentTender::class],
    [WageCostReportExporter::class, ReportKind::WageCost, User::class],
    [ProfitReportExporter::class, ReportKind::Profit, User::class],
    [StudentPaymentHistoryExporter::class, ReportKind::StudentPaymentHistory, Charge::class],
]);

it('renders every native adapter from one frozen snapshot and rechecks access in queue middleware', function (
    string $exporterClass,
    ReportKind $kind,
    string $modelClass,
) {
    Storage::fake('local');
    $record = reportCarrier($modelClass);
    $columns = collect($exporterClass::getColumns())
        ->mapWithKeys(fn ($column): array => [$column->getName() => $column->getLabel()])
        ->all();
    $cells = collect(array_keys($columns))
        ->mapWithKeys(fn (string $column): array => [$column => "snapshot-{$column}"])
        ->all();
    $options = reportSnapshotOptions($kind, $record, $cells);

    $export = new Export([
        'exporter' => $exporterClass,
        'file_disk' => 'local',
        'file_name' => 'report',
        'total_rows' => 1,
    ]);
    $export->user()->associate($this->admin);
    $export->save();

    /** @var ReportExporter $exporter */
    $exporter = $export->getExporter($columns, $options);

    expect($exporter($record))->toBe(array_values($cells));

    $adminRole = Role::findByName('admin', 'web');
    $this->system->syncRolePermissions(
        $adminRole,
        $adminRole->permissions->reject(
            fn ($permission): bool => $permission->name === 'export_financial_report',
        ),
    );

    $prepare = new PrepareReportCsvExport(
        $export,
        EloquentSerializeFacade::serialize($exporterClass::getModel()::query()),
        $columns,
        $options,
    );

    $run = function () use ($prepare): null {
        $prepare->handle();

        return null;
    };

    expect(fn () => $prepare->middleware()[0]->handle($prepare, $run))
        ->toThrow(AuthorizationException::class)
        ->and($export->refresh()->successful_rows)->toBe(0);
    Storage::disk('local')->assertMissing($export->getFileDirectory());
})->with('report exporter adapters');

it('writes every frozen xlsx row even when its live carrier is deleted after queueing', function () {
    Storage::fake('local');
    $student = Student::factory()->create(['first_name' => 'Deleted', 'last_name' => 'Carrier']);
    $enrollment = Enrollment::factory()->create(['student_id' => $student->getKey()]);
    $charge = Charge::factory()->create(['enrollment_id' => $enrollment->getKey()]);
    $columns = ['student_name' => 'Student'];
    $options = reportSnapshotOptions(
        ReportKind::StudentPaymentHistory,
        $charge,
        ['student_name' => $student->full_name],
    );
    $export = new Export([
        'exporter' => StudentPaymentHistoryExporter::class,
        'file_disk' => 'local',
        'file_name' => 'frozen-report',
        'total_rows' => 1,
    ]);
    $export->user()->associate($this->admin);
    $export->save();
    $serializedQuery = EloquentSerializeFacade::serialize(Charge::query()->whereKey($charge->getKey()));

    $charge->delete();

    (new PrepareReportCsvExport($export, $serializedQuery, $columns, $options))->handle();
    (new CreateXlsxFile($export, $columns, $options))->handle();

    $path = $export->getFileDirectory().'/'.$export->file_name.'.xlsx';
    expect($export->refresh()->successful_rows)->toBe(1)
        ->and(xlsxValues(Storage::disk('local')->path($path)))
        ->toContain('Deleted Carrier');
});

it('issues only the reauthorized xlsx link and refuses it after permission revocation', function () {
    Storage::fake('local');
    $fixture = reportHistoryFixture('Download', 'Owner');
    $options = reportSnapshotOptions(
        ReportKind::StudentPaymentHistory,
        $fixture['charge'],
        ['student_name' => $fixture['student']->full_name],
    );
    $export = new Export([
        'exporter' => StudentPaymentHistoryExporter::class,
        'file_disk' => 'local',
        'file_name' => 'authorized-report',
        'total_rows' => 1,
        'processed_rows' => 1,
        'successful_rows' => 1,
        'completed_at' => now(),
    ]);
    $export->user()->associate($this->admin);
    $export->save();
    (new PrepareReportCsvExport(
        $export,
        EloquentSerializeFacade::serialize(Charge::query()),
        ['student_name' => 'Student'],
        $options,
    ))->handle();
    (new CreateXlsxFile($export, ['student_name' => 'Student'], $options))->handle();

    $notification = StudentPaymentHistoryExporter::modifyCompletedNotification(
        Notification::make(),
        $export->refresh(),
    );
    $downloadUrl = $notification->getActions()[0]->getUrl();

    expect($downloadUrl)->toContain('/admin/reports/xlsx/')
        ->not->toContain('/filament/exports/');
    $this->actingAs($this->admin)->get($downloadUrl)->assertSuccessful();

    $export->update(['successful_rows' => 0]);
    $this->actingAs($this->admin)->get($downloadUrl)->assertNotFound();
    expect(StudentPaymentHistoryExporter::modifyCompletedNotification(
        Notification::make(),
        $export->refresh(),
    )->getActions())->toBeEmpty();
    $export->update(['successful_rows' => 1]);

    $adminRole = Role::findByName('admin', 'web');
    $this->system->syncRolePermissions(
        $adminRole,
        $adminRole->permissions->reject(
            fn ($permission): bool => $permission->name === 'export_financial_report',
        ),
    );

    $this->actingAs($this->admin)->get($downloadUrl)->assertForbidden();
    expect(StudentPaymentHistoryExporter::modifyCompletedNotification(
        Notification::make(),
        $export->refresh(),
    )->getActions())->toBeEmpty();
});

it('keeps an export snapshot unchanged when source rows and worker locale change', function () {
    $fixture = reportHistoryFixture('Frozen', 'Student');
    $snapshot = new ReportSnapshot(
        kind: ReportKind::StudentPaymentHistory,
        title: 'Frozen title',
        locale: 'en',
        filters: ['Student' => $fixture['student']->full_name],
        dataset: new ReportDataset(
            ['student_name' => 'Student'],
            [[
                'carrier_id' => $fixture['charge']->getKey(),
                'cells' => ['student_name' => $fixture['student']->full_name],
            ]],
        ),
    );

    $fixture['student']->update(['first_name' => 'Changed']);
    app()->setLocale('ar');

    $restored = ReportSnapshot::fromArray($snapshot->toArray());
    $export = new Export([
        'exporter' => StudentPaymentHistoryExporter::class,
        'file_disk' => 'local',
        'file_name' => 'report',
        'total_rows' => 1,
    ]);
    $export->user()->associate($this->admin);
    $export->save();
    $firstChunk = $export->getExporter(
        ['student_name' => 'Student'],
        ['snapshot' => $snapshot->toArray()],
    );
    $secondChunk = $export->getExporter(
        ['student_name' => 'Student'],
        ['snapshot' => $snapshot->toArray()],
    );
    $pdfHtml = view('finance.reports.pdf', [
        'snapshot' => $restored,
        'locale' => $restored->locale,
        'direction' => 'ltr',
    ])->render();

    expect($restored->locale)->toBe('en')
        ->and($restored->filters)->toBe(['Student' => 'Frozen Student'])
        ->and($restored->dataset->cell($fixture['charge']->getKey(), 'student_name'))
        ->toBe('Frozen Student')
        ->and($firstChunk($fixture['charge']))->toBe(['Frozen Student'])
        ->and($secondChunk($fixture['charge']))->toBe(['Frozen Student'])
        ->and($pdfHtml)->toContain('Frozen title', 'Student:', 'Frozen Student');
});

it('queues and generates a native xlsx with formula-like student names neutralized', function () {
    Storage::fake('local');
    $fixture = reportHistoryFixture('=HYPERLINK("https://example.test")', 'Target');
    $originalLocale = app()->getLocale();
    app()->setLocale('ar');

    try {
        Livewire::actingAs($this->admin)
            ->test(StudentPaymentHistoryPage::class)
            ->set('filters.student_id', $fixture['student']->getKey())
            ->call('applyFilters')
            ->callAction('export_xlsx')
            ->assertHasNoActionErrors()
            ->assertNotified();
    } finally {
        app()->setLocale($originalLocale);
    }

    $export = Export::query()->sole();

    expect($export->total_rows)->toBe(1)
        ->and($export->successful_rows)->toBe(1)
        ->and($export->completed_at)->not->toBeNull();

    $path = $export->getFileDirectory().'/'.$export->file_name.'.xlsx';
    Storage::disk('local')->assertExists($path);

    $values = xlsxValues(Storage::disk('local')->path($path));

    expect($values)->toContain('Student payment history')
        ->toContain('Student')
        ->toContain("'=HYPERLINK(\"https://example.test\") Target")
        ->not->toContain('=HYPERLINK("https://example.test") Target');
});

it('queues the pdf action and the job stores a real pdf then notifies the requester', function () {
    $originalQueue = Queue::getFacadeRoot();
    $fixture = reportHistoryFixture('PDF', 'Student');

    try {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(StudentPaymentHistoryPage::class)
            ->set('filters.student_id', $fixture['student']->getKey())
            ->call('applyFilters')
            ->callAction('export_pdf')
            ->assertHasNoActionErrors();

        Queue::assertPushed(GenerateReportPdfJob::class);

        /** @var GenerateReportPdfJob $queuedJob */
        $queuedJob = Queue::pushed(GenerateReportPdfJob::class)->sole();
        $snapshot = ReportSnapshot::fromArray($queuedJob->snapshot);

        expect($queuedJob->requesterId)->toBe($this->admin->getKey())
            ->and($snapshot->kind)->toBe(ReportKind::StudentPaymentHistory)
            ->and($snapshot->dataset->carrierIds())->toBe([$fixture['charge']->getKey()])
            ->and($snapshot->filters['Student'])->toEndWith($fixture['student']->full_name);
    } finally {
        Queue::swap($originalQueue);
    }

    Storage::fake('private');

    $job = new GenerateReportPdfJob(
        reportSnapshotOptions(
            StudentPaymentHistoryPage::kind(),
            $fixture['charge'],
            ['student_name' => $fixture['student']->full_name],
        )['snapshot'],
        $this->admin->getKey(),
    );
    $job->handle();

    $path = "financial-reports/{$this->admin->getKey()}/{$job->reference}.pdf";
    Storage::disk('private')->assertExists($path);
    expect(Storage::disk('private')->get($path))->toStartWith('%PDF-')
        ->and(DatabaseNotification::query()->where('notifiable_id', $this->admin->getKey())->exists())->toBeTrue();

    $url = URL::temporarySignedRoute('filament.admin.pages.reports.pdf.download', now()->addMinute(), [
        'requester' => $this->admin->getKey(),
        'reference' => $job->reference,
    ]);

    auth()->logout();
    $this->get($url)->assertRedirect();
    $this->actingAs($this->staff)->get($url)->assertForbidden();
    $this->actingAs(($this->actorWith)('admin'))->get($url)->assertForbidden();
    $this->actingAs($this->admin)->get($url)->assertSuccessful();

    $this->get(route('filament.admin.pages.reports.pdf.download', [
        'requester' => $this->admin->getKey(),
        'reference' => $job->reference,
    ]))->assertForbidden();
});

it('abandons a queued pdf after its requester loses panel access', function () {
    Storage::fake('private');
    $fixture = reportHistoryFixture('Deactivated', 'PDF');
    $this->admin->update(['is_active' => false]);

    $job = new GenerateReportPdfJob(
        reportSnapshotOptions(
            StudentPaymentHistoryPage::kind(),
            $fixture['charge'],
            ['student_name' => $fixture['student']->full_name],
        )['snapshot'],
        $this->admin->getKey(),
    );
    $job->handle();

    Storage::disk('private')->assertMissing(
        "financial-reports/{$this->admin->getKey()}/{$job->reference}.pdf",
    );
    expect(DatabaseNotification::query()->where('notifiable_id', $this->admin->getKey())->exists())->toBeFalse();
});

/**
 * @return array{student: Student, charge: Charge, payment: Payment}
 */
function reportHistoryFixture(string $firstName, string $lastName): array
{
    $student = Student::factory()->create([
        'first_name' => $firstName,
        'last_name' => $lastName,
    ]);
    $enrollment = Enrollment::factory()->create(['student_id' => $student->getKey()]);
    $charge = Charge::factory()->create([
        'enrollment_id' => $enrollment->getKey(),
        'list_price' => '2000.000',
        'amount' => '2000.000',
    ]);
    $payment = Payment::factory()->create(['student_id' => $student->getKey()]);
    PaymentTender::factory()->create([
        'payment_id' => $payment->getKey(),
        'amount' => '500.000',
    ]);
    PaymentAllocation::factory()->create([
        'payment_id' => $payment->getKey(),
        'charge_id' => $charge->getKey(),
        'amount' => '500.000',
    ]);

    return compact('student', 'charge', 'payment');
}

/**
 * @param  array<string, string|int|null>  $cells
 * @return array{snapshot: array<string, mixed>}
 */
function reportSnapshotOptions(ReportKind $kind, object $carrier, array $cells): array
{
    return [
        'snapshot' => (new ReportSnapshot(
            kind: $kind,
            title: 'Report title',
            locale: 'en',
            filters: ['Period' => '2026-08'],
            dataset: new ReportDataset(
                collect(array_keys($cells))->mapWithKeys(fn (string $column): array => [$column => $column])->all(),
                [[
                    'carrier_id' => (int) $carrier->getKey(),
                    'cells' => $cells,
                ]],
            ),
        ))->toArray(),
    ];
}

/** @param class-string $modelClass */
function reportCarrier(string $modelClass): object
{
    return match ($modelClass) {
        Batch::class => Batch::factory()->create(),
        Charge::class => Charge::factory()->create(),
        PaymentTender::class => PaymentTender::factory()->create(),
        User::class => User::factory()->create(),
    };
}

/** @return list<string | int | float | bool | null> */
function xlsxValues(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $values = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCells() as $cell) {
                $values[] = $cell->getValue();
            }
        }
    }

    $reader->close();

    return $values;
}
