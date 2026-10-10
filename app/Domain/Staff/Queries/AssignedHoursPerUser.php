<?php

declare(strict_types=1);

namespace App\Domain\Staff\Queries;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use Illuminate\Support\Collection;

/** Recorded allocations include closed batches and departed instructors. */
final class AssignedHoursPerUser
{
    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /** @return Collection<int, array{batch_count: int, assigned_hours: int}> */
    public function totals(): Collection
    {
        return $this->enrollments->allInstructorAssignments()->groupBy('user_id')
            ->map(fn (Collection $rows): array => [
                'batch_count' => $rows->pluck('batch_id')->unique()->count(),
                'assigned_hours' => (int) $rows->sum('assigned_hours'),
            ]);
    }
}
