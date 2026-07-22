<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Enrollment\Enums\StudentStatus;
use App\Models\User;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person the centre teaches.
 *
 * The record stands on its own: it carries the student's name, contact details
 * and status, and none of that depends on a login existing. P1-T11 hangs
 * enrolments off this model; that relation is deliberately absent here because
 * the Enrollment model does not exist yet, and a placeholder pointing at a
 * missing class is a fatal error waiting for the first eager load.
 *
 * Configuration only — casts, one relationship, one scope, one display
 * accessor. No business logic and no write guards; see App\Models\User for why
 * that architecture was removed in P1-T04c.
 */
#[Fillable([
    'user_id',
    'student_code',
    'first_name',
    'last_name',
    'email',
    'phone',
    'national_id',
    'date_of_birth',
    'gender',
    'address',
    'status',
    'notes',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The portal account for this student, if one was ever issued.
     *
     * Null for most students, and that is the normal case rather than an
     * incomplete record. Phase 3 populates it for the ones who sign in.
     *
     * No withTrashed() here, unlike StaffProfile::user(). A staff profile has
     * to resolve its account to know whose record it is; a student carries its
     * own name, so once the login is soft deleted the honest answer to "which
     * account does this student sign in with" is none.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Limit a query to students currently studying at the centre.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', StudentStatus::Active);
    }

    /**
     * The display name, for tables, headings and search results.
     *
     * Derived on read and never stored: a stored copy is a second source of
     * truth that drifts the first time someone corrects a spelling. Not a
     * database column, so it cannot be sorted on — order by last_name,
     * first_name, which is what the composite index is for.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'status' => StudentStatus::class,
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): StudentFactory
    {
        return StudentFactory::new();
    }
}
