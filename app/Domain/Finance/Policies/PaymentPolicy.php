<?php

declare(strict_types=1);

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\Payment;
use App\Models\User;

/**
 * Authorization for payments.
 *
 * READ IS PERMISSION-BASED. `view_any_payment` and `view_payment` are seeded
 * to both super_admin and admin (design section 10) — an admin reads every
 * finance figure while setting no rate and undoing nothing.
 *
 * create() AND reverse() ARE THE ONLY WRITES A PAYMENT HAS
 * -----------------------------------------------------------
 * `create_payment` records money coming in and is seeded to both super_admin
 * and admin. `reverse_payment` undoes one and is seeded to super_admin alone
 * — see `reverse()`'s own docblock for why the already-reversed refusal
 * lives in `ReversePaymentAction` rather than here. There is no third write:
 * a payment is never edited once it exists, only reversed.
 *
 * update() AND delete() RETURN false UNCONDITIONALLY
 * ------------------------------------------------------
 * Not "check a permission that does not exist" — design section 5 is
 * explicit that these are literally false. `update_payment` and
 * `delete_payment` are deliberately NOT seeded at all (`RolePermissionSeeder`),
 * following the same reasoning already recorded there for the activity log
 * and for `ChargePolicy`'s own `create()`/`update()`/`delete()`: seeding an
 * ability nothing honours invites someone to wire it up later. Asking
 * Spatie about either of those two names would in fact throw
 * `PermissionDoesNotExist` the moment anything checked, since it refuses an
 * unknown permission name rather than quietly returning false for it —
 * which is exactly why the answer below is a bare `false` and not a
 * permission check that cannot succeed.
 *
 * THE TWO NAMES ARE DELIBERATELY NOT WRITTEN HERE AS A PERMISSION CHECK,
 * NOT EVEN INSIDE THIS COMMENT. `RolePermissionSeederTest` scans every
 * policy for that call shape and does not strip comments first, so prose
 * demonstrating the call it forbids reads to the scanner as the violation
 * itself — `ChargePolicy`'s own docblock records hitting exactly this.
 *
 * A PAYMENT THAT MUST BE UNDONE IS REVERSED, NEVER DELETED
 * -----------------------------------------------------------
 * `payment_tenders.payment_id` restricts, so the database refuses a delete
 * while any tender exists — and every payment carries at least one tender
 * by the time it is ever visible, because `RecordPaymentAction` writes the
 * payment and its tenders together in one transaction. There is no state a
 * payment can be in where deleting it would leave the database
 * self-consistent, which is why `delete()` answers no for everyone rather
 * than checking the row.
 *
 * deleteAny(), restore(), restoreAny(), forceDelete(), forceDeleteAny(),
 * replicate() AND reorder() ARE WRITTEN OUT, NOT LEFT UNSTATED
 * ------------------------------------------------------------------------
 * `PolicyAbilitySurfaceTest` proves every one of the twelve Filament
 * abilities is stated somewhere in this file: Laravel's Gate resolves a
 * missing method to a refusal, but Filament resolves the same gap to
 * `Response::allow()` — so a method this policy simply did not mention
 * would be denied everywhere a test looked and permitted the instant
 * somebody rendered the standard bulk or soft-delete control for it.
 * Filament also authorizes a bulk action ONCE against the `*Any` method and
 * never re-consults the per-record rule for the rows actually selected, so
 * `deleteAny()` in particular could not be left to fall back on `delete()`
 * even if that method checked something. `Payment` never soft-deletes, so
 * `restore()`, `forceDelete()` and `replicate()` are dormant in the same
 * sense they are on `ChargePolicy` — never reachable today, written out
 * anyway so a future control on this resource does not inherit an unstated
 * method.
 *
 * NOT REGISTERED IN AppServiceProvider.
 * --------------------------------------
 * `Payment` lives at `App\Domain\Finance\Models\Payment`, and Laravel's
 * convention-based discovery (`Gate::guessPolicyName()`) resolves it to
 * `App\Domain\Finance\Policies\PaymentPolicy` with no registration line at
 * all — the same discovery `ChargePolicy`'s docblock demonstrates is
 * sufficient on its own.
 *
 * **No part of task 4 adds that line, and no later unit of it may.** This is
 * a scheduling constraint rather than a preference: task 8 (payroll runs) is
 * running concurrently in wave 4 and needs the same file for
 * `PayrollRunPolicy`, and the wave-isolation rule allows `AppServiceProvider`
 * exactly one writer per wave. Nothing is lost by leaving it out — discovery
 * resolves this class unaided, and `PaymentAuthorizationTest` proves exactly
 * that: `Gate::getPolicyFor(Payment::class)` resolves this class, and a
 * source scan of `AppServiceProvider` confirms no registration line was
 * added to make it happen. The panel half of the plan's requirement —
 * exercising this policy through the real Filament resource — belongs to
 * `PaymentResourceTest`, once `PaymentResource` exists to be tested against.
 * A later phase may add the explicit registration this codebase otherwise
 * prefers, once the file has a single owner again.
 */
final class PaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_payment');
    }

    public function view(User $actor, Payment $payment): bool
    {
        return $actor->can('view_payment');
    }

    /**
     * Record money against a bill. `create_payment` is seeded to both
     * super_admin and admin (design section 10) — an admin records money
     * coming in but sets no rate and undoes nothing.
     */
    public function create(User $actor): bool
    {
        return $actor->can('create_payment');
    }

    /** See the class docblock: update_payment is deliberately not seeded. */
    public function update(User $actor, Payment $payment): bool
    {
        return false;
    }

    /**
     * See the class docblock: a payment is never deleted on its own, only
     * reversed. `delete_payment` is deliberately not seeded.
     */
    public function delete(User $actor, Payment $payment): bool
    {
        return false;
    }

    /**
     * Undo a payment. Record-independent, exactly like `ChargePolicy::adjust()`
     * and `writeOff()`: whether an actor may reverse payments at all is a
     * question about the ACTOR, and whether this particular payment is
     * already reversed is a question about the ROW — that stays in
     * `ReversePaymentAction` as a typed exception
     * (`PaymentAlreadyReversedException`) once the lock is held, rather than
     * being folded in here.
     */
    public function reverse(User $actor): bool
    {
        return $actor->can('reverse_payment');
    }

    /*
    |--------------------------------------------------------------------------
    | Dormant Filament abilities
    |--------------------------------------------------------------------------
    |
    | THESE ARE NOT REDUNDANT AND MUST NOT BE DELETED AS DEAD CODE. See the
    | class docblock: an ability this policy simply did not mention would be
    | DENIED by the Gate and ALLOWED by Filament, and writing them out is what
    | makes the refusal real rather than assumed. None of these operations
    | exist for payments in phase 2.
    */

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Payment $payment): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Payment $payment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, Payment $payment): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
