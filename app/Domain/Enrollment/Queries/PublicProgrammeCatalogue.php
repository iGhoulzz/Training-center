<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Support\Money;

/**
 * The programmes the public site lists, each with its card, in the order every
 * public page shows them (P4-T12).
 *
 * WHAT A CARD MAY SHOW, AND WHERE THE LINE IS DRAWN. Price, start date, hours and
 * description are public; seats are not. So nothing here reads a batch's capacity
 * or counts its enrolments. The price, the start date and, for a course with a
 * planned batch, the hours come from NextUpcomingBatch, which asks PricingService
 * and `effective_total_hours` for the batch it chose, so this class never works a
 * price out for itself and cannot disagree with the billing screens about what a
 * batch costs. A course with NO upcoming batch has a null `card`, and what the
 * public sees of it is the course's own `total_hours`, with no price and no date.
 *
 * TWO QUERIES, WHATEVER THE NUMBER OF COURSES. NextUpcomingBatch makes one, and
 * the active courses are one more, keyed against it by course id. A page with six
 * programmes runs the same statements as a page with two, and a card never queries.
 *
 * One class, read by both controllers, so the order is decided once and the home
 * page's first four are the programmes page's first four.
 */
final class PublicProgrammeCatalogue
{
    public function __construct(private readonly NextUpcomingBatch $nextBatches) {}

    /**
     * Every active course with its card, in display order.
     *
     * Courses with an upcoming batch come first, by start date and then by name;
     * the courses with none follow, by name. Two courses that tie on both fall
     * back to their id, so the order never depends on how the database happened
     * to return them. A card is null for a course with no planned batch, which the
     * card component renders as its fallback.
     *
     * @return list<array{course: Course, card: array{batch_id: int, batch_code: string, start_date: string, price: Money, hours: int}|null}>
     */
    public function cards(): array
    {
        $cards = $this->nextBatches->forCourses();

        $entries = Course::query()
            ->active()
            ->get(['id', 'code', 'name_en', 'name_ar', 'description_en', 'description_ar', 'total_hours'])
            ->map(fn (Course $course): array => ['course' => $course, 'card' => $cards[(int) $course->getKey()] ?? null])
            ->values()
            ->all();

        usort($entries, function (array $first, array $second): int {
            $firstStart = $first['card']['start_date'] ?? null;
            $secondStart = $second['card']['start_date'] ?? null;

            // A course with a date sorts before one without; null <=> null is equal,
            // which sends two fallbacks on to the name.
            if (($firstStart === null) !== ($secondStart === null)) {
                return $firstStart === null ? 1 : -1;
            }

            return ($firstStart <=> $secondStart)
                ?: strnatcasecmp($first['course']->name(), $second['course']->name())
                ?: $first['course']->getKey() <=> $second['course']->getKey();
        });

        return $entries;
    }
}
