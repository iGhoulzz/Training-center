<?php

declare(strict_types=1);

namespace App\Domain\Finance\Data;

use App\Domain\Finance\Support\Money;
use InvalidArgumentException;

/**
 * One handover of money: which bill, how much of it, and by what tenders.
 *
 * THERE IS NO STUDENT FIELD, AND THAT IS THE SECURITY PROPERTY.
 * -------------------------------------------------------------
 * Design §5: the Action locks the charge and resolves the owning student
 * through `EnrollmentQueryService`. A crafted request cannot attach a payment to
 * one student while settling another's bill, because there is nothing to attach
 * — accepting an identifier and then validating it is a weaker construction than
 * never accepting one.
 *
 * THE ALLOCATION IS ONE AMOUNT AGAINST ONE BILL.
 * The schema keeps the many-charge capability so a future "pay both my courses"
 * flow needs no migration, but the phase 2 UI always targets exactly one bill and
 * the operator never sees the word allocation. Modelling more here would be a
 * shape nothing produces.
 */
final readonly class RecordPaymentData
{
    public Money $allocation;

    /** @var non-empty-list<TenderData> */
    public array $tenders;

    /**
     * `array<array-key, TenderData>` rather than `list<TenderData>`, because
     * that is what the signature can actually accept: `array $tenders` takes a
     * string-keyed array from any caller, and a form collecting a repeater of
     * tender lines is exactly the shape that hands one over. Promising a list
     * here would make the `array_values()` below dead code by declaration while
     * it is load-bearing in fact — the property is typed `non-empty-list` and
     * that normalisation is what makes it true.
     *
     * @param  array<array-key, TenderData>  $tenders
     */
    public function __construct(
        public int $chargeId,
        string $allocation,
        array $tenders,
        public string $idempotencyKey,
        public ?string $notes = null,
    ) {
        $this->allocation = Money::fromDecimal($allocation);

        if (! $this->allocation->isPositive()) {
            throw new InvalidArgumentException('A payment allocates a positive amount to a bill.');
        }

        if ($tenders === []) {
            throw new InvalidArgumentException('A payment records at least one tender.');
        }

        $this->tenders = array_values($tenders);

        if (trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'A payment carries the idempotency key its form minted; without one a double click is two payments.'
            );
        }
    }

    /** The sum of every tender, which the invariant service compares to the allocation. */
    public function tenderTotal(): Money
    {
        return array_reduce(
            $this->tenders,
            static fn (Money $carry, TenderData $tender): Money => $carry->add($tender->amount),
            Money::zero(),
        );
    }

    /**
     * The canonical fingerprint of this request, for idempotency conflict
     * detection (design §5).
     *
     * WHAT IS IN IT: the charge, the allocation, and every tender's method,
     * amount and terminal reference. Everything that decides what money moved
     * and against what.
     *
     * WHAT IS NOT, AND WHY EACH ABSENCE IS DELIBERATE:
     *
     * - `received_at` is not caller-supplied — the Action generates it — so a
     *   replay returns the original payment with its ORIGINAL timestamp, which
     *   is what makes a reprinted receipt identical to the one already handed
     *   over.
     * - `notes` decorates the payment without changing what was collected, so
     *   two submissions differing only in a typed note are the same payment
     *   recorded twice. Fingerprinting them apart would defeat the mechanism in
     *   exactly the case it exists for.
     *
     * THE TERMINAL REFERENCE IS IN IT, DELIBERATELY. Two submissions identical
     * but for the reference are two card transactions the terminal approved
     * twice; treating the second as a replay would discard real money while
     * telling the operator it had been recorded.
     *
     * Tenders are SORTED before hashing, so the same split submitted in a
     * different order is the same request. Amounts are integer dirham, so
     * `700` and `700.000` do not read as different money.
     */
    public function fingerprint(): string
    {
        $tenders = array_map(
            static fn (TenderData $tender): array => $tender->fingerprintParts(),
            $this->tenders,
        );

        usort($tenders, static fn (array $a, array $b): int => [$a['method'], $a['dirham'], $a['reference']]
            <=> [$b['method'], $b['dirham'], $b['reference']]);

        return hash('sha256', json_encode([
            'charge_id' => $this->chargeId,
            'allocation_dirham' => $this->allocation->dirham,
            'tenders' => $tenders,
        ], JSON_THROW_ON_ERROR));
    }
}
