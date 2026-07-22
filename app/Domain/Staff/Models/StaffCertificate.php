<?php

declare(strict_types=1);

namespace App\Domain\Staff\Models;

use Database\Factories\StaffCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded credential belonging to a staff profile.
 *
 * The row is metadata plus a pointer: `disk` and `path` say where the bytes
 * live, and the bytes live on a private, non-web-served disk. Nothing here
 * reads or writes a file — uploading and downloading are the concern of the
 * task that adds the UI, which must authorize every download per request.
 *
 * Configuration only: casts, relationships, scopes, one predicate.
 */
#[Fillable([
    'staff_profile_id',
    'title',
    'issued_on',
    'expires_on',
    'original_filename',
    'disk',
    'path',
])]
class StaffCertificate extends Model
{
    /** @use HasFactory<StaffCertificateFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /**
     * Certificates whose expiry date has already passed.
     *
     * INTENT: a null `expires_on` means the credential does not expire, so it
     * is never counted as expired. The column is nullable precisely because
     * many qualifications are permanent — a degree does not lapse — and
     * treating "no expiry recorded" as expired would flag every one of them.
     * A certificate expiring today is still current; it lapses tomorrow.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        // Plain where(), not whereDate(): expires_on is already a DATE column,
        // so wrapping it in SQL DATE() adds nothing and makes the comparison
        // non-sargable, discarding the index on the column.
        $query->whereNotNull('expires_on')
            ->where('expires_on', '<', today()->toDateString());
    }

    /**
     * The complement of scopeExpired(): never-expiring credentials plus those
     * whose expiry date has not yet passed.
     *
     * The conditions are wrapped in one group so that the orWhere cannot leak
     * out and widen a surrounding filter.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('expires_on')
                ->orWhere('expires_on', '>=', today()->toDateString());
        });
    }

    /**
     * The row-level counterpart of scopeExpired(), with the same rule: null
     * never expires, and the expiry date itself is still valid.
     */
    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(today());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): StaffCertificateFactory
    {
        return StaffCertificateFactory::new();
    }
}
