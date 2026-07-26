<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * A completed or cancelled batch rejects instructor changes.
 *
 * Spec section 6 names exactly two operations the status gates — new enrolments
 * and instructor changes — and this is the refusal for the second. Reassigning
 * who taught a finished course is rewriting history, and from phase 2 it is
 * rewriting what somebody is owed.
 *
 * Typed rather than a bare AuthorizationException because the two mean different
 * things to a caller: an authorization failure says "not you", this says "not
 * this batch, not any more". The UI can offer the second as a readable message
 * without implying the actor lacks a grant they in fact hold.
 */
class BatchClosedException extends RuntimeException
{
    public function __construct(public readonly int $batchId)
    {
        parent::__construct(__('enrollment.batch_closed'));
    }
}
