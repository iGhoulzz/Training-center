<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Services\PricingService;
use App\Domain\Finance\Support\Money;
use App\Support\CentreCalendar;
use Illuminate\Database\Query\JoinClause;

/** One closed intake projection per active course, with null for its fallback card. */
final class NextUpcomingBatch
{
    public function __construct(private readonly PricingService $pricing) {}

    /** @return array<int, array{batch_id: int, batch_code: string, start_date: string, price: Money, hours: int}|null> */
    public function forCourses(): array
    {
        $today = CentreCalendar::localise(now())->toDateString();
        $nextId = Batch::query()->select('id')
            ->whereColumn('course_id', 'courses.id')
            ->where('status', BatchStatus::Planned)
            ->where('start_date', '>=', $today)
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit(1);

        $courses = Course::query()->where('courses.is_active', true)
            ->leftJoin('batches', function (JoinClause $join) use ($nextId): void {
                $join->on('batches.course_id', '=', 'courses.id')
                    ->where('batches.id', '=', $nextId);
            })
            ->orderBy('courses.id')
            ->get([
                'courses.*',
                'batches.id as next_batch_id',
                'batches.code as next_batch_code',
                'batches.start_date as next_start_date',
                'batches.price as next_price',
                'batches.total_hours as next_total_hours',
            ]);

        $cards = [];
        foreach ($courses as $course) {
            $cards[(int) $course->getKey()] = $this->cardFor($course);
        }

        return $cards;
    }

    /** @return array{batch_id: int, batch_code: string, start_date: string, price: Money, hours: int}|null */
    private function cardFor(Course $course): ?array
    {
        if ($course->getAttribute('next_batch_id') === null) {
            return null;
        }

        $batch = (new Batch)->newFromBuilder([
            'id' => $course->getAttribute('next_batch_id'),
            'course_id' => $course->getKey(),
            'code' => $course->getAttribute('next_batch_code'),
            'start_date' => $course->getAttribute('next_start_date'),
            'price' => $course->getAttribute('next_price'),
            'total_hours' => $course->getAttribute('next_total_hours'),
        ]);

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
