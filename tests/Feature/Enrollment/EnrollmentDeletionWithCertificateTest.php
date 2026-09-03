<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\DeleteEnrollmentAction;
use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Exceptions\EnrollmentHasCertificateException;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\EnrollmentsRelationManager;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->admin, 'admin');

    $this->batch = Batch::factory()->for(Course::factory())->active()->create();
    $this->deleteEnrollment = app(DeleteEnrollmentAction::class);

    $this->mountPanel = fn () => Livewire::actingAs($this->admin)
        ->test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => ViewBatch::class,
        ]);
});

it('deletes an enrolment with no certificate', function (): void {
    $enrollment = Enrollment::factory()->for($this->batch)->create();

    $this->deleteEnrollment->execute($this->admin, $enrollment);

    expect(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeFalse();
});

it('refuses to delete an enrolment that holds any certificate', function (CertificateStatus $status): void {
    // Catches deleting the StudentCertificate existence guard from
    // DeleteEnrollmentAction: valid, revoked, and replaced rows all restrict
    // deletion of their enrolment.
    $enrollment = Enrollment::factory()->for($this->batch)->create();

    match ($status) {
        CertificateStatus::Valid => StudentCertificate::factory()->for($enrollment)->create(),
        CertificateStatus::Revoked => StudentCertificate::factory()->for($enrollment)->revoked()->create(),
        CertificateStatus::Replaced => StudentCertificate::factory()->for($enrollment)->replaced()->create(),
    };

    $thrown = null;

    try {
        $this->deleteEnrollment->execute($this->admin, $enrollment);
    } catch (EnrollmentHasCertificateException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(EnrollmentHasCertificateException::class)
        ->and($thrown?->enrollmentId)->toBe((int) $enrollment->getKey())
        ->and($thrown?->getMessage())->toBe(__('enrollment.enrollment_has_certificate'))
        ->and(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeTrue();
})->with([
    'valid certificate' => CertificateStatus::Valid,
    'revoked certificate' => CertificateStatus::Revoked,
    'replaced certificate' => CertificateStatus::Replaced,
]);

it('refuses deletion when a certificate committed after the transaction snapshot became stale', function (): void {
    // Catches removing lockForUpdate() from DeleteEnrollmentAction's certificate
    // query: its ordinary snapshot read would miss this row and hit the FK.
    $enrollment = Enrollment::factory()->for($this->batch)->create();

    // Warm the permission cache before the transaction fixes its snapshot. A
    // cold permission-cache write would otherwise make a later ordinary read
    // current for a reason unrelated to the certificate query's own lock.
    Gate::forUser($this->admin)->authorize('delete', $enrollment);

    Config::set(
        'database.connections.enrollment_deletion_certificate_probe',
        Config::get('database.connections.'.config('database.default')),
    );
    DB::purge('enrollment_deletion_certificate_probe');
    $secondary = DB::connection('enrollment_deletion_certificate_probe');

    expect($secondary->selectOne('select connection_id() as id')->id)
        ->not->toBe(DB::connection()->selectOne('select connection_id() as id')->id);

    DB::beginTransaction();
    $transactionEnded = false;

    try {
        // EnrollmentMutex::acquire() starts with the same ordinary read.
        Enrollment::query()->count();

        $reference = 'TC-'.now()->year.'-DELETE01';

        $secondary->table('student_certificates')->insert([
            'enrollment_id' => $enrollment->getKey(),
            'reference_number' => $reference,
            'student_name' => 'Snapshot Probe',
            'course_name' => 'Snapshot Probe Course',
            'completed_on' => now()->toDateString(),
            'issued_at' => now(),
            'issued_by' => $this->admin->getKey(),
            'status' => CertificateStatus::Valid->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $thrown = null;

        try {
            $this->deleteEnrollment->execute($this->admin, $enrollment);
        } catch (EnrollmentHasCertificateException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(EnrollmentHasCertificateException::class)
            ->and($thrown?->enrollmentId)->toBe((int) $enrollment->getKey())
            ->and($thrown?->getMessage())->toBe(__('enrollment.enrollment_has_certificate'));

        DB::rollBack();
        $transactionEnded = true;
    } finally {
        if (! $transactionEnded && DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $secondary->table('student_certificates')
            ->where('reference_number', $reference ?? '')
            ->delete();
    }
});

it('notifies an administrator when the panel refuses a certified enrolment deletion', function (): void {
    // Catches removing EnrollmentHasCertificateException from the panel action
    // catch: the domain refusal must become this exact translated notification.
    $enrollment = Enrollment::factory()->for($this->batch)->create();
    StudentCertificate::factory()->for($enrollment)->create();

    ($this->mountPanel)()
        ->callTableAction('delete', $enrollment)
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('enrollment.enrollment_has_certificate'));

    expect(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeTrue();
});
