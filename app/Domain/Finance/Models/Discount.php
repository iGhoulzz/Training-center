<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Staff\Support\RecordsActivity;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable percentage the centre is willing to give — "10%", "Staff family 30%".
 *
 * Configuration only — casts, one relation, one scope. No business logic and no
 * write guards; see App\Models\User for why that architecture was removed in
 * P1-T04c.
 *
 * THE DEFINITION IS IMMUTABLE FROM CREATION, AND NOTHING HERE SAYS SO
 * ------------------------------------------------------------------
 * Design section 3 sets name and percentage once. There is no
 * `UpdateDiscountAction`, no `is_locked` column and no guard in this class,
 * because **the absence of a write path enforces it for free**. The rejected
 * alternative — "immutable once referenced" — would mean a definition changed
 * shape depending on whether anyone had used it yet.
 *
 * `is_active` is the one lifecycle transition, owned by
 * `DeactivateDiscountAction`. It controls whether the definition is offered on a
 * new enrolment and changes nothing about a charge already issued: the charge
 * froze the percentage at issue.
 *
 * ALL THREE WRITES ARE GATED ON `manage_pricing`, NOT ON `create_discount`
 * -----------------------------------------------------------------------
 * Creating, deactivating and deleting a definition all require `manage_pricing`
 * (design section 10), and no `create_discount` ability is seeded. Setting the
 * rates the centre charges is one capability wherever it is expressed.
 */
#[Fillable([
    'name',
    'percentage',
    'is_active',
])]
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * Every bill that was issued against this definition.
     *
     * NOT A WRITE PATH. A charge is raised by `IssueChargeAction` as a system
     * consequence of a permitted enrolment, and corrected only by
     * `AdjustChargeAction`; a bare `$discount->charges()->create()` reaches
     * around both, and ActionBoundaryArchTest forbids it.
     *
     * It is also the relation that decides how a mistake is corrected, because
     * `charges.discount_id` restricts on delete. While this is empty, the wrong
     * definition is deleted and replaced. Once it is not, MySQL refuses the
     * delete (1451, which `DeleteDiscountAction` converts into a typed refusal)
     * and the correct move is to deactivate and create a replacement.
     *
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * Limit a query to the definitions still offered at the desk.
     *
     * The selector on a new enrolment renders through this. Deactivated
     * definitions stay queryable without it, because every issued charge still
     * names one and a receipt reprinted years later has to resolve it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Three columns, three decisions, all of them a human's. */
    public function auditedAttributes(): array
    {
        return [
            'name',
            'percentage',
            'is_active',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            /*
             * decimal:2, matching `decimal(5, 2)`. A percentage is NOT money and
             * is deliberately not decimal:3 — `Money::afterDiscount()` reads it
             * as integer hundredths of a percent, so 33.33% is exact, and a
             * third place here would fall outside what that parser accepts.
             *
             * A string cast rather than a numeric one, for the reason design
             * section 6 gives for every amount in this domain: a float cannot
             * hold these values exactly, and an architecture test forbids one.
             */
            'percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): DiscountFactory
    {
        return DiscountFactory::new();
    }
}
