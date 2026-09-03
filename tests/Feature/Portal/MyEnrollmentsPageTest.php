<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| My enrolments — course, batch, dates, status; certificate columns are a
| SEPARATE grant on the same page
|--------------------------------------------------------------------------
|
| Bespoke, single-ability actors throughout — see OverviewPageTest's own
| header for why the seeded `student` role (all five abilities at once)
| cannot be used to prove one ability gates one thing.
*/

/**
 * @param  array<int, string>  $abilities
 * @return array{0: User, 1: Student}
 */
function enrollmentsActor(array $abilities): array
{
    $user = User::factory()->create(['is_active' => true]);
    $student = Student::factory()->for($user)->create();
    $user->givePermissionTo($abilities);

    return [$user->fresh(), $student];
}

/**
 * A completed enrolment for $student, with a distinctive course and batch.
 *
 * THE DATES ARE FIXED AND DELIBERATELY OLD, and that is a bug fix rather than
 * tidiness. An earlier version of the dates assertion used whatever the factory
 * produced — which is today — and a mutation that DELETED the whole dates
 * column from the view did not fail it, because today's date also renders
 * elsewhere on the page (the batch carries its own dates). The assertion was
 * agreeing with the page rather than testing it.
 *
 * 2019-03-07 and 2021-11-23 cannot be produced by any other column, so seeing
 * them proves the enrolment's own date cells rendered.
 */
function completedEnrollmentFor(Student $student, string $courseCode, string $batchCode): Enrollment
{
    $course = Course::factory()->create(['code' => $courseCode, 'name_en' => $courseCode]);
    $batch = Batch::factory()->for($course)->create(['code' => $batchCode]);

    return Enrollment::factory()->completed()->for($student)->for($batch)->create([
        'enrolled_at' => CarbonImmutable::parse('2019-03-07 09:00:00', 'UTC'),
        'completed_at' => CarbonImmutable::parse('2021-11-23 09:00:00', 'UTC'),
    ]);
}

it('renders for a student holding view_own_enrollment', function () {
    [$user, $student] = enrollmentsActor(['access_student_portal', 'view_own_enrollment']);
    $enrollment = completedEnrollmentFor($student, 'ENG-B1', 'BATCH-JAN');

    $response = $this->actingAs($user, 'student')->get('/portal/my-enrollments')
        ->assertSuccessful();

    /*
     * AGAINST THE RENDERED HTML, NOT THE RAW BODY.
     *
     * Every one of these values also sits in Livewire's wire:snapshot payload,
     * so a plain assertSee() passes even when the view renders nothing —
     * measured by emptying the Blade file entirely and watching this test stay
     * green. renderedWithoutLivewireState() strips the payload so the assertion
     * means what its name says.
     */
    $rendered = renderedWithoutLivewireState($response);

    expect($rendered)
        ->toContain('ENG-B1')
        ->toContain('BATCH-JAN')
        ->toContain($enrollment->status->label())
        /*
         * THE DATES, WHICH NOTHING ASSERTED. The plan and design both name
         * "course, batch, DATES, enrolment status", and cross-review deleted
         * both date headers and both body cells from the view without a single
         * test failing. LocalizationTest is not a backstop either: it checks
         * that referenced keys resolve, so two newly-unreferenced keys go
         * unnoticed.
         */
        // LITERAL dates, not a re-read of the model — see completedEnrollmentFor().
        ->toContain('2019-03-07')
        ->toContain('2021-11-23');
});

it('shows the not-completed placeholder for an enrolment still in progress', function () {
    // The `?? __('portal.not_completed')` fallback, which was also untested —
    // and the case a student is most likely to be looking at.
    [$user, $student] = enrollmentsActor(['access_student_portal', 'view_own_enrollment']);

    $enrollment = Enrollment::factory()
        ->for($student)
        ->for(Batch::factory()->for(Course::factory()->create(['name_en' => 'In Progress Course'])))
        ->create(['enrolled_at' => CarbonImmutable::parse('2018-05-14 09:00:00', 'UTC')]);

    expect($enrollment->completed_at)->toBeNull();

    $rendered = renderedWithoutLivewireState(
        $this->actingAs($user, 'student')->get('/portal/my-enrollments')->assertSuccessful(),
    );

    expect($rendered)
        ->toContain(__('portal.not_completed'))
        ->toContain('2018-05-14');
});

it('refuses a student without view_own_enrollment', function () {
    [$user] = enrollmentsActor(['access_student_portal', 'view_own_student_record', 'view_own_balance', 'view_own_certificate']);

    $this->actingAs($user, 'student')->get('/portal/my-enrollments')->assertForbidden();
});

it('shows an empty state for a student with no enrolments, rather than erroring', function () {
    [$user] = enrollmentsActor(['access_student_portal', 'view_own_enrollment']);

    $this->actingAs($user, 'student')->get('/portal/my-enrollments')->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| The certificate columns: a separate grant, asserted ABSENT not merely
| assumed hidden
|--------------------------------------------------------------------------
*/

it('shows the certificate reference and status when the actor holds both abilities', function () {
    [$user, $student] = enrollmentsActor(['access_student_portal', 'view_own_enrollment', 'view_own_certificate']);
    $enrollment = completedEnrollmentFor($student, 'ENG-B1', 'BATCH-JAN');

    $certificate = StudentCertificate::factory()->for($enrollment)->create([
        'reference_number' => 'TC-2026-AAAA1111',
    ]);

    $response = $this->actingAs($user, 'student')->get('/portal/my-enrollments');

    $response->assertSuccessful()
        ->assertSee('TC-2026-AAAA1111', false)
        ->assertSee($certificate->status->label(), false);
});

it('renders the enrolment list with the certificate columns genuinely absent when the actor lacks view_own_certificate', function () {
    // Holding view_own_enrollment ALONE — the exact "sees the list, not the
    // certificate columns" case the design table names explicitly.
    [$user, $student] = enrollmentsActor(['access_student_portal', 'view_own_enrollment']);
    $enrollment = completedEnrollmentFor($student, 'ENG-B1', 'BATCH-JAN');

    $certificate = StudentCertificate::factory()->for($enrollment)->create([
        'reference_number' => 'TC-2026-BBBB2222',
    ]);

    $response = $this->actingAs($user, 'student')->get('/portal/my-enrollments');

    $response->assertSuccessful()
        // The row itself still renders — this is a missing COLUMN, not a
        // missing row.
        ->assertSee('ENG-B1', false)
        // The reference must not appear anywhere in the response — asserted
        // as absent, not inferred from a hidden CSS class, matching the
        // Done-when item verbatim.
        ->assertDontSee('TC-2026-BBBB2222', false)
        // The column HEADER must be absent too — mount() never even queries
        // student_certificates for this actor (MyEnrollments::certificatesFor()),
        // so there is nothing partially rendered to hide.
        ->assertDontSee(__('portal.enrollments_certificate_reference'), false)
        ->assertDontSee(__('portal.enrollments_certificate_status'), false);

    expect($certificate->status)->toBe(CertificateStatus::Valid);
});

it('shows no reference for a student whose only certificate is replaced', function () {
    [$user, $student] = enrollmentsActor(['access_student_portal', 'view_own_enrollment', 'view_own_certificate']);
    $enrollment = completedEnrollmentFor($student, 'ENG-B1', 'BATCH-JAN');

    StudentCertificate::factory()->replaced()->for($enrollment)->create([
        'reference_number' => 'TC-2026-CCCC3333',
    ]);

    $this->actingAs($user, 'student')->get('/portal/my-enrollments')
        ->assertSuccessful()
        ->assertDontSee('TC-2026-CCCC3333', false);
});

it('shows no reference for a student whose only certificate is revoked', function () {
    [$user, $student] = enrollmentsActor(['access_student_portal', 'view_own_enrollment', 'view_own_certificate']);
    $enrollment = completedEnrollmentFor($student, 'ENG-B1', 'BATCH-JAN');

    StudentCertificate::factory()->revoked()->for($enrollment)->create([
        'reference_number' => 'TC-2026-DDDD4444',
    ]);

    $this->actingAs($user, 'student')->get('/portal/my-enrollments')
        ->assertSuccessful()
        ->assertDontSee('TC-2026-DDDD4444', false);
});
