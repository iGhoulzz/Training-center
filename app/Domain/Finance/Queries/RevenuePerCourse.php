<?php

declare(strict_types=1);

namespace App\Domain\Finance\Queries;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Reports\RevenueReport;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Course revenue consumes the existing cash-basis report definition. */
final class RevenuePerCourse
{
    public const TOTAL_ALIAS = 'course_collected_amount';

    public const MAXIMUM_ALIAS = 'catalogue_maximum_collected';

    public function __construct(private readonly RevenueReport $revenue) {}

    /** @return Collection<int, array{id: int, code: string, total: Money}> */
    public function forPeriod(ReportPeriod $period): Collection
    {
        return $this->revenue->byCourse($period);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withTotals(Builder $query, ReportPeriod $period): Builder
    {
        return $query->leftJoinSub($this->revenue->courseTotalsQuery($period)->reorder(), 'catalogue_revenue',
            'catalogue_revenue.'.EnrollmentQueryService::COURSE_ID, '=', $query->qualifyColumn('id'))
            ->addSelect($query->qualifyColumn('*'))
            ->selectRaw('COALESCE(catalogue_revenue.total_amount, 0) as '.self::TOTAL_ALIAS)
            ->selectRaw('MAX(COALESCE(catalogue_revenue.total_amount, 0)) OVER () as '.self::MAXIMUM_ALIAS);
    }
}
