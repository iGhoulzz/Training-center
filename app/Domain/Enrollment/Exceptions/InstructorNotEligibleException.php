<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * The account named cannot be assigned to teach a batch.
 *
 * Three cases produce this, and they are deliberately one exception rather than
 * three:
 *
 *   - the account is deactivated (`is_active` false);
 *   - the account has a staff profile whose employment_type is administrative
 *     or support rather than instructor;
 *   - the account has no staff profile at all.
 *
 * They are one refusal because they answer one question — "does this person
 * teach here, right now" — and because a message that distinguished them would
 * tell a caller which accounts exist and what they do. Phase 2 pays wages from
 * these rows, so the front-desk clerk's account appearing on a batch is not a
 * cosmetic error; it is a person who gets paid for teaching they never did.
 *
 * The exception is NOT the whole boundary. It refuses a name the application
 * offers; the eligible-instructor query is what stops the name being offered.
 */
class InstructorNotEligibleException extends RuntimeException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct(__('enrollment.instructor_not_eligible'));
    }
}
