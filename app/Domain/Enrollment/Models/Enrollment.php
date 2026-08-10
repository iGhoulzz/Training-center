<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Staff\Support\RecordsActivity;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student on one batch.
 *
 * Configuration only — casts, two relations, one scope. No business logic and no
 * write guards; see App\Models\User for why that architecture was removed in
 * P1-T04c. In particular there is NO markCompleted() and no status setter: the
 * single status transition phase 1 has belongs to WithdrawEnrollmentAction,
 * which authorizes the actor against the freshly locked row.
 *
 * THE ASSOCIATION IS IMMUTABLE. Nothing offers an edit path for student_id or
 * batch_id — re-parenting an enrolment would strand the charges phase 2 hangs
 * off it against a batch the student never attended, exactly as re-parenting a
 * batch would (spec line 207). EnrollmentsRelationManagerTest proves a crafted
 * submission cannot move either.
 *
 * @property int $id
 * @property int $student_id
 * @property int $batch_id
 * @property string $reference
 */
#[Fillable([
    'student_id',
    'batch_id',
    'enrolled_at',
    'status',
    'completed_at',
    /*
     * Written twice by EnrollStudentAction inside one transaction — a unique
     * placeholder at insert, then the real `ENR-` value once the row has the id
     * the reference is built from. Fillable because both writes are mass
     * assignments; the write boundary is the Action, not the guarded list, and
     * ActionBoundaryArchTest is what holds it.
     */
    'reference',
])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The person enrolled.
     *
     * withTrashed() IS LOAD-BEARING. Students soft-delete and the foreign key is
     * restrictOnDelete, so a deleted student's enrolment rows survive them.
     * Without withTrashed() the SoftDeletes global scope resolves this relation
     * to null on exactly those rows, and phase 2 would bill against an enrolment
     * whose owner the application says does not exist.
     *
     * Reading and writing are separate questions, as on Batch::instructors().
     * EnrollStudentAction refuses a trashed student, so listing them here does
     * not make them enrollable.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    /**
     * The intake attended.
     *
     * No withTrashed(): batches do not soft-delete, and batch_id is
     * restrictOnDelete, so a batch with enrolments cannot go away underneath this
     * relation at all.
     *
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Limit a query to enrolments that still occupy a seat.
     *
     * Withdrawn students have left theirs, which is why capacity counts read
     * through this scope rather than counting rows.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', EnrollmentStatus::Active);
    }

    /**
     * Phase 2 bills from these rows, so every column is auditable — except one.
     *
     * `reference` IS DELIBERATELY ABSENT, AND THE OMISSION IS THE FEATURE.
     * ------------------------------------------------------------------
     * RecordsActivity logs on model events, not on Actions. EnrollStudentAction
     * inserts the row carrying Reference::placeholder() and replaces it with the
     * real `ENR-` value in the same transaction, so listing `reference` here
     * would file two entries: a `created` recording `reference = <uuid>`, and an
     * `updated` recording a phantom "the reference changed" beside it. Sharing a
     * transaction does not help — both commit with everything else, into a log
     * that has no delete path for any role, including super admin.
     *
     * Excluding it costs nothing. The reference is a deterministic function of
     * the subject id the log already records — `ENR-2026-000042` *is* enrolment
     * 42 — so the trail can still name the document. And because no audited
     * column moves during the replacement, dontLogEmptyChanges() suppresses that
     * `updated` entry entirely rather than filing an empty one.
     *
     * Design section 2 requires this on every table carrying a reference.
     */
    public function auditedAttributes(): array
    {
        return [
            'student_id',
            'batch_id',
            'enrolled_at',
            'status',
            'completed_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => EnrollmentStatus::class,
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): EnrollmentFactory
    {
        return EnrollmentFactory::new();
    }
}
