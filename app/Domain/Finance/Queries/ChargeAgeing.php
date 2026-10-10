<?php

declare(strict_types=1);

namespace App\Domain\Finance\Queries;

use App\Domain\Finance\Reports\OutstandingAgedReport;
use Illuminate\Support\Collection;

/** Chips follow the aged report: paid and written-off charges have no chip. */
final class ChargeAgeing
{
    public function __construct(private readonly OutstandingAgedReport $outstanding) {}

    /** @return Collection<int, string> */
    public function asOf(string $localDate): Collection
    {
        return $this->outstanding->asOf($localDate)
            ->mapWithKeys(fn (array $row): array => [$row['charge_id'] => $row['bucket']])
            ->sortKeys();
    }
}
