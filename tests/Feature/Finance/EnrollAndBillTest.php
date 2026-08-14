<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Actions\EnrollAndBillAction;
use App\Domain\Finance\Data\EnrollAndBillData;
use App\Domain\Finance\Exceptions\DiscountNotApplicableException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Support\Reference;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| EnrollAndBillAction — enrolling and billing are one act
|--------------------------------------------------------------------------
|
| Design section 12: enrolling without raising a bill was a real UI path before
| this task, on the batch screen staff already use. EnrollAndBillAction becomes
| the only application entry point, and an architecture rule keeps it that way.
|
| Design section 4: one charge per enrolment, due on the day it was raised,
| with list price, percentage and amount frozen at issue.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->superAdmin = ($this->actorWith)('super_admin');
    $this->admin = ($this->actorWith)('admin');
    $this->staff = ($this->actorWith)('staff');

    $this->enrollAndBill = app(EnrollAndBillAction::class);

    $this->batchPriced = function (string $price): Batch {
        $course = Course::factory()->create(['default_price' => '0.000']);

        return Batch::factory()->for($course)->create(['price' => $price]);
    };
});

/*
|--------------------------------------------------------------------------
| The walk-in: staff enrol at full price, holding nothing financial
|--------------------------------------------------------------------------
*/

it('raises a bill at full price when a staff member enrols a walk-in', function () {
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();

    expect($this->staff->can('view_any_charge'))->toBeFalse(
        'Staff hold nothing financial; that is what makes this test meaningful.',
    );

    $enrollment = $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
    ));

    $charge = Charge::query()->where('enrollment_id', $enrollment->getKey())->firstOrFail();

    expect($charge->list_price)->toBe('1000.000')
        ->and($charge->amount)->toBe('1000.000')
        ->and($charge->discount_id)->toBeNull()
        ->and($charge->discount_percentage)->toBeNull();
});

it('gives the charge a CHG reference and never leaves a placeholder behind', function () {
    $batch = ($this->batchPriced)('500.000');
    $student = Student::factory()->create();

    $enrollment = $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
    ));

    $stored = DB::table('charges')->where('enrollment_id', $enrollment->getKey())->first();

    expect($stored->reference)->toStartWith(Reference::CHARGE_PREFIX)
        ->and($stored->reference)->toBe(Reference::format(
            Reference::CHARGE_PREFIX,
            (int) date('Y'),
            (int) $stored->id,
        ));

    // The placeholder exists only inside the transaction, and never in the log.
    $placeholders = Activity::query()
        ->where('properties', 'like', '%'.Reference::PLACEHOLDER_MARKER.'%')
        ->count();

    expect($placeholders)->toBe(0);
});

it('sets the due date to the enrolment date, per design section 4', function () {
    $this->travelTo('2026-08-13 09:30:00');

    $batch = ($this->batchPriced)('300.000');
    $student = Student::factory()->create();

    $enrollment = $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
    ));

    $charge = Charge::query()->where('enrollment_id', $enrollment->getKey())->firstOrFail();

    expect($charge->due_date->toDateString())
        ->toBe($enrollment->enrolled_at->toDateString());
});

/*
|--------------------------------------------------------------------------
| One transaction: neither row survives the other's failure
|--------------------------------------------------------------------------
*/

it('writes the enrolment and the bill at the same transaction depth', function () {
    /*
     * THE PREVIOUS VERSION OF THIS TEST COULD NOT FAIL, AND THE INDEPENDENT
     * REVIEW PROVED IT BY DELETING THE TRANSACTION.
     *
     * It passed a discount id that does not exist and asserted no rows
     * survived. But authorizedDiscount() runs FIRST, so findOrFail() threw
     * before EnrollStudentAction was ever reached — nothing was inserted, so
     * nothing needed rolling back, and the assertions were trivially true. With
     * `DB::transaction()` removed from EnrollAndBillAction the whole file stayed
     * green. A rollback test whose failure happens before the first write is
     * not a rollback test.
     *
     * RefreshDatabase is why the obvious repair is not enough either: it holds
     * a transaction open around every test, so an Action that opens none still
     * sees `lockForUpdate()` emit `for update` and still appears to roll back.
     * The technique this repository settled on is to measure the DEPTH, and to
     * measure it as a DELTA — an absolute level of 1 also passes for an Action
     * that opens nothing.
     */
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();

    $baseline = DB::transactionLevel();
    $depths = [];

    DB::listen(function ($query) use (&$depths): void {
        if (str_contains($query->sql, 'insert into `enrollments`')) {
            $depths['enrollment'] = DB::transactionLevel();
        }

        if (str_contains($query->sql, 'insert into `charges`')) {
            $depths['charge'] = DB::transactionLevel();
        }
    });

    $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
    ));

    expect($depths)->toHaveKeys(['enrollment', 'charge']);

    expect($depths['enrollment'])->toBeGreaterThan(
        $baseline,
        'The enrolment was inserted outside any transaction this Action opened.',
    );

    expect($depths['charge'])->toBe(
        $depths['enrollment'],
        'The bill and the enrolment were written at different depths, so one can commit without the other.',
    );
});

it('rolls the enrolment back when the bill fails after it has been inserted', function () {
    /*
     * THE ONLY TEST HERE THAT PROVES ATOMICITY, AND THE DEPTH TEST ABOVE IS NOT
     * A SUBSTITUTE FOR IT.
     *
     * Cross-review finding: comparing depths proves both writes happen inside
     * *a* transaction at the same depth, not inside the SAME transaction. With
     * `DB::transaction()` removed from EnrollAndBillAction, EnrollStudentAction
     * opens and COMMITS its own at baseline + 1, then IssueChargeAction opens a
     * different one at baseline + 1 — equal depths, green test, and a charge
     * failure that leaves an unbilled enrolment behind. Which is the single
     * invariant this task exists to close.
     *
     * So the failure is injected where it has to be: after the enrolment row
     * exists and while the charge is being written. A model event does that
     * without needing to replace a final class.
     */
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();

    Charge::creating(function (): void {
        throw new RuntimeException('The bill could not be raised.');
    });

    $thrown = null;

    try {
        $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
            studentId: (int) $student->getKey(),
            batchId: (int) $batch->getKey(),
        ));
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        // Model event listeners outlive the test otherwise.
        Charge::flushEventListeners();
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);

    expect(Enrollment::query()->count())->toBe(
        0,
        'An enrolment survived a failed billing, so the two writes are not one transaction.',
    );

    expect(Charge::query()->count())->toBe(0);

    // Read past Eloquent, in case anything above is answering from memory.
    expect(DB::table('enrollments')->count())->toBe(0);
});

it('refuses an unknown discount before it writes anything at all', function () {
    // What the old rollback test actually exercised, kept and named honestly:
    // the discount is resolved first, so a bad id costs nothing.
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();

    $thrown = null;

    try {
        $this->enrollAndBill->execute($this->admin, new EnrollAndBillData(
            studentId: (int) $student->getKey(),
            batchId: (int) $batch->getKey(),
            discountId: 999_999,
        ));
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ModelNotFoundException::class);

    expect(Enrollment::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Discounts: authorized, frozen, and refused to those without the ability
|--------------------------------------------------------------------------
*/

it('freezes the discount figures on the charge when an admin applies one', function () {
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();
    $discount = Discount::factory()->create(['percentage' => '25.00', 'is_active' => true]);

    expect($this->admin->can('apply_discount'))->toBeTrue();

    $enrollment = $this->enrollAndBill->execute($this->admin, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
        discountId: (int) $discount->getKey(),
    ));

    $charge = Charge::query()->where('enrollment_id', $enrollment->getKey())->firstOrFail();

    expect($charge->list_price)->toBe('1000.000')
        ->and($charge->discount_percentage)->toBe('25.00')
        ->and((int) $charge->discount_id)->toBe((int) $discount->getKey())
        ->and($charge->amount)->toBe('750.000');
});

it('writes the rounded figure onto the row, not an exact-division one', function () {
    /*
     * THE DISCOUNT CASE THAT ACTUALLY ROUNDS.
     *
     * 1000.000 @ 25% divides exactly, so the case above would pass against an
     * implementation that never rounds at all. Design section 3's rule is
     * round(list_price × (100 − percentage) ÷ 100) in integer dirham, half-up:
     * 216.350 @ 1.00% is 214.1865, which has to land on 214.187 and cannot be
     * reached by truncation. MoneyTest proves the rule; this proves the ACTION
     * writes its output rather than a figure of its own.
     */
    $batch = ($this->batchPriced)('216.350');
    $student = Student::factory()->create();
    $discount = Discount::factory()->create(['percentage' => '1.00', 'is_active' => true]);

    $enrollment = $this->enrollAndBill->execute($this->admin, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
        discountId: (int) $discount->getKey(),
    ));

    $charge = Charge::query()->where('enrollment_id', $enrollment->getKey())->firstOrFail();

    expect($charge->amount)->toBe('214.187')
        ->and($charge->list_price)->toBe('216.350')
        ->and($charge->discount_percentage)->toBe('1.00');
});

it('dates the bill on the centre calendar, not on UTC', function () {
    /*
     * 22:30 UTC on 31 December is 00:30 on 1 January in Tripoli. Letting the
     * `date` cast truncate the stored UTC value put the due date a day early
     * and minted CHG- in the previous year while ENR- said the next one — two
     * references for one act disagreeing about which year it happened in.
     * Found by the independent review of this task.
     */
    $this->travelTo('2025-12-31 22:30:00');

    $batch = ($this->batchPriced)('500.000');
    $student = Student::factory()->create();

    $enrollment = $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
    ));

    $charge = Charge::query()->where('enrollment_id', $enrollment->getKey())->firstOrFail();

    expect($charge->due_date->toDateString())->toBe('2026-01-01')
        ->and($charge->reference)->toStartWith(Reference::CHARGE_PREFIX.'-2026-')
        // The two series agree, which is the property that was broken.
        ->and($enrollment->reference)->toStartWith(Reference::ENROLLMENT_PREFIX.'-2026-');
});

it('authorizes the enrolment before it resolves the discount at all', function () {
    /*
     * Cross-review finding: the discount was resolved first, so an actor holding
     * apply_discount but not create_enrollment learned whether a discount id was
     * unknown or merely retired before being refused — and the wrapper was not
     * self-authorizing for its own primary write.
     *
     * The id below is invalid, so if resolution ran first the failure would be
     * ModelNotFoundException. Authorization has to be the first answer.
     */
    /*
     * Granted directly and holding no role, because `create_enrollment` reaches
     * an admin through their ROLE — revoking it from the user leaves the role
     * grant intact, which is how the first version of this test asserted a
     * permission the actor still had.
     */
    $noEnrolmentGrant = User::factory()->create(['is_active' => true]);
    $noEnrolmentGrant->givePermissionTo('apply_discount');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $noEnrolmentGrant = $noEnrolmentGrant->refresh();

    expect($noEnrolmentGrant->can('apply_discount'))->toBeTrue()
        ->and($noEnrolmentGrant->can('create_enrollment'))->toBeFalse();

    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();

    $thrown = null;

    try {
        $this->enrollAndBill->execute($noEnrolmentGrant, new EnrollAndBillData(
            studentId: (int) $student->getKey(),
            batchId: (int) $batch->getKey(),
            discountId: 999_999,
        ));
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class);

    expect(Enrollment::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0);
});

it('reads the chosen discount under a lock, so deactivation cannot race issuance', function () {
    /*
     * Cross-review finding: a plain read left a window between resolving the
     * definition and writing the charge, in which DeactivateDiscountAction could
     * commit and this transaction would still apply a retired rate.
     *
     * This asserts the mechanism — the read is a locking one — by capturing the
     * SQL. It is NOT a two-connection race test; that is stated plainly in the
     * PR rather than implied by this test's name.
     */
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();
    $discount = Discount::factory()->create(['percentage' => '10.00', 'is_active' => true]);

    $discountReads = [];

    DB::listen(function ($query) use (&$discountReads): void {
        if (str_contains($query->sql, 'from `discounts`')) {
            $discountReads[] = $query->sql;
        }
    });

    $this->enrollAndBill->execute($this->admin, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
        discountId: (int) $discount->getKey(),
    ));

    expect($discountReads)->not->toBeEmpty();

    expect(collect($discountReads)->every(fn (string $sql): bool => str_contains($sql, 'for update')))
        ->toBeTrue('The discount was read without a lock: '.implode(' | ', $discountReads));
});

it('refuses a deactivated discount rather than applying it or dropping it', function () {
    /*
     * DEACTIVATION MEANS SOMETHING ON THE SERVER.
     *
     * This Action is the only application path that applies a discount, so
     * without the check DeactivateDiscountAction would have no effect on
     * enrolment at all — a retired definition would stay usable by id forever.
     *
     * The other wrong answer is filtering it out and billing full price: that
     * charges a figure the operator did not choose, silently. Asserted here as
     * a refusal with nothing written, so neither wrong answer can return
     * unnoticed.
     */
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();
    $retired = Discount::factory()->create(['percentage' => '25.00', 'is_active' => false]);

    $thrown = null;

    try {
        $this->enrollAndBill->execute($this->admin, new EnrollAndBillData(
            studentId: (int) $student->getKey(),
            batchId: (int) $batch->getKey(),
            discountId: (int) $retired->getKey(),
        ));
    } catch (DiscountNotApplicableException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(DiscountNotApplicableException::class)
        ->and($thrown->discountId)->toBe((int) $retired->getKey());

    // Not billed at full price behind the operator's back, and not billed at all.
    expect(Enrollment::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0);
});

it('refuses a crafted discount from a staff member who does not hold apply_discount', function () {
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();
    $discount = Discount::factory()->create(['percentage' => '25.00', 'is_active' => true]);

    expect($this->staff->can('apply_discount'))->toBeFalse();

    $thrown = null;

    try {
        $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
            studentId: (int) $student->getKey(),
            batchId: (int) $batch->getKey(),
            discountId: (int) $discount->getKey(),
        ));
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class);

    // Refused BEFORE anything was written, not rolled back after.
    expect(Enrollment::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The frozen figures do not move when the catalogue does
|--------------------------------------------------------------------------
*/

it('does not move an issued charge when the course price changes later', function () {
    $course = Course::factory()->create(['default_price' => '800.000']);
    $batch = Batch::factory()->for($course)->create(['price' => null]);
    $student = Student::factory()->create();

    $enrollment = $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
    ));

    $charge = Charge::query()->where('enrollment_id', $enrollment->getKey())->firstOrFail();

    expect($charge->list_price)->toBe('800.000');

    // The inherited price changes at the course, which every later enrolment
    // gets — and this bill does not.
    $course->update(['default_price' => '950.000']);

    expect($charge->fresh()->list_price)->toBe('800.000')
        ->and($charge->fresh()->amount)->toBe('800.000');
});

/*
|--------------------------------------------------------------------------
| The audit entry names the actor, not the session
|--------------------------------------------------------------------------
*/

it('attributes the charge to the Action actor rather than to whoever holds the session', function () {
    $batch = ($this->batchPriced)('400.000');
    $student = Student::factory()->create();

    // A different user holds the session, exactly as in WriteOffChargeTest.
    $this->actingAs($this->admin);

    $enrollment = $this->enrollAndBill->execute($this->staff, new EnrollAndBillData(
        studentId: (int) $student->getKey(),
        batchId: (int) $batch->getKey(),
    ));

    $charge = Charge::query()->where('enrollment_id', $enrollment->getKey())->firstOrFail();

    $causerId = Activity::query()
        ->where('subject_type', Charge::class)
        ->where('subject_id', $charge->getKey())
        ->where('event', 'created')
        ->value('causer_id');

    expect((int) $causerId)->toBe((int) $this->staff->getKey())
        ->and((int) $causerId)->not->toBe((int) $this->admin->getKey());
});
