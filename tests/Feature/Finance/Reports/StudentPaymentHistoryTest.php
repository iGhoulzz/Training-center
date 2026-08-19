<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Reports\StudentPaymentHistory;
use App\Support\CentreCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| StudentPaymentHistory — every bill and every receipt for one student
|--------------------------------------------------------------------------
|
| Design §8: "Per-student payment history | Every bill and every receipt."
| No period filter: a history is the whole record.
|
| THE CONTRAST WITH OutstandingAgedReport IS THE POINT.
| A written-off charge is EXCLUDED from that report and PRESENT here — same
| source row, opposite treatment, both deliberate (design §4). A reversed
| payment is visible here, marked, and specifically excluded from the
| collected total — never silently dropped (design §5).
|
| EVERY EXPECTED FIGURE BELOW IS A LITERAL, never recomputed from the same
| rows the report read.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->history = app(StudentPaymentHistory::class);

    /** A bill for $student, due on $dueDate, for the caller-stated $amount. */
    $this->chargeFor = function (Student $student, string $amount, string $dueDate): Charge {
        $enrollment = Enrollment::factory()->for($student)->create();

        return Charge::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'list_price' => $amount,
            'amount' => $amount,
            'due_date' => $dueDate,
        ]);
    };

    /** A payment — standing, or reversed if asked — received by $student. */
    $this->paymentFor = function (Student $student, string $receivedAt, bool $reversed = false): Payment {
        $attributes = [
            'student_id' => $student->getKey(),
            'received_at' => $receivedAt,
        ];

        return $reversed
            ? Payment::factory()->reversed()->create($attributes)
            : Payment::factory()->create($attributes);
    };

    /** One cash tender of $amount on $payment. */
    $this->tenderOn = function (Payment $payment, string $amount): PaymentTender {
        return PaymentTender::factory()->create([
            'payment_id' => $payment->getKey(),
            'amount' => $amount,
        ]);
    };
});

/*
|--------------------------------------------------------------------------
| 7. Every bill and every receipt for one student — and none of another's
|--------------------------------------------------------------------------
*/

it('lists every bill and every receipt for one student, and none of another student\'s', function () {
    $studentA = Student::factory()->create();
    $studentB = Student::factory()->create();

    $chargeA = ($this->chargeFor)($studentA, '1000.000', '2026-01-10');
    $chargeB = ($this->chargeFor)($studentB, '2000.000', '2026-01-11');

    $paymentA = ($this->paymentFor)($studentA, '2026-01-15 10:00:00');
    ($this->tenderOn)($paymentA, '500.000');

    $paymentB = ($this->paymentFor)($studentB, '2026-01-16 10:00:00');
    ($this->tenderOn)($paymentB, '700.000');

    $history = $this->history->forStudent((int) $studentA->getKey());

    // An EXACT list, not merely "contains" — which is what proves student B's
    // charge and payment are absent rather than just asserting student A's
    // are present alongside whatever else came back.
    expect($history['charges']->pluck('id')->all())->toBe([$chargeA->getKey()])
        ->and($history['payments']->pluck('id')->all())->toBe([$paymentA->getKey()]);

    expect(in_array($chargeB->getKey(), $history['charges']->pluck('id')->all(), true))
        ->toBeFalse('Another student\'s charge leaked into this history.');

    expect(in_array($paymentB->getKey(), $history['payments']->pluck('id')->all(), true))
        ->toBeFalse('Another student\'s payment leaked into this history.');
});

/*
|--------------------------------------------------------------------------
| 8. A written-off charge is PRESENT here — the opposite of
|    OutstandingAgedReport, on the same row, both deliberate
|--------------------------------------------------------------------------
*/

it('shows a written-off charge, with its write-off visible', function () {
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->for($student)->create();

    $charge = Charge::factory()->writtenOff()->create([
        'enrollment_id' => $enrollment->getKey(),
        'list_price' => '1000.000',
        'amount' => '1000.000',
        'due_date' => '2026-01-10',
    ]);

    $history = $this->history->forStudent((int) $student->getKey());

    $row = $history['charges']->firstWhere('id', $charge->getKey());

    expect($row)->not->toBeNull()
        ->and($row['written_off_at'])->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 9. A reversed payment is present and marked, and does not count toward
|    the collected total
|--------------------------------------------------------------------------
*/

it('shows a reversed payment marked as such, excluded from the collected total', function () {
    $student = Student::factory()->create();

    $standing = ($this->paymentFor)($student, '2026-01-15 10:00:00');
    ($this->tenderOn)($standing, '600.000');

    $reversed = ($this->paymentFor)($student, '2026-01-16 10:00:00', reversed: true);
    ($this->tenderOn)($reversed, '400.000');

    $history = $this->history->forStudent((int) $student->getKey());

    $reversedRow = $history['payments']->firstWhere('id', $reversed->getKey());

    expect($reversedRow)->not->toBeNull()
        ->and($reversedRow['reversed_at'])->not->toBeNull()
        ->and($reversedRow['total']->toDecimal())->toBe('400.000')
        // The reversed payment's own row still shows what was handed over —
        // it is the AGGREGATE that must exclude it, not this row.
        ->and($history['collected_total']->toDecimal())->toBe('600.000');
});

/*
|--------------------------------------------------------------------------
| 10. Split tenders are reflected as one receipt total
|--------------------------------------------------------------------------
*/

it('reflects a split-tender payment as one receipt total', function () {
    $student = Student::factory()->create();

    $payment = ($this->paymentFor)($student, '2026-01-15 10:00:00');
    ($this->tenderOn)($payment, '300.000');
    ($this->tenderOn)($payment, '700.000');

    $history = $this->history->forStudent((int) $student->getKey());

    $row = $history['payments']->firstWhere('id', $payment->getKey());

    expect($row)->not->toBeNull()
        ->and($row['total']->toDecimal())->toBe('1000.000')
        ->and($history['collected_total']->toDecimal())->toBe('1000.000');
});

/*
|--------------------------------------------------------------------------
| due_date comes back on the centre's calendar, not UTC
|--------------------------------------------------------------------------
|
| `charges.due_date` is a `date`: a local calendar day with no time and no
| zone. Hydrating it with a bare CarbonImmutable::parse() yields midnight in
| the application's default zone (UTC), while OutstandingAgedReport builds
| local midnight in Africa/Tripoli — so the same bill came back from the two
| reports as instants two hours apart, and a consumer rendering both with a
| timezone conversion would show 00:00 from one and 02:00 from the other.
|
| The independent pre-PR review found the divergence; nothing had pinned it.
*/

it('hydrates a due date on the centre calendar, so two reports agree about one bill', function () {
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->create(['student_id' => $student->getKey()]);

    Charge::factory()->create([
        'enrollment_id' => $enrollment->getKey(),
        'amount' => '500.000',
        'due_date' => '2026-05-16',
    ]);

    $dueDate = app(StudentPaymentHistory::class)
        ->forStudent((int) $student->getKey())['charges'][0]['due_date'];

    expect($dueDate->timezone->getName())->toBe(
        CentreCalendar::TIMEZONE,
        'A local calendar date came back on some other calendar.',
    )->and($dueDate->format('Y-m-d H:i:s'))->toBe('2026-05-16 00:00:00');
});
