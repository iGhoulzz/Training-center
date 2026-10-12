<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

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
 * NO MODEL REACHES A VIEW. The catalogue hands over PublicProgramme projections,
 * so a column added to `courses` later cannot be published by default; see
 * PublicProgramme. There is no route-model binding here for the same reason.
 *
 * A programme's URL carries its id, not its code: codes are typed by hand with no
 * character rule, and a `/`, `?` or `#` in one would give a link that 404s.
 */
final class ProgrammeController extends Controller
{
    public function __construct(private readonly PublicProgrammeCatalogue $catalogue) {}

    public function index(): View
    {
        return view('public.programmes.index', ['programmes' => $this->catalogue->cards()]);
    }

    /**
     * One programme's page.
     *
     * A RETIRED COURSE AND AN UNKNOWN ID ARE THE SAME RESPONSE. The catalogue
     * answers both with null, and both get the same 404 page: a different one
     * would tell a visitor which programmes the centre once ran.
     *
     * THE ID ARRIVES AS A STRING, AND IS CAST HERE. The route's `whereNumber()`
     * guarantees digits only, and a `(int)` cast saturates a digit string too long
     * for an integer at the largest one, which no course has: a 404. An `int`
     * parameter would instead throw a TypeError on such a value and answer 500,
     * a different response for an id that does not exist.
     */
    public function show(string $course): View
    {
        $programme = $this->catalogue->find((int) $course);

        abort_if($programme === null, 404);

        return view('public.programmes.show', ['programme' => $programme]);
    }
}
