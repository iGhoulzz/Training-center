<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\AssignInstructorAction;
use App\Domain\Enrollment\Actions\DeleteBatchAction;
use App\Domain\Enrollment\Data\AssignInstructorData;
use App\Domain\Enrollment\Exceptions\BatchInUseException;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->admin = ($this->actorWith)('admin');
    $this->course = Course::factory()->create(['total_hours' => 30]);
    $this->batch = Batch::factory()->for($this->course)->active()->create();

    $this->delete = app(DeleteBatchAction::class);
});

it('deletes a batch that nothing references', function () {
    // THE POSITIVE CONTROL. Every refusal below would also hold if the Action
    // refused everything, so this is what makes those refusals mean something.
    $this->delete->execute($this->admin, $this->batch);

    expect(Batch::whereKey($this->batch->getKey())->exists())->toBeFalse();
});

it('refuses an actor without delete_batch', function () {
    $this->delete->execute(($this->actorWith)('staff'), $this->batch);
})->throws(AuthorizationException::class);

it('refuses a batch that still has enrollments', function () {
    Enrollment::factory()->for($this->batch)->create();

    try {
        $this->delete->execute($this->admin, $this->batch);
        $thrown = null;
    } catch (BatchInUseException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(BatchInUseException::class)
        ->and(Batch::whereKey($this->batch->getKey())->exists())->toBeTrue();
});

it('refuses a batch that still has instructor allocations', function () {
    // Since P1-T11 batch_instructor.batch_id restricts too: those hours are what
    // phase 2 pays wages from.
    $instructor = User::factory()->create(['is_active' => true]);
    StaffProfile::factory()->for($instructor)->instructor()->create();

    app(AssignInstructorAction::class)->execute($this->admin, new AssignInstructorData(
        (int) $this->batch->getKey(),
        (int) $instructor->getKey(),
        30,
    ));

    $this->delete->execute($this->admin, $this->batch);
})->throws(BatchInUseException::class);

it('converts a real foreign key refusal that the pre-check missed', function () {
    /*
     * REACHES THE CATCH, NOT THE PRE-CHECK.
     *
     * The enrolment is inserted from a deleting() listener, so it lands after
     * enrollments()->exists() has already returned false and before MySQL runs
     * the DELETE. That is the race the foreign key exists to win, and it is the
     * only way into this branch — inserting beforehand tests the pre-check
     * instead, which is the mistake P1-T09c caught in the course equivalent.
     */
    Batch::deleting(function (Batch $batch): void {
        DB::table('enrollments')->insert([
            'student_id' => Student::factory()->create()->getKey(),
            'batch_id' => $batch->getKey(),
            'enrolled_at' => now(),
            'status' => 'active',
            /*
             * P2-T01: enrollments.reference is NOT NULL UNIQUE, and this row is
             * inserted through the query builder precisely to bypass the model,
             * so neither EnrollmentFactory nor EnrollStudentAction supplies one.
             * A literal stands in for the row another connection committed. It
             * is deliberately NOT a placeholder: an acceptance test asserts no
             * row anywhere holds one, and it is deliberately out of the range a
             * generated reference can reach, so it cannot collide with one.
             */
            'reference' => 'ENR-2026-999998',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $this->delete->execute($this->admin, $this->batch);
        $thrown = null;
    } catch (BatchInUseException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(BatchInUseException::class)
        ->and(Batch::whereKey($this->batch->getKey())->exists())->toBeTrue();
});

it('rethrows a database error that is not a foreign key restriction', function () {
    /*
     * Only 1451 means "still in use". A deadlock, a lost connection or a
     * disk-full error must not be reported as a batch with enrolments — that is
     * a reassuring message about a completely different problem, and it hides an
     * outage.
     */
    Batch::deleting(function (): void {
        $previous = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout');
        $previous->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

        throw new QueryException('mysql', 'delete from `batches` ...', [], $previous);
    });

    $this->delete->execute($this->admin, $this->batch);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| Both Filament surfaces, which share one action
|--------------------------------------------------------------------------
*/

it('refuses deletion from the batch table and says why', function () {
    Enrollment::factory()->for($this->batch)->create();

    Livewire::actingAs($this->admin)
        ->test(ListBatches::class)
        ->callTableAction('delete', $this->batch)
        ->assertNotified(__('enrollment.batch_in_use'));

    expect(Batch::whereKey($this->batch->getKey())->exists())->toBeTrue();
});

it('does not navigate away from the edit page when the delete is refused', function () {
    /*
     * PROVES $action->halt() IS DOING SOMETHING — and it is NOT the success
     * notification, which is what this test originally asserted.
     *
     * DeleteAction::setUp() registers a default ->action() closure that calls
     * $this->success(). Overriding ->action() replaces that closure outright, so
     * the "Deleted" notification is never sent whether halt() is there or not:
     * asserting its absence passes vacuously and proves nothing. Verified by
     * removing halt() and watching that assertion stay green.
     *
     * What halt() actually prevents is the REDIRECT. successRedirectUrl() sends
     * the edit page to the listing once the action completes, so without halt()
     * a refused delete navigates the user away as though it had worked, while
     * the batch is still sitting there. Removing halt() fails this line.
     */
    Enrollment::factory()->for($this->batch)->create();

    Livewire::actingAs($this->admin)
        ->test(EditBatch::class, ['record' => $this->batch->getKey()])
        ->callAction('delete')
        ->assertNotified(__('enrollment.batch_in_use'))
        ->assertNoRedirect();

    expect(Batch::whereKey($this->batch->getKey())->exists())->toBeTrue();
});

it('refuses deletion from the batch edit page and says why', function () {
    Enrollment::factory()->for($this->batch)->create();

    Livewire::actingAs($this->admin)
        ->test(EditBatch::class, ['record' => $this->batch->getKey()])
        ->callAction('delete')
        ->assertNotified(__('enrollment.batch_in_use'));

    expect(Batch::whereKey($this->batch->getKey())->exists())->toBeTrue();
});

it('deletes from the batch table when nothing references the batch', function () {
    // The positive control for the UI path, matching the one for the Action.
    Livewire::actingAs($this->admin)
        ->test(ListBatches::class)
        ->callTableAction('delete', $this->batch);

    expect(Batch::whereKey($this->batch->getKey())->exists())->toBeFalse();
});

it('deletes from the edit page and redirects to the listing', function () {
    // The edit page's delete must navigate away: staying on the edit form of a
    // record that no longer exists 404s on the next interaction.
    Livewire::actingAs($this->admin)
        ->test(EditBatch::class, ['record' => $this->batch->getKey()])
        ->callAction('delete')
        ->assertRedirect(BatchResource::getUrl('index'));

    expect(Batch::whereKey($this->batch->getKey())->exists())->toBeFalse();
});
