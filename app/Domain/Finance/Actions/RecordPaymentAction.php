<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Exceptions\IdempotencyConflictException;
use App\Domain\Finance\Jobs\GenerateReceiptJob;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Services\PaymentInvariantService;
use App\Domain\Finance\Support\Reference;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Record one handover of money against one bill.
 *
 * RETRY PROTECTION IS INSERT-FIRST, AND THE UNIQUE INDEX DECIDES
 * ---------------------------------------------------------------
 * A double-clicked collection button is otherwise two payments and two
 * receipts for one handover of cash, and nothing else in the design catches
 * it because both submissions are individually valid (design section 5).
 *
 * Design section 5 states the required outcome and deliberately declines to
 * prescribe the operation order — an earlier draft said "resolve the key,
 * compare, then lock the charge", which has a race of its own: two requests
 * can both find no existing key before either inserts. **Task 4 chooses the
 * mechanism**, and it is this one: the insert goes in and
 * `payments_idempotency_key_unique` answers. A concurrent second insert blocks
 * until the first commits, then surfaces as a duplicate that
 * `replayOrConflict()` routes to the replay path.
 *
 * That ordering also gives the settled-bill replay for free — see
 * `replayOrConflict()`.
 *
 * THE STUDENT IS DERIVED, NEVER SUPPLIED
 * ----------------------------------------
 * `RecordPaymentData` carries no student field — see its own docblock.
 * `student_id` is resolved from the locked charge, through
 * `EnrollmentQueryService::studentIdFor()`, and NEVER through
 * `$charge->enrollment->student`: design section 5 names that walk
 * specifically as the domain-boundary violation to avoid, and
 * `Charge::enrollment()`'s own docblock repeats the warning at the
 * declaration it would be tempting to walk.
 *
 * THE AUTHORIZATION CHECK RUNS BEFORE ANYTHING IS LOADED
 * ---------------------------------------------------------
 * The same reasoning `AdjustChargeAction` and `WriteOffChargeAction` already
 * use: an actor without `create_payment` learns nothing about whether the
 * charge id they supplied even exists, because nothing is read until the
 * Gate has already allowed the request through.
 *
 * THE INSERT COMES BEFORE THE INVARIANT CHECK, DELIBERATELY
 * --------------------------------------------------------------
 * `PaymentInvariantService::assertRecordable()` runs AFTER the `payments`
 * row is inserted, not before. The insert is the idempotency gate a later
 * unit hangs its replay off, and design section 5 requires replay detection
 * to run before revalidation — so that replaying a payment which has since
 * settled its own bill returns the original payment instead of raising an
 * overpayment error nobody caused. A refusal from `assertRecordable()`
 * still rolls the whole transaction back, including the insert, so no
 * partial row survives a refusal in THIS unit either; the ordering exists
 * for the unit that comes after it.
 *
 * THE RECEIPT REFERENCE IS WRITTEN TWICE, IN THIS ACTION'S OWN TRANSACTION
 * ------------------------------------------------------------------------
 * Same mechanism as `IssueChargeAction`'s `CHG-` and `EnrollStudentAction`'s
 * `ENR-`: `payments.reference` is NOT NULL UNIQUE and the value contains the
 * row's own id, which does not exist until after the insert. The row is
 * created carrying `Reference::placeholder()` and updated to its real
 * `RCT-` value before this transaction commits, so no other connection ever
 * observes the placeholder — and `reference` stays out of
 * `Payment::auditedAttributes()` so the placeholder never reaches the
 * append-only activity log either.
 *
 * `received_at` IS GENERATED HERE, NEVER CALLER-SUPPLIED — see
 * `RecordPaymentData`'s own docblock for why: it is what lets a later
 * replay return a receipt identical to the one first handed over, and it is
 * what the `RCT-` reference is dated by, on the centre's calendar rather
 * than UTC (`CentreCalendar::yearOf()`).
 *
 * EVERY WRITE RUNS UNDER THE ACTOR, NOT THE SESSION
 * -----------------------------------------------------
 * Spatie resolves the activity-log causer from the authenticated session,
 * while `recorded_by` is written from the actor this Action was PASSED —
 * and those are not always the same thing. Left alone, a console or queued
 * invocation logs no causer at all, and an invocation made while somebody
 * else holds the session logs that person's name over the actor's. This
 * exact gap was a blocking review finding on `WriteOffChargeAction`
 * (P2-T05), so every write below runs inside
 * `CauserResolver::withCauser($actor, ...)`, the same way `IssueChargeAction`
 * already does it.
 */
final class RecordPaymentAction
{
    /** MySQL ER_DUP_ENTRY. */
    private const DUPLICATE_ENTRY = 1062;

    /**
     * The index that carries "one payment per idempotency key".
     *
     * Laravel's default name for `unique()` on `payments.idempotency_key`, and
     * the payments migration names it in prose for exactly this reason.
     * `PaymentIdempotencyTest` proves a violation on any OTHER index still
     * surfaces as itself, so a rename breaks that test rather than silently
     * turning every replay into a raw driver error.
     */
    private const IDEMPOTENCY_INDEX = 'payments_idempotency_key_unique';

    public function __construct(
        private readonly PaymentInvariantService $invariants,
        private readonly EnrollmentQueryService $enrollments,
        private readonly CauserResolver $causers,
    ) {}

    public function execute(User $actor, RecordPaymentData $data): Payment
    {
        return DB::transaction(function () use ($actor, $data): Payment {
            Gate::forUser($actor)->authorize('create', Payment::class);

            $charge = $this->invariants->lockCharge($data->chargeId);

            /*
             * NEVER `$charge->enrollment->student` — see the class docblock.
             * The receipt snapshot needs student, enrollment, course and batch
             * facts, so receiptContextFor() pays for that join once here while
             * those historical values can still be captured atomically.
             */
            $receiptContext = $this->enrollments->receiptContextFor((int) $charge->enrollment_id);

            // Generated here, never from the request. See the class docblock.
            $receivedAt = now();

            return $this->causers->withCauser($actor, function () use (
                $actor,
                $data,
                $charge,
                $receiptContext,
                $receivedAt,
            ): Payment {
                try {
                    $payment = Payment::create([
                        'student_id' => $receiptContext['student_id'],
                        // Replaced below, before this transaction commits.
                        'reference' => Reference::placeholder(),
                        'idempotency_key' => $data->idempotencyKey,
                        'request_fingerprint' => $data->fingerprint(),
                        'received_at' => $receivedAt,
                        'recorded_by' => (int) $actor->getKey(),
                        'notes' => $data->notes,
                    ]);
                } catch (UniqueConstraintViolationException $exception) {
                    /*
                     * The key has been seen before. Everything below this
                     * point is skipped — no tenders, no allocation, no
                     * reference, and no invariant check.
                     */
                    return $this->replayOrConflict($exception, $data);
                }

                /*
                 * AFTER THE INSERT, DELIBERATELY — see the class docblock.
                 * A refusal here rolls this whole transaction back, insert
                 * included, so no partial row survives it.
                 */
                $remainingBalance = $this->invariants->assertRecordable($data);

                foreach ($data->tenders as $tender) {
                    PaymentTender::create([
                        'payment_id' => $payment->getKey(),
                        'method' => $tender->method,
                        'amount' => $tender->amount->toDecimal(),
                        'external_reference' => $tender->externalReference,
                    ]);
                }

                PaymentAllocation::create([
                    'payment_id' => $payment->getKey(),
                    'charge_id' => $data->chargeId,
                    'amount' => $data->allocation->toDecimal(),
                ]);

                $payment->update([
                    'reference' => Reference::format(
                        Reference::PAYMENT_PREFIX,
                        // The centre's calendar, not UTC. See the class docblock.
                        CentreCalendar::yearOf($payment->received_at),
                        (int) $payment->getKey(),
                    ),
                ]);

                PaymentReceiptSnapshot::create([
                    'payment_id' => (int) $payment->getKey(),
                    'locale' => app()->getLocale(),
                    'student_code' => $receiptContext['student_code'],
                    'student_name' => $receiptContext['student_name'],
                    'enrollment_reference' => $receiptContext['enrollment_reference'],
                    'course_code' => $receiptContext['course_code'],
                    'batch_code' => $receiptContext['batch_code'],
                    'charge_reference' => $charge->reference,
                    'list_price' => $charge->list_price,
                    'discount_percentage' => $charge->discount_percentage,
                    'final_charge' => $charge->amount,
                    'amount_paid' => $data->allocation->toDecimal(),
                    'remaining_balance' => $remainingBalance->toDecimal(),
                    'recorded_by_name' => $actor->name,
                    'payment_reference' => $payment->reference,
                    'received_at' => $receivedAt,
                ]);

                GenerateReceiptJob::dispatch((int) $payment->getKey())->afterCommit();

                return $payment;
            });
        });
    }

    /**
     * Resolve a collision on the idempotency key: the same request replayed,
     * or the same key pointed at a different one.
     *
     * THE INDEX NAME IS CHECKED, NOT JUST THE DRIVER CODE.
     * 1062 alone means "some unique index refused this row", which is not the
     * same statement as "this key has been used before". `payments` also
     * carries a unique index on `reference`, and converting its violation into
     * a replay would return a stranger's receipt for a collision nobody was
     * thinking about. `EnrollStudentAction` narrows its own catch the same way
     * and for the same reason.
     *
     * THE LOOKUP IS A LOCKING READ, AND THAT IS LOAD-BEARING.
     * InnoDB's REPEATABLE READ serves a plain SELECT from the snapshot this
     * transaction established at its FIRST read — which happened during the
     * authorization check, before the competing transaction committed the row
     * we have just collided with. A plain read therefore returns **null** for
     * a row the database has this instant told us exists, on precisely the
     * concurrent path this mechanism exists for. `lockForUpdate()` always
     * reads the latest committed version. None of this is visible to a
     * single-connection test; `PaymentConcurrencyTest` is what proves it, and
     * removing the lock turns that test red.
     *
     * NOTHING IS REVALIDATED ON THE REPLAY PATH, AND THAT IS THE POINT.
     * `PaymentInvariantService::assertRecordable()` is never reached from
     * here, so replaying a payment that settled its bill in full returns the
     * original payment instead of an overpayment error about money the
     * operator is not trying to take twice. Design section 5 requires that
     * outcome specifically.
     *
     * @throws IdempotencyConflictException if the key was minted for a
     *                                      different request.
     */
    private function replayOrConflict(
        UniqueConstraintViolationException $exception,
        RecordPaymentData $data,
    ): Payment {
        $isIdempotencyCollision = ($exception->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY
            && $exception->index === self::IDEMPOTENCY_INDEX;

        if (! $isIdempotencyCollision) {
            throw $exception;
        }

        $existing = Payment::query()
            ->where('idempotency_key', $data->idempotencyKey)
            ->lockForUpdate()
            ->first();

        if (! $existing instanceof Payment) {
            /*
             * The database refused this key as a duplicate and then had no row
             * carrying it. Nothing in this system deletes a payment, so this
             * is not a state to paper over by inserting again: returning a
             * fresh payment here would record the money twice, which is the
             * one outcome the key exists to prevent.
             */
            throw new RuntimeException(
                "Idempotency key [{$data->idempotencyKey}] collided, but no payment holds it."
            );
        }

        /*
         * hash_equals rather than `===`. Both sides are hex digests of
         * caller-influenced input, and constant-time comparison costs nothing
         * here — a timing signal on a fingerprint is a way to learn what an
         * earlier payment contained.
         */
        if (! hash_equals((string) $existing->request_fingerprint, $data->fingerprint())) {
            throw new IdempotencyConflictException($data->idempotencyKey, (int) $existing->getKey());
        }

        return $existing;
    }
}
