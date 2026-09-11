<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Services\ChargeQueryService;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The cross-domain boundary, in both directions
|--------------------------------------------------------------------------
|
| Design section 12 requires EnrollmentQueryService to be built ONCE with the
| full surface four later tasks need — payments, receipts, payroll and reports —
| so that no two of them extend it in parallel branches. This file is that
| contract: one test per consumer, named for the task that will read it, so a
| later change that breaks task 8 fails with task 8's name attached.
|
| ChargeQueryService is the reverse direction, and design section 11 is explicit
| that it stays read-only: "a query service that also deletes is a write path
| wearing a reader's name."
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->enrollments = app(EnrollmentQueryService::class);
    $this->charges = app(ChargeQueryService::class);

    $this->course = Course::factory()->create([
        'code' => 'ENG-101',
        'default_price' => '600.000',
    ]);

    $this->batch = Batch::factory()->for($this->course)->create(['code' => 'ENG-101-A']);

    $this->student = Student::factory()->create([
        'student_code' => 'STU-0042',
        'first_name' => 'Amal',
        'last_name' => 'Zarrouk',
    ]);
});

/*
|--------------------------------------------------------------------------
| Task 4 — payments derive the student from the bill, never from the client
|--------------------------------------------------------------------------
*/

it('gives task 4 the student who owns an enrolment', function () {
    $enrollment = Enrollment::factory()
        ->for($this->student)
        ->for($this->batch)
        ->create();

    expect($this->enrollments->studentIdFor((int) $enrollment->getKey()))
        ->toBe((int) $this->student->getKey());
});

it('refuses to guess when the enrolment does not exist', function () {
    // Answering 0, or null, would let a payment be recorded against nobody.
    expect(fn () => $this->enrollments->studentIdFor(999_999))
        ->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| Task 6 — everything the receipt prints, in one read
|--------------------------------------------------------------------------
*/

it('gives task 6 the reference, codes and name a receipt prints', function () {
    $enrollment = Enrollment::factory()
        ->for($this->student)
        ->for($this->batch)
        ->create();

    $context = $this->enrollments->receiptContextFor((int) $enrollment->getKey());

    expect($context['enrollment_reference'])->toBe($enrollment->reference)
        ->and($context['student_code'])->toBe('STU-0042')
        ->and($context['student_name'])->toBe('Amal Zarrouk')
        ->and($context['batch_code'])->toBe('ENG-101-A')
        ->and($context['course_code'])->toBe('ENG-101')
        ->and($context['student_id'])->toBe((int) $this->student->getKey())
        /*
         * NO COURSE NAME, and this asserts that on purpose. `courses` is
         * bilingual, so returning "the name" would mean choosing a language
         * inside a query service — a view-layer decision that would bake English
         * into every consumer three phases before Arabic ships. Design section 2
         * asks the receipt for codes.
         */
        ->and($context)->not->toHaveKey('course_title')
        ->and($context)->not->toHaveKey('course_name');
});

it('reads the receipt context in a single query, because it runs in a queued job', function () {
    $enrollment = Enrollment::factory()
        ->for($this->student)
        ->for($this->batch)
        ->create();

    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->enrollments->receiptContextFor((int) $enrollment->getKey());

    expect($queries)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Task 8 — payroll freezes assigned hours, so it has to be able to read them
|--------------------------------------------------------------------------
*/

it('gives task 8 each instructor assignment with its hours', function () {
    $system = app(SystemRoleWriter::class);

    $sara = User::factory()->create(['is_active' => true]);
    $omar = User::factory()->create(['is_active' => true]);
    $system->assignRoles($sara, 'staff');
    $system->assignRoles($omar, 'staff');

    $this->batch->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 30],
        $omar->getKey() => ['assigned_hours' => 12],
    ]);

    $assignments = $this->enrollments->instructorAssignmentsFor((int) $this->batch->getKey());

    expect($assignments)->toHaveCount(2);

    $hours = $assignments->mapWithKeys(
        fn (array $row): array => [$row['user_id'] => $row['assigned_hours']]
    );

    expect($hours[(int) $sara->getKey()])->toBe(30)
        ->and($hours[(int) $omar->getKey()])->toBe(12);

    /*
     * THE ASSIGNMENT'S OWN ID, WITHOUT WHICH TASK 8 CANNOT WRITE A LINE.
     * `payroll_lines.batch_instructor_id` is a foreign key to it, and design
     * section 7's "paid at most once" unique index is keyed on it. The first
     * version of this service returned only the user and the hours, and this
     * test asserted only what was implemented — which is how a contract test
     * passes while the contract is unmet.
     */
    $pivotIds = DB::table('batch_instructor')
        ->where('batch_id', $this->batch->getKey())
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    expect($assignments->pluck('id')->all())->toEqualCanonicalizing($pivotIds)
        ->and($assignments->every(fn (array $row): bool => $row['batch_id'] === (int) $this->batch->getKey()))
        ->toBeTrue();
});

it('gives task 8 every assignment in the centre, whatever the batch status', function () {
    /*
     * Design section 7: an instructor_batch run lists every assignment not
     * already paid in a finalized run, WHATEVER THE BATCH'S STATUS. Keyed by
     * batch, task 8 would have to enumerate batch ids first — walking
     * Enrolment's tables from Finance, which is the cross-domain query this
     * boundary exists to prevent.
     */
    $system = app(SystemRoleWriter::class);

    $sara = User::factory()->create(['is_active' => true]);
    $system->assignRoles($sara, 'staff');

    $completed = Batch::factory()->for($this->course)->create(['status' => 'completed']);

    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 30]]);
    $completed->instructors()->attach([$sara->getKey() => ['assigned_hours' => 8]]);

    $all = $this->enrollments->allInstructorAssignments();

    expect($all)->toHaveCount(2)
        ->and($all->pluck('batch_id')->all())
        ->toEqualCanonicalizing([(int) $this->batch->getKey(), (int) $completed->getKey()]);
});

it('searches task 3 instructor assignments within the requested bound before hydration', function () {
    $this->batch->update(['code' => 'NEEDLE-BATCH']);
    $users = User::factory()->count(2_000)->create();
    $this->batch->instructors()->attach($users->mapWithKeys(
        fn (User $user): array => [$user->getKey() => ['assigned_hours' => 1]],
    )->all());

    $excludedId = (int) DB::table('batch_instructor')->value('id');
    $excluded = User::query()
        ->whereKey($users->firstOrFail()->getKey())
        ->selectRaw("{$excludedId} as batch_instructor_id");

    $results = $this->enrollments->searchInstructorAssignments($excluded, 'NEEDLE-BATCH', 25);

    expect($results)->toHaveCount(25)
        ->and($results->pluck('id'))->not->toContain($excludedId)
        ->and($results->every(fn (array $row): bool => array_keys($row) === [
            'id',
            'batch_id',
            'batch_code',
            'user_id',
            'user_name',
            'assigned_hours',
        ]))->toBeTrue()
        ->and($results->every(fn (array $row): bool => $row['batch_code'] === 'NEEDLE-BATCH'))->toBeTrue();

    $selected = $this->enrollments->instructorAssignmentsById([
        $results->firstOrFail()['id'],
        $results->last()['id'],
    ]);

    expect($selected)->toHaveCount(2)
        ->and($selected->pluck('id')->all())->toEqualCanonicalizing([
            $results->firstOrFail()['id'],
            $results->last()['id'],
        ]);
});

it('gives task 8 an empty list for a batch nobody teaches, rather than failing', function () {
    expect($this->enrollments->instructorAssignmentsFor((int) $this->batch->getKey()))
        ->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Task 10 — the enrolment → batch → course path every grouping walks
|--------------------------------------------------------------------------
*/

it('gives task 10 a join it can group and sum in one query', function () {
    /*
     * THE SHAPE T10 ACTUALLY NEEDS, WHICH IS NOT A LOOKUP.
     *
     * catalogueContextFor() answers one enrolment per call. A revenue report
     * grouped by batch could only use that by calling it per row and summing in
     * PHP — an N+1, and against design section 6's rule that aggregation happens
     * in SQL where DECIMAL sums are exact. The cross-review of this task caught
     * that the service satisfied the shape of the four-consumer contract while
     * leaving task 10 unable to write its query without extending it.
     *
     * This asserts the composable path: one grouped aggregate over charges,
     * joined through the service, in a SINGLE query.
     */
    $second = Batch::factory()->for($this->course)->create(['code' => 'ENG-101-B']);

    $first = Charge::factory()->create(['amount' => '100.000']);
    $first->enrollment->update(['batch_id' => $this->batch->getKey()]);

    $alsoFirst = Charge::factory()->create(['amount' => '250.000']);
    $alsoFirst->enrollment->update(['batch_id' => $this->batch->getKey()]);

    $other = Charge::factory()->create(['amount' => '400.000']);
    $other->enrollment->update(['batch_id' => $second->getKey()]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $revenue = $this->enrollments->joinCatalogueTo(
        DB::table('charges')->selectRaw('SUM(charges.amount) as billed'),
        'charges.enrollment_id',
        [EnrollmentQueryService::DIMENSION_BATCH],
    )
        ->groupBy(EnrollmentQueryService::BATCH_ID, EnrollmentQueryService::BATCH_CODE)
        ->get()
        ->mapWithKeys(fn (object $row): array => [
            $row->{EnrollmentQueryService::BATCH_CODE} => (string) $row->billed,
        ]);

    expect($queries)->toBe(1, 'The grouping path must be one SQL aggregate, not a lookup per row.');

    expect($revenue['ENG-101-A'])->toBe('350.000')
        ->and($revenue['ENG-101-B'])->toBe('400.000');
});

it('aggregates two batches of one course into a single course row', function () {
    /*
     * THE INVERSE CONTRACT, AND THE ONE THE FIRST VERSION COULD NOT SERVE.
     *
     * Selecting all four columns unconditionally works for a per-batch grouping
     * only because a course is functionally determined by its batch. Design
     * section 8 also requires revenue BY COURSE, and there the batch columns are
     * fatal: under ONLY_FULL_GROUP_BY MySQL rejects them, and adding them to the
     * GROUP BY silently answers per batch instead. Same fixture as the test
     * above, grouped one level up: two batches of one course, one row.
     */
    $second = Batch::factory()->for($this->course)->create(['code' => 'ENG-101-B']);

    foreach ([['100.000', $this->batch], ['250.000', $this->batch], ['400.000', $second]] as [$amount, $batch]) {
        $charge = Charge::factory()->create(['amount' => $amount]);
        $charge->enrollment->update(['batch_id' => $batch->getKey()]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $rows = $this->enrollments->joinCatalogueTo(
        DB::table('charges')->selectRaw('SUM(charges.amount) as billed'),
        'charges.enrollment_id',
        [EnrollmentQueryService::DIMENSION_COURSE],
    )
        ->groupBy(EnrollmentQueryService::COURSE_ID, EnrollmentQueryService::COURSE_CODE)
        ->get();

    expect($queries)->toBe(1)
        ->and($rows)->toHaveCount(1, 'Two batches of one course must aggregate into one row.');

    expect($rows->first()->{EnrollmentQueryService::COURSE_CODE})->toBe('ENG-101')
        ->and((string) $rows->first()->billed)->toBe('750.000');
});

it('refuses a catalogue join that names no dimension, or an unknown one', function () {
    $base = fn (): object => DB::table('charges')->selectRaw('SUM(charges.amount) as billed');

    expect(fn () => $this->enrollments->joinCatalogueTo($base(), 'charges.enrollment_id', []))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->enrollments->joinCatalogueTo($base(), 'charges.enrollment_id', ['student']))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Task 7 — the portal's join it cannot take without the restriction
|--------------------------------------------------------------------------
|
| scopeToStudent() replaces the phase-2 carried item joinStudentTo(). A method
| that only joins would leave every caller responsible for remembering the
| WHERE clause — the exact part that must never be forgotten on the portal,
| where every page renders one student's own rows and nothing belonging to
| anyone else. scopeToStudent() joins AND constrains in one call, so there is
| no way to take the join without the restriction.
|
| IT PRESERVES THE STUDENT'S OWN ROW EVEN WHEN THE CALLER'S TABLE HAS NONE.
| joinCatalogueTo() is an INNER join, and rightly so — a revenue report has
| nothing to show for an enrolment with no charge. The portal is the opposite:
| an unbilled enrolment must still appear on "my balance" as zero owed, not
| silently disappear because `charges` has no row for it. scopeToStudent() is
| therefore a RIGHT JOIN onto `enrollments` — the enrolment side is always
| kept, and the caller's side is NULL-padded when it has nothing to
| contribute. StudentBalanceQueryTest is where that property is exercised end
| to end through a real caller; the tests below pin the join itself.
*/

it('gives task 7 only the signed-in student\'s own rows', function () {
    $studentA = Student::factory()->create();
    $studentB = Student::factory()->create();

    $enrollmentA = Enrollment::factory()->for($studentA)->for($this->batch)->create();
    $enrollmentB = Enrollment::factory()->for($studentB)->for($this->batch)->create();

    $chargeA = Charge::factory()->create(['enrollment_id' => $enrollmentA->getKey(), 'amount' => '111.000']);
    Charge::factory()->create(['enrollment_id' => $enrollmentB->getKey(), 'amount' => '222.000']);

    $rows = $this->enrollments->scopeToStudent(
        DB::table('charges'),
        'charges.enrollment_id',
        (int) $studentA->getKey(),
    )->pluck('charges.id')->map(fn ($id): int => (int) $id)->all();

    expect($rows)->toBe([(int) $chargeA->getKey()]);
});

it('gives task 7 the row even when the caller\'s table has none for it', function () {
    /*
     * THE PROPERTY joinCatalogueTo() DOES NOT HAVE. A revenue report is right
     * to drop an unbilled enrolment from a SUM; the portal's balance page is
     * not allowed to — "my balance" must show zero owed, not nothing at all.
     */
    $student = Student::factory()->create();
    $otherBatch = Batch::factory()->for($this->course)->create(['code' => 'ENG-101-B']);

    $billed = Enrollment::factory()->for($student)->for($this->batch)->create();
    $unbilled = Enrollment::factory()->for($student)->for($otherBatch)->create();

    Charge::factory()->create(['enrollment_id' => $billed->getKey()]);

    $rows = $this->enrollments->scopeToStudent(
        DB::table('charges'),
        'charges.enrollment_id',
        (int) $student->getKey(),
    )
        ->select(['enrollments.id as enrollment_id', 'charges.id as charge_id'])
        ->orderBy('enrollments.id')
        ->get();

    expect($rows)->toHaveCount(2);

    $byEnrollment = $rows->mapWithKeys(fn (object $row): array => [(int) $row->enrollment_id => $row]);

    expect($byEnrollment[(int) $billed->getKey()]->charge_id)->not->toBeNull()
        ->and($byEnrollment[(int) $unbilled->getKey()]->charge_id)->toBeNull();
});

it('gives task 7 no way to call scopeToStudent and get an unconstrained result', function () {
    // Asserted by inspecting the produced SQL, per design section 11.4 — not by
    // trusting that the method happens to behave, since the whole point of the
    // reshape is that there is no code path that skips the WHERE.
    $sql = $this->enrollments->scopeToStudent(
        DB::table('charges'),
        'charges.enrollment_id',
        42,
    )->toSql();

    expect(strtolower($sql))->toContain('right join')
        ->and($sql)->toContain('`enrollments`.`student_id` = ?');
});

/*
|--------------------------------------------------------------------------
| The reverse direction, and it reads only
|--------------------------------------------------------------------------
*/

it('tells Enrolment about a bill without handing over the Charge', function () {
    $charge = Charge::factory()->create(['amount' => '750.000']);
    $enrollmentId = (int) $charge->enrollment_id;

    expect($this->charges->existsForEnrollment($enrollmentId))->toBeTrue()
        ->and($this->charges->referenceForEnrollment($enrollmentId))->toBe($charge->reference)
        ->and($this->charges->outstandingForEnrollment($enrollmentId)->toDecimal())->toBe('750.000');
});

it('answers null for an enrolment with no bill, rather than throwing', function () {
    // Phase 1 enrolments predate billing entirely.
    $enrollment = Enrollment::factory()
        ->for($this->student)
        ->for($this->batch)
        ->create();

    $enrollmentId = (int) $enrollment->getKey();

    expect($this->charges->existsForEnrollment($enrollmentId))->toBeFalse()
        ->and($this->charges->referenceForEnrollment($enrollmentId))->toBeNull()
        ->and($this->charges->outstandingForEnrollment($enrollmentId))->toBeNull();
});

it('exposes no write path on either service', function () {
    /*
     * Design section 11: a query service that also deletes is a write path
     * wearing a reader's name. Asserted by reflection rather than by reading,
     * because the whole point is that a future method would slip past a reader.
     */
    foreach ([EnrollmentQueryService::class, ChargeQueryService::class] as $service) {
        $methods = collect((new ReflectionClass($service))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $method): string => $method->getName())
            ->reject(fn (string $name): bool => $name === '__construct');

        $writeShaped = $methods->filter(
            fn (string $name): bool => preg_match(
                '/^(create|update|delete|destroy|save|store|write|remove|attach|detach|sync|issue|adjust)/i',
                $name,
            ) === 1,
        );

        expect($writeShaped->all())->toBeEmpty(
            "{$service} exposes a write-shaped method: ".$writeShaped->implode(', '),
        );
    }
});
