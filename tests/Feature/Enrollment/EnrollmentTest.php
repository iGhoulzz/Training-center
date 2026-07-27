<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\DeleteEnrollmentAction;
use App\Domain\Enrollment\Actions\EnrollStudentAction;
use App\Domain\Enrollment\Actions\WithdrawEnrollmentAction;
use App\Domain\Enrollment\Data\EnrollStudentData;
use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\DuplicateEnrollmentException;
use App\Domain\Enrollment\Exceptions\EnrollmentBatchChangedException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotWithdrawableException;
use App\Domain\Enrollment\Exceptions\StudentNotEnrollableException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
    $this->batch = Batch::factory()->for($this->course)->active()->create(['capacity' => 2]);

    $this->enroll = app(EnrollStudentAction::class);
    $this->withdraw = app(WithdrawEnrollmentAction::class);
    $this->remove = app(DeleteEnrollmentAction::class);

    /** Enrol a fresh student, returning the enrolment. */
    $this->enrolSomeone = fn (?Batch $batch = null): Enrollment => $this->enroll->execute(
        $this->admin,
        new EnrollStudentData(
            (int) Student::factory()->create()->getKey(),
            (int) ($batch ?? $this->batch)->getKey(),
        ),
    );
});

/*
|--------------------------------------------------------------------------
| Enrolling
|--------------------------------------------------------------------------
*/

it('enrolls a student into an open batch', function () {
    $student = Student::factory()->create();

    $enrollment = $this->enroll->execute($this->admin, new EnrollStudentData(
        studentId: (int) $student->getKey(),
        batchId: (int) $this->batch->getKey(),
    ));

    expect($enrollment->status)->toBe(EnrollmentStatus::Active)
        ->and($enrollment->enrolled_at)->not->toBeNull()
        ->and($enrollment->completed_at)->toBeNull()
        ->and((int) $enrollment->student_id)->toBe((int) $student->getKey())
        ->and((int) $enrollment->batch_id)->toBe((int) $this->batch->getKey());
});

it('refuses a duplicate enrollment with a typed exception', function () {
    $student = Student::factory()->create();
    $data = new EnrollStudentData((int) $student->getKey(), (int) $this->batch->getKey());

    $this->enroll->execute($this->admin, $data);
    $this->enroll->execute($this->admin, $data);
})->throws(DuplicateEnrollmentException::class);

it('refuses enrollment into a completed batch', function () {
    $closed = Batch::factory()->for($this->course)->completed()->create();

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) Student::factory()->create()->getKey(),
        (int) $closed->getKey(),
    ));
})->throws(BatchClosedException::class);

it('refuses enrollment into a cancelled batch', function () {
    // Both closed states, not just one. Spec section 6 names completed AND
    // cancelled, and covering one proves nothing about the other.
    $cancelled = Batch::factory()->for($this->course)->cancelled()->create();

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) Student::factory()->create()->getKey(),
        (int) $cancelled->getKey(),
    ));
})->throws(BatchClosedException::class);

it('refuses to enroll a soft-deleted student', function () {
    $student = Student::factory()->create();
    $student->delete();

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) $student->getKey(),
        (int) $this->batch->getKey(),
    ));
})->throws(StudentNotEnrollableException::class);

it('permits exceeding capacity but reports it', function () {
    // Spec line 217: over-enrolment warns and never blocks. Capacity 2, enrol 3.
    foreach (range(1, 3) as $ignored) {
        ($this->enrolSomeone)();
    }

    expect($this->batch->fresh()->enrollments)->toHaveCount(3)
        ->and($this->batch->fresh()->isOverCapacity())->toBeTrue();
});

it('does not report a batch filled exactly to capacity as over it', function () {
    // The boundary, which "> capacity" gets right and ">= capacity" would not.
    foreach (range(1, 2) as $ignored) {
        ($this->enrolSomeone)();
    }

    expect($this->batch->fresh()->isOverCapacity())->toBeFalse();
});

it('does not count withdrawn enrollments towards capacity', function () {
    foreach (range(1, 2) as $ignored) {
        ($this->enrolSomeone)();
    }

    $third = ($this->enrolSomeone)();
    $this->withdraw->execute($this->admin, $third);

    expect($this->batch->fresh()->isOverCapacity())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Withdrawing — the only status transition phase 1 has
|--------------------------------------------------------------------------
*/

it('withdraws an active enrollment', function () {
    $enrollment = ($this->enrolSomeone)();

    expect($this->withdraw->execute($this->admin, $enrollment)->status)
        ->toBe(EnrollmentStatus::Withdrawn);
});

it('treats withdrawing an already-withdrawn enrollment as a no-op', function () {
    // Idempotent by decision: a double-clicked button, or two staff acting on the
    // same row, must not become an error somebody has to interpret.
    $enrollment = ($this->enrolSomeone)();

    $this->withdraw->execute($this->admin, $enrollment);
    $again = $this->withdraw->execute($this->admin, $enrollment->fresh());

    expect($again->status)->toBe(EnrollmentStatus::Withdrawn)
        ->and(Enrollment::query()->count())->toBe(1);
});

it('refuses to withdraw a completed enrollment', function () {
    // Phase 1 cannot produce one — completion marking is phase 3, spec line 71 —
    // but a phase 3 row can be one, and withdrawing it would invalidate a
    // certificate already issued against the completion.
    $this->withdraw->execute($this->admin, Enrollment::factory()->completed()->create());
})->throws(EnrollmentNotWithdrawableException::class);

it('withdraws a student from a COMPLETED batch', function () {
    /*
     * PINNED ON PURPOSE. Spec line 237 names exactly two operations a closed
     * batch refuses: new enrolments and instructor changes. Withdrawal is
     * neither, and a student recorded in error on a finished batch must still be
     * removable or the mistake is permanent.
     *
     * Somebody will eventually "fix" WithdrawEnrollmentAction by adding an
     * acceptsEnrollments() check, because the Action next to it has one. This is
     * what tells them not to.
     */
    $closed = Batch::factory()->for($this->course)->active()->create();
    $enrollment = ($this->enrolSomeone)($closed);

    $closed->update(['status' => BatchStatus::Completed]);

    expect($this->withdraw->execute($this->admin, $enrollment->fresh())->status)
        ->toBe(EnrollmentStatus::Withdrawn);
});

it('withdraws a student from a CANCELLED batch', function () {
    $cancelled = Batch::factory()->for($this->course)->active()->create();
    $enrollment = ($this->enrolSomeone)($cancelled);

    $cancelled->update(['status' => BatchStatus::Cancelled]);

    expect($this->withdraw->execute($this->admin, $enrollment->fresh())->status)
        ->toBe(EnrollmentStatus::Withdrawn);
});

/*
|--------------------------------------------------------------------------
| Deleting — a separate grant from withdrawing
|--------------------------------------------------------------------------
*/

it('deletes an enrollment for an actor holding delete_enrollment', function () {
    $enrollment = ($this->enrolSomeone)();

    $this->remove->execute($this->admin, $enrollment);

    expect(Enrollment::query()->count())->toBe(0);
});

it('refuses deletion for an actor holding only the update grants', function () {
    // Staff withdraw; they do not delete. Withdrawal keeps the record that the
    // student was once on the batch, which is what phase 2 bills from — deletion
    // destroys it, so the two are separate grants and are tested as such.
    $staff = ($this->actorWith)('staff');
    $enrollment = ($this->enrolSomeone)();

    $this->remove->execute($staff, $enrollment);
})->throws(AuthorizationException::class);

it('deletes an enrollment from a completed batch', function () {
    $closed = Batch::factory()->for($this->course)->active()->create();
    $enrollment = ($this->enrolSomeone)($closed);

    $closed->update(['status' => BatchStatus::Completed]);

    $this->remove->execute($this->admin, $enrollment->fresh());

    expect(Enrollment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Authorization — negatives first, because those are the ones that matter
|--------------------------------------------------------------------------
*/

it('refuses enrollment by an actor holding no create grant', function () {
    $this->enroll->execute(($this->actorWith)('student'), new EnrollStudentData(
        (int) Student::factory()->create()->getKey(),
        (int) $this->batch->getKey(),
    ));
})->throws(AuthorizationException::class);

it('refuses enrollment by an actor with no roles at all', function () {
    // The floor. A brand-new account holds nothing and must be refused by the
    // permission check rather than by happening to fail something later.
    $this->enroll->execute(User::factory()->create(['is_active' => true]), new EnrollStudentData(
        (int) Student::factory()->create()->getKey(),
        (int) $this->batch->getKey(),
    ));
})->throws(AuthorizationException::class);

it('lets staff enroll into any batch, not only ones they teach', function () {
    // Creation is deliberately unscoped: spec line 15 requires a front-desk
    // staffer to enrol a walk-in, and they teach nothing at all.
    $enrollment = $this->enroll->execute(($this->actorWith)('staff'), new EnrollStudentData(
        (int) Student::factory()->create()->getKey(),
        (int) $this->batch->getKey(),
    ));

    expect($enrollment->status)->toBe(EnrollmentStatus::Active);
});

it('refuses to reveal a closed batch to an actor who may not enroll at all', function () {
    // Ordering matters: the ability is checked BEFORE the batch's status, so a
    // refusal never tells an unentitled actor what state the batch is in.
    $closed = Batch::factory()->for($this->course)->completed()->create();

    $this->enroll->execute(($this->actorWith)('student'), new EnrollStudentData(
        (int) Student::factory()->create()->getKey(),
        (int) $closed->getKey(),
    ));
})->throws(AuthorizationException::class);

it('refuses withdrawal by an actor holding neither update grant', function () {
    $this->withdraw->execute(($this->actorWith)('student'), ($this->enrolSomeone)());
})->throws(AuthorizationException::class);

/*
|--------------------------------------------------------------------------
| The locks, and the transaction they must live in
|--------------------------------------------------------------------------
*/

it('locks the batch row while enrolling, inside a transaction it opened itself', function () {
    $student = Student::factory()->create();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) $student->getKey(),
        (int) $this->batch->getKey(),
    ));

    $locks = locksOn($statements, 'batches');

    expect($locks)->not->toBeEmpty(
        'EnrollStudentAction read the batch without lockForUpdate(). Statements seen: '
        .describeStatements($statements),
    );

    expectOneLevelDeeper($locks[0], $baseline, (int) $this->batch->getKey(), 'The batch lock');
});

it('locks the student row while enrolling, inside a transaction it opened itself', function () {
    $student = Student::factory()->create();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) $student->getKey(),
        (int) $this->batch->getKey(),
    ));

    $locks = locksOn($statements, 'students');

    expect($locks)->not->toBeEmpty(
        'EnrollStudentAction read the student without lockForUpdate(), so the deleted-student '
        .'refusal was decided from an unlocked row. Statements seen: '.describeStatements($statements),
    );

    expectOneLevelDeeper($locks[0], $baseline, (int) $student->getKey(), 'The student lock');
});

it('writes the enrollment inside the same transaction as the locks', function () {
    $student = Student::factory()->create();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) $student->getKey(),
        (int) $this->batch->getKey(),
    ));

    $writes = writesTo($statements, 'enrollments');

    expect($writes)->not->toBeEmpty(
        'No write to enrollments was observed. Statements seen: '.describeStatements($statements),
    );

    expectOneLevelDeeper($writes[0], $baseline, (int) $student->getKey(), 'The enrollment insert');
});

it('locks the batch mutex while withdrawing, inside a transaction it opened itself', function () {
    // THE SAME MUTEX ENROLMENT TAKES. Withdrawal changes what counts against
    // capacity and the rule reads the batch's instructor list, so the batch is the
    // row that serializes them.
    $enrollment = ($this->enrolSomeone)();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->withdraw->execute($this->admin, $enrollment);

    $locks = locksOn($statements, 'batches');

    expect($locks)->not->toBeEmpty(
        'WithdrawEnrollmentAction did not lock the batch. Statements seen: '
        .describeStatements($statements),
    );

    expectOneLevelDeeper($locks[0], $baseline, (int) $this->batch->getKey(), 'The batch mutex');
});

it('locks the enrollment row while withdrawing, inside a transaction it opened itself', function () {
    $enrollment = ($this->enrolSomeone)();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->withdraw->execute($this->admin, $enrollment);

    $locks = locksOn($statements, 'enrollments');

    expect($locks)->not->toBeEmpty(
        'WithdrawEnrollmentAction re-read the enrollment without lockForUpdate(), so the status '
        .'transition was decided from a stale copy. Statements seen: '.describeStatements($statements),
    );

    expectOneLevelDeeper($locks[0], $baseline, (int) $enrollment->getKey(), 'The enrollment lock');
});

it('writes the withdrawal inside the same transaction as its locks', function () {
    $enrollment = ($this->enrolSomeone)();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->withdraw->execute($this->admin, $enrollment);

    $writes = writesTo($statements, 'enrollments');

    expect($writes)->not->toBeEmpty(
        'No write to enrollments was observed. Statements seen: '.describeStatements($statements),
    );

    expectOneLevelDeeper($writes[0], $baseline, (int) $enrollment->getKey(), 'The withdrawal update');
});

it('locks the batch mutex while deleting, inside a transaction it opened itself', function () {
    $enrollment = ($this->enrolSomeone)();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->remove->execute($this->admin, $enrollment);

    $locks = locksOn($statements, 'batches');

    expect($locks)->not->toBeEmpty(
        'DeleteEnrollmentAction did not lock the batch. '.describeStatements($statements),
    );

    expectOneLevelDeeper($locks[0], $baseline, (int) $this->batch->getKey(), 'The batch mutex');
});

it('locks the enrollment row while deleting, inside a transaction it opened itself', function () {
    $enrollment = ($this->enrolSomeone)();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->remove->execute($this->admin, $enrollment);

    $locks = locksOn($statements, 'enrollments');

    expect($locks)->not->toBeEmpty(
        'DeleteEnrollmentAction re-read the enrollment without lockForUpdate(). '
        .describeStatements($statements),
    );

    expectOneLevelDeeper($locks[0], $baseline, (int) $enrollment->getKey(), 'The enrollment lock');
});

it('issues the DELETE inside the same transaction as its locks', function () {
    $enrollment = ($this->enrolSomeone)();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->remove->execute($this->admin, $enrollment);

    $writes = writesTo($statements, 'enrollments');

    expect($writes)->not->toBeEmpty('No DELETE observed. '.describeStatements($statements));

    expectOneLevelDeeper($writes[0], $baseline, (int) $enrollment->getKey(), 'The enrollment delete');
});

it('locks the batch before the enrollment when withdrawing', function () {
    /*
     * LOCK ORDER IS NOT COSMETIC. EnrollStudentAction takes the batch first; an
     * Action taking them in the other order deadlocks against it under
     * concurrency, and a deadlock is a 500 for whichever request loses.
     */
    $enrollment = ($this->enrolSomeone)();

    $statements = captureStatements();

    $this->withdraw->execute($this->admin, $enrollment);

    $ordered = collect($statements)->values();

    $batchLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `batches`'));

    $enrollmentLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `enrollments`'));

    expect($batchLock)->not->toBeFalse()
        ->and($enrollmentLock)->not->toBeFalse()
        ->and($batchLock)->toBeLessThan(
            $enrollmentLock,
            'The enrollment was locked before the batch, which deadlocks against '
            .'EnrollStudentAction under concurrency.',
        );
});

it('locks the batch before the enrollment when deleting', function () {
    $enrollment = ($this->enrolSomeone)();

    $statements = captureStatements();

    $this->remove->execute($this->admin, $enrollment);

    $ordered = collect($statements)->values();

    $batchLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `batches`'));

    $enrollmentLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `enrollments`'));

    expect($batchLock)->not->toBeFalse()
        ->and($enrollmentLock)->not->toBeFalse()
        ->and($batchLock)->toBeLessThan($enrollmentLock);
});

/*
|--------------------------------------------------------------------------
| The authorization that binds is taken under lock, after the mutex
|--------------------------------------------------------------------------
*/

it('reads the instructor assignment with FOR UPDATE, after the batch lock, in the transaction', function () {
    /*
     * THE SNAPSHOT BUG, PINNED.
     *
     * MySQL runs at REPEATABLE READ. The Action's own first statement — the
     * enrolment lookup that picks the mutex — establishes the transaction's
     * snapshot, and every ordinary read afterwards is served from it, INCLUDING
     * one that runs after lockForUpdate() on another table. So a plain SELECT of
     * the pivot here answers from before the mutex was held, and an assignment
     * revoked in that window stays invisible.
     *
     * Only a locking read escapes the snapshot. All three properties are
     * asserted, because each alone is satisfiable by the broken arrangement: the
     * read happens, something is locked, something runs in the transaction.
     */
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    $this->batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 30]);

    $enrollment = ($this->enrolSomeone)();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->withdraw->execute($staff, $enrollment);

    $ordered = collect($statements)->values();

    $batchLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `batches`'));

    $pivotLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `batch_instructor`'));

    $update = $ordered->search(fn (array $s): bool => str_starts_with($s['sql'], 'update `enrollments`'));

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

    expect($pivotLock)->toBeLessThan($update, 'The row was written before it was authorized.');

    foreach ([$batchLock, $pivotLock, $update] as $index) {
        expect($ordered[$index]['level'])->toBe(
            $baseline + 1,
            'Statement ran outside the Action\'s transaction: '.$ordered[$index]['sql'],
        );
    }
});

it('refuses a withdrawal when the binding locking check sees the assignment removed', function () {
    /*
     * The behavioural half of the test above. The listener removes the assignment
     * on this connection after the batch lock statement and before the binding
     * pivot read. This deliberately does NOT claim to simulate a second committed
     * connection: it forces the binding answer to false and proves the Action
     * refuses directly from that answer. Removing the AuthorizationException in
     * WithdrawEnrollmentAction makes the status assertion fail.
     */
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    $this->batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 30]);

    $enrollment = ($this->enrolSomeone)();

    $revoked = false;

    DB::listen(function (QueryExecuted $query) use (&$revoked, $staff): void {
        if ($revoked || ! str_contains(strtolower($query->sql), 'from `batches`')) {
            return;
        }

        $revoked = true;

        DB::table('batch_instructor')
            ->where('batch_id', $this->batch->getKey())
            ->where('user_id', $staff->getKey())
            ->delete();
    });

    try {
        $this->withdraw->execute($staff, $enrollment);
        $thrown = null;
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
});

/*
|--------------------------------------------------------------------------
| The mutex is chosen from the database, not from the caller's object
|--------------------------------------------------------------------------
*/

it('locks the persisted batch when handed a dirty enrollment instance', function () {
    /*
     * THE BUG THIS EXISTS TO CATCH: reading $enrollment->batch_id off the passed
     * model. An in-memory assignment is not a persisted change, so trusting it
     * locks some other batch and then writes a row guarded by a mutex nobody
     * holds — and a test that only asserts "a batch was locked" still passes,
     * because a batch WAS locked. The wrong one.
     */
    $enrollment = ($this->enrolSomeone)();
    $decoy = Batch::factory()->for($this->course)->active()->create();

    // Dirty, never saved.
    $enrollment->batch_id = $decoy->getKey();

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    $this->withdraw->execute($this->admin, $enrollment);

    $locks = locksOn($statements, 'batches');

    expect($locks)->not->toBeEmpty(
        'No batch lock at all. Statements seen: '.describeStatements($statements),
    );

    expectOneLevelDeeper($locks[0], $baseline, (int) $this->batch->getKey(), 'The batch mutex');

    $lockedIds = collect($locks)
        ->flatMap(fn (array $lock): array => $lock['bindings'])
        ->map(fn (mixed $binding): int => (int) $binding)
        ->all();

    expect(in_array((int) $decoy->getKey(), $lockedIds, true))->toBeFalse(
        'The decoy batch was locked, so the mutex came from the caller\'s dirty attribute '
        .'rather than from the database.',
    );

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Withdrawn);
});

it('locks the persisted batch when handed a dirty instance for deletion', function () {
    $enrollment = ($this->enrolSomeone)();
    $decoy = Batch::factory()->for($this->course)->active()->create();

    $enrollment->batch_id = $decoy->getKey();

    $statements = captureStatements();

    $this->remove->execute($this->admin, $enrollment);

    $lockedIds = collect(locksOn($statements, 'batches'))
        ->flatMap(fn (array $lock): array => $lock['bindings'])
        ->map(fn (mixed $binding): int => (int) $binding)
        ->all();

    expect(in_array((int) $this->batch->getKey(), $lockedIds, true))->toBeTrue()
        ->and(in_array((int) $decoy->getKey(), $lockedIds, true))->toBeFalse()
        ->and(Enrollment::query()->count())->toBe(0);
});

it('refuses when the enrollment moves batches between the lookup and the locked reload', function () {
    /*
     * REACHES THE REVALIDATION, WHICH THE DIRTY-INSTANCE TESTS DO NOT.
     *
     * Those prove the first lookup ignores caller memory. They never exercise the
     * comparison, because the persisted value does not change during them — the
     * branch could be deleted and both would still pass.
     *
     * Here the row is moved on disk after EnrollmentMutex has read batch_id and
     * before it reloads under lock, which is exactly the window the comparison
     * exists to close.
     */
    $enrollment = ($this->enrolSomeone)();
    $elsewhere = Batch::factory()->for($this->course)->active()->create();

    $originalBatchId = (int) $enrollment->batch_id;
    $moved = false;

    DB::listen(function (QueryExecuted $query) use (&$moved, $enrollment, $elsewhere): void {
        // After the scalar lookup on enrollments, before the locking reload.
        if ($moved || ! str_contains(strtolower($query->sql), 'from `enrollments`')) {
            return;
        }

        $moved = true;

        DB::table('enrollments')
            ->where('id', $enrollment->getKey())
            ->update(['batch_id' => $elsewhere->getKey()]);
    });

    try {
        $this->withdraw->execute($this->admin, $enrollment);
        $thrown = null;
    } catch (EnrollmentBatchChangedException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(EnrollmentBatchChangedException::class)
        ->and($thrown->expectedBatchId)->toBe($originalBatchId)
        ->and($thrown->actualBatchId)->toBe((int) $elsewhere->getKey());

    /*
     * AND THE TRANSACTION ROLLED BACK. The injected move happened inside the
     * Action's own transaction, so throwing must undo it as well as leaving the
     * status alone — a refusal that half-commits is not a refusal.
     */
    $after = $enrollment->fresh();

    expect((int) $after->batch_id)->toBe($originalBatchId)
        ->and($after->status)->toBe(EnrollmentStatus::Active);
});

it('reports a missing enrollment as a missing enrollment', function () {
    // findOrFail on a selected model, not value('batch_id'): (int) null is 0, and
    // Batch::findOrFail(0) reports a missing BATCH, sending whoever reads the log
    // after the wrong record entirely.
    $ghost = new Enrollment;
    $ghost->id = 999999;

    try {
        $this->withdraw->execute($this->admin, $ghost);
        $model = null;
    } catch (ModelNotFoundException $exception) {
        $model = $exception->getModel();
    }

    expect($model)->toBe(Enrollment::class);
});

/*
|--------------------------------------------------------------------------
| What the database itself guarantees
|--------------------------------------------------------------------------
*/

it('enforces one enrollment per student per batch at the database level', function () {
    $student = Student::factory()->create();

    Enrollment::factory()->create([
        'student_id' => $student->getKey(),
        'batch_id' => $this->batch->getKey(),
    ]);

    Enrollment::factory()->create([
        'student_id' => $student->getKey(),
        'batch_id' => $this->batch->getKey(),
    ]);
})->throws(UniqueConstraintViolationException::class);

it('names a unique index that actually exists on the table', function () {
    // EnrollStudentAction matches on this index by name. If a migration renames
    // it, the match silently stops working and every duplicate becomes a raw
    // driver error — so the name is asserted against the live schema.
    $indexes = collect(DB::select('show index from enrollments'))
        ->pluck('Key_name')
        ->unique()
        ->all();

    expect($indexes)->toContain('enrollments_student_id_batch_id_unique');
});

it('converts a duplicate lost at INSERT time into the typed exception', function () {
    /*
     * REACHES THE CATCH BRANCH.
     *
     * Inserting the conflicting row before calling the Action does not test the
     * catch at all — the exists() pre-check sees it and throws first, so the test
     * passes with the catch deleted. That is the same failure P1-T09c found in
     * the 1451 branch.
     *
     * The conflicting row is therefore injected from a creating() listener, which
     * fires AFTER the pre-check and BEFORE the insert. The Action's own INSERT is
     * then the statement MySQL refuses with 1062.
     */
    $student = Student::factory()->create();

    $injected = false;

    Enrollment::creating(function () use ($student, &$injected): void {
        if ($injected) {
            return;
        }

        $injected = true;

        DB::table('enrollments')->insert([
            'student_id' => $student->getKey(),
            'batch_id' => $this->batch->getKey(),
            'enrolled_at' => now(),
            'status' => EnrollmentStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) $student->getKey(),
        (int) $this->batch->getKey(),
    ));
})->throws(DuplicateEnrollmentException::class);

it('rethrows a 1062 raised by a DIFFERENT unique index', function () {
    /*
     * 1062 alone means "some unique index refused this row", which is not the
     * same statement as "this student is already on this batch". A second unique
     * index added to this table later would otherwise start reporting its own
     * violations as duplicate enrolments.
     *
     * Constructed with the real exception shape — Laravel sets ->index via
     * setIndex() — rather than a fabricated one.
     */
    $student = Student::factory()->create();

    Enrollment::creating(function (): void {
        $previous = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'X' for key 'enrollments_some_other_unique'");
        $previous->errorInfo = ['23000', 1062, "Duplicate entry 'X' for key 'enrollments_some_other_unique'"];

        throw (new UniqueConstraintViolationException(
            'mysql',
            'insert into `enrollments` ...',
            [],
            $previous,
        ))->setIndex('enrollments_some_other_unique');
    });

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) $student->getKey(),
        (int) $this->batch->getKey(),
    ));
})->throws(UniqueConstraintViolationException::class);

it('refuses at the foreign key to hard-delete a student holding enrollments', function () {
    // restrictOnDelete on student_id. Students soft-delete, so this only fires on
    // forceDelete — which is exactly the operation that would otherwise destroy
    // phase 2's billing history.
    $student = Student::factory()->create();

    $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) $student->getKey(),
        (int) $this->batch->getKey(),
    ));

    try {
        $student->forceDelete();
        $thrown = null;
    } catch (QueryException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($thrown->errorInfo[1] ?? null)->toBe(1451)
        ->and(Student::withTrashed()->whereKey($student->getKey())->exists())->toBeTrue();
});

it('keeps a soft-deleted student resolvable from their enrollment', function () {
    // Enrollment::student() is withTrashed(). Without it the relation resolves to
    // null once the student is deleted, and phase 2 would bill against an
    // enrolment whose owner the application says does not exist.
    $student = Student::factory()->create();

    $enrollment = $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) $student->getKey(),
        (int) $this->batch->getKey(),
    ));

    $student->delete();

    expect($enrollment->fresh()->student)->not->toBeNull()
        ->and((int) $enrollment->fresh()->student->getKey())->toBe((int) $student->getKey());
});
