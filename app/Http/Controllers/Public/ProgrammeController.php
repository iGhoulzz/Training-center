<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Queries\NextUpcomingBatch;
use App\Domain\Enrollment\Queries\PublicProgrammeCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * The programmes the centre offers, on the sessionless public site (P4-T12).
 *
 * WHAT A PAGE MAY SHOW. Price, start date, hours and description are public;
 * seats are not, so nothing here reads a batch's capacity or counts its
 * enrolments. The figures come from NextUpcomingBatch through
 * PublicProgrammeCatalogue, which says where each one comes from; this class
 * never works a price out for itself.
 *
 * A programme's URL carries its id, not its code: codes are typed by hand with no
 * character rule, and a `/`, `?` or `#` in one would give a link that 404s.
 */
final class ProgrammeController extends Controller
{
    public function __construct(
        private readonly PublicProgrammeCatalogue $catalogue,
        private readonly NextUpcomingBatch $nextBatches,
    ) {}

    public function index(): View
    {
        return view('public.programmes.index', ['programmes' => $this->catalogue->cards()]);
    }

    /**
     * One programme's page.
     *
     * A RETIRED COURSE AND AN UNKNOWN ID ARE THE SAME RESPONSE. Route binding
     * answers an unknown id with Laravel's 404 page, so an inactive course is
     * answered the same way: a different page here would tell a visitor which
     * programmes the centre once ran.
     */
    public function show(Course $course): View
    {
        abort_unless($course->is_active, 404);

        return view('public.programmes.show', [
            'course' => $course,
            'card' => $this->nextBatches->forCourses()[$course->getKey()] ?? null,
        ]);
    }
}
