<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Services\ChargeQueryService;
use App\Domain\Finance\Services\StudentBalanceQuery;
use App\Domain\Finance\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| StudentBalanceQuery — the portal's "my balance" page, without the N+1
|--------------------------------------------------------------------------
|
| Design section 4.2: ChargeQueryService::outstandingForEnrollment() runs
| roughly two queries per enrolment, which is the N+1 on the page most likely
| to be opened by every student at once. StudentBalanceQuery answers every
| enrolment a student holds — billed or not — in a FIXED number of
| statements, reusing ChargeBalance's own SQL rather than restating the
| subtraction a third time (design section 11.4, and the "paid_amount"
| mistake this whole domain is built to avoid wearing a third name).
|
| THE EQUALITY-WITH-outstandingForEnrollment() ASSERTION IS THE SPINE OF THIS
| FILE. Every balance state below is checked two ways: through the bulk query
| under test, and through the single-row reader ChargeBalanceTest already
| proves correct. If the two ever disagree, one of them restated the
| arithmetic instead of reusing it.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->query = app(StudentBalanceQuery::class);
    $this->charges = app(ChargeQueryService::class);

    $this->course = Course::factory()->create();

    /*
     * A bare, unbilled enrolment for the given student, on a FRESH batch every
     * call. `enrollments` carries a `unique(student_id, batch_id)` constraint,
     * so a test giving one student several enrolments would collide on a
     * shared batch — each call gets its own batch instead, exactly as a real
     * student enrolling in several different courses would.
     */
    $this->enrollFor = fn (Student $student): Enrollment => Enrollment::factory()
        ->for($student)
        ->for(Batch::factory()->for($this->course))
        ->create();

    /** An enrolment with a bill of exactly $amount, nothing paid against it yet. */
    $this->billFor = function (Student $student, string $amount): Enrollment {
        $enrollment = ($this->enrollFor)($student);

        Charge::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'list_price' => $amount,
            'amount' => $amount,
        ]);

        return $enrollment;
    };

    /** A payment that still stands, allocating $amount to the enrolment's own charge. */
    $this->payTowards = function (Enrollment $enrollment, string $amount): void {
        $chargeId = DB::table('charges')->where('enrollment_id', $enrollment->getKey())->value('id');

        $payment = Payment::factory()->create();

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $chargeId,
            'amount' => $amount,
        ]);
    };

    /**
     * The assertion the whole file rests on: the bulk figure for one enrolment
     * must equal what the single-row reader — already proven correct by
     * ChargeBalanceTest — reports for the very same enrolment.
     */
    $this->expectSameAsIndividual = function (Enrollment $enrollment): Money {
        $enrollmentId = (int) $enrollment->getKey();
        $summary = $this->query->forStudent((int) $enrollment->student_id);
        $bulk = $summary->enrollments[$enrollmentId]->outstanding;
        $individual = $this->charges->outstandingForEnrollment($enrollmentId);

        expect($individual)->not->toBeNull('The fixture must carry a bill for this comparison to mean anything.');
        expect($bulk->equals($individual))->toBeTrue(
            "Bulk [{$bulk->toDecimal()}] disagreed with the individual reader "
            ."[{$individual->toDecimal()}] for enrolment [{$enrollmentId}]."
        );

        return $bulk;
    };
});

/*
|--------------------------------------------------------------------------
| Every state agrees with the single-row reader
|--------------------------------------------------------------------------
*/

it('agrees with the individual reader for an unpaid bill', function () {
    $student = Student::factory()->create();
    $enrollment = ($this->billFor)($student, '1000.000');

    expect(($this->expectSameAsIndividual)($enrollment)->toDecimal())->toBe('1000.000');
});

it('agrees with the individual reader for a partly paid bill', function () {
    $student = Student::factory()->create();
    $enrollment = ($this->billFor)($student, '1000.000');
    ($this->payTowards)($enrollment, '400.000');

    expect(($this->expectSameAsIndividual)($enrollment)->toDecimal())->toBe('600.000');
});

it('agrees with the individual reader for a fully paid bill', function () {
    $student = Student::factory()->create();
    $enrollment = ($this->billFor)($student, '1000.000');
    ($this->payTowards)($enrollment, '1000.000');

    expect(($this->expectSameAsIndividual)($enrollment)->toDecimal())->toBe('0.000');
});

it('agrees with the individual reader for a written-off bill', function () {
    /*
     * ChargeBalance deliberately does NOT subtract a write-off — design
     * section 4 keeps the debt in history and leaves the exclusion to the
     * aged report alone. A written-off bill therefore still owes its
     * outstanding figure through this path too; this pins that
     * StudentBalanceQuery inherited the non-exclusion by reusing
     * ChargeBalance's SQL rather than by deciding it separately, which is
     * exactly the "excluded from the total exactly as ChargeBalance excludes
     * it" requirement — ChargeBalance excludes it nowhere.
     */
    $student = Student::factory()->create();
    $enrollment = ($this->enrollFor)($student);

    $charge = Charge::factory()->writtenOff()->create([
        'enrollment_id' => $enrollment->getKey(),
        'list_price' => '1000.000',
        'amount' => '1000.000',
    ]);

    ($this->payTowards)($enrollment, '300.000');

    $outstanding = ($this->expectSameAsIndividual)($enrollment);

    expect($outstanding->toDecimal())->toBe('700.000')
        ->and($charge->fresh()->written_off_at)->not->toBeNull();

    /*
     * AND THE TOTAL, which the Done-when names explicitly: "a written-off charge
     * is excluded from the total exactly as ChargeBalance excludes it".
     *
     * ChargeBalance excludes it from nothing, so "exactly as" resolves to "not
     * at all" and the total still carries the 700. Asserting the per-row figure
     * alone left the stated criterion untouched — found in review.
     */
    $summary = $this->query->forStudent((int) $enrollment->student_id);

    expect($summary->total->toDecimal())->toBe('700.000');
});

/*
|--------------------------------------------------------------------------
| The shapes ChargeQueryService cannot express on its own
|--------------------------------------------------------------------------
*/

it('returns chargeId null and Money::zero() for an enrolment with no bill, not a missing row', function () {
    $student = Student::factory()->create();
    $enrollment = ($this->enrollFor)($student);

    $summary = $this->query->forStudent((int) $student->getKey());

    expect($summary->enrollments)->toHaveKey((int) $enrollment->getKey());

    $row = $summary->enrollments[(int) $enrollment->getKey()];

    expect($row->chargeId)->toBeNull()
        ->and($row->outstanding->equals(Money::zero()))->toBeTrue();
});

it('returns an empty array and Money::zero() for a student with no enrolments, rather than throwing', function () {
    $student = Student::factory()->create();

    $summary = $this->query->forStudent((int) $student->getKey());

    expect($summary->enrollments)->toBe([])
        ->and($summary->total->equals(Money::zero()))->toBeTrue();
});

it('never mixes two students\' enrolments in one summary', function () {
    $studentA = Student::factory()->create();
    $studentB = Student::factory()->create();

    $enrollmentA = ($this->billFor)($studentA, '111.000');
    $enrollmentB = ($this->billFor)($studentB, '222.000');

    $summaryA = $this->query->forStudent((int) $studentA->getKey());

    expect($summaryA->enrollments)->toHaveKey((int) $enrollmentA->getKey())
        ->and($summaryA->enrollments)->not->toHaveKey((int) $enrollmentB->getKey());
});

/*
|--------------------------------------------------------------------------
| The total is summed in PHP over the rows, never a second SQL aggregate
|--------------------------------------------------------------------------
*/

it('sums the total in Money over exactly the rows it returns', function () {
    $student = Student::factory()->create();

    $paid = ($this->billFor)($student, '500.000');
    ($this->payTowards)($paid, '500.000');

    $partial = ($this->billFor)($student, '300.000');
    ($this->payTowards)($partial, '100.000');

    ($this->enrollFor)($student); // Unbilled — contributes zero.

    $summary = $this->query->forStudent((int) $student->getKey());

    // 0.000 (settled) + 200.000 (partial) + 0.000 (unbilled)
    expect($summary->total->toDecimal())->toBe('200.000');
});

/*
|--------------------------------------------------------------------------
| A fixed number of statements — the N+1 this class exists to remove
|--------------------------------------------------------------------------
*/

it('runs the same number of queries for one enrolment as for twenty', function () {
    $solo = Student::factory()->create();
    ($this->billFor)($solo, '100.000');

    $busy = Student::factory()->create();

    foreach (range(1, 20) as $i) {
        if ($i % 2 === 0) {
            $enrollment = ($this->billFor)($busy, '100.000');
            if ($i % 4 === 0) {
                ($this->payTowards)($enrollment, '40.000');
            }
        } else {
            ($this->enrollFor)($busy);
        }
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->query->forStudent((int) $solo->getKey());
    $queriesForOne = $queries;

    $queries = 0;
    $this->query->forStudent((int) $busy->getKey());
    $queriesForTwenty = $queries;

    expect($queriesForOne)->toBe($queriesForTwenty)
        ->and($queriesForOne)->toBe(1, 'forStudent() must run one statement, not one per enrolment.');
});

/*
|--------------------------------------------------------------------------
| Every returned value is a Money instance
|--------------------------------------------------------------------------
*/

it('shapes the summary as one row per enrolment, each carrying a Money', function () {
    /*
     * THE NAME IS NARROWER THAN IT WAS, DELIBERATELY.
     *
     * This was called "returns Money instances everywhere, never a float or a
     * bare string", and it could not fail for that reason: $outstanding and
     * $total are declared `Money` on promoted readonly properties, so PHP
     * refuses the construction before any assertion runs, and a wrongly
     * hydrated Money is still a Money.
     *
     * What actually polices the money contract is the property types plus the
     * 123.456 regression below. What THIS test does police is the shape: one
     * entry per enrolment including the unbilled one, keyed by enrolment id.
     */
    $student = Student::factory()->create();
    $enrollment = ($this->billFor)($student, '250.000');
    ($this->enrollFor)($student);

    $summary = $this->query->forStudent((int) $student->getKey());

    expect($summary->total)->toBeInstanceOf(Money::class)
        ->and($summary->enrollments)->toHaveCount(2);

    foreach ($summary->enrollments as $balance) {
        expect($balance->outstanding)->toBeInstanceOf(Money::class);
    }

    expect($summary->enrollments[(int) $enrollment->getKey()]->outstanding)->toBeInstanceOf(Money::class);
});

/*
|--------------------------------------------------------------------------
| The explicit regression for the fromDirham() mistake
|--------------------------------------------------------------------------
*/

it('reads a charge of 123.456 with nothing allocated as 123.456, never 0.123', function () {
    /*
     * OUTSTANDING_SQL runs over decimal(12,3) columns, so MySQL hands PDO back
     * the STRING "123.456" — not an integer count of dirham.
     * Money::fromDirham((int) "123.456") would truncate to 123 dirham and
     * report 0.123 LYD against a 123.456 LYD debt, silently, with no
     * exception anywhere. This is the test the equality-with-
     * outstandingForEnrollment() assertions above are not left to carry
     * alone: if StudentBalanceQuery and ChargeQueryService ever hydrated
     * through the SAME wrong cast, those assertions would still agree with
     * each other while both were wrong.
     */
    $student = Student::factory()->create();
    $enrollment = ($this->billFor)($student, '123.456');

    $summary = $this->query->forStudent((int) $student->getKey());

    expect($summary->enrollments[(int) $enrollment->getKey()]->outstanding->toDecimal())->toBe('123.456')
        ->and($summary->total->toDecimal())->toBe('123.456');
});

it('reports a withdrawn enrolment that still owes money', function () {
    /*
     * WITHDRAWAL IS NOT A FINANCIAL EVENT (phase 2 design, section 4): it leaves
     * the bill exactly as it stands. So a withdrawn enrolment can still be owed
     * money, and it must appear — hiding it would show a student a total they
     * cannot account for from the rows above it.
     *
     * Pinned because the class applies no status filter at all, and "we simply
     * never wrote one" and "we decided not to" look identical in the code.
     */
    $student = Student::factory()->create();
    $enrollment = ($this->billFor)($student, '400.000');

    $enrollment->forceFill(['status' => EnrollmentStatus::Withdrawn])->save();

    $summary = $this->query->forStudent((int) $student->getKey());

    expect($summary->enrollments)->toHaveKey((int) $enrollment->getKey())
        ->and($summary->enrollments[(int) $enrollment->getKey()]->outstanding->toDecimal())->toBe('400.000')
        ->and($summary->total->toDecimal())->toBe('400.000');
});
