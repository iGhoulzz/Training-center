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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
