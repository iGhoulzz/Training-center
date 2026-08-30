<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\CompleteEnrollmentAction;
use App\Domain\Enrollment\Actions\ReverseEnrollmentCompletionAction;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\CompletionNotReversibleException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotCompletableException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

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

    $this->course = Course::factory()->create(['total_hours' => 30]);
    $this->batch = Batch::factory()->for($this->course)->active()->create(['capacity' => 5]);

    $this->complete = app(CompleteEnrollmentAction::class);
    $this->reverse = app(ReverseEnrollmentCompletionAction::class);

    /** An active enrolment on the fixture batch, or a batch supplied by the caller. */
    $this->enrolSomeone = fn (?Batch $batch = null): Enrollment => Enrollment::factory()
        ->for($batch ?? $this->batch)
        ->create();

    /** A completed enrolment on the fixture batch, ready to be reversed. */
    $this->completedEnrollment = fn (): Enrollment => Enrollment::factory()
        ->for($this->batch)
        ->completed()
        ->create();
});

/*
|--------------------------------------------------------------------------
| The transition itself
|--------------------------------------------------------------------------
*/

it('completes an active enrolment and sets completed_at from the server clock', function () {
    $this->travelTo('2026-08-30 10:15:00');

    $enrollment = ($this->enrolSomeone)();

    $result = $this->complete->execute($this->admin, $enrollment);

    expect($result->status)->toBe(EnrollmentStatus::Completed)
        ->and($result->completed_at?->toDateTimeString())->toBe('2026-08-30 10:15:00')
        ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed)
        ->and($enrollment->fresh()->completed_at?->toDateTimeString())->toBe('2026-08-30 10:15:00');
});

it('lets a staff actor assigned to the batch complete an enrolment on it', function () {
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    $this->batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 30]);

    $enrollment = ($this->enrolSomeone)();

    $result = $this->complete->execute($staff, $enrollment);

    expect($result->status)->toBe(EnrollmentStatus::Completed);
});

it('refuses a staff actor who does not teach the batch', function () {
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();

    $enrollment = ($this->enrolSomeone)();

    expect(fn () => $this->complete->execute($staff, $enrollment))
        ->toThrow(AuthorizationException::class);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
});

it('refuses to complete a withdrawn enrolment', function () {
    $enrollment = Enrollment::factory()->for($this->batch)->withdrawn()->create();

    expect(fn () => $this->complete->execute($this->admin, $enrollment))
        ->toThrow(EnrollmentNotCompletableException::class);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Withdrawn);
});

it('refuses a second completion rather than treating it as idempotent', function () {
    // Unlike withdrawal, which is idempotent, a second completion is a refusal:
    // the design table names no idempotent case for this transition, and a
    // silent no-op would let a completed_at get silently overwritten by whoever
    // clicks the button twice.
    $enrollment = Enrollment::factory()->for($this->batch)->completed()->create();
    $originalCompletedAt = $enrollment->completed_at;

    expect(fn () => $this->complete->execute($this->admin, $enrollment))
        ->toThrow(EnrollmentNotCompletableException::class);

    expect($enrollment->fresh()->completed_at?->toDateTimeString())
        ->toBe($originalCompletedAt->toDateTimeString());
});

/*
|--------------------------------------------------------------------------
| Locking, order, and the transaction boundary
|--------------------------------------------------------------------------
|
| Mirrors EnrollmentTest's proof for WithdrawEnrollmentAction: the batch is
| locked before the enrolment, matching EnrollStudentAction's order, so this
| Action cannot deadlock against it under concurrency.
*/

it('locks the batch before the enrollment when completing', function () {
    $enrollment = ($this->enrolSomeone)();

    $statements = captureStatements();

    $this->complete->execute($this->admin, $enrollment);

    $ordered = collect($statements)->values();

    $batchLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `batches`'));

    $enrollmentLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `enrollments`'));

    expect($batchLock)->not->toBeFalse('No batch mutex. '.describeStatements($statements))
        ->and($enrollmentLock)->not->toBeFalse('No enrolment lock. '.describeStatements($statements))
        ->and($batchLock)->toBeLessThan(
            $enrollmentLock,
            'The enrolment was locked before the batch, which deadlocks against '
            .'EnrollStudentAction under concurrency.',
        );
});

it('reads the instructor assignment with FOR UPDATE, after the batch lock, in the transaction', function () {
    /*
     * THE SNAPSHOT BUG, PINNED — the same guard EnrollmentTest proves for
     * WithdrawEnrollmentAction, restated for CompletionRule. All three
     * properties are asserted because each alone is satisfiable by the broken
     * arrangement: the read happens, something is locked, something runs in
     * the transaction.
     */
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    $this->batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 30]);

    $enrollment = ($this->enrolSomeone)();

    $statements = captureStatements();

    $this->complete->execute($staff, $enrollment);

    $ordered = collect($statements)->values();

    $batchLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `batches`'));

    $pivotLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `batch_instructor`'));

    expect($batchLock)->not->toBeFalse('No batch mutex. '.describeStatements($statements));

    expect($pivotLock)->not->toBeFalse(
        'The instructor assignment was read WITHOUT for update, so it was served from the '
        .'transaction snapshot taken before the batch mutex. Statements: '
        .describeStatements($statements),
    );

    expect($batchLock)->toBeLessThan(
        $pivotLock,
        'The assignment was locked before the batch mutex, so the authorization decision raced '
        .'the mutex it was supposed to be protected by.',
    );
});

it('opens its own transaction rather than relying on the caller\'s', function () {
    $enrollment = ($this->enrolSomeone)();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->complete->execute($this->admin, $enrollment);

    $locks = locksOn($statements, 'enrollments');

    expect($locks)->not->toBeEmpty('CompleteEnrollmentAction re-read the enrollment without lockForUpdate(). '.describeStatements($statements));

    expectOneLevelDeeper($locks[0], $baseline, (int) $enrollment->getKey(), 'The enrollment lock');
});

/*
|--------------------------------------------------------------------------
| Activity logging, and the actor it names
|--------------------------------------------------------------------------
|
| Spatie resolves a causer from the authenticated session unless told
| otherwise, so both failure modes below are real: a console invocation with
| no session records nobody, and an invocation while somebody else holds the
| session records the wrong person. See WriteOffChargeTest for the identical
| two-sided assertion this mirrors.
*/

function completionCauserId(Enrollment $enrollment): ?int
{
    $causerId = Activity::query()
        ->where('subject_type', Enrollment::class)
        ->where('subject_id', $enrollment->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->value('causer_id');

    return $causerId === null ? null : (int) $causerId;
}

it('attributes the completion to the Action actor when no session exists at all', function () {
    $enrollment = ($this->enrolSomeone)();

    $this->complete->execute($this->admin, $enrollment);

    expect(completionCauserId($enrollment))->toBe((int) $this->admin->getKey());
});

it('attributes the completion to the Action actor while a different user holds the session', function () {
    $someoneElse = ($this->actorWith)('admin');
    $this->actingAs($someoneElse);

    $enrollment = ($this->enrolSomeone)();

    $this->complete->execute($this->admin, $enrollment);

    expect(completionCauserId($enrollment))->toBe((int) $this->admin->getKey())
        ->and(completionCauserId($enrollment))->not->toBe((int) $someoneElse->getKey());
});

it('logs the status change on the enrolment', function () {
    // NAMED FOR WHAT IT ASSERTS. It read "status and completed_at" and never
    // touched completed_at on the entry; the completed_at guarantee is the
    // server-clock test above, and auditedAttributes() is what puts it in the
    // diff at all.
    $this->travelTo('2026-08-30 10:15:00');

    $enrollment = ($this->enrolSomeone)();

    $this->complete->execute($this->admin, $enrollment);

    $activity = Activity::query()
        ->where('subject_type', Enrollment::class)
        ->where('subject_id', $enrollment->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();

    $changes = $activity->attribute_changes;

    expect($changes->get('attributes')['status'] ?? null)->toBe('completed')
        ->and($changes->get('old')['status'] ?? null)->toBe('active');
});

/*
|--------------------------------------------------------------------------
| Reversal — ReverseEnrollmentCompletionAction
|--------------------------------------------------------------------------
|
| The identical pair CompleteEnrollmentAction uses — EnrollmentMutex then
| CompletionRule, checked with locking: true — plus a mandatory reason, so the
| assigned instructor who mis-marked a row can correct their own mistake
| without escalating (spec section 5.1). Kept in this file rather than a
| separate one: the plan's file scope names no ReverseEnrollmentCompletionTest,
| and the two Actions are one feature told from both directions.
*/

it('reverses a completed enrolment back to active and clears completed_at', function () {
    $enrollment = ($this->completedEnrollment)();

    $result = $this->reverse->execute($this->admin, $enrollment, 'Marked complete by mistake.');

    expect($result->status)->toBe(EnrollmentStatus::Active)
        ->and($result->completed_at)->toBeNull()
        ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Active)
        ->and($enrollment->fresh()->completed_at)->toBeNull();
});

it('lets a staff actor assigned to the batch reverse their own completion mistake', function () {
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    $this->batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 30]);

    $enrollment = ($this->completedEnrollment)();

    $result = $this->reverse->execute($staff, $enrollment, 'Wrong student marked by accident.');

    expect($result->status)->toBe(EnrollmentStatus::Active);
});

it('refuses a staff actor who does not teach the batch to reverse a completion', function () {
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();

    $enrollment = ($this->completedEnrollment)();

    expect(fn () => $this->reverse->execute($staff, $enrollment, 'Reason.'))
        ->toThrow(AuthorizationException::class);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed);
});

/*
|--------------------------------------------------------------------------
| The mandatory reason
|--------------------------------------------------------------------------
*/

it('refuses a blank reversal reason', function () {
    $enrollment = ($this->completedEnrollment)();

    expect(fn () => $this->reverse->execute($this->admin, $enrollment, ''))
        ->toThrow(InvalidArgumentException::class);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed);
});

it('refuses a whitespace-only reversal reason', function () {
    // trim() is the check, not strlen(): a reason of only spaces clears a bare
    // NOT NULL guard while saying nothing to whoever reads the log later.
    $enrollment = ($this->completedEnrollment)();

    expect(fn () => $this->reverse->execute($this->admin, $enrollment, "   \n\t  "))
        ->toThrow(InvalidArgumentException::class);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed);
});

it('records the mandatory reason in the activity log', function () {
    $enrollment = ($this->completedEnrollment)();

    $this->reverse->execute($this->admin, $enrollment, 'Recorded against the wrong student.');

    $activity = Activity::query()
        ->where('subject_type', Enrollment::class)
        ->where('subject_id', $enrollment->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('reason'))->toBe('Recorded against the wrong student.')
        ->and((int) $activity->causer_id)->toBe((int) $this->admin->getKey());

    $changes = $activity->attribute_changes;

    expect($changes->get('attributes')['status'] ?? null)->toBe('active')
        ->and($changes->get('old')['status'] ?? null)->toBe('completed');
});

it('attributes the reversal to the Action actor while a different user holds the session', function () {
    $someoneElse = ($this->actorWith)('admin');
    $this->actingAs($someoneElse);

    $enrollment = ($this->completedEnrollment)();

    $this->reverse->execute($this->admin, $enrollment, 'Correcting an earlier mistake.');

    $activity = Activity::query()
        ->where('subject_type', Enrollment::class)
        ->where('subject_id', $enrollment->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect((int) $activity->causer_id)->toBe((int) $this->admin->getKey())
        ->and((int) $activity->causer_id)->not->toBe((int) $someoneElse->getKey());
});

/*
|--------------------------------------------------------------------------
| Status refusals
|--------------------------------------------------------------------------
*/

it('refuses to reverse an active enrolment', function () {
    $enrollment = ($this->enrolSomeone)();

    expect(fn () => $this->reverse->execute($this->admin, $enrollment, 'Reason.'))
        ->toThrow(CompletionNotReversibleException::class);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
});

it('refuses to reverse a withdrawn enrolment', function () {
    $enrollment = Enrollment::factory()->for($this->batch)->withdrawn()->create();

    expect(fn () => $this->reverse->execute($this->admin, $enrollment, 'Reason.'))
        ->toThrow(CompletionNotReversibleException::class);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Withdrawn);
});

/*
|--------------------------------------------------------------------------
| The certificate lock — real student_certificates rows, not a stand-in
|--------------------------------------------------------------------------
|
| T4 shipped the real table and model ahead of this task specifically so this
| check reads it directly rather than through a temporary interface — see the
| plan's note under Task 3.
*/

it('refuses to reverse while a valid certificate exists', function () {
    $enrollment = ($this->completedEnrollment)();
    StudentCertificate::factory()->for($enrollment, 'enrollment')->create();

    expect(fn () => $this->reverse->execute($this->admin, $enrollment, 'Reason.'))
        ->toThrow(CompletionNotReversibleException::class);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed);
});

it('permits reversal once the certificate has been revoked', function () {
    $enrollment = ($this->completedEnrollment)();
    StudentCertificate::factory()->for($enrollment, 'enrollment')->revoked()->create();

    $result = $this->reverse->execute($this->admin, $enrollment, 'Reason.');

    expect($result->status)->toBe(EnrollmentStatus::Active);
});

it('permits reversal when only a replaced certificate exists', function () {
    // A replaced row is history, not a standing document — only 'valid' blocks.
    $enrollment = ($this->completedEnrollment)();
    StudentCertificate::factory()->for($enrollment, 'enrollment')->replaced()->create();

    $result = $this->reverse->execute($this->admin, $enrollment, 'Reason.');

    expect($result->status)->toBe(EnrollmentStatus::Active);
});

/*
|--------------------------------------------------------------------------
| Locking and order — batch, then enrolment, then certificate
|--------------------------------------------------------------------------
*/

it('locks the batch before the enrollment when reversing', function () {
    $enrollment = ($this->completedEnrollment)();

    $statements = captureStatements();

    $this->reverse->execute($this->admin, $enrollment, 'Reason.');

    $ordered = collect($statements)->values();

    $batchLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `batches`'));

    $enrollmentLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `enrollments`'));

    expect($batchLock)->not->toBeFalse('No batch mutex. '.describeStatements($statements))
        ->and($enrollmentLock)->not->toBeFalse('No enrolment lock. '.describeStatements($statements))
        ->and($batchLock)->toBeLessThan($enrollmentLock);
});

it('locks the certificate rows after the enrolment, preserving batch -> enrolment -> certificate', function () {
    $enrollment = ($this->completedEnrollment)();
    StudentCertificate::factory()->for($enrollment, 'enrollment')->revoked()->create();

    $statements = captureStatements();

    $this->reverse->execute($this->admin, $enrollment, 'Reason.');

    $ordered = collect($statements)->values();

    $enrollmentLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `enrollments`'));

    $certificateLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `student_certificates`'));

    expect($enrollmentLock)->not->toBeFalse('No enrolment lock. '.describeStatements($statements))
        ->and($certificateLock)->not->toBeFalse(
            'The certificate rows were read without lockForUpdate(), so two concurrent reversal '
            .'attempts could both see no valid certificate. '.describeStatements($statements),
        )
        ->and($enrollmentLock)->toBeLessThan(
            $certificateLock,
            'The certificate rows were locked before the enrolment, breaking the global '
            .'batch -> enrolment -> certificate lock order.',
        );
});
