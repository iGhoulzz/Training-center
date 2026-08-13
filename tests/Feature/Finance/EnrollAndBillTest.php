<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Actions\EnrollAndBillAction;
use App\Domain\Finance\Data\EnrollAndBillData;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Support\Reference;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

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

it('leaves no enrolment behind when the bill cannot be raised', function () {
    $batch = ($this->batchPriced)('1000.000');
    $student = Student::factory()->create();

    // A discount id that does not exist: the charge insert fails on its foreign
    // key AFTER the enrolment row has been created inside the same transaction.
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

    expect($thrown)->not->toBeNull('The bill must fail on a discount that does not exist.');

    expect(Enrollment::query()->where('student_id', $student->getKey())->exists())->toBeFalse(
        'An enrolment survived a failed billing. Enrolling and billing are one transaction.',
    );

    expect(Charge::query()->count())->toBe(0);
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
