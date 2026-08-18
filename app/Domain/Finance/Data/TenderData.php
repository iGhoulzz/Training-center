<?php

declare(strict_types=1);

namespace App\Domain\Finance\Data;

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Support\Money;
use InvalidArgumentException;

/**
 * One way money arrived: a method, an amount, and — for a card — the terminal's
 * reference.
 *
 * A 1,000 bill settled with 300 on card and 700 in cash is one payment with two
 * of these (design §5). The original single `payments.method` column could not
 * represent the split-tender checkout every retail counter performs.
 *
 * THE AMOUNT ARRIVES AS A STRING AND LEAVES AS Money, for the reason
 * AdjustChargeData gives: `Money::fromDecimal()` is built for what a form
 * submits, so the parsing and the precision guard happen once, at the boundary,
 * rather than in every caller.
 *
 * THE CARD REFERENCE IS REQUIRED HERE AND IN THE DATABASE.
 * `payment_tenders` carries a CHECK that rejects a null or whitespace-only
 * reference on a card tender. This constructor refuses the same thing, applying
 * the SAME trim, so the two cannot disagree about what "blank" means — and so a
 * hand-built payload fails at the boundary rather than as a driver error.
 */
final readonly class TenderData
{
    public Money $amount;

    public ?string $externalReference;

    public function __construct(
        public TenderMethod $method,
        string $amount,
        ?string $externalReference = null,
    ) {
        $this->amount = Money::fromDecimal($amount);

        if (! $this->amount->isPositive()) {
            throw new InvalidArgumentException('A tender records money that arrived, so its amount is positive.');
        }

        $trimmed = $externalReference === null ? null : trim($externalReference);

        $this->externalReference = $trimmed === '' ? null : $trimmed;

        if ($this->method === TenderMethod::Card && $this->externalReference === null) {
            throw new InvalidArgumentException('A card tender carries the terminal reference it was approved under.');
        }
    }

    /**
     * This tender's contribution to the request fingerprint.
     *
     * Amount as integer dirham rather than a decimal string, so `700` and
     * `700.000` are the same tender; reference after the same trim the CHECK
     * applies, so a stray space is not a different card transaction.
     *
     * @return array{method: string, dirham: int, reference: string}
     */
    public function fingerprintParts(): array
    {
        return [
            'method' => $this->method->value,
            'dirham' => $this->amount->dirham,
            'reference' => $this->externalReference ?? '',
        ];
    }
}
