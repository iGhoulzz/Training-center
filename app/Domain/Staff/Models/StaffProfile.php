<?php

declare(strict_types=1);

namespace App\Domain\Staff\Models;

use App\Domain\Staff\Enums\EmploymentType;
use App\Models\User;
use Database\Factories\StaffProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A staff member's employment record: an optional 1:1 companion to a user
 * account.
 *
 * Configuration only — casts, relationships, scopes, and one display accessor.
 * No business logic and no write guards. That architecture was removed
 * deliberately in P1-T04c; see App\Models\User for why.
 */
#[Fillable([
    'user_id',
    'phone',
    'job_title',
    'hire_date',
    'employment_type',
    'qualifications',
    'profile_photo_path',
])]
class StaffProfile extends Model
{
    /** @use HasFactory<StaffProfileFactory> */
    use HasFactory;

    /**
     * withTrashed() is required, not a convenience.
     *
     * Users soft delete, profiles do not — a departed instructor keeps their
     * employment record. Without this the relation applies the SoftDeletes
     * global scope and resolves to null the moment the account is deleted,
     * leaving a profile nobody can attribute: the register cannot show whose
     * it is, and initials() derives from the account name, so the avatar
     * placeholder breaks too.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return HasMany<StaffCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(StaffCertificate::class);
    }

    /**
     * Limit a query to the staff who teach.
     *
     * P1-T10 uses this to decide who may be assigned to a batch.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInstructors(Builder $query): void
    {
        $query->where('employment_type', EmploymentType::Instructor);
    }

    /**
     * The avatar placeholder shown when profile_photo_path is null.
     *
     * No default image is stored per user (spec section 6), so the UI needs
     * something to render instead. This derives it from the linked account's
     * name: "Amal Ibrahim" gives "AI", a single-word name gives its one letter,
     * and a blank name gives an empty string rather than an error.
     *
     * Capped at two letters so a four-part name still fits an avatar circle,
     * and multibyte-safe throughout, because phase 4 brings Arabic names.
     *
     * Reads the user relation, so eager-load `user` when rendering a list.
     */
    public function initials(): string
    {
        $name = $this->user?->name;

        return Str::substr(
            Str::initials(is_string($name) ? $name : '', capitalize: true),
            0,
            2,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'employment_type' => EmploymentType::class,
        ];
    }

    /**
     * Laravel guesses Database\Factories\<model path minus App\>Factory, which
     * for a domain model resolves to a namespace that does not exist. Naming
     * the factory here is the supported way to keep models under app/Domain
     * while factories stay in the one place Laravel loads them from.
     */
    protected static function newFactory(): StaffProfileFactory
    {
        return StaffProfileFactory::new();
    }
}
