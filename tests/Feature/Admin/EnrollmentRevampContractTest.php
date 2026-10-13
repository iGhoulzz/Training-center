<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Pages\CertificateIssuanceQueue;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\EnrollmentsRelationManager;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages\ListStudentCertificates;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Models\Charge;
use App\Domain\Staff\Filament\Resources\UserResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };
});

it('keeps over-capacity enrollment available and flagged with the exact grant', function () {
    $batch = Batch::factory()->active()->create(['capacity' => 1]);
    Enrollment::factory()->for($batch)->create();
    $student = Student::factory()->create();
    $permissions = ['view_batch', 'view_any_batch', 'view_any_enrollment'];
    $actor = ($this->actor)([...$permissions, 'create_enrollment']);
    Livewire::actingAs($actor)->test(EnrollmentsRelationManager::class, [
        'ownerRecord' => $batch, 'pageClass' => ViewBatch::class,
    ])->callTableAction('enroll', null, ['student_id' => $student->getKey()])
        ->assertNotified(__('enrollment.over_capacity_warning'));
    expect($batch->enrollments()->count())->toBe(2)->and(Charge::query()->count())->toBe(1);
    Livewire::actingAs($actor)->test(ListBatches::class)
        ->assertTableColumnStateSet('enrolment_load', '2 / 1', $batch);
    $denied = ($this->actor)($permissions);
    Livewire::actingAs($denied)->test(EnrollmentsRelationManager::class, [
        'ownerRecord' => $batch, 'pageClass' => ViewBatch::class,
    ])->assertTableActionHidden('enroll')
        ->mountTableAction('enroll')->assertTableActionNotMounted('enroll');
    expect($batch->enrollments()->count())->toBe(2);
});

it('shows debt on the restyled issue form without blocking the exact issuer', function () {
    $enrollment = Enrollment::factory()->completed()->create();
    Charge::factory()->for($enrollment)->create(['list_price' => '750.125', 'amount' => '750.125']);
    $actor = ($this->actor)(['view_any_student_certificate', 'issue_student_certificate']);
    $component = Livewire::actingAs($actor)->test(ListStudentCertificates::class)
        ->mountAction('issue')->setActionData(['enrollment_id' => $enrollment->getKey()]);
    $page = $component->instance();
    $entry = $page->getSchema($page->getMountedActionSchemaName())->getComponent('outstanding_balance');
    expect($entry)->not->toBeNull()->and((string) $entry->getState())->toContain('750.125');
    $component->callMountedAction()->assertHasNoActionErrors();
    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())->toBe(1);
    $denied = ($this->actor)(['view_any_student_certificate']);
    Livewire::actingAs($denied)->test(ListStudentCertificates::class)
        ->assertActionHidden('issue')->mountAction('issue')->assertActionNotMounted('issue');
    expect(StudentCertificate::query()->count())->toBe(1);
});

it('cannot issue a portal login without email on the restyled student register', function () {
    $student = Student::factory()->create(['email' => null]);
    $permissions = ['view_any_student', 'view_student'];
    $actor = ($this->actor)([...$permissions, 'issue_portal_credential']);
    $usersBefore = User::query()->count();
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->assertTableActionDisabled('issuePortalCredential', $student)
        ->mountTableAction('issuePortalCredential', $student)->assertTableActionNotMounted('issuePortalCredential');
    expect($student->fresh()->user_id)->toBeNull()->and(User::query()->count())->toBe($usersBefore);
    $withEmail = Student::factory()->create();
    Livewire::actingAs($actor)->test(ListStudents::class)->callTableAction('issuePortalCredential', $withEmail);
    expect($withEmail->fresh()->user_id)->not->toBeNull();
    $denied = ($this->actor)($permissions);
    $another = Student::factory()->create();
    Livewire::actingAs($denied)->test(ListStudents::class)
        ->assertTableActionHidden('issuePortalCredential', $another)
        ->mountTableAction('issuePortalCredential', $another)->assertTableActionNotMounted('issuePortalCredential');
    expect($another->fresh()->user_id)->toBeNull();
});

it('groups student creation by identity contact and records', function () {
    $actor = ($this->actor)(['view_any_student', 'create_student']);
    Livewire::actingAs($actor)->test(CreateStudent::class)
        ->assertSee('Identity')->assertSee('Contact')->assertSee('Records')
        ->assertSee('No email means no portal login.');
});

it('opens a selected student in the detail panel and preserves the URL selection', function () {
    $student = Student::factory()->create(['email' => 'detail@example.test']);
    $actor = ($this->actor)(['view_any_student', 'view_student']);
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->callTableAction('selectStudent', $student)
        ->assertSet('selectedStudentId', $student->getKey())->assertSee('detail@example.test');
});

it('renders real course card counts and the eligible certificate queue callout', function () {
    $course = Course::factory()->create();
    Batch::factory()->count(2)->for($course)->create();
    Enrollment::factory()->completed()->create();
    $actor = ($this->actor)(['view_any_course', 'view_any_student_certificate', 'issue_student_certificate']);
    Livewire::actingAs($actor)->test(ListCourses::class)->assertTableColumnStateSet('batches_count', 2, $course);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)
        ->assertSee('1 completed enrolment awaiting certificate issuance')->assertSee('Review queue');
    Livewire::actingAs($actor)->test(CertificateIssuanceQueue::class)->assertSuccessful();
});

it('applies capacity and hour mismatch tabs to the real batch query', function () {
    $course = Course::factory()->create(['total_hours' => 30]);
    $over = Batch::factory()->for($course)->active()->create(['capacity' => 1]);
    Enrollment::factory()->count(2)->for($over)->create();
    $unlimited = Batch::factory()->for($course)->create(['capacity' => 0, 'total_hours' => 0]);
    Enrollment::factory()->count(2)->for($unlimited)->create();
    $actor = ($this->actor)(['view_any_batch']);
    Livewire::actingAs($actor)->test(ListBatches::class)
        ->set('activeTab', 'over_capacity')->assertCanSeeTableRecords([$over])
        ->assertCanNotSeeTableRecords([$unlimited]);
    Livewire::actingAs($actor)->test(ListBatches::class)
        ->set('activeTab', 'hour_mismatch')->assertCanSeeTableRecords([$over])
        ->assertCanNotSeeTableRecords([$unlimited]);
});

it('applies student tabs without exposing financial data to a student reader', function () {
    $owing = Student::factory()->create();
    $paid = Student::factory()->create();
    $enrollment = Enrollment::factory()->for($owing)->create();
    Charge::factory()->for($enrollment)->create(['list_price' => '456.789', 'amount' => '456.789']);
    $actor = ($this->actor)(['view_any_student', 'view_student', 'view_any_charge']);
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->set('activeTab', 'owes_money')->assertCanSeeTableRecords([$owing])
        ->assertCanNotSeeTableRecords([$paid]);
    $denied = ($this->actor)(['view_any_student', 'view_student']);
    Livewire::actingAs($denied)->test(ListStudents::class)
        ->set('selectedStudentId', $owing->getKey())->assertDontSee('456.789')->assertDontSee('Owes money');
});

it('does not disclose the selected record without its view permission', function () {
    $student = Student::factory()->create(['email' => 'private-detail@example.test']);
    $actor = ($this->actor)(['view_any_student']);
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->assertTableActionHidden('selectStudent', $student)
        ->set('selectedStudentId', $student->getKey())->assertDontSee('private-detail@example.test');
});

it('issues portal credentials from the selected student footer with the same email guard', function () {
    $student = Student::factory()->create();
    $actor = ($this->actor)(['view_any_student', 'view_student', 'issue_portal_credential']);
    $component = Livewire::actingAs($actor)->test(ListStudents::class)
        ->set('selectedStudentId', $student->getKey());
    $component->callAction(TestAction::make('issuePortalCredential')->schemaComponent('studentDetail', 'content'));
    expect($student->fresh()->user_id)->not->toBeNull();
    $noEmail = Student::factory()->create(['email' => null]);
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->set('selectedStudentId', $noEmail->getKey())->assertActionDisabled(TestAction::make('issuePortalCredential')->schemaComponent('studentDetail', 'content'))
        ->mountAction(TestAction::make('issuePortalCredential')->schemaComponent('studentDetail', 'content'))
        ->assertActionNotMounted(TestAction::make('issuePortalCredential')->schemaComponent('studentDetail', 'content'));
    expect($noEmail->fresh()->user_id)->toBeNull();
});

it('hides the queue from a register reader without the issue permission', function () {
    Enrollment::factory()->completed()->create();
    $actor = ($this->actor)(['view_any_student_certificate']);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)->assertDontSee('Review queue');
    $this->actingAs($actor)->get(CertificateIssuanceQueue::getUrl())->assertForbidden();
});

it('keeps the issuer name static even when the actor can edit staff accounts', function (string $missingPermission) {
    $issuer = User::factory()->create();
    $certificate = StudentCertificate::factory()->create(['issued_by' => $issuer->getKey()]);
    $permissions = ['view_any_student_certificate', 'view_any_user', 'update_user'];
    $actor = ($this->actor)($permissions);
    $url = UserResource::getUrl('edit', ['record' => $issuer]);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)
        ->assertTableColumnStateSet('issuedBy.name', $issuer->name, $certificate)
        ->assertSee($issuer->name)
        ->assertDontSee($url, false);
    $denied = ($this->actor)(array_values(array_diff($permissions, [$missingPermission])));
    Livewire::actingAs($denied)->test(ListStudentCertificates::class)
        ->assertTableColumnStateSet('issuedBy.name', $issuer->name, $certificate)
        ->assertSee($issuer->name)
        ->assertDontSee($url, false);
})->with(['update_user', 'view_any_user']);

it('keeps certificate snapshots readable without linking to deleted records', function () {
    $certificate = StudentCertificate::factory()->create();
    $student = $certificate->enrollment->student;
    $studentUrl = StudentResource::getUrl('view', ['record' => $student]);
    $student->delete();
    $certificate->issuedBy->delete();
    $actor = ($this->actor)(['view_any_student_certificate', 'view_any_student', 'view_student', 'view_any_user', 'update_user']);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)
        ->assertSee($certificate->student_name)->assertDontSee($studentUrl, false)->assertSuccessful();
});
