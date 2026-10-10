<?php

declare(strict_types=1);

namespace App\Domain\Finance\Queries;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Live balances by batch; written-off debt remains part of the balance history. */
final class BatchOutstanding
{
    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /** @return Collection<int, Money> */
    public function totals(): Collection
    {
        return $this->query()->get()->mapWithKeys(fn (object $row): array => [
            (int) $row->{EnrollmentQueryService::BATCH_ID} => Money::fromDecimal((string) $row->total_amount),
        ]);
    }

    public function forBatch(int $batchId): Money
    {
        $row = $this->query()->having(EnrollmentQueryService::BATCH_ID, $batchId)->first();

        return $row === null ? Money::zero() : Money::fromDecimal((string) $row->total_amount);
    }

    private function query(): Builder
    {
        $query = DB::table('charges')->selectRaw('SUM('.ChargeBalance::outstandingSql().') as total_amount');
        $this->enrollments->joinCatalogueTo($query, 'charges.enrollment_id', [EnrollmentQueryService::DIMENSION_BATCH]);

        return $query->groupBy(EnrollmentQueryService::BATCH_ID, EnrollmentQueryService::BATCH_CODE)
            ->orderBy(EnrollmentQueryService::BATCH_ID);
    }
}
