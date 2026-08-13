<?php

declare(strict_types=1);

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\Charge;
use App\Models\User;

/**
 * Authorization for charges (bills).
 *
 * REGISTERED EXPLICITLY, FOR CONSISTENCY — NOT BECAUSE DISCOVERY FAILS.
 * `App\Providers\AppServiceProvider::boot()` calls
 * `Gate::policy(Charge::class, ChargePolicy::class)`, exactly as it already
 * does for `Batch`, `Enrollment` and every other domain model. An earlier
 * version of this docblock claimed that line was load-bearing — that without
 * it "every check below silently falls through to false for everyone, super
 * admin included." That claim was checked and is false: `Charge` lives at
 * `App\Domain\Finance\Models\Charge`, and Laravel's convention-based
 * discovery (`Gate::guessPolicyName()`) resolves a policy by replacing
 * `\Models\` with `\Policies\` in the model's namespace, which lands exactly
 * on `App\Domain\Finance\Policies\ChargePolicy` with no registration at all.
 * `Gate::getPolicyFor(Charge::class)` finds this class on its own, and the
 * test suite passes with the registration removed.
 *
 * The explicit line stays anyway. Every other policy under `app/Domain` in
 * this codebase is registered the same way, in the same place, and relying
 * on convention discovery to keep working — rather than depending on a line
 * anyone can read — is an accident to build on, not a mechanism to build on.
 * See `AppServiceProvider`'s own comment on the same point.
 *
 * READ IS PERMISSION-BASED. `view_any_charge` and `view_charge` are seeded to
 * both super_admin and admin (design section 10) — every finance figure is
 * readable by an admin, who records money but sets nothing and undoes
 * nothing.
 *
 * create(), update() AND delete() RETURN false UNCONDITIONALLY
 * ------------------------------------------------------------
 * Not "check a permission that does not exist" — design section 4 is explicit
 * that these are literally false. `create_charge`, `update_charge` and
 * `delete_charge` are deliberately NOT seeded at all (`RolePermissionSeeder`),
 * following the same reasoning already recorded there for the activity log:
 * seeding an ability nothing honours invites someone to wire it up later.
 * Asking Spatie about any of those three names would in fact throw
 * `PermissionDoesNotExist` the moment anything checked, since it refuses an
 * unknown permission name rather than quietly returning false for it — which
 * is exactly why the answer is a bare `false` and not a permission check that
 * cannot succeed.
 *
 * The three names are deliberately NOT written here as a permission check, not
 * even inside this comment. `RolePermissionSeederTest` scans every policy for
 * that call shape and does not strip comments first, so prose demonstrating the
 * call it forbids reads to the scanner as the violation itself — which is how
 * this paragraph was originally written, and it failed the build. The scanner
 * is right to stay fail-closed; the comment is what changed. Its
 * over-sensitivity to prose is noted for the task that owns that test, and was
 * deliberately not "fixed" by teaching a security scanner to ignore things.
 *
 * A charge is raised only as a system consequence of `create_enrollment`
 * (`EnrollAndBillAction`, via the internal `IssueChargeAction`), corrected
 * only through `adjust()` below, and retired only through `writeOff()` below.
 * There is no general-purpose create, update or delete for this model at all.
 *
 * THE ONE EXCEPTION IS NOT A CONTRADICTION OF delete()
 * ------------------------------------------------------
 * Design section 4: an enrolment created in error must stay deletable, which
 * means deleting its unpaid bill with it. That path is
 * `DeleteUncommittedChargeAction` (task 3), an internal Finance Action
 * callable only from `DeleteEnrollmentAction`. It does **not** consult this
 * policy at all, and that is deliberate rather than an oversight this policy
 * happens to allow: `delete()` above answers "may this actor delete a bill on
 * its own", and design section 4 states that answer is no for everyone.
 * `DeleteUncommittedChargeAction` answers a different question — "may the
 * enrolment this bill belongs to be deleted" — authorized upstream by
 * `delete_enrollment`, and it locks the charge and refuses itself if any
 * allocation, adjustment or write-off exists. Two different questions, two
 * different answers, and neither is wrong because the other exists.
 *
 * deleteAny(), restoreAny(), forceDeleteAny() AND reorder() ARE WRITTEN OUT
 * ---------------------------------------------------------------------------
 * Filament authorizes a bulk action ONCE against the `*Any` method and never
 * consults the per-record rule for the rows actually selected — see
 * `RolePolicy`'s identical reasoning. A per-record protection expressed only
 * in `delete()` would therefore be a bypass the moment a bulk control existed,
 * so these refuse unconditionally rather than being left unstated. An
 * unstated method is not a safer default here: Filament resolves a MISSING
 * policy method to allow, where the Gate resolves it to false, so leaving one
 * out is an open door for whoever next adds the standard Filament bulk-action
 * idiom to this resource, not a closed one. `PolicyAbilitySurfaceTest` proves
 * every one of the twelve Filament abilities is stated somewhere in this file.
 *
 * `Charge` never soft-deletes, so restore(), forceDelete() and replicate()
 * are dormant in the same sense they are on `BatchPolicy` — never reachable
 * today, written out anyway so a future soft-delete control on this resource
 * does not inherit an unstated method.
 *
 * adjust() AND writeOff() ARE RECORD-INDEPENDENT ON PURPOSE
 * ---------------------------------------------------------------
 * Neither `adjust_charge` nor `write_off_charge` depends on any one charge's
 * state — unlike `BatchPolicy::assignInstructor()`, which reads the batch's
 * own status, there is no per-record condition to express here at all. That
 * is why `AdjustChargeAction` and `WriteOffChargeAction` both authorize
 * against `Charge::class` before ever loading the row they act on (see their
 * own docblocks), and why the two business-rule refusals that DO depend on a
 * specific charge — dropping the amount below what is allocated, writing off
 * a charge twice — are raised by the Actions themselves as typed exceptions
 * rather than folded in here. A policy method answers "may this actor act at
 * all"; it does not also answer "is this particular row in a state that
 * allows it", which is the Actions' job once the lock is held.
 */
final class ChargePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_charge');
    }

    public function view(User $actor, Charge $charge): bool
    {
        return $actor->can('view_charge');
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Charge $charge): bool
    {
        return false;
    }

    /**
     * See the class docblock: this is "may a charge be deleted on its own",
     * which is no for everyone. It has nothing to do with
     * `DeleteUncommittedChargeAction`, which does not consult this method.
     */
    public function delete(User $actor, Charge $charge): bool
    {
        return false;
    }

    /**
     * Correct a data-entry error on this charge's amount, never a late
     * discount. See `AdjustChargeAction` for the full reasoning and for why
     * the amount-below-allocated refusal is not expressed here.
     */
    public function adjust(User $actor): bool
    {
        return $actor->can('adjust_charge');
    }

    /**
     * Retire a debt the centre will never collect. See `WriteOffChargeAction`
     * for the full reasoning and for why the already-written-off refusal is
     * not expressed here.
     */
    public function writeOff(User $actor): bool
    {
        return $actor->can('write_off_charge');
    }

    /*
    |--------------------------------------------------------------------------
    | Dormant Filament abilities
    |--------------------------------------------------------------------------
    |
    | THESE ARE NOT REDUNDANT AND MUST NOT BE DELETED AS DEAD CODE. See the
    | class docblock, and BatchPolicy's identical section: an ability this
    | policy simply did not mention would be DENIED by the Gate and ALLOWED by
    | Filament, and writing them out is what makes the refusal real rather than
    | assumed. None of these operations exist for charges in phase 2.
    */

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Charge $charge): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Charge $charge): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, Charge $charge): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
