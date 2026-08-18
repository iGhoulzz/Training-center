<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Exceptions\PaymentAlreadyReversedException;
use App\Domain\Finance\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Void a payment recorded in error: a set-once lifecycle transition on an
 * otherwise immutable row (design section 5).
 *
 * THIS IS NOT A REFUND, AND THE SYSTEM CANNOT EXPRESS ONE
 * -------------------------------------------------------
 * Design section 1: "**No refunds.** Money is strictly one-directional.
 * Courses are face-to-face; a student who did not pay simply owes, and a
 * student who paid is not paid back." There is no outbound tender anywhere in
 * this schema — `payment_tenders.amount` carries `CHECK (amount > 0)` — so no
 * row can record money leaving the centre.
 *
 * A reversal therefore says **this payment should not have been recorded**: it
 * was entered against the wrong bill, duplicated, or never actually received.
 * It takes the money back out of collected revenue and reopens the student's
 * balance, which is only truthful if the money was never the centre's to
 * begin with. Used to document cash genuinely handed back it would be a lie by
 * omission — revenue removed, a bill reopened, and nothing recording an
 * outbound movement that the reports would have to explain.
 *
 * An earlier version of this file described it as deciding "the money should
 * be given back", and the cross-review was right to reject that: the wording
 * invited a use the design forbids and the schema cannot represent.
 *
 * NOTHING IS ERASED, AND NOTHING IS DELETED
 * ------------------------------------------
 * The payment's financial facts — student, reference, tenders, allocations —
 * are never rewritten and never deleted; there is no delete path for a
 * payment at all. A reversed payment drops out of every balance through
 * exactly one mechanism, `ChargeBalance`'s `payments.reversed_at IS NULL`
 * filter — see that class's own docblock. This Action never touches
 * `payment_tenders` or `payment_allocations`.
 *
 * SCALARS, NOT A DTO
 * -------------------
 * No `ReversePaymentData` exists, and that is a decision rather than an
 * oversight. `ChangeCompensationAction` (P2-T07, merged) takes scalars for
 * the identical shape — an actor, a target row, and a reason — and this
 * task's declared file scope for `app/Domain/Finance/Data/` names only
 * `RecordPaymentData` and `TenderData`. There is nothing here a DTO would
 * validate beyond what this Action's own guard already checks.
 *
 * A BLANK REASON IS REFUSED FIRST, BEFORE ANYTHING ELSE IS LOADED
 * -----------------------------------------------------------------
 * Same wording and reasoning as `WriteOffChargeData`: a blank reason is not a
 * valid reason, and reaching this method with one is a hand-built payload
 * rather than a user mistake — the Filament form (a later unit) marks the
 * field required, so a real operator never sees this message, and it does
 * NOT go through `__()`. Checked before authorization and before the payment
 * id is even looked up, so this refusal never varies with which payment or
 * which actor was supplied.
 *
 * THE AUTHORIZATION CHECK RUNS BEFORE THE PAYMENT IS LOADED
 * -------------------------------------------------------------
 * Same reasoning `WriteOffChargeAction` and `AdjustChargeAction` already
 * use: `reverse_payment` is super-admin only and does not depend on any one
 * payment's state, so authorizing first costs nothing and means an actor
 * without the ability never learns whether the payment id they supplied
 * even exists. The already-reversed refusal below is deliberately NOT folded
 * into `PaymentPolicy::reverse()` for the same reason
 * `ChargeAlreadyWrittenOffException`'s own docblock gives: whether an actor
 * may reverse payments at all is a question about the ACTOR, and whether
 * this particular payment already carries one is a question about the ROW.
 *
 * THE LOCK IS TAKEN BEFORE isReversed() IS READ
 * --------------------------------------------------
 * `lockForUpdate()` runs before `isReversed()` is read, so two concurrent
 * reversal attempts against the same payment cannot both see "not yet
 * reversed" and both proceed — the second waits for the first's transaction
 * to commit, then sees the reversal that already happened and refuses. A
 * second reversal is refused outright rather than silently re-stamped:
 * re-stamping would destroy exactly the fact these three columns exist to
 * record — who decided this payment was recorded in error, and when. Same
 * shape and same reasoning as `ChargeAlreadyWrittenOffException`.
 *
 * ALL THREE COLUMNS, IN ONE update(), OR THE DATABASE REFUSES THE ROW
 * -----------------------------------------------------------------------
 * `reversed_at`, `reversed_by` and `reversal_reason` are written together.
 * `payments_reversal_columns_paired` (see the payments migration) requires
 * all three or none — a reversal with no actor or no reason is exactly the
 * state the mandatory-reason rule exists to prevent, and the database, not
 * just this Action, refuses to hold it.
 *
 * EVERY WRITE RUNS UNDER THE ACTOR, NOT THE SESSION
 * -------------------------------------------------------
 * Spatie resolves the activity-log causer from the authenticated session,
 * while `reversed_by` is written from the actor this Action was PASSED —
 * and those are not always the same thing. Left alone, a console or queued
 * invocation logs no causer at all, and an invocation made while somebody
 * else holds the session logs that person's name over the actor's. This
 * exact gap was a blocking review finding on `WriteOffChargeAction`
 * (P2-T05), so the update below runs inside
 * `CauserResolver::withCauser($actor, ...)`, the same way every other
 * Finance write does it.
 */
final class ReversePaymentAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    /**
     * @throws PaymentAlreadyReversedException if this payment already carries
     *                                         a reversal.
     */
    public function execute(User $actor, int $paymentId, string $reason): Payment
    {
        return DB::transaction(function () use ($actor, $paymentId, $reason): Payment {
            if (trim($reason) === '') {
                throw new InvalidArgumentException('Reversing a payment requires a reason.');
            }

            Gate::forUser($actor)->authorize('reverse', Payment::class);

            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->isReversed()) {
                throw new PaymentAlreadyReversedException((int) $payment->getKey());
            }

            $this->causers->withCauser(
                $actor,
                fn (): bool => $payment->update([
                    'reversed_at' => now(),
                    'reversed_by' => (int) $actor->getKey(),
                    'reversal_reason' => $reason,
                ]),
            );

            return $payment;
        });
    }
}
