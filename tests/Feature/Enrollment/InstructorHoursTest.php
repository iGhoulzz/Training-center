<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\AssignInstructorAction;
use App\Domain\Enrollment\Actions\RemoveInstructorAction;
use App\Domain\Enrollment\Data\AssignInstructorData;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\InstructorNotEligibleException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
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
use Illuminate\Support\Facades\File;

/**
 * Instructor hour allocation (P1-T10).
 *
 * THE LOAD-BEARING RULE THIS FILE PROTECTS
 * ----------------------------------------
 * Hours attach to the batch↔instructor RELATIONSHIP. Two instructors sharing a
 * batch may split its hours 18/12, or may BOTH be assigned all 30 when they
 * genuinely co-teach. The sum may therefore legitimately exceed the batch total,
 * so a mismatch WARNS and never blocks. If a future change makes the co-teaching
 * test below fail, the change is wrong — not the test.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    // The actor for the ordinary paths: admin holds assign_instructor.
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($this->admin, 'admin');
    $this->admin->refresh();

    /** An active account whose staff profile says they teach. */
    $this->makeInstructor = function (string $name = 'Instructor'): User {
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        StaffProfile::factory()->for($user)->instructor()->create();

        return $user->refresh();
    };

    $this->course = Course::factory()->create(['total_hours' => 30]);
    $this->batch = Batch::factory()->for($this->course)->active()->create(['total_hours' => null]);

    $this->assign = app(AssignInstructorAction::class);
    $this->remove = app(RemoveInstructorAction::class);

    /*
     * Start recording every statement that takes a row lock.
     *
     * An ArrayObject rather than an array because the listener has to keep
     * filling the same collection after this closure has returned, and a
     * returned array would be a copy.
     */
    $this->captureLocks = function (): ArrayObject {
        $locking = new ArrayObject;

        DB::listen(function (QueryExecuted $query) use ($locking): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, ' for update')) {
                $locking->append($sql);
            }
        });

        return $locking;
    };

    /** Which tables those statements locked — `select ... from `batches` ... for update`. */
    $this->lockedTables = fn (ArrayObject $locking): array => collect($locking)
        ->map(function (string $sql): string {
            preg_match('/ from `(\w+)`/', $sql, $matches);

            return $matches[1] ?? '';
        })
        ->all();

    /** The statements themselves, for a failure message that says what DID run. */
    $this->describeLocks = fn (ArrayObject $locking): string => $locking->count() === 0
        ? 'none'
        : implode(' | ', (array) $locking);
});

/*
|--------------------------------------------------------------------------
| Distributing the hours
|--------------------------------------------------------------------------
*/

it('assigns all of a batch\'s hours to a single instructor', function () {
    $sara = ($this->makeInstructor)('Sara');

    $this->assign->execute($this->admin, new AssignInstructorData(
        batchId: (int) $this->batch->getKey(),
        instructorId: (int) $sara->getKey(),
        assignedHours: 30,
    ));

    $batch = $this->batch->fresh();

    expect($batch->instructors)->toHaveCount(1)
        ->and((int) $batch->instructors->first()->pivot->assigned_hours)->toBe(30)
        ->and($batch->totalAssignedHours())->toBe(30)
        // 30 assigned against 30 inherited from the course: no warning.
        ->and($batch->hasHourMismatch())->toBeFalse();
});

it('splits hours unevenly between two instructors', function () {
    // The spec's own example: ENG-B1-JAN, Sara 18, Omar 12.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $omar->getKey(), 12));

    $batch = $this->batch->fresh();

    expect($batch->totalAssignedHours())->toBe(30)
        ->and($batch->hasHourMismatch())->toBeFalse()
        ->and($batch->instructors)->toHaveCount(2)
        ->and(DB::table('batch_instructor')->count())->toBe(2);
});

it('permits co-teaching where both instructors are assigned every hour', function () {
    // THE DESIGN DECISION. Both are present throughout a 30-hour batch, so both
    // are down for 30 and the sum is 60. That is a correct record of a real
    // arrangement: the system warns, and MUST NOT refuse it.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 30));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $omar->getKey(), 30));

    $batch = $this->batch->fresh();

    expect($batch->totalAssignedHours())->toBe(60)
        ->and($batch->hasHourMismatch())->toBeTrue()
        // Both rows survived: the warning changed nothing about what was stored.
        ->and($batch->instructors)->toHaveCount(2)
        ->and(DB::table('batch_instructor')->where('batch_id', $this->batch->getKey())->count())->toBe(2)
        ->and(
            DB::table('batch_instructor')
                ->where('batch_id', $this->batch->getKey())
                ->pluck('assigned_hours')
                ->map(fn ($hours): int => (int) $hours)
                ->all()
        )->toBe([30, 30]);
});

it('flags a mismatch when the assigned hours undershoot the batch total', function () {
    // The other side of the same warning: ten hours nobody is down as teaching.
    $sara = ($this->makeInstructor)('Sara');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 20));

    expect($this->batch->fresh()->hasHourMismatch())->toBeTrue();
});

it('flags a mismatch on a batch with no instructor at all', function () {
    // Zero assigned against thirty run is the commonest real mistake, and the
    // aggregate is NULL rather than 0 for these rows — so this also pins that
    // totalAssignedHours() reads a null sum as zero.
    expect($this->batch->totalAssignedHours())->toBe(0)
        ->and($this->batch->hasHourMismatch())->toBeTrue();
});

it('measures the mismatch against an explicit batch override, not the course', function () {
    // A batch that overrides total_hours is measured against ITS OWN figure.
    $intensive = Batch::factory()->for($this->course)->active()->create(['total_hours' => 45]);
    $sara = ($this->makeInstructor)('Sara');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $intensive->getKey(), (int) $sara->getKey(), 45));

    expect($intensive->fresh()->hasHourMismatch())->toBeFalse();

    // And it follows the course when it does not override.
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 45));

    expect($this->batch->fresh()->hasHourMismatch())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Idempotence
|--------------------------------------------------------------------------
*/

it('updates the hours instead of duplicating the row on reassignment', function () {
    // Without the unique index and syncWithoutDetaching, correcting 18 to 24
    // would leave two rows summing to 42 — and phase 2 would pay for both.
    $sara = ($this->makeInstructor)('Sara');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 24));

    $batch = $this->batch->fresh();

    expect($batch->instructors)->toHaveCount(1)
        ->and($batch->totalAssignedHours())->toBe(24)
        ->and(DB::table('batch_instructor')->count())->toBe(1);
});

it('leaves a co-teacher alone when one instructor is reassigned', function () {
    // syncWithoutDetaching, not sync: sync() would silently remove Omar.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $omar->getKey(), 12));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 20));

    $batch = $this->batch->fresh();

    expect($batch->instructors)->toHaveCount(2)
        ->and($batch->totalAssignedHours())->toBe(32);
});

/*
|--------------------------------------------------------------------------
| A closed batch rejects instructor changes
|--------------------------------------------------------------------------
*/

it('refuses to assign an instructor to a completed batch', function () {
    $closed = Batch::factory()->for($this->course)->completed()->create();
    $sara = ($this->makeInstructor)('Sara');

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $closed->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(BatchClosedException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses to assign an instructor to a cancelled batch', function () {
    $closed = Batch::factory()->for($this->course)->cancelled()->create();
    $sara = ($this->makeInstructor)('Sara');

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $closed->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(BatchClosedException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses to change the hours of an instructor already on a closed batch', function () {
    // The batch was open when Sara was assigned and was completed afterwards.
    // Rewriting her hours now is rewriting history, and from phase 2, wages.
    $sara = ($this->makeInstructor)('Sara');
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));

    $this->batch->update(['status' => 'completed']);

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        4,
    )))->toThrow(BatchClosedException::class);

    expect($this->batch->fresh()->totalAssignedHours())->toBe(18);
});

it('refuses to remove an instructor from a closed batch', function () {
    $sara = ($this->makeInstructor)('Sara');
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 30));

    $this->batch->update(['status' => 'cancelled']);

    expect(fn () => $this->remove->execute($this->admin, $this->batch->fresh(), $sara))
        ->toThrow(BatchClosedException::class);

    expect(DB::table('batch_instructor')->count())->toBe(1);
});

it('authorizes against the freshly locked batch, not the instance it was handed', function () {
    // THE RACE THE LOCK-THEN-AUTHORIZE ORDER EXISTS FOR.
    //
    // $stale was read while the batch was open. The batch has since been closed
    // by someone else. An Action that authorized the copy it was given would see
    // "active" and let the write through; re-reading under the lock sees the
    // real row.
    $sara = ($this->makeInstructor)('Sara');
    $stale = $this->batch->fresh();

    expect($stale->acceptsEnrollments())->toBeTrue();

    // Closed by another process, invisible to the instance above.
    DB::table('batches')->where('id', $this->batch->getKey())->update(['status' => 'completed']);

    expect($stale->status->value)->toBe('active');

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $stale->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(BatchClosedException::class);

    // The removal path re-reads too, and it is handed the stale MODEL directly.
    expect(fn () => $this->remove->execute($this->admin, $stale, $sara))
        ->toThrow(BatchClosedException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Only an active instructor may be assigned
|--------------------------------------------------------------------------
*/

it('refuses a deactivated account', function () {
    $sara = ($this->makeInstructor)('Sara');
    $sara->forceFill(['is_active' => false])->saveQuietly();

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(InstructorNotEligibleException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses an administrative staff member', function () {
    // A front-desk administrator is staff, but they do not teach — and phase 2
    // would pay them for teaching they never did.
    $clerk = User::factory()->create(['is_active' => true]);
    StaffProfile::factory()->for($clerk)->administrative()->create();

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $clerk->getKey(),
        30,
    )))->toThrow(InstructorNotEligibleException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses a support staff member', function () {
    $caretaker = User::factory()->create(['is_active' => true]);
    StaffProfile::factory()->for($caretaker)->create(['employment_type' => 'support']);

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $caretaker->getKey(),
        30,
    )))->toThrow(InstructorNotEligibleException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses an account with no staff profile at all', function () {
    // A super admin with no employment record is the normal case for this, and
    // "has an account here" is not "teaches here".
    $accountOnly = User::factory()->create(['is_active' => true]);

    expect($accountOnly->staffProfile()->exists())->toBeFalse();

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $accountOnly->getKey(),
        30,
    )))->toThrow(InstructorNotEligibleException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses a departed account', function () {
    // A soft-deleted instructor is refused NEW hours exactly as a deactivated
    // one is — and refused with the same typed exception, not reported as a
    // missing record. The account is genuinely still there: Batch::instructors()
    // is withTrashed() so the hours already recorded against it survive.
    $sara = ($this->makeInstructor)('Sara');
    $sara->delete();

    expect($sara->trashed())->toBeTrue()
        // Still findable, which is the whole reason this is a refusal rather
        // than a ModelNotFoundException.
        ->and(User::withTrashed()->whereKey($sara->getKey())->exists())->toBeTrue()
        ->and(User::whereKey($sara->getKey())->exists())->toBeFalse();

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(InstructorNotEligibleException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses to change the hours of an instructor who has since departed', function () {
    // The allocation stays; it just cannot be rewritten. Phase 2 pays from this
    // row, and "Sara left, so her 18 hours became 40" is not a correction.
    $sara = ($this->makeInstructor)('Sara');
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));

    $sara->delete();

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        40,
    )))->toThrow(InstructorNotEligibleException::class);

    expect((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(18);
});

it('refuses an instructor id that matches no account', function () {
    // Distinct from ineligibility: nothing was named, so nothing is refused —
    // the lookup itself fails.
    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        999_999,
        30,
    )))->toThrow(ModelNotFoundException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('rejects an hours figure the column could not hold', function () {
    $sara = ($this->makeInstructor)('Sara');

    expect(fn () => new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), -1))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 65_536))
        ->toThrow(InvalidArgumentException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Removal
|--------------------------------------------------------------------------
*/

it('removes only the named instructor, leaving the co-teacher in place', function () {
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $omar->getKey(), 12));

    $this->remove->execute($this->admin, $this->batch, $omar);

    $batch = $this->batch->fresh();

    expect($batch->instructors)->toHaveCount(1)
        ->and($batch->instructors->first()->getKey())->toBe($sara->getKey())
        ->and($batch->totalAssignedHours())->toBe(18)
        ->and(DB::table('batch_instructor')->count())->toBe(1);
});

it('removes a departed instructor from an open batch', function () {
    // A person who left and should never have been on this batch must still be
    // removable, or the mistake is permanent. Only the batch's status gates the
    // removal; the instructor's own state does not enter into it.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $omar->getKey(), 12));

    $sara->delete();

    $this->remove->execute($this->admin, $this->batch, $sara);

    $batch = $this->batch->fresh();

    expect(DB::table('batch_instructor')->count())->toBe(1)
        ->and($batch->totalAssignedHours())->toBe(12)
        ->and($batch->instructors->pluck('id')->all())->toBe([$omar->getKey()])
        // The account itself is untouched: removing an allocation is not a
        // second deletion.
        ->and(User::withTrashed()->whereKey($sara->getKey())->exists())->toBeTrue();
});

it('refuses to remove a departed instructor from a closed batch', function () {
    // Departed or not, a finished batch is history. Erasing an allocation from
    // it erases what phase 2 owes.
    $sara = ($this->makeInstructor)('Sara');
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 30));

    $sara->delete();
    $this->batch->update(['status' => 'completed']);

    expect(fn () => $this->remove->execute($this->admin, $this->batch->fresh(), $sara))
        ->toThrow(BatchClosedException::class);

    expect(DB::table('batch_instructor')->count())->toBe(1)
        ->and($this->batch->fresh()->totalAssignedHours())->toBe(30);
});

it('refuses to assign a departed instructor to a closed batch', function () {
    // Both refusals apply; the batch's is asked first, because the status gate
    // lives in the policy and runs before eligibility.
    $closed = Batch::factory()->for($this->course)->completed()->create();
    $sara = ($this->makeInstructor)('Sara');
    $sara->delete();

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $closed->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(BatchClosedException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('leaves the same instructor on other batches when removed from one', function () {
    $sara = ($this->makeInstructor)('Sara');
    $other = Batch::factory()->for($this->course)->active()->create();

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 30));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $other->getKey(), (int) $sara->getKey(), 30));

    $this->remove->execute($this->admin, $this->batch, $sara);

    expect($this->batch->fresh()->instructors)->toHaveCount(0)
        ->and($other->fresh()->instructors)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Departed instructors stay in the allocation history
|--------------------------------------------------------------------------
|
| Batch::instructors() is withTrashed() ON PURPOSE. The pivot row survives a
| soft delete — user_id is restrictOnDelete, so it must — and phase 2 pays wages
| from it. A relation that dropped the row's owner would make those hours
| invisible to the panel, to the badge and to every total, while the money still
| came out of them. If a change makes the tests below fail, the change is wrong.
*/

it('keeps a departed instructor in the relation and in the hour total', function () {
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $omar->getKey(), 12));

    $omar->delete();

    $batch = $this->batch->fresh();

    expect($batch->instructors)->toHaveCount(2)
        ->and($batch->instructors->pluck('id')->sort()->values()->all())
        ->toBe(collect([$sara->getKey(), $omar->getKey()])->sort()->values()->all())
        ->and($batch->instructors->firstWhere('id', $omar->getKey())->trashed())->toBeTrue()
        ->and((int) $batch->instructors->firstWhere('id', $omar->getKey())->pivot->assigned_hours)->toBe(12)
        // The whole point: the hours still count.
        ->and($batch->totalAssignedHours())->toBe(30)
        ->and($batch->hasHourMismatch())->toBeFalse();
});

it('counts a departed instructor in the eager hour aggregate too', function () {
    /*
     * The listing path, not the relation path. BatchResource selects the SUM as
     * a sub-query and totalAssignedHours() reads that alias instead of querying,
     * so the two can disagree without anything failing: the panel would list
     * Omar's twelve hours while the schedule's badge quietly dropped them.
     *
     * withSum() does inherit the relation's withTrashed() — it calls
     * mergeConstraintsFrom($relation->getQuery()), which re-applies the scopes
     * the relation removed — but that is a Laravel internal, not a promise. This
     * test is what turns it into one.
     */
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $omar->getKey(), 12));

    $omar->delete();

    $listed = Batch::query()
        ->withSum('instructors as '.Batch::ASSIGNED_HOURS_SUM, 'batch_instructor.assigned_hours')
        ->whereKey($this->batch->getKey())
        ->firstOrFail();

    // Read from the alias, so this is the aggregate and not a fallback query.
    expect($listed->getAttributes())->toHaveKey(Batch::ASSIGNED_HOURS_SUM)
        ->and($listed->totalAssignedHours())->toBe(30)
        // And it agrees with the relation, which is the property that matters.
        ->and($listed->totalAssignedHours())->toBe($this->batch->fresh()->totalAssignedHours());
});

/*
|--------------------------------------------------------------------------
| The row locks, proven from the SQL that was actually emitted
|--------------------------------------------------------------------------
|
| "Authorizes against the freshly locked batch" above proves the Action
| RE-QUERIES; it does not prove the query takes a lock, and a re-query without
| one loses every race it was written to win. These read the emitted SQL.
*/

it('locks the batch row while assigning', function () {
    $sara = ($this->makeInstructor)('Sara');

    $locked = ($this->captureLocks)();

    $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        18,
    ));

    expect(in_array('batches', ($this->lockedTables)($locked), true))->toBeTrue(
        'AssignInstructorAction re-read the batch without lockForUpdate(). Locking statements seen: '
        .($this->describeLocks)($locked),
    );
});

it('locks the instructor row while assigning', function () {
    // The eligibility decision READS the instructor's is_active, deleted_at and
    // employment type. Reading them unlocked is the same race the batch lock
    // exists to prevent: a deactivation or departure committed between that read
    // and the pivot write leaves hours against somebody already stood down.
    $sara = ($this->makeInstructor)('Sara');

    $locked = ($this->captureLocks)();

    $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        18,
    ));

    expect(in_array('users', ($this->lockedTables)($locked), true))->toBeTrue(
        'AssignInstructorAction read the instructor without lockForUpdate(). Locking statements seen: '
        .($this->describeLocks)($locked),
    );
});

it('locks the batch row while removing', function () {
    $sara = ($this->makeInstructor)('Sara');
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));

    $locked = ($this->captureLocks)();

    $this->remove->execute($this->admin, $this->batch, $sara);

    expect(in_array('batches', ($this->lockedTables)($locked), true))->toBeTrue(
        'RemoveInstructorAction re-read the batch without lockForUpdate(). Locking statements seen: '
        .($this->describeLocks)($locked),
    );
});

/*
|--------------------------------------------------------------------------
| What the database itself guarantees
|--------------------------------------------------------------------------
*/

it('cascades the allocations away when the batch is deleted', function () {
    // An allocation to a batch that no longer exists is not a fact about
    // anything.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $omar->getKey(), 12));

    $survivor = Batch::factory()->for($this->course)->active()->create();
    $this->assign->execute($this->admin, new AssignInstructorData((int) $survivor->getKey(), (int) $sara->getKey(), 30));

    $this->batch->delete();

    expect(DB::table('batch_instructor')->where('batch_id', $this->batch->getKey())->count())->toBe(0)
        // Only this batch's rows went; the other batch is untouched.
        ->and(DB::table('batch_instructor')->count())->toBe(1)
        // And both accounts survive: batches are deleted, people are not.
        ->and(User::whereKey([$sara->getKey(), $omar->getKey()])->count())->toBe(2);
});

it('refuses at the foreign key to hard-delete an instructor holding allocations', function () {
    // restrictOnDelete on user_id. Phase 2 pays wages from these rows, so an
    // instructor with hours must not be destroyable — and the guarantee lives in
    // the database, where it cannot be raced or reasoned around.
    $sara = ($this->makeInstructor)('Sara');
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 30));

    try {
        $sara->forceDelete();
        $thrown = null;
    } catch (QueryException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class)
        // 1451: "Cannot delete or update a parent row: a foreign key constraint
        // fails". Asserting the exact driver code, not merely "something threw":
        // a NOT NULL violation or a lost connection would also be a
        // QueryException and would prove nothing about the restriction.
        ->and($thrown->errorInfo[1] ?? null)->toBe(1451)
        ->and(User::withTrashed()->whereKey($sara->getKey())->exists())->toBeTrue()
        ->and(DB::table('batch_instructor')->count())->toBe(1);
});

it('lets an instructor with no allocations be hard-deleted', function () {
    // The control for the restriction above: the foreign key refuses instructors
    // with hours, not instructors in general.
    $spare = ($this->makeInstructor)('Spare');

    $spare->forceDelete();

    expect(User::withTrashed()->whereKey($spare->getKey())->exists())->toBeFalse();
});

it('refuses a second row for the same batch and instructor at the database level', function () {
    // The unique index, proven directly rather than only through the Action.
    $sara = ($this->makeInstructor)('Sara');
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 18));

    expect(fn () => DB::table('batch_instructor')->insert([
        'batch_id' => $this->batch->getKey(),
        'user_id' => $sara->getKey(),
        'assigned_hours' => 12,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

/*
|--------------------------------------------------------------------------
| Authorization through the Action
|--------------------------------------------------------------------------
*/

it('refuses an actor who lacks assign_instructor', function () {
    // Staff hold view_batch and no assign_instructor: they answer "when does the
    // next English B1 start", they do not decide who is paid for teaching it.
    $staff = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($staff, 'staff');
    $sara = ($this->makeInstructor)('Sara');

    expect(fn () => $this->assign->execute($staff->refresh(), new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(AuthorizationException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses an actor who may update a batch but not assign its instructors', function () {
    // update_batch and assign_instructor are separate grants: editing the
    // schedule is not deciding whose name goes against the teaching.
    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('view_any_batch', 'view_batch', 'update_batch');
    $sara = ($this->makeInstructor)('Sara');

    expect(fn () => $this->assign->execute($editor->refresh(), new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(AuthorizationException::class);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses an unauthorized actor to remove an instructor', function () {
    $sara = ($this->makeInstructor)('Sara');
    $this->assign->execute($this->admin, new AssignInstructorData((int) $this->batch->getKey(), (int) $sara->getKey(), 30));

    $staff = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($staff, 'staff');

    expect(fn () => $this->remove->execute($staff->refresh(), $this->batch, $sara))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('batch_instructor')->count())->toBe(1);
});

it('tells an unauthorized actor nothing about a closed batch', function () {
    // The refusal order matters. An actor WITHOUT assign_instructor gets the
    // ordinary authorization failure whatever the batch's state, so a refusal
    // cannot be used to probe which batches are closed. Only an actor who holds
    // the ability learns that the batch is the reason.
    $closed = Batch::factory()->for($this->course)->completed()->create();
    $sara = ($this->makeInstructor)('Sara');

    $staff = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($staff, 'staff');

    expect(fn () => $this->assign->execute($staff->refresh(), new AssignInstructorData(
        (int) $closed->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(AuthorizationException::class);

    expect(fn () => $this->assign->execute($this->admin, new AssignInstructorData(
        (int) $closed->getKey(),
        (int) $sara->getKey(),
        30,
    )))->toThrow(BatchClosedException::class);
});

it('lets a super admin assign instructors', function () {
    // The positive control at the other end of the role range.
    $superAdmin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($superAdmin, 'super_admin');
    $sara = ($this->makeInstructor)('Sara');

    $this->assign->execute($superAdmin->refresh(), new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $sara->getKey(),
        30,
    ));

    expect($this->batch->fresh()->totalAssignedHours())->toBe(30);
});

/*
|--------------------------------------------------------------------------
| The write boundary
|--------------------------------------------------------------------------
*/

it('writes the instructor pivot from nowhere but the two Actions', function () {
    /*
     * A scan, not a behavioural proof — see docs/ENGINEERING.md on what these
     * are worth. It catches the code shape that reaches around the Actions:
     * $batch->instructors()->attach/detach/sync/syncWithoutDetaching/toggle/
     * updateExistingPivot. The existing ActionBoundaryArchTest covers the same
     * shape for roles, permissions and users; instructors are outside its regex,
     * and that test is not this task's to edit.
     *
     * Comments are stripped first, so the docblocks above that NAME these
     * methods do not trip it.
     */
    $stripComments = function (string $path): string {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    };

    // The sanctioned write path, and nothing else. Both authorize the actor
    // against the freshly locked batch before touching the pivot.
    $sanctioned = ['AssignInstructorAction', 'RemoveInstructorAction'];

    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (in_array($file->getFilenameWithoutExtension(), $sanctioned, true)) {
            continue;
        }

        $pattern = '/->\s*instructors\s*\(\s*\)\s*->\s*(attach|detach|sync|syncWithoutDetaching|toggle|updateExistingPivot)\s*\(/';

        if (preg_match($pattern, $stripComments((string) $file->getRealPath())) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBeEmpty(
        'Instructor pivot writes must go through AssignInstructorAction / RemoveInstructorAction: '
        .implode(', ', $offenders),
    );
});
