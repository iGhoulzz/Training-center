<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Authorization for the course catalogue, asserted in both directions for every
 * role. The negative assertions carry the weight: a policy that grants correctly
 * but denies nothing reads as working right up until someone checks.
 *
 * The staff row is the interesting one. Front-desk staff read the catalogue all
 * day — "do you run English B1, and how long is it" — but they do not decide
 * what the centre offers, so they hold view only. Unlike students, where staff
 * also hold create, there is no create here at all.
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

    $this->studentUser = User::factory()->create(['is_active' => true]);
    $system->assignRoles($this->studentUser, 'student');

    $this->course = Course::factory()->create();
});

it('lets a super admin fully manage courses', function () {
    expect($this->superAdmin->can('viewAny', Course::class))->toBeTrue()
        ->and($this->superAdmin->can('view', $this->course))->toBeTrue()
        ->and($this->superAdmin->can('create', Course::class))->toBeTrue()
        ->and($this->superAdmin->can('update', $this->course))->toBeTrue()
        ->and($this->superAdmin->can('delete', $this->course))->toBeTrue();
});

it('lets an admin fully manage courses', function () {
    expect($this->admin->can('viewAny', Course::class))->toBeTrue()
        ->and($this->admin->can('view', $this->course))->toBeTrue()
        ->and($this->admin->can('create', Course::class))->toBeTrue()
        ->and($this->admin->can('update', $this->course))->toBeTrue()
        ->and($this->admin->can('delete', $this->course))->toBeTrue();
});

it('lets staff read the catalogue but change nothing in it', function () {
    // Deliberately narrower than the student register, where staff also hold
    // create. Defining what the centre sells is an administrative act; quoting
    // it to a walk-in is front-desk work.
    expect($this->staff->can('viewAny', Course::class))->toBeTrue()
        ->and($this->staff->can('view', $this->course))->toBeTrue()
        ->and($this->staff->can('create', Course::class))->toBeFalse()
        ->and($this->staff->can('update', $this->course))->toBeFalse()
        ->and($this->staff->can('delete', $this->course))->toBeFalse();
});

it('gives the student role no access to courses at all', function () {
    // The portal is phase 3, on a separate panel with a separate guard. On the
    // staff dashboard the student role holds nothing.
    expect($this->studentUser->can('viewAny', Course::class))->toBeFalse()
        ->and($this->studentUser->can('view', $this->course))->toBeFalse()
        ->and($this->studentUser->can('create', Course::class))->toBeFalse()
        ->and($this->studentUser->can('update', $this->course))->toBeFalse()
        ->and($this->studentUser->can('delete', $this->course))->toBeFalse();
});

it('denies a user with no role anything', function () {
    $unroled = User::factory()->create(['is_active' => true]);

    expect($unroled->can('viewAny', Course::class))->toBeFalse()
        ->and($unroled->can('view', $this->course))->toBeFalse()
        ->and($unroled->can('create', Course::class))->toBeFalse()
        ->and($unroled->can('update', $this->course))->toBeFalse()
        ->and($unroled->can('delete', $this->course))->toBeFalse();
});

it('authorizes on the permission, not the role name', function () {
    // The project's first non-negotiable. A user with no role at all, holding
    // the permissions directly, is authorized; without them it is not.
    $unroled = User::factory()->create(['is_active' => true]);

    $unroled->givePermissionTo('view_any_course', 'view_course');

    expect($unroled->fresh()?->can('viewAny', Course::class))->toBeTrue()
        ->and($unroled->fresh()?->can('view', $this->course))->toBeTrue()
        // Still denied everything it was not granted.
        ->and($unroled->fresh()?->can('create', Course::class))->toBeFalse()
        ->and($unroled->fresh()?->can('update', $this->course))->toBeFalse()
        ->and($unroled->fresh()?->can('delete', $this->course))->toBeFalse();
});

it('treats update and delete as separate grants', function () {
    // Reaching the edit page does not imply the right to delete from it, which
    // is why the delete action authorizes rather than merely hiding — and why
    // it lives on the table row, reachable without update_course.
    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('view_any_course', 'view_course', 'update_course');

    $remover = User::factory()->create(['is_active' => true]);
    $remover->givePermissionTo('view_any_course', 'view_course', 'delete_course');

    expect($editor->fresh()?->can('update', $this->course))->toBeTrue()
        ->and($editor->fresh()?->can('delete', $this->course))->toBeFalse()
        ->and($remover->fresh()?->can('delete', $this->course))->toBeTrue()
        ->and($remover->fresh()?->can('update', $this->course))->toBeFalse();
});
