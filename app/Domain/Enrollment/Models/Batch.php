<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A course actually running — the January intake, the March intake.
 *
 * The course says what is taught; the batch says when, to whom, by whom, and
 * for how many hours. That split is why "English B1" can run four times a year
 * without four copies of its definition.
 *
 * P1-T11 added enrollments() and the capacity predicates. Every write to that
 * relation goes through EnrollStudentAction / WithdrawEnrollmentAction /
 * DeleteEnrollmentAction, never through the relation here.
 *
 * Configuration only — casts, relationships, one inheriting accessor, status
 * and hour predicates, one scope. No business logic and no write guards; see
 * App\Models\User for why that architecture was removed in P1-T04c. Every write
 * to batch_instructor goes through AssignInstructorAction /
 * RemoveInstructorAction, never through the relation here.
 *
 * `price` is fillable for UpdateBatchPriceAction, the only application writer.
 * BatchResource exposes an always-non-dehydrated field only with
 * `manage_pricing`, so generic Filament persistence never receives the value.
 */
#[Fillable([
    'course_id',
    'code',
    'start_date',
    'end_date',
    'capacity',
    'total_hours',
    'price',
    'status',
])]
class Batch extends Model
{
    /** @use HasFactory<BatchFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The alias BatchResource selects the instructor-hours SUM into.
     *
     * Named here rather than spelled out at both ends so the resource's
     * withSum() and totalAssignedHours()'s lookup cannot drift apart — a typo in
     * either would silently reinstate the per-row query the aggregate exists to
     * remove, with nothing failing.
     */
    public const ASSIGNED_HOURS_SUM = 'assigned_hours_total';

    /**
     * The alias BatchResource selects the active-enrolment COUNT into.
     *
     * Named here for the same reason ASSIGNED_HOURS_SUM is: a typo at either end
     * silently reinstates the per-row query the aggregate exists to remove, and
     * nothing fails when it does.
     */
    public const ACTIVE_ENROLLMENTS_COUNT = 'active_enrollments_count';

    /**
     * Everyone enrolled on this intake, withdrawn students included.
     *
     * NOT A WRITE PATH. Every insert goes through EnrollStudentAction, every
     * status change through WithdrawEnrollmentAction, and every removal through
     * DeleteEnrollmentAction — each authorizing the actor against freshly locked
     * rows. A bare $batch->enrollments()->create() reaches around all three, and
     * ActionBoundaryArchTest forbids it.
     *
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Students currently holding a seat on this batch.
     *
     * Counts ACTIVE enrolments only. A withdrawn student has left their seat, and
     * counting them would report a full batch that in fact has room.
     *
     * Reads the eager aggregate when the row was loaded through a query that
     * selected it (BatchResource::getEloquentQuery() does), and only falls back to
     * its own count when it was not.
     *
     * array_key_exists rather than a null coalesce, because the two ask different
     * questions: whether the alias was SELECTED, not what value it holds. COUNT
     * returns 0 for a batch with no enrolments — it is SUM that returns NULL, and
     * totalAssignedHours() carries that reasoning correctly for its own aggregate.
     * The check is still the right one: a row loaded without the alias must fall
     * back, and `??` cannot tell an absent alias from a present zero.
     */
    public function activeEnrollmentCount(): int
    {
        $attributes = $this->getAttributes();

        return array_key_exists(self::ACTIVE_ENROLLMENTS_COUNT, $attributes)
            ? (int) $attributes[self::ACTIVE_ENROLLMENTS_COUNT]
            : (int) $this->enrollments()->active()->count();
    }

    /**
     * Are more students holding seats than this batch has?
     *
     * A WARNING CONDITION, NEVER A BLOCK. Spec line 217: enrolling beyond
     * capacity produces a warning, because centres routinely squeeze in one more
     * student. Do not "fix" this into a constraint — the centre's own answer,
     * recorded in the spec, is that it is allowed.
     *
     * capacity > 0 guards a batch with no stated ceiling, which cannot be over it.
     */
    public function isOverCapacity(): bool
    {
        return $this->capacity > 0 && $this->activeEnrollmentCount() > $this->capacity;
    }

    /**
     * The catalogue entry this intake runs.
     *
     * Never null: course_id is a non-nullable foreign key, because a batch with
     * no course is not a thing the centre can teach.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The staff teaching this batch, with the hours each is assigned.
     *
     * The table name is stated because Laravel would otherwise guess `batch_user`
     * from the two model names, and the spec calls the table batch_instructor.
     *
     * NOT A WRITE PATH. withPivot() makes assigned_hours readable and
     * withTimestamps() records when an allocation was made or changed, but every
     * attach, update and detach goes through AssignInstructorAction /
     * RemoveInstructorAction, which authorize the actor against the freshly
     * locked batch. A bare $batch->instructors()->attach() reaches around that.
     *
     * withTrashed() IS LOAD-BEARING — DO NOT REMOVE IT
     * ------------------------------------------------
     * Without it the SoftDeletes global scope on the related model drops a
     * departed instructor out of the relation while their pivot row survives,
     * because the foreign key is restrictOnDelete. The allocation would then be
     * invisible to totalAssignedHours(), to the schedule's hour badge and to the
     * instructors panel, and phase 2 would pay wages from rows the UI says do
     * not exist. This relation is ALLOCATION HISTORY, not a staff directory: it
     * answers "whose hours are recorded against this batch", and a departed
     * person's are.
     *
     * Keeping them here does not make them assignable. AssignInstructorAction
     * refuses a trashed account exactly as it refuses a deactivated one, and the
     * instructors panel's Select never offers one. Reading and writing are
     * separate questions, and only the read includes the departed.
     *
     * @return BelongsToMany<User, $this>
     */
    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'batch_instructor')
            ->withTrashed()
            ->withPivot('assigned_hours')
            ->withTimestamps();
    }

    /**
     * Limit a query to batches that still accept enrolments.
     *
     * Mirrors BatchStatus::isOpen() as a WHERE clause. The two are kept in step
     * by BatchTest, which asserts the scope returns exactly the rows whose
     * acceptsEnrollments() is true — a query filter and a PHP predicate are
     * easy to drift apart, and the drift is silent.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [BatchStatus::Planned, BatchStatus::Active]);
    }

    /**
     * Whether this batch takes new enrolments and instructor changes.
     *
     * Spec section 6: completed and cancelled batches reject both. P1-T10 and
     * P1-T11 enforce that in their Actions; this is the single place that
     * answers the question, so the rule is stated once.
     */
    public function acceptsEnrollments(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * The hours assigned across every instructor on this batch.
     *
     * Reads the eager aggregate when the row was loaded through a query that
     * selected it (BatchResource does), and only falls back to its own SUM when
     * it was not. array_key_exists rather than a null coalesce is load-bearing:
     * a batch with no instructors aggregates to NULL, and `?? null` would send
     * exactly those rows back to the database one at a time — an N+1 that only
     * appears on the rows nobody thinks to check.
     *
     * Both paths count departed instructors: the relation is withTrashed() and
     * so is the aggregate's sub-query. Anything else would make the two
     * disagree depending on how the row happened to be loaded.
     */
    public function totalAssignedHours(): int
    {
        $attributes = $this->getAttributes();

        if (array_key_exists(self::ASSIGNED_HOURS_SUM, $attributes)) {
            return (int) $attributes[self::ASSIGNED_HOURS_SUM];
        }

        return (int) $this->instructors()->sum('assigned_hours');
    }

    /**
     * Do the assigned hours differ from what the batch actually runs for?
     *
     * A WARNING CONDITION, NEVER A BLOCK. Spec section 6: two instructors
     * co-teaching a 30-hour batch are both present for all 30, so both are
     * assigned 30 and the sum is 60. That is a correct record of a real
     * arrangement, and the system says so rather than refusing it. Undershooting
     * — 20 assigned on a 30-hour batch — is the other side of the same warning:
     * ten hours nobody is down as teaching.
     *
     * Do not "fix" this into a constraint. The centre's own answer, recorded in
     * the spec, is that the sum may legitimately exceed the total.
     */
    public function hasHourMismatch(): bool
    {
        return $this->totalAssignedHours() !== $this->effective_total_hours;
    }

    /**
     * The hours this batch actually runs for.
     *
     * INHERITANCE, NOT A COPY. A null total_hours falls back to the parent
     * course EVERY TIME IT IS READ, so correcting the course corrects every
     * inheriting batch at once. Copying the value at creation would have looked
     * identical on day one and drifted silently on day two, with nothing to
     * tell you which batches were stale. BatchTest proves the distinction by
     * changing the course after the batch exists.
     *
     * Reads through the relation, so eager-load `course` when listing batches.
     *
     * @return Attribute<int, never>
     */
    protected function effectiveTotalHours(): Attribute
    {
        return Attribute::get(
            fn (): int => $this->total_hours ?? $this->course->total_hours,
        );
    }

    /** Instructor hours live on batch_instructor and are audited by their Actions. */
    public function auditedAttributes(): array
    {
        return [
            'course_id',
            'code',
            'start_date',
            'end_date',
            'capacity',
            'total_hours',
            'price',
            'status',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'integer',
            'total_hours' => 'integer',
            // decimal:3, matching the column. Phase 2 reads this; phase 1 only
            // guarantees it round-trips without losing a dirham.
            'price' => 'decimal:3',
            'status' => BatchStatus::class,
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): BatchFactory
    {
        return BatchFactory::new();
    }
}
