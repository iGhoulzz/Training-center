<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use App\Domain\Enrollment\Models\Student;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * "Whose portal is this?" — asked once, answered here.
 *
 * Every portal page resolves the viewing student through this class, and no page
 * derives a student_id for itself. T7's PortalScopeArchTest proves none does.
 *
 * WHAT THAT ARCHITECTURE TEST DOES AND DOES NOT PROVE
 * ---------------------------------------------------
 * It is a source scan, so it proves a call was made. It does NOT prove the
 * resulting query was constrained to that student. Row isolation is carried by
 * T7's two-student behavioural tests, which sign in as one student and assert
 * another's data appears nowhere. This class is the third leg: one place that
 * decides who is viewing, so there is one thing to get right.
 *
 * IT THROWS RATHER THAN RETURNING NULL
 * ------------------------------------
 * A null is a value every caller must remember to check, on the one surface
 * where forgetting means showing a student someone else's record. There is no
 * correct portal page for a user with no student row, so the absence is a broken
 * invariant rather than a case to render.
 *
 * It should be unreachable: User::canAccessPanel() already refuses a student
 * account with no linked, non-trashed student record. Both exist deliberately —
 * the panel gate is the boundary, this is the assertion that the boundary held.
 *
 * THE GUARD IS NOT NAMED HERE
 * ---------------------------
 * Auth::user() reads whichever guard is current. Filament's Authenticate
 * middleware calls Auth::shouldUse() with the panel's guard, so inside a portal
 * request that is `student` — while the same call in a test using actingAs()
 * resolves the default. Naming `student` here would make this class untestable
 * outside a panel request and would silently answer null anywhere else.
 */
final class AuthenticatedStudent
{
    /**
     * The student record behind the current request.
     *
     * @throws RuntimeException if nobody is signed in, or the signed-in account
     *                          has no live student record.
     */
    public function resolve(): Student
    {
        $user = Auth::user();

        if ($user === null) {
            throw new RuntimeException(
                'The portal asked which student is viewing, and nobody is signed in.'
            );
        }

        // Student applies the SoftDeletes global scope, so a trashed record does
        // not resolve. An account outliving its record must not keep serving a
        // portal from it.
        $student = Student::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->first();

        if ($student === null) {
            throw new RuntimeException(
                "User [{$user->getAuthIdentifier()}] reached the portal with no live student record."
            );
        }

        return $student;
    }
}
