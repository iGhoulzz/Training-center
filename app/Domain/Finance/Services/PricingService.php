<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Support\Money;
use InvalidArgumentException;

/**
 * The one definition of effective and normalized catalogue pricing.
 *
 * A null batch price inherits the course value live. Normalization is exact
 * integer-dirham parsing through Money: excess precision is refused before any
 * comparison, while equivalent strings such as `100` and `100.000` compare as
 * the same amount. Null remains distinct from zero because it carries the
 * inheritance decision.
 */
final class PricingService
{
    private const MAXIMUM_PRICE = '999999999.999';

    public function priceForBatch(Batch $batch): Money
    {
        if ($batch->price !== null) {
            return Money::fromDecimal($batch->price);
        }

        $batch->loadMissing('course');

        return Money::fromDecimal($batch->course->default_price);
    }

    public function normalizePrice(string $price): string
    {
        $money = Money::fromDecimal($price);

        if ($money->isNegative() || $money->isGreaterThan(Money::fromDecimal(self::MAXIMUM_PRICE))) {
            throw new InvalidArgumentException('The price is outside the decimal(12,3) storage range.');
        }

        return $money->toDecimal();
    }

    public function normalizeNullablePrice(?string $price): ?string
    {
        if ($price === null || trim($price) === '') {
            return null;
        }

        return $this->normalizePrice($price);
    }

    public function coursePriceChanged(Course $course, string $submittedPrice): bool
    {
        return ! Money::fromDecimal($course->default_price)
            ->equals(Money::fromDecimal($this->normalizePrice($submittedPrice)));
    }

    public function batchPriceChanged(Batch $batch, ?string $submittedPrice): bool
    {
        $normalized = $this->normalizeNullablePrice($submittedPrice);

        if ($batch->price === null || $normalized === null) {
            return $batch->price !== $normalized;
        }

        return ! Money::fromDecimal($batch->price)
            ->equals(Money::fromDecimal($normalized));
    }
}
