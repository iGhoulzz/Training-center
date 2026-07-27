<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Staff\Support\RecordsActivity;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalogue template: what the centre offers, not what it is currently
 * running.
 *
 * Batches are the running instances. A course is edited in place — correcting
 * its hours or its description changes what every inheriting batch reports,
 * which is the point of keeping the definition in one row.
 *
 * Configuration only — casts, one relationship, one scope, one display helper.
 * No business logic and no write guards; see App\Models\User for why that
 * architecture was removed in P1-T04c.
 *
 * `default_price` is fillable but exists for phase 2 alone. It is absent from
 * CourseResource entirely, and CourseTest asserts that absence so nobody
 * helpfully surfaces it: phase 1 has no financial features of any kind.
 */
#[Fillable([
    'code',
    'name_en',
    'name_ar',
    'description_en',
    'description_ar',
    'total_hours',
    'default_price',
    'is_active',
])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * Every intake of this course, past and present.
     *
     * NOT a cascade path. batches.course_id is restrictOnDelete, so a course
     * with batches cannot be deleted at all and the teaching history hanging
     * off those batches survives. Deleting through this relation is refused by
     * the database, not merely discouraged here.
     *
     * @return HasMany<Batch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * Limit a query to courses the centre still offers.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The catalogue name in the reader's language.
     *
     * A method rather than an accessor: the value depends on the request
     * locale, not on the row, so exposing it as `$course->name` would invite it
     * being cached, serialized, or sorted on as though it were a column.
     *
     * Arabic wins only when the locale is Arabic AND a translation exists. A
     * course added before anyone translated it still has a usable name, which
     * matters because name_ar stays null for most rows until phase 4.
     */
    public function name(): string
    {
        return app()->getLocale() === 'ar' && filled($this->name_ar)
            ? (string) $this->name_ar
            : (string) $this->name_en;
    }

    /**
     * BOTH LOCALE COLUMNS, and no `name`.
     *
     * There is no `name` column. Course::name() is a METHOD that resolves against
     * the request locale, and listing it here made the audit read $course->name,
     * which Eloquent resolved as a relationship accessor and broke every course
     * test in the suite. The audited facts are the stored columns: name_en and
     * name_ar change independently and a rename in either is worth recording.
     *
     * default_price is phase 2's, but a change to it is audited from commit one.
     */
    public function auditedAttributes(): array
    {
        return [
            'code',
            'name_en',
            'name_ar',
            'description_en',
            'description_ar',
            'total_hours',
            'default_price',
            'is_active',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_hours' => 'integer',
            // decimal:3, matching the column. Phase 2 reads this; phase 1 only
            // guarantees it round-trips without losing a dirham.
            'default_price' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): CourseFactory
    {
        return CourseFactory::new();
    }
}
