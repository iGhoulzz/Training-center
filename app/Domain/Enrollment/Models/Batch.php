<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Models\User;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A course actually running — the January intake, the March intake.
 *
 * The course says what is taught; the batch says when, to whom, by whom, and
 * for how many hours. That split is why "English B1" can run four times a year
 * without four copies of its definition.
 *
 * P1-T11 adds enrollments(), deliberately absent rather than stubbed: a relation
 * pointing at a class or table that does not exist yet is a fatal error waiting
 * for the first eager load.
 *
 * Configuration only — casts, relationships, one inheriting accessor, status
 * and hour predicates, one scope. No business logic and no write guards; see
 * App\Models\User for why that architecture was removed in P1-T04c. Every write
 * to batch_instructor goes through AssignInstructorAction /
 * RemoveInstructorAction, never through the relation here.
 *
 * `price` is fillable but exists for phase 2 alone. It is absent from
 * BatchResource entirely, and BatchResourceTest asserts that absence so nobody
 * helpfully surfaces it: phase 1 has no financial features of any kind.
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
     * Soft-deleted accounts fall out of this relation, and so out of the hour
     * sum, because the SoftDeletes global scope applies to the related model.
     * That is the phase 1 reading — the relation answers "who teaches this
     * batch", and a departed account does not. What phase 2 owes for teaching
     * already delivered is phase 2's decision to make against the pivot rows,
     * which survive: the foreign key refuses to hard-delete an instructor who
     * holds allocations, so the history is still there to be read.
     *
     * @return BelongsToMany<User, $this>
     */
    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'batch_instructor')
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
