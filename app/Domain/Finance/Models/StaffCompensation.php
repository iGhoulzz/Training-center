<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\StaffCompensationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a person is paid, and for which stretch of time.
 *
 * Configuration only — casts, two relations, one scope, one derived predicate.
 * No business logic and no write guards; see App\Models\User for why that
 * architecture was removed in P1-T04c.
 *
 * EFFECTIVE-DATED: A RAISE INSERTS A ROW, IT NEVER UPDATES ONE
 * -----------------------------------------------------------
 * A project non-negotiable, not a preference. `ChangeCompensationAction` closes
 * the previous row and inserts the new one in a single transaction, and **there
 * is no update path at all**: `update_staff_compensation` is deliberately not
 * seeded and `StaffCompensationPolicy::update()` refuses even if it is granted
 * (design sections 7 and 10). `create_staff_compensation` is therefore the write
 * ability for this table and has no update counterpart.
 *
 * The reason is payroll history. A March payroll run recomputed in June must
 * still produce March's figures, and a rate that was overwritten cannot do that.
 *
 * WHAT PREVENTS OVERLAPPING PERIODS IS NOT IN THIS CLASS
 * ------------------------------------------------------
 * No `CHECK`, no unique index and no model guard can express "no two rows for
 * this person and type overlap in range". `CompensationPeriodInvariantService`
 * does it with a lock **on the `users` row** — because when a person has no
 * compensation rows yet there is nothing here to lock, and two concurrent
 * first-row writes would both see an empty table and both pass (design section
 * 7). A predicate on this model would run outside that lock and would be
 * reassuring rather than true.
 *
 * ONE PERSON MAY HOLD BOTH TYPES AT ONCE
 * --------------------------------------
 * A salaried administrator who also teaches carries an open `salary` row and an
 * open `hourly` row simultaneously. That is why the no-overlap rule is per person
 * **per type**, and why openEnded() below is not "the person's current rate" but
 * "the rows not yet closed" — asking it without also filtering `type` returns
 * two rows for that administrator, correctly.
 *
 * @property int $id
 */
#[Fillable([
    'user_id',
    'type',
    'amount',
    'effective_from',
    'effective_to',
])]
class StaffCompensation extends Model
{
    /** @use HasFactory<StaffCompensationFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * Design section 9 names this table `staff_compensation`, singular.
     *
     * Stated because Laravel's convention would guess `staff_compensations` and
     * every query would fail on a table that does not exist. The migration and
     * both foreign keys pointing here spell the same name explicitly for the same
     * reason.
     */
    protected $table = 'staff_compensation';

    /**
     * The person this rate belongs to.
     *
     * withTrashed(), because users soft-delete while this foreign key restricts:
     * without it the SoftDeletes global scope resolves the relation to null on
     * exactly the rows a departed employee's payroll history hangs off, and a
     * frozen rate would explain a wage figure for nobody. Payment::recordedBy()
     * carries the same reasoning.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Every payroll line that froze its figures from this rate.
     *
     * NOT A WRITE PATH. Lines are written by `CreatePayrollRunAction` and sealed
     * by `FinalizePayrollRunAction`, which verifies a line's shape against its
     * run's type inside the finalizing transaction — something no database
     * constraint can do, because a MySQL `CHECK` may not reference another table.
     * A bare `$compensation->payrollLines()->create()` reaches around that
     * verification and around the duplicate-payment indexes it feeds.
     *
     * It is also the relation that makes this row undeletable:
     * `payroll_lines.staff_compensation_id` restricts, so a rate that has ever
     * been paid from stays as the explanation for what was paid.
     *
     * @return HasMany<PayrollLine, $this>
     */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * Limit a query to rates that have not been closed off.
     *
     * The row `ChangeCompensationAction` closes as it inserts the next one, and
     * the row a payroll run intersects its period against at the current end.
     * **Not "the current rate" on its own** — see the class docblock: filter
     * `type` alongside it, or a person holding both types returns two rows.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpenEnded(Builder $query): void
    {
        $query->whereNull('effective_to');
    }

    /**
     * Is this rate still running?
     *
     * Derived from the fact column, never stored — design section 4's "no status
     * column" rule holds across the whole Finance domain, and an open range is
     * exactly a null `effective_to`.
     */
    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }

    /** Who, priced how, at what, from when, until when. All five are decisions. */
    public function auditedAttributes(): array
    {
        return [
            'user_id',
            'type',
            'amount',
            'effective_from',
            'effective_to',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CompensationType::class,

            // decimal:3. The monthly amount or the per-hour rate, depending on
            // `type`. Never float, never two places.
            'amount' => 'decimal:3',

            /*
             * Dates, not datetimes, matching the columns. A rate applies from a
             * day, and design section 7's salary segmentation intersects this
             * range with calendar months — whole-day arithmetic on the centre's
             * local calendar, with no instant to convert.
             */
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): StaffCompensationFactory
    {
        return StaffCompensationFactory::new();
    }
}
