<?php

declare(strict_types=1);

namespace App\Domain\Finance\Queries;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Support\ChargeBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** A reusable student-list filter derived from positive charge balances. */
final class StudentsOwingMoney
{
    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /** @return Collection<int, int> */
    public function studentIds(): Collection
    {
        $balances = DB::table('charges')->selectRaw(ChargeBalance::outstandingSql().' as '.ChargeBalance::OUTSTANDING_ALIAS);
        $this->enrollments->joinEnrollmentStudentIdentityTo($balances, 'charges.enrollment_id');

        return DB::query()->fromSub($balances, 'student_balances')
            ->where(ChargeBalance::OUTSTANDING_ALIAS, '>', 0)
            ->distinct()
            ->orderBy(EnrollmentQueryService::ENROLLMENT_STUDENT_ID)
            ->pluck(EnrollmentQueryService::ENROLLMENT_STUDENT_ID)
            ->map(fn (mixed $id): int => (int) $id);
    }
}
