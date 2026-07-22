<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Authorization for student records, asserted in both directions for every
 * role. The negative assertions carry the weight: a policy that grants
 * correctly but denies nothing reads as working right up until someone checks.
 *
 * Roles are assigned through SystemRoleWriter, the trusted actorless path, for
 * the same reason the seeder uses it — there is no acting principal in a
 * fixture, so the request-path Actions are the wrong tool.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $system = app(SystemRoleWriter::class);

    $this->superAdmin = User::factory()->create(['is_active' => true]);
    $system->assignRoles($this->superAdmin, 'super_admin');

    $this->admin = User::factory()->create(['is_active' => true]);
    $system->assignRoles($this->admin, 'admin');

    $this->staff = User::factory()->create(['is_active' => true]);
    $system->assignRoles($this->staff, 'staff');

    $this->student = User::factory()->create(['is_active' => true]);
    $system->assignRoles($this->student, 'student');

    $this->record = Student::factory()->create();
});

it('lets a super admin fully manage students', function () {
    expect($this->superAdmin->can('viewAny', Student::class))->toBeTrue()
        ->and($this->superAdmin->can('view', $this->record))->toBeTrue()
        ->and($this->superAdmin->can('create', Student::class))->toBeTrue()
        ->and($this->superAdmin->can('update', $this->record))->toBeTrue()
        ->and($this->superAdmin->can('delete', $this->record))->toBeTrue();
});

it('lets an admin fully manage students', function () {
    expect($this->admin->can('viewAny', Student::class))->toBeTrue()
        ->and($this->admin->can('view', $this->record))->toBeTrue()
        ->and($this->admin->can('create', Student::class))->toBeTrue()
        ->and($this->admin->can('update', $this->record))->toBeTrue()
        ->and($this->admin->can('delete', $this->record))->toBeTrue();
});

it('lets staff read every student and register new ones, but amend none', function () {
    // The read is deliberately unscoped: front-desk staff answer questions
    // about whoever walks in, so it is NOT limited to the batches they teach.
    //
    // create WITHOUT update is the deliberate part. Registering a walk-in is
    // front-desk work; correcting or removing an existing record is an
    // administrative act, and the two are separate grants.
    expect($this->staff->can('viewAny', Student::class))->toBeTrue()
        ->and($this->staff->can('view', $this->record))->toBeTrue()
        ->and($this->staff->can('create', Student::class))->toBeTrue()
        ->and($this->staff->can('update', $this->record))->toBeFalse()
        ->and($this->staff->can('delete', $this->record))->toBeFalse();
});

it('gives the student role no access to student records at all', function () {
    // The portal is phase 3, on a separate panel with a separate guard. On the
    // staff dashboard the student role holds nothing.
    expect($this->student->can('viewAny', Student::class))->toBeFalse()
        ->and($this->student->can('view', $this->record))->toBeFalse()
        ->and($this->student->can('create', Student::class))->toBeFalse()
        ->and($this->student->can('update', $this->record))->toBeFalse()
        ->and($this->student->can('delete', $this->record))->toBeFalse();
});

it('denies a user with no role anything', function () {
    $unroled = User::factory()->create(['is_active' => true]);

    expect($unroled->can('viewAny', Student::class))->toBeFalse()
        ->and($unroled->can('view', $this->record))->toBeFalse()
        ->and($unroled->can('create', Student::class))->toBeFalse()
        ->and($unroled->can('update', $this->record))->toBeFalse()
        ->and($unroled->can('delete', $this->record))->toBeFalse();
});

it('authorizes on the permission, not the role name', function () {
    // The project's first non-negotiable. A user with no role at all, holding
    // the permissions directly, is authorized; without them it is not.
    $unroled = User::factory()->create(['is_active' => true]);

    $unroled->givePermissionTo('view_any_student', 'view_student');

    expect($unroled->fresh()?->can('viewAny', Student::class))->toBeTrue()
        ->and($unroled->fresh()?->can('view', $this->record))->toBeTrue()
        // Still denied everything it was not granted.
        ->and($unroled->fresh()?->can('create', Student::class))->toBeFalse()
        ->and($unroled->fresh()?->can('update', $this->record))->toBeFalse()
        ->and($unroled->fresh()?->can('delete', $this->record))->toBeFalse();
});

it('treats update and delete as separate grants', function () {
    // Reaching the edit page does not imply the right to delete from it, which
    // is why EditStudent's delete action authorizes rather than merely hiding.
    $editor = User::factory()->create(['is_active' => true]);

    $editor->givePermissionTo('view_any_student', 'view_student', 'update_student');

    expect($editor->fresh()?->can('update', $this->record))->toBeTrue()
        ->and($editor->fresh()?->can('delete', $this->record))->toBeFalse();
});
