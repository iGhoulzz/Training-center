<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Finance\Support\Money;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Builds exact, frozen payroll figures without converting money to floats. */
final class PayrollCalculator
{
    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /**
     * @return Collection<int, array{
     *     user_id: int,
     *     staff_compensation_id: int,
     *     segment_start: string,
     *     segment_end: string,
     *     frozen_rate: string,
     *     frozen_days: int,
     *     frozen_days_in_month: int,
     *     computed_amount: string
     * }>
     */
    public function salarySegments(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Collection
    {
        return StaffCompensation::query()
            ->where('type', CompensationType::Salary->value)
            ->whereDate('effective_from', '<=', $periodEnd->toDateString())
            ->where(function ($query) use ($periodStart): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $periodStart->toDateString());
            })
            ->orderBy('user_id')
            ->orderBy('effective_from')
            ->get()
            ->flatMap(function (StaffCompensation $rate) use ($periodStart, $periodEnd): array {
                $rateStart = CarbonImmutable::instance($rate->effective_from);
                $rateEnd = $rate->effective_to === null
                    ? $periodEnd
                    : CarbonImmutable::instance($rate->effective_to);
                $intersectionStart = $periodStart->greaterThan($rateStart) ? $periodStart : $rateStart;
                $intersectionEnd = $periodEnd->lessThan($rateEnd) ? $periodEnd : $rateEnd;

                if ($intersectionStart->greaterThan($intersectionEnd)) {
                    return [];
                }

                $segments = [];
                $month = $intersectionStart->startOfMonth();

                while ($month->lessThanOrEqualTo($intersectionEnd)) {
                    $monthEnd = $month->endOfMonth();
                    $segmentStart = $intersectionStart->greaterThan($month) ? $intersectionStart : $month;
                    $segmentEnd = $intersectionEnd->lessThan($monthEnd) ? $intersectionEnd : $monthEnd;
                    $days = (int) $segmentStart->diffInDays($segmentEnd) + 1;
                    $daysInMonth = $month->daysInMonth;

                    $segments[] = [
                        'user_id' => (int) $rate->user_id,
                        'staff_compensation_id' => (int) $rate->getKey(),
                        'segment_start' => $segmentStart->toDateString(),
                        'segment_end' => $segmentEnd->toDateString(),
                        'frozen_rate' => (string) $rate->amount,
                        'frozen_days' => $days,
                        'frozen_days_in_month' => $daysInMonth,
                        'computed_amount' => Money::fromDecimal((string) $rate->amount)
                            ->multipliedBy($days, $daysInMonth)
                            ->toDecimal(),
                    ];

                    $month = $month->addMonth();
                }

                return $segments;
            })
            ->values();
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return Collection<int, array{
     *     user_id: int,
     *     staff_compensation_id: int,
     *     batch_instructor_id: int,
     *     frozen_rate: string,
     *     frozen_hours: int,
     *     computed_amount: string
     * }>
     */
    public function instructorLines(array $assignmentIds): Collection
    {
        $selectedIds = collect($assignmentIds)
            ->unique()
            ->values();

        if ($selectedIds->isEmpty()) {
            throw ValidationException::withMessages([
                'assignment_ids' => __('payroll.select_assignment'),
            ]);
        }

        $assignments = $this->availableInstructorAssignments()
            ->whereIn('id', $selectedIds)
            ->values();

        if ($assignments->count() !== $selectedIds->count()) {
            throw ValidationException::withMessages([
                'assignment_ids' => __('payroll.assignment_unavailable'),
            ]);
        }

        $today = CentreCalendar::localise(now())->startOfDay();
        $rates = StaffCompensation::query()
            ->where('type', CompensationType::Hourly->value)
            ->whereIn('user_id', $assignments->pluck('user_id'))
            ->whereDate('effective_from', '<=', $today->toDateString())
            ->where(function ($query) use ($today): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today->toDateString());
            })
            ->orderByDesc('effective_from')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        return $assignments->map(function (array $assignment) use ($rates): array {
            $rate = $rates->get($assignment['user_id']);

            if (! $rate instanceof StaffCompensation) {
                throw ValidationException::withMessages([
                    'assignment_ids' => __('payroll.hourly_rate_missing'),
                ]);
            }

            return [
                'user_id' => $assignment['user_id'],
                'staff_compensation_id' => (int) $rate->getKey(),
                'batch_instructor_id' => $assignment['id'],
                'frozen_rate' => (string) $rate->amount,
                'frozen_hours' => $assignment['assigned_hours'],
                'computed_amount' => Money::fromDecimal((string) $rate->amount)
                    ->multipliedBy($assignment['assigned_hours'], 1)
                    ->toDecimal(),
            ];
        });
    }

    /**
     * @return Collection<int, array{id: int, batch_id: int, user_id: int, assigned_hours: int}>
     */
    public function availableInstructorAssignments(): Collection
    {
        $paidIds = PayrollLine::query()
            ->finalized()
            ->whereNotNull('batch_instructor_id')
            ->pluck('batch_instructor_id')
            ->mapWithKeys(fn (int $id): array => [$id => true]);

        return $this->enrollments->allInstructorAssignments()
            ->reject(fn (array $assignment): bool => $paidIds->has($assignment['id']))
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     batch_id: int,
     *     batch_code: string,
     *     user_id: int,
     *     user_name: string,
     *     assigned_hours: int
     * }>
     */
    public function searchAvailableInstructorAssignments(string $search, int $limit = 25): Collection
    {
        $paidAssignmentIds = PayrollLine::query()
            ->finalized()
            ->whereNotNull('batch_instructor_id')
            ->select('batch_instructor_id');

        return $this->enrollments->searchInstructorAssignments(
            $paidAssignmentIds,
            $search,
            $limit,
        );
    }
}
