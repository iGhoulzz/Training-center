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

    // FIRST: prove the action is resolvable for someone allowed to use it.
    // InteractsWithActions::mountAction() unmounts and returns null in three
    // different cases — unresolvable name, disabled, and unauthorized — so a
    // probe that only asserts "nothing happened" cannot tell a refusal from a
    // typo. Without this control the test below is vacuous.
    $admin = ($this->makeUser)('admin');

    $control = Livewire::actingAs($admin)
        ->test(EditStudent::class, ['record' => $student->getKey()]);
    $control->call('mountAction', 'delete');
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The delete action did not resolve even for an admin — the probe below would prove nothing.'
    );

    // NOW the real probe, with the same action name on the same page.
    $component = Livewire::actingAs($editor->fresh())
        ->test(EditStudent::class, ['record' => $student->getKey()]);

    $component->assertActionHidden('delete');

    $component->call('mountAction', 'delete');

    // Refused at mount: nothing is on the stack to execute.
    expect($component->get('mountedActions'))->toBeEmpty();

    // Call it anyway, the way a crafted client would after a failed mount.
    $component->call('callMountedAction');

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
    $student = Student::factory()->create();

    // A table action only resolves with table context and the record key —
    // mountAction('delete') alone silently fails to resolve on a list page,
    // which would make this probe pass for the wrong reason.
    $context = ['table' => true, 'recordKey' => (string) $student->getKey()];

    // Control: the action resolves for an actor who may delete.
    $control = Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListStudents::class);
    $control->call('mountAction', 'delete', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The table delete action did not resolve even for an admin — the probe below would prove nothing.'
    );

    $viewer = User::factory()->create(['is_active' => true]);
    $viewer->givePermissionTo('access_admin_panel', 'view_any_student', 'view_student');

    $component = Livewire::actingAs($viewer->fresh())->test(ListStudents::class);
    $component->call('mountAction', 'delete', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(Student::whereKey($student->getKey())->exists())->toBeTrue()
        ->and(Student::withTrashed()->whereKey($student->getKey())->first()->trashed())->toBeFalse();
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

it('lets staff actually submit the create form, not merely open it', function () {
    // A 200 on GET /create only proves the page renders. The grant is real
    // only if the submission persists.
    Livewire::actingAs(($this->makeUser)('staff'))
        ->test(CreateStudent::class)
        ->fillForm([
            'student_code' => 'STU-STAFF-0001',
            'first_name' => 'Walk',
            'last_name' => 'In',
            'status' => 'prospective',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Student::where('student_code', 'STU-STAFF-0001')->exists())->toBeTrue();
});

it('ignores a user_id smuggled into the create payload', function () {
    // user_id is not on the form: linking a login is phase 3's job, and a
    // client-supplied value would let a student record be attached to any
    // account — including an administrator's.
    $victim = User::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateStudent::class)
        ->fillForm([
            'student_code' => 'STU-CRAFTED-0001',
            'first_name' => 'Crafted',
            'last_name' => 'Payload',
            'status' => 'prospective',
            'user_id' => $victim->getKey(),
        ])
        ->call('create');

    $student = Student::where('student_code', 'STU-CRAFTED-0001')->first();

    expect($student)->not->toBeNull()
        ->and($student->user_id)->toBeNull();
});

it('ignores a user_id smuggled into an edit payload', function () {
    $victim = User::factory()->create();
    $student = Student::factory()->create(['user_id' => null]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditStudent::class, ['record' => $student->getKey()])
        ->fillForm(['user_id' => $victim->getKey()])
        ->call('save');

    expect($student->fresh()->user_id)->toBeNull();
});

it('saves a real edit made by an admin', function () {
    // The refusal paths are covered above; this is the positive control that
    // proves the edit page is not simply broken for everyone.
    $student = Student::factory()->create([
        'first_name' => 'Amal',
        'last_name' => 'Ibrahim',
    ]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditStudent::class, ['record' => $student->getKey()])
        ->fillForm([
            'first_name' => 'Amel',
            'status' => 'active',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $student->refresh();

    expect($student->first_name)->toBe('Amel')
        ->and($student->last_name)->toBe('Ibrahim')
        ->and($student->status->value)->toBe('active');
});

it('gates each resource page on its own permission', function () {
    // The four pages are separate grants. An actor holding only view must not
    // reach create or edit, and each refusal must come from the page itself.
    $student = Student::factory()->create();

    $viewer = User::factory()->create(['is_active' => true]);
    $viewer->givePermissionTo('access_admin_panel', 'view_any_student', 'view_student');

    $creator = User::factory()->create(['is_active' => true]);
    $creator->givePermissionTo('access_admin_panel', 'view_any_student', 'view_student', 'create_student');

    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('access_admin_panel', 'view_any_student', 'view_student', 'update_student');

    $key = $student->getKey();

    $this->actingAs($viewer->fresh())->get('/admin/students')->assertSuccessful();
    $this->actingAs($viewer->fresh())->get("/admin/students/{$key}")->assertSuccessful();
    $this->actingAs($viewer->fresh())->get('/admin/students/create')->assertForbidden();
    $this->actingAs($viewer->fresh())->get("/admin/students/{$key}/edit")->assertForbidden();

    $this->actingAs($creator->fresh())->get('/admin/students/create')->assertSuccessful();
    $this->actingAs($creator->fresh())->get("/admin/students/{$key}/edit")->assertForbidden();

    $this->actingAs($editor->fresh())->get("/admin/students/{$key}/edit")->assertSuccessful();
    $this->actingAs($editor->fresh())->get('/admin/students/create')->assertForbidden();
});
