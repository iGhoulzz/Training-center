<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\DeleteStudentAction;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Deleting a student is refused while the student has an active enrolment or owes
 * money (QA Q2, owner decision of 2026-10-17: "refuse while owing").
 *
 * The owed amount is StudentBalanceQuery's total, which counts every enrolment
 * whatever its status and does not exclude a written-off charge (design 4), so the
 * "owes" fixtures below use withdrawn and completed enrolments: the active-enrolment
 * rule must not be what refuses them.
 *
 * Actors hold exactly the permissions named, never super_admin.
 */
uses(RefreshDatabase::class);

const ACTIVE_ENROLMENT_REFUSAL = 'This student has an active enrolment. Withdraw or complete it before deleting the student.';
const OWES_REFUSAL = 'This student has an outstanding balance. Settle it before deleting the student.';

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    // delete_student and nothing else the Action's own gate could lean on.
    $this->remover = ($this->userWith)('delete_student');

    // What the list and edit pages need to open at all, plus the delete grant.
    $this->listRemover = ($this->userWith)('access_admin_panel', 'view_any_student', 'view_student', 'delete_student');
    $this->editRemover = ($this->userWith)('access_admin_panel', 'view_any_student', 'view_student', 'update_student', 'delete_student');

    $this->delete = app(DeleteStudentAction::class);

    /** A student with one enrolment in the given status and a bill of exactly $billed, $paid of it settled. */
    $this->studentWith = function (EnrollmentStatus $status, ?string $billed = null, string $paid = '0.000'): Student {
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->for($student)->create(['status' => $status]);

        if ($billed !== null) {
            $charge = Charge::factory()->create([
                'enrollment_id' => $enrollment->getKey(),
                'list_price' => $billed,
                'amount' => $billed,
            ]);

            if ($paid !== '0.000') {
                PaymentAllocation::factory()->create([
                    'payment_id' => Payment::factory()->create()->getKey(),
                    'charge_id' => $charge->getKey(),
                    'amount' => $paid,
                ]);
            }
        }

        return $student;
    };
});

/*
|--------------------------------------------------------------------------
| The Action
|--------------------------------------------------------------------------
*/

it('refuses to delete a student with an active enrolment, and writes nothing', function () {
    $student = ($this->studentWith)(EnrollmentStatus::Active);
    $logged = Activity::query()->count();

    try {
        $this->delete->execute($this->remover, $student);

        $this->fail('Deleting a student with an active enrolment was not refused.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toBe(['student' => [ACTIVE_ENROLMENT_REFUSAL]]);
    }

    expect($student->fresh()->trashed())->toBeFalse()
        ->and(Activity::query()->count())->toBe($logged);
});

it('refuses to delete a student who owes money, even with no enrolment still active', function (EnrollmentStatus $status) {
    // Withdrawal is not a financial event: the bill stands (design 4).
    $student = ($this->studentWith)($status, '1000.000', '400.000');
    $logged = Activity::query()->count();

    try {
        $this->delete->execute($this->remover, $student);

        $this->fail('Deleting a student who owes 600.000 was not refused.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toBe(['student' => [OWES_REFUSAL]]);
    }

    expect($student->fresh()->trashed())->toBeFalse()
        ->and(Activity::query()->count())->toBe($logged);
})->with([EnrollmentStatus::Withdrawn, EnrollmentStatus::Completed]);

it('refuses a student who owes a single thousandth of a dinar', function () {
    // The currency has three decimals. A balance read through two, or a float,
    // would let this one through.
    $student = ($this->studentWith)(EnrollmentStatus::Withdrawn, '1000.000', '999.999');

    expect(fn () => $this->delete->execute($this->remover, $student))->toThrow(ValidationException::class);

    expect($student->fresh()->trashed())->toBeFalse();
});

it('deletes a student who owes nothing and has no active enrolment', function (EnrollmentStatus $status) {
    $student = ($this->studentWith)($status, '1000.000', '1000.000');

    $this->delete->execute($this->remover, $student);

    // Soft deleted: the row leaves the register, not the database.
    expect($student->fresh()->trashed())->toBeTrue()
        ->and(Student::withTrashed()->whereKey($student->getKey())->exists())->toBeTrue();
})->with([EnrollmentStatus::Withdrawn, EnrollmentStatus::Completed]);

it('deletes a student with no enrolments at all', function () {
    $student = Student::factory()->create();

    $this->delete->execute($this->remover, $student);

    expect($student->fresh()->trashed())->toBeTrue();
});

it('deletes a student whose enrolment was never billed', function () {
    $student = ($this->studentWith)(EnrollmentStatus::Withdrawn);

    $this->delete->execute($this->remover, $student);

    expect($student->fresh()->trashed())->toBeTrue();
});

it('still refuses an actor without delete_student, so the rule did not loosen authorization', function () {
    // The pair for the deletes above: one grant fewer. view_student is the nearest
    // grant the student resource hands out.
    $viewer = ($this->userWith)('view_student');
    $student = Student::factory()->create();

    expect(fn () => $this->delete->execute($viewer, $student))->toThrow(AuthorizationException::class);

    expect($student->fresh()->trashed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The two buttons
|--------------------------------------------------------------------------
*/

/** The toast a refusal shows: the rule's sentence, red, and staying until it is dismissed. */
function studentDeleteRefusal(string $message): Notification
{
    return Notification::make()->title($message)->danger()->persistent();
}

it('refuses from the row action with a persistent danger toast', function (string $message, string $case) {
    $student = $case === 'active'
        ? ($this->studentWith)(EnrollmentStatus::Active)
        : ($this->studentWith)(EnrollmentStatus::Withdrawn, '500.000');

    Livewire::actingAs($this->listRemover)
        ->test(ListStudents::class)
        ->callTableAction('delete', $student)
        ->assertNotified(studentDeleteRefusal($message));

    expect($student->fresh()->trashed())->toBeFalse();
})->with([
    'active enrolment' => [ACTIVE_ENROLMENT_REFUSAL, 'active'],
    'owes money' => [OWES_REFUSAL, 'owes'],
]);

it('deletes from the row action when nothing is owed and nothing is active', function () {
    $student = ($this->studentWith)(EnrollmentStatus::Completed, '500.000', '500.000');

    Livewire::actingAs($this->listRemover)
        ->test(ListStudents::class)
        ->callTableAction('delete', $student)
        ->assertNotified();

    expect($student->fresh()->trashed())->toBeTrue();
});

it('refuses from the edit page, stays on it, and shows a persistent danger toast', function (string $message, string $case) {
    $student = $case === 'active'
        ? ($this->studentWith)(EnrollmentStatus::Active)
        : ($this->studentWith)(EnrollmentStatus::Withdrawn, '500.000');

    Livewire::actingAs($this->editRemover)
        ->test(EditStudent::class, ['record' => $student->getKey()])
        ->callAction('delete')
        ->assertNotified(studentDeleteRefusal($message))
        ->assertNoRedirect();

    expect($student->fresh()->trashed())->toBeFalse();
})->with([
    'active enrolment' => [ACTIVE_ENROLMENT_REFUSAL, 'active'],
    'owes money' => [OWES_REFUSAL, 'owes'],
]);

it('deletes from the edit page when nothing is owed and nothing is active', function () {
    $student = ($this->studentWith)(EnrollmentStatus::Completed, '500.000', '500.000');

    Livewire::actingAs($this->editRemover)
        ->test(EditStudent::class, ['record' => $student->getKey()])
        ->callAction('delete')
        ->assertRedirect();

    expect($student->fresh()->trashed())->toBeTrue();
});
