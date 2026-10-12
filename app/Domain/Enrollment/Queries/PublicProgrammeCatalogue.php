<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Enrollment\Data\PublicProgramme;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Support\Money;

/**
 * The programmes the public site lists, as closed projections, in the order every
 * public page shows them (P4-T12).
 *
 * WHAT A PAGE MAY SHOW, AND WHERE THE LINE IS DRAWN. Price, start date, hours and
 * description are public; seats are not. So nothing here reads a batch's capacity
 * or counts its enrolments. The price, the start date and, for a course with a
 * planned batch, the hours come from NextUpcomingBatch, which asks PricingService
 * and `effective_total_hours` for the batch it chose, so this class never works a
 * price out for itself and cannot disagree with the billing screens about what a
 * batch costs. A course with NO upcoming batch has no intake: its programme shows
 * the course's own `total_hours`, with no price and no date.
 *
 * NO MODEL LEAVES THIS CLASS. Every course is turned into a PublicProgramme by
 * the one private method below, which names each property, so a column added to
 * `courses` later cannot reach a public page by default. See PublicProgramme.
 *
 * TWO QUERIES, WHATEVER THE NUMBER OF COURSES. NextUpcomingBatch makes one, and
 * the active courses are one more, keyed against it by course id. A page with six
 * programmes runs the same statements as a page with two, and a programme never
 * queries.
 *
 * One class, read by both controllers, so the order is decided once and the home
 * page's first four are the programmes page's first four.
 */
final class PublicProgrammeCatalogue
{
    /** The only columns of a course the projection reads. */
    private const COURSE_COLUMNS = ['id', 'name_en', 'name_ar', 'description_en', 'description_ar', 'total_hours'];

    public function __construct(private readonly NextUpcomingBatch $nextBatches) {}

    /**
     * Every active course, in display order.
     *
     * Courses with an upcoming batch come first, by start date and then by name;
     * the courses with none follow, by name. Two courses that tie on both fall
     * back to their id, so the order never depends on how the database happened
     * to return them.
     *
     * @return list<PublicProgramme>
     */
    public function cards(): array
    {
        $intakes = $this->nextBatches->forCourses();

        $programmes = Course::query()
            ->active()
            ->get(self::COURSE_COLUMNS)
            ->map(fn (Course $course): PublicProgramme => $this->project($course, $intakes[(int) $course->getKey()] ?? null))
            ->values()
            ->all();

        usort($programmes, function (PublicProgramme $first, PublicProgramme $second): int {
            // A course with a date sorts before one without; null <=> null is equal,
            // which sends two with no intake on to the name.
            if (($first->startDate === null) !== ($second->startDate === null)) {
                return $first->startDate === null ? 1 : -1;
            }

            return ($first->startDate <=> $second->startDate)
                ?: strnatcasecmp($first->name, $second->name)
                ?: $first->id <=> $second->id;
        });

        return $programmes;
    }

    /**
     * One programme, or null for an id that is inactive OR unknown.
     *
     * The two are the same answer on purpose: the page answers both with the same
     * 404, so nothing here can tell a caller which ids the centre once ran. An
     * inactive row is never loaded into a projection. The same two queries as
     * cards(), and again a programme never queries.
     */
    public function find(int $id): ?PublicProgramme
    {
        $intakes = $this->nextBatches->forCourses();

        $course = Course::query()
            ->active()
            ->whereKey($id)
            ->first(self::COURSE_COLUMNS);

        return $course instanceof Course
            ? $this->project($course, $intakes[(int) $course->getKey()] ?? null)
            : null;
    }

    /**
     * The one place a course becomes a public programme: each property named, and
     * nothing handed over wholesale.
     *
     * @param  array{batch_id: int, batch_code: string, start_date: string, price: Money, hours: int}|null  $intake  NextUpcomingBatch's entry for the course.
     */
    private function project(Course $course, ?array $intake): PublicProgramme
    {
        return new PublicProgramme(
            id: (int) $course->getKey(),
            name: $course->name(),
            description: $course->description(),
            hours: $intake['hours'] ?? (int) $course->total_hours,
            price: $intake === null ? null : $intake['price']->toDecimal(),
            startDate: $intake['start_date'] ?? null,
        );
    }
}
