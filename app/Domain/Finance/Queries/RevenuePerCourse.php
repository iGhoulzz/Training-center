<?php

declare(strict_types=1);

namespace App\Domain\Finance\Queries;

use App\Domain\Finance\Reports\RevenueReport;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;
use Illuminate\Support\Collection;

/** Course revenue consumes the existing cash-basis report definition. */
final class RevenuePerCourse
{
    public function __construct(private readonly RevenueReport $revenue) {}

    /** @return Collection<int, array{id: int, code: string, total: Money}> */
    public function forPeriod(ReportPeriod $period): Collection
    {
        return $this->revenue->byCourse($period);
    }
}
