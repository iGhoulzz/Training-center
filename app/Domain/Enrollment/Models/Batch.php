<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Enrollment\Enums\BatchStatus;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A course actually running — the January intake, the March intake.
 *
 * The course says what is taught; the batch says when, to whom, by whom, and
 * for how many hours. That split is why "English B1" can run four times a year
 * without four copies of its definition.
 *
 * P1-T10 adds the instructors() relation and the batch_instructor pivot;
 * P1-T11 adds enrollments(). Both are deliberately absent rather than stubbed:
 * a relation pointing at a class or table that does not exist yet is a fatal
 * error waiting for the first eager load.
 *
 * Configuration only — casts, one relationship, one inheriting accessor, one
 * status predicate, one scope. No business logic and no write guards; see
 * App\Models\User for why that architecture was removed in P1-T04c.
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
