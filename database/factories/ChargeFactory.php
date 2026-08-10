<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\Reference;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    protected $model = Charge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * A fresh enrolment per charge. `charges.enrollment_id` is UNIQUE —
             * one bill per enrolment — so sharing one would collide; pass an
             * explicit enrolment when a test needs the pair to be the same.
             */
            'enrollment_id' => Enrollment::factory(),

            /*
             * A unique placeholder, replaced with the real `CHG-` value in
             * configure() once the row has the id the reference is built from.
             * EnrollmentFactory carries the full reasoning; the short version is
             * that the column is NOT NULL UNIQUE and the final value is a
             * function of an id that does not exist until after the insert.
             */
            'reference' => Reference::placeholder(),

            /*
             * A whole number of dinar as a decimal STRING. Never a float: the
             * `decimal:3` cast hands back a string and `Money` parses digits
             * rather than casting, precisely so 0.001 survives.
             *
             * Comfortably above the default allocation in PaymentAllocationFactory,
             * so a default charge paid by a default allocation lands part-paid
             * rather than over-paid — the state most balance assertions want.
             */
            'list_price' => (string) fake()->numberBetween(500, 2000).'.000',

            /*
             * Full price. Both discount columns are null together, which is what
             * `charges_discount_columns_paired` requires — a `discount_id` with no
             * percentage cannot explain the amount it produced. Use withDiscount()
             * for the other branch.
             */
            'discount_id' => null,
            'discount_percentage' => null,

            /*
             * DELIBERATELY ABSENT, and derived in configure() below from whatever
             * `list_price` and `discount_percentage` survive every state. Setting
             * it here would freeze it against the definition's list price, so
             * `->withDiscount()` or an overridden price would leave a row whose
             * amount does not follow from its own columns.
             */

            /*
             * The date the charge was raised, which design section 4 makes the
             * enrolment date. A `date`, because aging is counted in whole days on
             * the centre's local calendar.
             */
            'due_date' => now()->toDateString(),

            // All three write-off columns null together, per
            // `charges_write_off_columns_paired`. See writtenOff().
            'written_off_at' => null,
            'written_off_by' => null,
            'written_off_reason' => null,
        ];
    }

    /**
     * Derive the amount, then mint the reference.
     *
     * WHY THE AMOUNT IS COMPUTED HERE AND NOT IN definition()
     * -------------------------------------------------------
     * afterMaking runs after every state has been merged, so it sees the final
     * `list_price` and `discount_percentage` no matter what order the caller
     * chained things in. It applies design section 3's rule through
     * `Money::afterDiscount()` — the single definition of that arithmetic — so a
     * factory-built bill is one the application could actually have issued.
     *
     * **It only fires when `amount` was not supplied.** A test modelling an
     * `AdjustChargeAction` correction needs an amount that deliberately no longer
     * equals `list_price × (100 − percentage) ÷ 100`, and design section 4 says
     * that divergence is expected rather than a defect. Passing `amount`
     * explicitly is how a test says so, and this leaves it alone.
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(function (Charge $charge): void {
                if ($charge->amount !== null) {
                    return;
                }

                $listPrice = Money::fromDecimal((string) $charge->list_price);

                $charge->amount = $charge->discount_percentage === null
                    ? $listPrice->toDecimal()
                    : $listPrice->afterDiscount((string) $charge->discount_percentage)->toDecimal();
            })
            ->afterCreating(function (Charge $charge): void {
                $charge->reference = Reference::format(
                    Reference::CHARGE_PREFIX,
                    /*
                     * `due_date` is the day the debt was incurred, and so the day
                     * this document is dated by. The calendar it is read on is
                     * CentreCalendar's, shared with every other series so two
                     * references cannot disagree about the year of a row nobody
                     * can tell apart (design section 8).
                     */
                    CentreCalendar::yearOf($charge->due_date),
                    (int) $charge->getKey(),
                );

                $charge->saveQuietly();
            });
    }

    /**
     * A discounted bill, with the percentage frozen and the amount recomputed.
     *
     * ORDER MATTERS IF YOU ALSO SET THE PRICE. States are applied left to right
     * over the definition, so `->state(['list_price' => …])->withDiscount()`
     * discounts your price while `->withDiscount()->create(['list_price' => …])`
     * discounts the factory's. The amount follows whichever `list_price` and
     * percentage survive, because it is derived in configure() after everything.
     */
    public function withDiscount(?Discount $discount = null): static
    {
        return $this->state(function () use ($discount): array {
            $discount ??= Discount::factory()->create();

            return [
                'discount_id' => $discount->getKey(),

                /*
                 * COPIED ONTO THE BILL, not read back through the relation. The
                 * definition can be deactivated or replaced and a receipt
                 * reprinted years later still has to show the figure that was
                 * actually applied (design section 3).
                 */
                'discount_percentage' => $discount->percentage,
            ];
        });
    }

    /**
     * A debt the centre has accepted it will never collect.
     *
     * All three columns move together, because
     * `charges_write_off_columns_paired` refuses a write-off with no actor or no
     * reason. Writing off does not pay the bill — `ChargeBalance` deliberately
     * does not subtract it, so a written-off charge still shows its outstanding
     * balance and the aged report filters on `written_off_at` instead.
     */
    public function writtenOff(?User $actor = null): static
    {
        return $this->state(fn (): array => [
            'written_off_at' => now(),
            'written_off_by' => $actor?->getKey() ?? User::factory(),
            'written_off_reason' => fake()->sentence(),
        ]);
    }
}
