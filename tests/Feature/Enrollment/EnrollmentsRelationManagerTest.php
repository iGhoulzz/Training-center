<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\EnrollmentsRelationManager;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\Reference;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    /*
     * Sentinels rather than the eventual English copy. These prove the visible
     * composite values are controlled by translations; hardcoded separators or
     * ordering cannot accidentally satisfy the assertions.
     */
    Lang::addLines([
        'enrollment.student_option_label' => 'student=:code|name=:name',
        'enrollment.enrolment_load_value' => 'load=:active|limit=:capacity',
        'enrollment.no_capacity_limit_short' => 'unlimited',
    ], app()->getLocale());

    $this->system = app(SystemRoleWriter::class);

    $this->makeUser = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->course = Course::factory()->create(['total_hours' => 30]);
    $this->batch = Batch::factory()->for($this->course)->active()->create(['capacity' => 2]);

    /** Mount the relation manager on the batch's view page as the given actor. */
    $this->mountPanel = fn (User $actor, ?Batch $batch = null) => Livewire::actingAs($actor)
        ->test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $batch ?? $this->batch,
            'pageClass' => ViewBatch::class,
        ]);
});

it('enrolls a student through the panel', function () {
    // THE AUTHORIZED CONTROL. mountAction() returns null identically for an
    // unresolvable record, a disabled action and an unauthorized one, so without
    // a positive proving the panel works, every negative below would pass for
    // the wrong reason.
    $student = Student::factory()->create();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('enroll', null, ['student_id' => $student->getKey()]);

    expect(Enrollment::query()->count())->toBe(1)
        ->and(Enrollment::query()->sole()->status)->toBe(EnrollmentStatus::Active);
});

it('raises the bill as part of enrolling through the panel', function () {
    /*
     * THE ASSERTION THAT PROVES THE SURFACE WAS ACTUALLY MIGRATED (P2-T03).
     *
     * Design section 12 is explicit that this file must change: this screen was
     * the one UI path that could create an enrolment with no bill, and a phase 2
     * suite passing while this file still asserted only the phase 1 behaviour
     * would mean the migration never happened. Every case above passed unchanged
     * against EnrollAndBillAction — which is correct, and is exactly why it
     * proves nothing on its own.
     *
     * Driven through the real component rather than the Action, because the
     * defect being closed was in the wiring, not in either Action.
     */
    // 22:30 UTC is 00:30 the NEXT day in Tripoli — see the due-date assertion.
    $this->travelTo('2026-08-14 22:30:00');

    $student = Student::factory()->create();

    /*
     * A REAL PRICE, BECAUSE THE FACTORY DEFAULTS MAKE THE MONEY ASSERTION
     * VACUOUS. BatchFactory leaves `price` null and CourseFactory sets
     * `default_price` to 0, so "amount equals list price" would be
     * '0.000' === '0.000' — true for any implementation, including one that
     * bills nothing. The independent review caught this.
     */
    $this->batch->update(['price' => '850.000']);

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('enroll', null, ['student_id' => $student->getKey()]);

    $enrollment = Enrollment::query()->sole();
    $charge = Charge::query()->sole();

    expect((int) $charge->enrollment_id)->toBe((int) $enrollment->getKey())
        ->and($charge->reference)->toStartWith(Reference::CHARGE_PREFIX)
        // Full price: this screen offers no discount, by design section 3.
        ->and($charge->discount_id)->toBeNull()
        ->and($charge->list_price)->toBe('850.000')
        ->and($charge->amount)->toBe('850.000')
        /*
         * Design section 4: due on the day the debt was incurred, ON THE
         * CENTRE'S CALENDAR.
         *
         * This compared against `enrolled_at`'s UTC date, which is the exact
         * confusion the Action was fixed to stop making — and it was
         * CLOCK-DEPENDENT: green whenever UTC and Tripoli share a date, red
         * between 22:00 and midnight UTC. CI failed it at 22:xx with
         * `-'2026-08-14' +'2026-08-15'` after the local gate had passed.
         *
         * Pinned inside that window, so it now proves the property instead of
         * restating whatever the clock makes true, and a regression to UTC
         * truncation fails it every time rather than two hours a day.
         */
        ->and($charge->due_date->toDateString())->toBe('2026-08-15');
});

it('takes the batch from the owner record, not a crafted payload', function () {
    $other = Batch::factory()->for($this->course)->active()->create();
    $student = Student::factory()->create();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('enroll', null, [
            'student_id' => $student->getKey(),
            'batch_id' => $other->getKey(),
        ]);

    expect((int) Enrollment::query()->sole()->batch_id)->toBe((int) $this->batch->getKey())
        ->and($other->enrollments()->count())->toBe(0);
});

it('refuses a crafted status on enrollment', function () {
    // The enrol form still has no status field, and completion arrives on its
    // own bulk action (P3-T03) rather than through this one — a payload naming
    // a status here must not reach the column either way. EnrollStudentAction
    // sets Active unconditionally.
    $student = Student::factory()->create();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('enroll', null, [
            'student_id' => $student->getKey(),
            'status' => EnrollmentStatus::Completed->value,
        ]);

    expect(Enrollment::query()->sole()->status)->toBe(EnrollmentStatus::Active);
});

it('refuses enrollment into a completed batch and says why', function () {
    $closed = Batch::factory()->for($this->course)->completed()->create();

    ($this->mountPanel)(($this->makeUser)('admin'), $closed)
        ->callTableAction('enroll', null, ['student_id' => Student::factory()->create()->getKey()])
        ->assertNotified(__('enrollment.batch_closed'));

    expect(Enrollment::query()->count())->toBe(0);
});

it('warns rather than refusing when a batch goes over capacity', function () {
    // Capacity is 2; this is the third. It must SUCCEED and notify.
    Enrollment::factory()->count(2)->for($this->batch)->create();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('enroll', null, ['student_id' => Student::factory()->create()->getKey()])
        ->assertNotified(__('enrollment.over_capacity_warning'));

    expect($this->batch->enrollments()->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Authorization at the panel, and past it
|--------------------------------------------------------------------------
*/

it('withdraws through the panel for an assigned staff actor', function () {
    $staff = ($this->makeUser)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    $this->batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 30]);

    $enrollment = Enrollment::factory()->for($this->batch)->create();

    ($this->mountPanel)($staff)->callTableAction('withdraw', $enrollment);

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Withdrawn);
});

it('hides withdrawal from a staff actor who does not teach the batch', function () {
    $enrollment = Enrollment::factory()->for($this->batch)->create();

    ($this->mountPanel)(($this->makeUser)('staff'))
        ->assertTableActionHidden('withdraw', $enrollment);
});

it('refuses a CRAFTED withdrawal by an unassigned staff actor', function () {
    /*
     * MOUNTING IS NOT THE ATTACK — CALLING IS.
     *
     * assertTableActionHidden proves the button is absent and says nothing about
     * a request that skips the button, which is what an attacker sends. This
     * mounts the action directly and then calls it.
     *
     * The authorized control comes first because mountAction() returns null
     * identically for an unresolvable name, a disabled action and an unauthorized
     * one — a null on its own proves nothing.
     */
    $context = fn (Enrollment $e): array => ['table' => true, 'recordKey' => (string) $e->getKey()];

    $control = Enrollment::factory()->for($this->batch)->create();
    ($this->mountPanel)(($this->makeUser)('admin'))
        ->call('mountAction', 'withdraw', [], $context($control))
        ->call('callMountedAction');

    expect($control->fresh()->status)->toBe(
        EnrollmentStatus::Withdrawn,
        'The authorized control could not withdraw, so the refusal below proves nothing.',
    );

    $target = Enrollment::factory()->for($this->batch)->create();
    ($this->mountPanel)(($this->makeUser)('staff'))
        ->call('mountAction', 'withdraw', [], $context($target))
        ->call('callMountedAction');

    expect($target->fresh()->status)->toBe(
        EnrollmentStatus::Active,
        'An unassigned staff actor withdrew a student by calling the action directly.',
    );
});

it('refuses a CRAFTED delete by a staff actor', function () {
    // delete_enrollment is admin-only. Same control-first shape as above.
    $context = fn (Enrollment $e): array => ['table' => true, 'recordKey' => (string) $e->getKey()];

    $control = Enrollment::factory()->for($this->batch)->create();
    ($this->mountPanel)(($this->makeUser)('admin'))
        ->call('mountAction', 'delete', [], $context($control))
        ->call('callMountedAction');

    expect(Enrollment::whereKey($control->getKey())->exists())->toBeFalse(
        'The authorized control could not delete, so the refusal below proves nothing.',
    );

    $target = Enrollment::factory()->for($this->batch)->create();
    ($this->mountPanel)(($this->makeUser)('staff'))
        ->call('mountAction', 'delete', [], $context($target))
        ->call('callMountedAction');

    expect(Enrollment::whereKey($target->getKey())->exists())->toBeTrue(
        'A staff actor deleted an enrolment by calling the action directly.',
    );
});

it('hides the withdraw button on an already-withdrawn row', function () {
    $enrollment = Enrollment::factory()->for($this->batch)->withdrawn()->create();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->assertTableActionHidden('withdraw', $enrollment);
});

/*
|--------------------------------------------------------------------------
| The bulk completion action (P3-T03)
|--------------------------------------------------------------------------
*/

it('shows the complete bulk action to an admin', function () {
    ($this->mountPanel)(($this->makeUser)('admin'))
        ->assertTableBulkActionVisible('complete');
});

it('shows the complete bulk action to a staff actor assigned to the batch', function () {
    $staff = ($this->makeUser)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    $this->batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 30]);

    ($this->mountPanel)($staff)->assertTableBulkActionVisible('complete');
});

it('hides the complete bulk action from a staff actor who does not teach the batch', function () {
    ($this->mountPanel)(($this->makeUser)('staff'))
        ->assertTableBulkActionHidden('complete');
});

it('runs the Action once per selected row, writing one activity entry per row', function () {
    /*
     * THE DONE-WHEN LINE, MADE CONCRETE. "The bulk action over three selected
     * rows writes three activity entries, not one" — proving there is no
     * aggregate write anywhere in the path: not a single UPDATE ... WHERE id
     * IN (...), and not one activity() call describing all three.
     */
    $enrollments = Enrollment::factory()->count(3)->for($this->batch)->create();

    $before = Activity::query()
        ->where('subject_type', Enrollment::class)
        ->where('event', 'updated')
        ->count();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableBulkAction('complete', $enrollments);

    $after = Activity::query()
        ->where('subject_type', Enrollment::class)
        ->where('event', 'updated')
        ->count();

    expect($after - $before)->toBe(3)
        ->and($enrollments->fresh()->pluck('status')->unique()->all())
        ->toBe([EnrollmentStatus::Completed]);

    // Every one of the three rows has its OWN entry naming its own id — not
    // one entry repeated, and not one entry naming only the first row.
    $loggedIds = Activity::query()
        ->where('subject_type', Enrollment::class)
        ->where('event', 'updated')
        ->whereIn('subject_id', $enrollments->pluck('id'))
        ->pluck('subject_id')
        ->unique()
        ->sort()
        ->values();

    expect($loggedIds->all())->toBe($enrollments->pluck('id')->sort()->values()->all());
});

it('completes the completable rows and leaves an already-withdrawn row alone in a mixed selection', function () {
    // Partial failure is expected, not exceptional (see completeAction()'s
    // docblock): one row already withdrawn from another tab must not stop the
    // other two from completing.
    //
    // concat(), NOT push(). Collection::push() mutates the receiver in place
    // and returns the SAME instance, so $completable->push($withdrawn) would
    // leave $completable itself holding all three rows — and the assertion
    // below would then be checking the withdrawn row against its own claim.
    $completable = Enrollment::factory()->count(2)->for($this->batch)->create();
    $withdrawn = Enrollment::factory()->for($this->batch)->withdrawn()->create();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableBulkAction('complete', $completable->concat([$withdrawn]));

    expect($completable->fresh()->pluck('status')->unique()->all())
        ->toBe([EnrollmentStatus::Completed])
        ->and($withdrawn->fresh()->status)->toBe(EnrollmentStatus::Withdrawn);
});

it('refuses a CRAFTED bulk completion by an unassigned staff actor calling it directly', function () {
    /*
     * MOUNTING IS NOT THE ATTACK — CALLING IS, the same control-first shape
     * EnrollmentsRelationManagerTest already uses for withdraw and delete.
     *
     * callTableBulkAction() cannot play that role here: it wraps the SAME
     * mountAction() test helper the single-record tests deliberately avoid,
     * which asserts the action is VISIBLE before calling it and fails the test
     * outright for an unauthorized actor rather than exercising the refusal.
     * So this selects the row exactly as that helper does (selectTableRecords()
     * only sets a public Livewire property — no assertion of its own) and then
     * calls the component's raw `mountAction` method directly, bypassing the
     * visibility check the way a crafted Livewire payload would.
     */
    $bulkContext = ['table' => true, 'bulk' => true];

    $control = Enrollment::factory()->for($this->batch)->create();
    ($this->mountPanel)(($this->makeUser)('admin'))
        ->selectTableRecords([$control])
        ->call('mountAction', 'complete', [], $bulkContext)
        ->call('callMountedAction');

    expect($control->fresh()->status)->toBe(
        EnrollmentStatus::Completed,
        'The authorized control could not complete, so the refusal below proves nothing.',
    );

    $target = Enrollment::factory()->for($this->batch)->create();
    ($this->mountPanel)(($this->makeUser)('staff'))
        ->selectTableRecords([$target])
        ->call('mountAction', 'complete', [], $bulkContext)
        ->call('callMountedAction');

    expect($target->fresh()->status)->toBe(
        EnrollmentStatus::Active,
        'An unassigned staff actor completed an enrolment through a crafted bulk action call.',
    );
});

/*
|--------------------------------------------------------------------------
| The action registry, and what may not appear in it
|--------------------------------------------------------------------------
*/

it('registers exactly the actions it declares, each of the expected class', function () {
    /*
     * NAME -> CONCRETE CLASS, AS AN ALLOWLIST.
     *
     * A DENYLIST of forbidden classes is unbounded by construction — it would
     * miss DissociateAction, ReplicateAction, and whatever Filament adds next.
     *
     * NAMES ALONE are not enough either: Filament\Actions\DeleteAction::make('delete')
     * is named 'delete' and persists by calling $record->delete() straight
     * through the relation, bypassing DeleteEnrollmentAction. The name matches;
     * the behaviour is the bypass. So the class is asserted alongside it.
     *
     * getFlatActions() already includes the header action, so header membership
     * is asserted separately rather than by concatenating the two collections —
     * which would double-count 'enroll'.
     *
     * getFlatActions() and getFlatBulkActions() are DISJOINT — Filament sorts a
     * cached action into the bulk collection purely by `instanceof BulkAction`
     * (see HasActions::cacheAction()), so 'complete' appears in the bulk
     * assertion below and never in this one, regardless of which method
     * registered it.
     */
    $table = ($this->mountPanel)(($this->makeUser)('super_admin'))->instance()->getTable();

    $flat = collect($table->getFlatActions())
        ->mapWithKeys(fn (Action $action): array => [$action->getName() => $action::class])
        ->all();

    ksort($flat);

    expect($flat)->toBe([
        'delete' => Action::class,
        'enroll' => Action::class,
        'withdraw' => Action::class,
    ]);

    expect(collect($table->getHeaderActions())->map(fn (Action $a): string => $a->getName())->all())
        ->toBe(['enroll']);

    // toolbarActions() is the current, non-deprecated way to register a bulk
    // action in this Filament version — see completeAction()'s own docblock.
    expect(collect($table->getToolbarActions())->map(fn (Action $a): string => $a->getName())->all())
        ->toBe(['complete']);

    $bulk = collect($table->getFlatBulkActions())
        ->mapWithKeys(fn (BulkAction $action): array => [$action->getName() => $action::class])
        ->all();

    expect($bulk)->toBe(['complete' => BulkAction::class]);
});

/*
|--------------------------------------------------------------------------
| The student picker
|--------------------------------------------------------------------------
*/

it('bounds the student picker to the result limit', function () {
    // 30 matches, 25 offered. Unbounded, this loads every student in the centre
    // on every render — fine at forty rows, a timeout at ten thousand.
    Student::factory()->count(30)->create(['last_name' => 'Zarrouk']);

    expect(EnrollmentsRelationManager::searchStudents('Zarrouk'))->toHaveCount(25);
});

it('matches the student code by prefix and either name anywhere', function () {
    $byCode = Student::factory()->create(['student_code' => 'TC-0042', 'last_name' => 'Idris']);
    $byFirst = Student::factory()->create(['first_name' => 'Fatima', 'last_name' => 'Idris']);
    $byLast = Student::factory()->create(['first_name' => 'Omar', 'last_name' => 'Zarrouk']);

    expect(array_keys(EnrollmentsRelationManager::searchStudents('TC-0042')))
        ->toContain((int) $byCode->getKey())
        ->and(array_keys(EnrollmentsRelationManager::searchStudents('atim')))
        ->toContain((int) $byFirst->getKey())
        ->and(array_keys(EnrollmentsRelationManager::searchStudents('arrouk')))
        ->toContain((int) $byLast->getKey());
});

it('labels each result with the code and the full name', function () {
    // A centre has more than one Mohammed, and picking the wrong student enrols
    // the wrong person on a course phase 2 will bill.
    $student = Student::factory()->create([
        'student_code' => 'TC-0042',
        'first_name' => 'Fatima',
        'last_name' => 'Zarrouk',
    ]);

    expect(EnrollmentsRelationManager::searchStudents('Zarrouk')[(int) $student->getKey()])
        ->toBe('student=TC-0042|name=Fatima Zarrouk');
});

it('rehydrates the label of an already-selected student without searching', function () {
    // Not Student::find()?->pipe(): Eloquent models do not provide pipe(), which
    // would be a fatal error the first time a form redisplayed a chosen student
    // after a validation failure elsewhere.
    $student = Student::factory()->create([
        'student_code' => 'TC-0042',
        'first_name' => 'Fatima',
        'last_name' => 'Zarrouk',
    ]);

    expect(EnrollmentsRelationManager::studentOptionLabel($student->getKey()))
        ->toBe('student=TC-0042|name=Fatima Zarrouk')
        ->and(EnrollmentsRelationManager::studentOptionLabel(999999))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Query cost
|--------------------------------------------------------------------------
*/

it('asks the assignment question once per panel, not once per row', function () {
    /*
     * EnrollmentUpdateRule asks whether the actor is on this batch's instructor
     * list. Every row asks the identical question, so a per-record authorize()
     * is one pivot query per enrolment listed.
     *
     * COUNTS ONLY THE RELEVANT STATEMENTS, AND ASSERTS AN EXACT NUMBER.
     * Comparing total query counts between a cold and a warm render is not a
     * test: totals move for unrelated reasons and an N+1 of three can hide inside
     * that noise in either direction.
     *
     * TWO READS, NOT ONE, SINCE P3-T03 — AND STILL NOT ONE PER ROW.
     * mayAmendThisBatch() (withdraw's visibility) and mayCompleteThisBatch()
     * (the bulk complete action's visibility) are two INDEPENDENT questions,
     * asking about two different permission pairs through two different rule
     * classes — EnrollmentUpdateRule and CompletionRule. Each memoises its own
     * answer once per panel render, so the render cost is a constant 2 rather
     * than 1, and — the property this test actually exists to guard — it stays
     * flat as the row count grows rather than becoming 2 x 8. Reverting either
     * memoisation would turn this into 16, not 2, and this test would catch it.
     */
    $staff = ($this->makeUser)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    $this->batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 30]);

    Enrollment::factory()->count(8)->for($this->batch)->create();

    $statements = captureStatements();

    ($this->mountPanel)($staff)->assertSuccessful();

    $pivotReads = collect($statements)
        ->filter(fn (array $statement): bool => str_contains($statement['sql'], 'batch_instructor'))
        ->count();

    expect($pivotReads)->toBe(
        2,
        "Rendering eight enrolments ran {$pivotReads} queries against batch_instructor; the "
        .'assigned-batch answer should be memoised once per panel for EACH of the two rules '
        .'that ask it (withdraw and bulk-complete visibility). Statements: '
        .describeStatements($statements),
    );
});

it('renders enrolment load for every batch without a query per row', function () {
    /*
     * THE POINT OF THE AGGREGATE. activeEnrollmentCount() falls back to counting
     * per row when the alias is absent, and the ANSWER stays correct either way —
     * so nothing but a query count can tell the two apart, and a column that
     * silently costs one query per batch is exactly the regression that ships.
     *
     * The count is a withCount sub-query on the batches select, so the right
     * number of separate selects against enrollments is zero.
     */
    foreach (range(1, 5) as $ignored) {
        $batch = Batch::factory()->for($this->course)->active()->create(['capacity' => 2]);
        Enrollment::factory()->count(3)->for($batch)->create();
    }

    $statements = captureStatements();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->assertSuccessful();

    /*
     * The `from \`batches\`` exclusion is load-bearing. The listing's own query
     * CONTAINS "from `enrollments`" — that is the withCount sub-select, which is
     * the thing working correctly — so a filter without it counts the aggregate
     * as though it were the N+1 and fails on a passing implementation.
     *
     * What this counts is a standalone select against enrollments, which is what
     * the per-row fallback in activeEnrollmentCount() emits.
     */
    $enrollmentReads = collect($statements)
        ->filter(fn (array $statement): bool => str_starts_with($statement['sql'], 'select')
            && str_contains($statement['sql'], 'from `enrollments`')
            && ! str_contains($statement['sql'], 'from `batches`'))
        ->count();

    expect($enrollmentReads)->toBe(
        0,
        "Listing batches ran {$enrollmentReads} separate selects against enrollments; the count "
        .'should ride along on the batches query. Statements: '.describeStatements($statements),
    );
});

it('shows seats taken against seats available, and only warns when over', function () {
    // The column is what makes the aggregate load-bearing, so its OUTPUT is
    // asserted rather than only its query cost. Three active on a capacity of two
    // is over; two is not, and a withdrawn student does not count towards it.
    $over = Batch::factory()->for($this->course)->active()->create(['capacity' => 2]);
    Enrollment::factory()->count(3)->for($over)->create();
    Enrollment::factory()->for($over)->withdrawn()->create();

    $within = Batch::factory()->for($this->course)->active()->create(['capacity' => 2]);
    Enrollment::factory()->count(2)->for($within)->create();

    $unlimited = Batch::factory()->for($this->course)->active()->create(['capacity' => 0]);
    Enrollment::factory()->for($unlimited)->create();

    $loaded = fn (Batch $batch): Batch => BatchResource::getEloquentQuery()
        ->whereKey($batch->getKey())
        ->sole();

    expect($loaded($over)->activeEnrollmentCount())->toBe(3)
        ->and($loaded($over)->isOverCapacity())->toBeTrue()
        ->and($loaded($within)->activeEnrollmentCount())->toBe(2)
        ->and($loaded($within)->isOverCapacity())->toBeFalse();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->assertSuccessful()
        ->assertSee('load=3|limit=2')
        ->assertSee('load=2|limit=2')
        ->assertSee('load=1|limit=unlimited');
});
