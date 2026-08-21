<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Data\AdjustChargeData;
use App\Domain\Finance\Exceptions\ChargeAmountBelowAllocatedException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Staff\Support\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Correct a charge's amount after a data-entry error — never to apply a late
 * discount.
 *
 * Design section 4 is explicit about the distinction and it is load-bearing:
 * a discount is a decision made (or not made) at enrolment, through
 * `apply_discount` and a `Discount` definition, and it is frozen onto the
 * charge at issue. Reaching for this Action instead — because the enrolment
 * has already happened and reopening it feels like more work — would let a
 * late "discount" bypass `apply_discount` entirely and would stop
 * `list_price` / `discount_percentage` / `amount` from agreeing with the
 * rounding rule for a reason nobody can see on the row. This Action exists to
 * fix a wrong number, not to grant a favour.
 *
 * AFTER AN ADJUSTMENT, `amount` NO LONGER EQUALS THE ROUNDING RULE'S OUTPUT
 * --------------------------------------------------------------------------
 * `list_price × (100 − discount_percentage) ÷ 100` describes what was BILLED.
 * Once this Action has run, `amount` describes what a human corrected it to,
 * and the two are allowed to disagree. Do not "fix" that drift later — the
 * frozen figures and the corrected figure are answering different questions,
 * and forcing them back into agreement would erase the correction.
 *
 * THE ABILITY IS CHECKED BEFORE THE CHARGE IS EVEN LOADED
 * ---------------------------------------------------------
 * Same reasoning as `EnrollStudentAction`'s create check: `adjust_charge` is
 * super-admin-only and does not depend on any one charge's state, so nothing
 * is lost by authorizing first. What IS gained is that an actor without the
 * permission learns nothing about whether the charge id they guessed even
 * exists — authorize() runs against the class, not an instance, and only an
 * entitled actor's request goes on to touch the row at all.
 *
 * THE ALLOCATED-TOTAL REFUSAL IS DERIVED UNDER THE CHARGE'S OWN LOCK
 * ---------------------------------------------------------------------
 * `ChargeBalance::allocatedForUpdate()` is called after `lockForUpdate()` has
 * taken the charge row. The locking allocation read is load-bearing: under
 * InnoDB REPEATABLE READ, an ordinary read can keep using a snapshot established
 * before a competing payment committed, even after this transaction waited for
 * and acquired the charge lock. That stale answer would let the amount drop
 * below money already taken against it. `ChargeBalance` remains the single
 * definition of what has been allocated (design sections 4, 6 and 11); this
 * Action does not sum allocations a second way.
 *
 * THE REASON HAS NOWHERE TO LIVE EXCEPT THE ACTIVITY LOG
 * ----------------------------------------------------------
 * `charges` carries no adjustment columns — design section 4 is explicit that
 * the append-only activity log *is* the audit record, and duplicating it onto
 * the row would be a second thing to keep true. That is also why this Action
 * cannot rely on `RecordsActivity`'s ordinary model-event logging: that path
 * would record the amount's before/after diff with no reason attached to it,
 * because a diff produced from a bare model event has nowhere to hang a
 * caller-supplied string. So `$charge->disableLogging()` suppresses the
 * automatic `updated` entry for this one instance, and this Action builds the
 * entry itself — `withChanges()` for the diff, `withProperties()` for the
 * reason, in the SAME call — so the two land in one entry rather than two,
 * which is what design section 4's "alongside the before/after diff" actually
 * requires. `WriteOffChargeAction` needs none of this: its reason has a real
 * column, `written_off_reason`, so the ordinary automatic log already carries
 * it in the diff.
 *
 * `ActivityEvent::UPDATED` is reused rather than a new event name minted for
 * this: `LocalizationTest` requires every literal passed to `->event()` or
 * `->log()` to come from the declared `ActivityEvent` vocabulary, adding a
 * case there is out of this task's file scope, and 'updated' is honest —
 * this genuinely is an update to the charge row, correctly labelled and
 * already translated. The reason itself is what a reader opens the entry to
 * find, in the properties panel `ActivityResource::describeProperties()`
 * renders.
 */
final class AdjustChargeAction
{
    /**
     * @throws ChargeAmountBelowAllocatedException if the new amount is less
     *                                             than what has already been
     *                                             allocated to this charge
     *                                             from payments that still
     *                                             stand.
     */
    public function execute(User $actor, AdjustChargeData $data): Charge
    {
        return DB::transaction(function () use ($actor, $data): Charge {
            Gate::forUser($actor)->authorize('adjust', Charge::class);

            $charge = Charge::query()->lockForUpdate()->findOrFail($data->chargeId);

            $allocated = ChargeBalance::allocatedForUpdate((int) $charge->getKey());

            if ($data->amount->isLessThan($allocated)) {
                throw new ChargeAmountBelowAllocatedException(
                    (int) $charge->getKey(),
                    $data->amount->toDecimal(),
                    $allocated->toDecimal(),
                );
            }

            $previousAmount = $charge->amount;

            /*
             * Suppressed for THIS instance only — see the class docblock. The
             * ordinary model-event log has no way to attach the reason, so
             * this Action builds the one entry that carries both instead of
             * letting an automatic, reason-less entry exist alongside it.
             */
            $charge->disableLogging();

            $charge->update(['amount' => $data->amount->toDecimal()]);

            $charge->enableLogging();

            activity()
                ->causedBy($actor)
                ->performedOn($charge)
                ->event(ActivityEvent::UPDATED)
                ->withProperties(['reason' => $data->reason])
                ->withChanges([
                    'attributes' => ['amount' => $charge->amount],
                    'old' => ['amount' => $previousAmount],
                ])
                ->log(ActivityEvent::UPDATED);

            return $charge;
        });
    }
}
