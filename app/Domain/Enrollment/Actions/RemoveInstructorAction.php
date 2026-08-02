<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Models\Batch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Take an instructor off a batch.
 *
 * The sibling of AssignInstructorAction and gated identically: the spec's
 * "completed and cancelled batches reject instructor changes" covers removals
 * as much as additions — taking a name off a finished batch is the same
 * rewriting of history as putting one on, and from phase 2 it erases what
 * somebody is owed.
 *
 * The gate is expressed by asking BatchPolicy::assignInstructor() about the
 * freshly locked row, exactly as the assign path does, so the RULE lives in one
 * place and cannot drift between the two Actions. Only the translation of a
 * denial into a typed refusal is repeated, and it is repeated rather than shared
 * because a base class or trait holding six lines of gate would be a third place
 * to look for the rule that is in fact in the policy.
 *
 * Detaching only the named pair is the point: a batch co-taught by Sara and Omar
 * loses one row when Omar leaves it, never both. detach() with an explicit key
 * does that; a bare detach() would empty the batch.
 *
 * A DEPARTED INSTRUCTOR IS STILL REMOVABLE FROM AN OPEN BATCH
 * -----------------------------------------------------------
 * Nothing here consults the instructor's own state, and that is deliberate. A
 * soft-deleted account that was mis-assigned in the first place must be
 * removable, or the mistake is permanent; the only gate is the batch's status,
 * which is where the spec puts it. detach() addresses the pivot table directly
 * and so is unaffected by the SoftDeletes scope on users either way.
 */
final class RemoveInstructorAction
{
    /**
     * @throws BatchClosedException if the batch is completed or cancelled.
     */
    public function execute(User $actor, Batch $batch, User $instructor): void
    {
        DB::transaction(function () use ($actor, $batch, $instructor): void {
            // Re-read under a lock rather than trusting the instance handed in:
            // the caller's copy may have been read before the batch closed, and
            // authorizing that copy would let a closed batch accept the change.
            $locked = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());

            $this->authorize($actor, $locked);

            /*
             * The hours are read BEFORE the detach, or there is nothing left to
             * read them from — the same ordering DeleteStaffProfileAction uses
             * to collect file paths ahead of its cascade.
             */
            $existing = $locked->instructors()
                ->where('users.id', $instructor->getKey())
                ->first();

            $locked->instructors()->detach($instructor->getKey());

            /*
             * detach() fires no Eloquent event, so nothing recorded that an
             * allocation phase 2 pays wages from was removed, or by whom
             * (P1-T15, group 3 finding H2).
             *
             * Only when a row actually went. Detaching an instructor who holds
             * no allocation removes nothing, and logging that as a removal would
             * put an event in the trail for a change that never happened —
             * worse than the silence this fixes, because it is actively wrong.
             */
            if ($existing === null) {
                return;
            }

            activity()
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('instructor_removed')
                ->withProperties([
                    'instructor_id' => (int) $instructor->getKey(),
                    'instructor_name' => $instructor->name,
                    // getAttribute() rather than a dynamic property — see
                    // AssignInstructorAction for why.
                    'assigned_hours' => (int) $existing->pivot->getAttribute('assigned_hours'),
                ])
                ->log('instructor_removed');
        });
    }

    /**
     * See AssignInstructorAction::authorize() for why a denial is re-examined
     * rather than surfaced as a bare 403.
     */
    private function authorize(User $actor, Batch $batch): void
    {
        if (Gate::forUser($actor)->allows('assignInstructor', $batch)) {
            return;
        }

        if ($actor->can('assign_instructor') && ! $batch->acceptsEnrollments()) {
            throw new BatchClosedException((int) $batch->getKey());
        }

        Gate::forUser($actor)->authorize('assignInstructor', $batch);
    }
}
