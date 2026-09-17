<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * A TYPED student or batch code lost its unique index.
 *
 * The operator chose this value — typically while importing an existing
 * centre's records — so it is never silently replaced with a generated one.
 * Redrawing would store a code nobody typed and nobody expects, on a record
 * whose whole point was to keep its old identifier.
 *
 * The form's own unique rule refuses the ordinary case before the insert; this
 * is the race that rule cannot see, arriving from the database. Every caller
 * maps it back onto the field the operator typed into.
 *
 * `$typedCode`, not `$code`: Exception already owns `$code` (the error number).
 */
final class IdentifierCodeAlreadyUsedException extends RuntimeException
{
    public function __construct(
        public readonly string $typedCode,
    ) {
        parent::__construct(__('enrollment.identifier_code_already_used'));
    }
}
