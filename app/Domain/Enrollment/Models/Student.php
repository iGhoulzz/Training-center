<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person the centre teaches.
 *
 * The record stands on its own: it carries the student's name, contact details
 * and status, and none of that depends on a login existing. P1-T11 hung
 * enrolments off this model.
 *
 * Configuration only — casts, two relationships, one scope, one display
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

    use RecordsActivity;

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
     * Every batch this student has been placed on.
     *
     * Survives the student's own soft delete, because enrollments.student_id is
     * restrictOnDelete and the rows outlive the scope on this model.
     *
     * NOT A WRITE PATH — see Batch::enrollments().
     *
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
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
     * The whole record, national_id included. This is personal data and it is in
     * the audit trail on purpose: the log is admin-only (view_any_activity), and
     * "who changed this student's national ID" is precisely the question a
     * register has to be able to answer.
     */
    public function auditedAttributes(): array
    {
        return [
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
        ];
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
