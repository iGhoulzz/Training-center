<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Services\PricingService;
use App\Domain\Finance\Support\Money;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

/**
 * One closed intake projection per active course, with null for its fallback card,
 * and beside it the batch the course is running now, if any.
 *
 * AN INTAKE is the earliest batch a person can still join: status Planned OR Active,
 * starting today (centre calendar) or later, ties to the lowest id. An Active batch
 * with a future start date is an intake exactly like a Planned one (P4-T42).
 *
 * A BATCH IN SESSION is an Active batch that started before today and has no end
 * date or one of today or later; of several, the one that started most recently
 * (ties to the lowest id). It carries no price: a price with no joinable intake
 * behind it is a promise the centre has not made. Both are read by ONE statement.
 *
 * @phpstan-type Intake array{batch_id: int, batch_code: string, start_date: string, price: Money, hours: int}
 * @phpstan-type InSession array{start_date: string, end_date: string|null}
 */
final class NextUpcomingBatch
{
    public function __construct(private readonly PricingService $pricing) {}

    /** @return array<int, Intake|null> */
    public function forCourses(): array
    {
        return array_map(fn (array $card): ?array => $card['intake'], $this->forCoursesWithSessions());
    }

    /**
     * The intake and the batch in session for every active course, in one statement.
     *
     * @return array<int, array{intake: Intake|null, in_session: InSession|null}>
     */
    public function forCoursesWithSessions(): array
    {
        $today = CentreCalendar::localise(now())->toDateString();
        $nextId = Batch::query()->select('id')
            ->whereColumn('course_id', 'courses.id')
            ->whereIn('status', [BatchStatus::Planned, BatchStatus::Active])
            ->where('start_date', '>=', $today)
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit(1);
        $runningId = Batch::query()->from('batches as running')->select('running.id')
            ->whereColumn('running.course_id', 'courses.id')
            ->where('running.status', BatchStatus::Active)
            ->where('running.start_date', '<', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('running.end_date')->orWhere('running.end_date', '>=', $today);
            })
            ->orderByDesc('running.start_date')
            ->orderBy('running.id')
            ->limit(1);

        $courses = Course::query()->where('courses.is_active', true)
            ->leftJoin('batches', function (JoinClause $join) use ($nextId): void {
                $join->on('batches.course_id', '=', 'courses.id')
                    ->where('batches.id', '=', $nextId);
            })
            ->leftJoin('batches as running', function (JoinClause $join) use ($runningId): void {
                $join->on('running.course_id', '=', 'courses.id')
                    ->where('running.id', '=', $runningId);
            })
            ->orderBy('courses.id')
            ->get([
                'courses.*',
                'batches.id as next_batch_id',
                'batches.code as next_batch_code',
                'batches.start_date as next_start_date',
                'batches.price as next_price',
                'batches.total_hours as next_total_hours',
                'running.start_date as running_start_date',
                'running.end_date as running_end_date',
            ]);

        $cards = [];
        foreach ($courses as $course) {
            $cards[(int) $course->getKey()] = [
                'intake' => $this->cardFor($course),
                'in_session' => $this->inSessionFor($course),
            ];
        }

        return $cards;
    }

    /** @return InSession|null */
    private function inSessionFor(Course $course): ?array
    {
        $start = $course->getAttribute('running_start_date');
        $end = $course->getAttribute('running_end_date');

        if ($start === null) {
            return null;
        }

        return [
            'start_date' => CarbonImmutable::parse((string) $start)->toDateString(),
            'end_date' => $end === null ? null : CarbonImmutable::parse((string) $end)->toDateString(),
        ];
    }

    /** @return Intake|null */
    private function cardFor(Course $course): ?array
    {
        if ($course->getAttribute('next_batch_id') === null) {
            return null;
        }

        $batch = Batch::query()->hydrate([[
            'id' => $course->getAttribute('next_batch_id'),
            'course_id' => $course->getKey(),
            'code' => $course->getAttribute('next_batch_code'),
            'start_date' => $course->getAttribute('next_start_date'),
            'price' => $course->getAttribute('next_price'),
            'total_hours' => $course->getAttribute('next_total_hours'),
        ]])->firstOrFail();

        // Both inheriting readers use this relation; the card must issue no lazy query.
        $batch->setRelation('course', $course);

        return [
            'batch_id' => (int) $batch->getKey(),
            'batch_code' => (string) $batch->code,
            'start_date' => $batch->start_date->toDateString(),
            'price' => $this->pricing->priceForBatch($batch),
            'hours' => $batch->effective_total_hours,
        ];
    }
}
