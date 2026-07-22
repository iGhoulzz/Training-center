<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalogue template: what the centre offers, not what it is currently
 * running.
 *
 * Batches are the running instances. A course is edited in place — correcting
 * its hours or its description changes what every inheriting batch reports,
 * which is the point of keeping the definition in one row. The batches()
 * relation is added by P1-T09, which introduces the Batch model; a relation
 * pointing at a class that does not exist yet is a fatal error waiting for the
 * first eager load, and it would fail static analysis today.
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
