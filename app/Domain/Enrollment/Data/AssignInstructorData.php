<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Data;

use InvalidArgumentException;

/**
 * The intent of "assign this instructor to this batch for this many hours".
 *
 * Readonly, so an Action cannot be handed one thing and quietly act on another
 * after a later mutation. Identifiers rather than models: the Action re-reads
 * and locks the batch itself, and a caller that passed a model would invite the
 * assumption that the passed instance is the one authorized against. It is not.
 *
 * The range check is here rather than in the Action because it is a property of
 * the value, not of the operation, and both Actions and any future caller get it
 * for free. It is a programming-error guard, not a business rule: the form
 * already constrains the field, so reaching this means a hand-built payload or a
 * console typo, and the column would otherwise reject it as a raw driver error.
 */
final readonly class AssignInstructorData
{
    /** The ceiling of the unsignedSmallInteger column the value lands in. */
    public const MAX_ASSIGNED_HOURS = 65535;

    public function __construct(
        public int $batchId,
        public int $instructorId,
        public int $assignedHours,
    ) {
        if ($assignedHours < 0 || $assignedHours > self::MAX_ASSIGNED_HOURS) {
            throw new InvalidArgumentException(
                'Assigned hours must be between 0 and '.self::MAX_ASSIGNED_HOURS.", got {$assignedHours}.",
            );
        }
    }
}
