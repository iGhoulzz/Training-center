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
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

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
    // There is no status field, and completion is phase 3. A payload naming one
    // must not reach the column — EnrollStudentAction sets Active unconditionally.
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

    expect($table->getToolbarActions())->toBeEmpty()
        ->and($table->getFlatBulkActions())->toBeEmpty();
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
        ->toBe('TC-0042 — Fatima Zarrouk');
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
        ->toBe('TC-0042 — Fatima Zarrouk')
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
        1,
        "Rendering eight enrolments ran {$pivotReads} queries against batch_instructor; the "
        .'assigned-batch answer should be memoised once per panel. Statements: '
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
        ->assertSee('3 / 2')
        ->assertSee('2 / 2');
});
