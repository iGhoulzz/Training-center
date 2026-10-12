<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Queries\NextUpcomingBatch;
use App\Domain\Finance\Support\Money;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * The programmes the centre offers, on the sessionless public site (P4-T12).
 *
 * WHAT A CARD MAY SHOW, AND WHERE THE LINE IS DRAWN. Price, start date, hours and
 * description are public; seats are not. So nothing here reads a batch's capacity
 * or counts its enrolments, and every price and hour figure comes from
 * NextUpcomingBatch, which asks PricingService and `effective_total_hours` for the
 * batch it chose. This class never works a price out for itself, so it cannot
 * disagree with the billing screens about what a batch costs.
 *
 * ONE QUERY FOR THE COURSES, ONE FOR THEIR NEXT BATCHES, WHATEVER THEIR NUMBER.
 * NextUpcomingBatch returns one entry per active course id, and the courses
 * come from a single query keyed against it, so a page with six cards runs the
 * same statements as a page with two. A card never queries.
 */
final class ProgrammeController extends Controller
{
    public function __construct(private readonly NextUpcomingBatch $nextBatches) {}

    public function index(): View
    {
        return view('public.programmes.index', ['programmes' => $this->catalogue()]);
    }

    /**
     * One programme's page.
     *
     * A RETIRED COURSE AND AN UNKNOWN CODE ARE THE SAME RESPONSE. Route binding
     * answers an unknown code with Laravel's 404 page, so an inactive course is
     * answered the same way: a different page here would tell a visitor which
     * codes the centre once used.
     */
    public function show(Course $course): View
    {
        abort_unless($course->is_active, 404);

        return view('public.programmes.show', [
            'course' => $course,
            'card' => $this->nextBatches->forCourses()[$course->getKey()] ?? null,
        ]);
    }

    /**
     * Every active course with its card, in the order both pages show them.
     *
     * Public so the home page takes its first few from the same list: the order
     * is decided once, here, and the two pages cannot disagree about which
     * programmes come first.
     *
     * Courses with an upcoming batch come first, by start date and then by name;
     * the courses with none follow, by name. A card is null for a course with no
     * planned batch, which the card component renders as its fallback.
     *
     * @return list<array{course: Course, card: array{batch_id: int, batch_code: string, start_date: string, price: Money, hours: int}|null}>
     */
    public function catalogue(): array
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
