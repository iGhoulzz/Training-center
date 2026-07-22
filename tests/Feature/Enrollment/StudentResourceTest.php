<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;

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

it('refuses staff the create and edit pages', function () {
    // The register is readable, the record is not writable. Both pages abort
    // 403 in their own authorizeAccess(), not merely by hiding a button.
    $student = Student::factory()->create();
    $staff = ($this->makeUser)('staff');

    $this->actingAs($staff)->get('/admin/students/create')->assertForbidden();
    $this->actingAs($staff)->get("/admin/students/{$student->getKey()}/edit")->assertForbidden();
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

it('hides and refuses the delete action for an editor who may not delete', function () {
    // Hidden is not the same as absent — a crafted Livewire mount ignores
    // visible(). authorize('delete') is what refuses it, and the record must
    // still be there afterwards. Catch ONLY the visibility failure; a broader
    // catch would let this pass for the wrong reason.
    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('access_admin_panel', 'view_any_student', 'view_student', 'update_student');

    $student = Student::factory()->create();

    $component = Livewire::actingAs($editor->fresh())
        ->test(EditStudent::class, ['record' => $student->getKey()]);

    $component->assertActionHidden('delete');

    expect(fn () => $component->callAction('delete'))
        ->toThrow(ExpectationFailedException::class);

    expect(Student::whereKey($student->getKey())->exists())->toBeTrue();
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
