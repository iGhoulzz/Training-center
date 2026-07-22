<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * The student register as it is actually reachable over HTTP.
 *
 * The policy tests prove the rules; these prove the resource is wired to them.
 * Both are needed — a correct policy nobody consults denies nothing.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->makeUser = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };
});

it('lets an admin reach the student register', function () {
    $this->actingAs(($this->makeUser)('admin'))
        ->get('/admin/students')
        ->assertSuccessful();
});

it('lets staff reach the student register', function () {
    // Confirmed by the centre: front-desk staff see every student, not only
    // the ones on their own batches.
    $this->actingAs(($this->makeUser)('staff'))
        ->get('/admin/students')
        ->assertSuccessful();
});

it('denies a student-role user the register', function () {
    // Denied twice over: the student role holds neither access_admin_panel nor
    // any student permission.
    $this->actingAs(($this->makeUser)('student'))
        ->get('/admin/students')
        ->assertForbidden();
});

it('lets staff open a student record read only', function () {
    $student = Student::factory()->create();

    $this->actingAs(($this->makeUser)('staff'))
        ->get("/admin/students/{$student->getKey()}")
        ->assertSuccessful();
});

it('lets an admin open the create and edit pages', function () {
    $student = Student::factory()->create();
    $admin = ($this->makeUser)('admin');

    $this->actingAs($admin)->get('/admin/students/create')->assertSuccessful();
    $this->actingAs($admin)->get("/admin/students/{$student->getKey()}/edit")->assertSuccessful();
});

it('creates a student through the create page', function () {
    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateStudent::class)
        ->fillForm([
            'student_code' => 'STU-UI-0001',
            'first_name' => 'Amal',
            'last_name' => 'Ibrahim',
            'status' => 'prospective',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $student = Student::firstOrFail();

    expect($student->student_code)->toBe('STU-UI-0001')
        ->and($student->full_name)->toBe('Amal Ibrahim')
        ->and($student->status->value)->toBe('prospective')
        // Not settable from the form: linking a login is phase 3's job.
        ->and($student->user_id)->toBeNull();
});

it('refuses a delete mounted directly on the server by an editor who may not delete', function () {
    // A previous version called $component->callAction('delete') and expected
    // ExpectationFailedException. That proved only that Filament's TEST HELPER
    // asserts visibility before dispatching — it never reached the server, so
    // it would have passed even with no authorization at all.
    //
    // A crafted client does not use the helper: it sends the Livewire wire
    // call. mountAction() is that entry point, so this drives the real path.
    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('access_admin_panel', 'view_any_student', 'view_student', 'update_student');

    $student = Student::factory()->create();

    $component = Livewire::actingAs($editor->fresh())
        ->test(EditStudent::class, ['record' => $student->getKey()]);

    // Hidden in the UI...
    $component->assertActionHidden('delete');

    // ...and refused on the server when mounted anyway.
    $component->call('mountAction', 'delete');

    expect(Student::whereKey($student->getKey())->exists())->toBeTrue()
        ->and(Student::withTrashed()->whereKey($student->getKey())->first()->trashed())->toBeFalse();
});

it('lets an actor with delete but not update remove a student from the table', function () {
    // delete_student and update_student are separate grants. Because
    // EditRecord::authorizeAccess() demands update_student to open the edit
    // page, a delete action living only there would make delete_student
    // unreachable for this actor. It is on the table row for exactly this case.
    $remover = User::factory()->create(['is_active' => true]);
    $remover->givePermissionTo('access_admin_panel', 'view_any_student', 'view_student', 'delete_student');

    $student = Student::factory()->create();

    Livewire::actingAs($remover->fresh())
        ->test(ListStudents::class)
        ->callTableAction('delete', $student);

    expect(Student::count())->toBe(0)
        ->and(Student::withTrashed()->count())->toBe(1);
});

it('refuses a table delete mounted directly by an actor without the delete grant', function () {
    $viewer = User::factory()->create(['is_active' => true]);
    $viewer->givePermissionTo('access_admin_panel', 'view_any_student', 'view_student');

    $student = Student::factory()->create();

    Livewire::actingAs($viewer->fresh())
        ->test(ListStudents::class)
        ->call('mountAction', 'delete', ['record' => $student->getKey()]);

    expect(Student::whereKey($student->getKey())->exists())->toBeTrue();
});

it('lets staff create a student but refuses them the edit page', function () {
    // Staff hold view + create on students, deliberately without update or
    // delete: registering a walk-in is front-desk work, amending an existing
    // record is administrative.
    $staff = ($this->makeUser)('staff');
    $student = Student::factory()->create();

    $this->actingAs($staff)->get('/admin/students/create')->assertSuccessful();
    $this->actingAs($staff)
        ->get('/admin/students/'.$student->getKey().'/edit')
        ->assertForbidden();
});

it('refuses a crafted edit submitted by staff, leaving the record unchanged', function () {
    // The edit page is already refused above; this proves the refusal is not
    // merely a hidden link by driving the component directly.
    $staff = ($this->makeUser)('staff');
    $student = Student::factory()->create(['first_name' => 'Amal']);

    Livewire::actingAs($staff)
        ->test(EditStudent::class, ['record' => $student->getKey()])
        ->assertForbidden();

    expect($student->fresh()->first_name)->toBe('Amal');
});

it('lets an admin delete a student from the edit page', function () {
    $student = Student::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditStudent::class, ['record' => $student->getKey()])
        ->callAction('delete');

    expect(Student::count())->toBe(0)
        // Soft deleted: the row leaves the register, not the database.
        ->and(Student::withTrashed()->count())->toBe(1);
});
