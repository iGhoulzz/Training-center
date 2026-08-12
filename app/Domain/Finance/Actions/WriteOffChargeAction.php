<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Data\WriteOffChargeData;
use App\Domain\Finance\Exceptions\ChargeAlreadyWrittenOffException;
use App\Domain\Finance\Models\Charge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Retire a debt the centre has accepted it will never collect.
 *
 * NOTHING IS ERASED
 * ------------------
 * Writing off does not pay the bill and does not delete it. `ChargeBalance`
 * deliberately does not subtract a write-off (see its own docblock), so the
 * balance on a receipt and the balance in the payment history keep agreeing
 * for the same charge on the same day. Only the aged outstanding report (task
 * 10) excludes a written-off charge, by filtering on `written_off_at` — a
 * report-level filter, not a change to what the student owes.
 *
 * THE ABILITY IS CHECKED BEFORE THE CHARGE IS EVEN LOADED
 * ---------------------------------------------------------
 * Same reasoning as `AdjustChargeAction`: `write_off_charge` is super-admin
 * only and does not depend on any one charge's state, so authorizing first
 * costs nothing and means an actor without the permission never learns
 * whether the charge id they supplied exists. The idempotence refusal below
 * is deliberately NOT folded into `ChargePolicy::writeOff()` for the same
 * reason `ChargeAlreadyWrittenOffException`'s own docblock gives: whether an
 * actor may write off charges at all is a question about the ACTOR, and
 * whether this particular charge already carries one is a question about the
 * ROW. Mixing the two into one policy method would make a super admin's
 * denial on an already-written-off charge look like a permissions problem
 * when it is a business-rule refusal — the same shape `DuplicateEnrollmentException`
 * and `BatchInUseException` already use for exactly this reason.
 *
 * IDEMPOTENCE IS CHECKED UNDER THE CHARGE'S OWN LOCK
 * -----------------------------------------------------
 * `lockForUpdate()` is taken before `isWrittenOff()` is read, so two
 * concurrent write-off attempts on the same charge cannot both see "not yet
 * written off" and both proceed — one waits for the other's transaction to
 * commit, then sees the write-off that already happened and refuses. A second
 * write-off is refused outright rather than silently succeeding: silently
 * re-stamping a new actor and timestamp over an earlier super admin's
 * decision would destroy exactly the fact these three columns exist to
 * record — who decided the debt was uncollectable, and when. See
 * `ChargeAlreadyWrittenOffException`.
 *
 * ALL THREE COLUMNS, OR THE DATABASE REFUSES THE ROW
 * -------------------------------------------------------
 * `written_off_at`, `written_off_by` and `written_off_reason` are written
 * together in one `update()` call. The `charges_write_off_columns_paired`
 * CHECK constraint (see the charges migration) requires all three or none —
 * a write-off with no actor or no reason is exactly the state the mandatory
 * reason rule exists to prevent, and the database, not just this Action,
 * refuses to hold it.
 *
 * NO MANUAL ACTIVITY LOGGING, UNLIKE `AdjustChargeAction`
 * -------------------------------------------------------------
 * `AdjustChargeAction` has to build its own activity entry because a
 * corrected `amount` has no column to explain WHY it changed. Here, the
 * reason has a real column — `written_off_reason` — and it is in
 * `Charge::auditedAttributes()` alongside the other two write-off columns, so
 * `RecordsActivity`'s ordinary model-event logging already puts the reason in
 * the before/after diff `ActivityResource::describeChanges()` renders,
 * without this Action lifting a finger. Reaching for `disableLogging()` here
 * as well would only throw that diff away for no reason.
 */
final class WriteOffChargeAction
{
    /**
     * @throws ChargeAlreadyWrittenOffException if this charge already carries
     *                                          a write-off.
     */
    public function execute(User $actor, WriteOffChargeData $data): Charge
    {
        return DB::transaction(function () use ($actor, $data): Charge {
            Gate::forUser($actor)->authorize('writeOff', Charge::class);

            $charge = Charge::query()->lockForUpdate()->findOrFail($data->chargeId);

            if ($charge->isWrittenOff()) {
                throw new ChargeAlreadyWrittenOffException((int) $charge->getKey());
            }

            $charge->update([
                'written_off_at' => now(),
                'written_off_by' => (int) $actor->getKey(),
                'written_off_reason' => $data->reason,
            ]);

            return $charge;
        });
    }
}
