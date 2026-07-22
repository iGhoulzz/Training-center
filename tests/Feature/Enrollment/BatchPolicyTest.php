<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Authorization for batches, asserted in both directions for every role. The
 * negative assertions carry the weight: a policy that grants correctly but
 * denies nothing reads as working right up until someone checks.
 *
 * assignInstructor() is the interesting one — the only record-dependent rule
 * here, and the only place the spec's status gate applies. See BatchPolicy for
 * why update() deliberately does not gate on status.
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

    $this->batch = Batch::factory()->active()->create();
});

it('lets a super admin fully manage batches', function () {
    expect($this->superAdmin->can('viewAny', Batch::class))->toBeTrue()
        ->and($this->superAdmin->can('view', $this->batch))->toBeTrue()
        ->and($this->superAdmin->can('create', Batch::class))->toBeTrue()
        ->and($this->superAdmin->can('update', $this->batch))->toBeTrue()
        ->and($this->superAdmin->can('delete', $this->batch))->toBeTrue()
        ->and($this->superAdmin->can('assignInstructor', $this->batch))->toBeTrue();
});

it('lets an admin fully manage batches', function () {
    expect($this->admin->can('viewAny', Batch::class))->toBeTrue()
        ->and($this->admin->can('view', $this->batch))->toBeTrue()
        ->and($this->admin->can('create', Batch::class))->toBeTrue()
        ->and($this->admin->can('update', $this->batch))->toBeTrue()
        ->and($this->admin->can('delete', $this->batch))->toBeTrue()
        ->and($this->admin->can('assignInstructor', $this->batch))->toBeTrue();
});

it('lets staff read the schedule but change nothing in it', function () {
    // Front-desk staff answer "when does the next English B1 start". They do
    // not schedule intakes, and they certainly do not decide whose teaching
    // hours go against one — assign_instructor is not theirs.
    expect($this->staff->can('viewAny', Batch::class))->toBeTrue()
        ->and($this->staff->can('view', $this->batch))->toBeTrue()
        ->and($this->staff->can('create', Batch::class))->toBeFalse()
        ->and($this->staff->can('update', $this->batch))->toBeFalse()
        ->and($this->staff->can('delete', $this->batch))->toBeFalse()
        ->and($this->staff->can('assignInstructor', $this->batch))->toBeFalse();
});

it('gives the student role no access to batches at all', function () {
    expect($this->studentUser->can('viewAny', Batch::class))->toBeFalse()
        ->and($this->studentUser->can('view', $this->batch))->toBeFalse()
        ->and($this->studentUser->can('create', Batch::class))->toBeFalse()
        ->and($this->studentUser->can('update', $this->batch))->toBeFalse()
        ->and($this->studentUser->can('delete', $this->batch))->toBeFalse()
        ->and($this->studentUser->can('assignInstructor', $this->batch))->toBeFalse();
});

it('denies a user with no role anything', function () {
    $unroled = User::factory()->create(['is_active' => true]);

    expect($unroled->can('viewAny', Batch::class))->toBeFalse()
        ->and($unroled->can('view', $this->batch))->toBeFalse()
        ->and($unroled->can('create', Batch::class))->toBeFalse()
        ->and($unroled->can('update', $this->batch))->toBeFalse()
        ->and($unroled->can('delete', $this->batch))->toBeFalse()
        ->and($unroled->can('assignInstructor', $this->batch))->toBeFalse();
});

it('authorizes on the permission, not the role name', function () {
    // The project's first non-negotiable. A user with no role at all, holding
    // the permissions directly, is authorized; without them it is not.
    $unroled = User::factory()->create(['is_active' => true]);

    $unroled->givePermissionTo('view_any_batch', 'view_batch');

    expect($unroled->fresh()?->can('viewAny', Batch::class))->toBeTrue()
        ->and($unroled->fresh()?->can('view', $this->batch))->toBeTrue()
        ->and($unroled->fresh()?->can('create', Batch::class))->toBeFalse()
        ->and($unroled->fresh()?->can('update', $this->batch))->toBeFalse()
        ->and($unroled->fresh()?->can('delete', $this->batch))->toBeFalse()
        ->and($unroled->fresh()?->can('assignInstructor', $this->batch))->toBeFalse();
});

it('treats update and delete as separate grants', function () {
    // Reaching the edit page does not imply the right to delete from it, which
    // is why the delete action authorizes rather than merely hiding — and why
    // it lives on the table row, reachable without update_batch.
    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('view_any_batch', 'view_batch', 'update_batch');

    $remover = User::factory()->create(['is_active' => true]);
    $remover->givePermissionTo('view_any_batch', 'view_batch', 'delete_batch');

    expect($editor->fresh()?->can('update', $this->batch))->toBeTrue()
        ->and($editor->fresh()?->can('delete', $this->batch))->toBeFalse()
        ->and($remover->fresh()?->can('delete', $this->batch))->toBeTrue()
        ->and($remover->fresh()?->can('update', $this->batch))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| assignInstructor: the one rule that reads the record
|--------------------------------------------------------------------------
*/

it('allows assigning an instructor to an open batch', function () {
    expect($this->admin->can('assignInstructor', Batch::factory()->create()))->toBeTrue()
        ->and($this->admin->can('assignInstructor', Batch::factory()->active()->create()))->toBeTrue();
});

it('refuses assigning an instructor to a completed or cancelled batch', function () {
    // Spec section 6: completed and cancelled batches reject instructor
    // changes. Reassigning who taught a finished course is rewriting history,
    // and from phase 2 it is rewriting what somebody is owed.
    expect($this->admin->can('assignInstructor', Batch::factory()->completed()->create()))->toBeFalse()
        ->and($this->admin->can('assignInstructor', Batch::factory()->cancelled()->create()))->toBeFalse()
        // Even a super admin, because this is not a rank question. The batch is
        // closed for everyone.
        ->and($this->superAdmin->can('assignInstructor', Batch::factory()->completed()->create()))->toBeFalse();
});

it('requires both the permission and an open batch to assign an instructor', function () {
    // Two independent conditions; neither alone is enough. An actor with the
    // permission is refused on a closed batch, and an actor without it is
    // refused on an open one.
    $assigner = User::factory()->create(['is_active' => true]);
    $assigner->givePermissionTo('view_any_batch', 'view_batch', 'assign_instructor');

    $manager = User::factory()->create(['is_active' => true]);
    $manager->givePermissionTo('view_any_batch', 'view_batch', 'update_batch', 'delete_batch');

    $open = Batch::factory()->active()->create();
    $closed = Batch::factory()->completed()->create();

    expect($assigner->fresh()?->can('assignInstructor', $open))->toBeTrue()
        ->and($assigner->fresh()?->can('assignInstructor', $closed))->toBeFalse()
        // update_batch is not assign_instructor: editing the schedule is not
        // the same capability as deciding whose paid hours go against it.
        ->and($manager->fresh()?->can('assignInstructor', $open))->toBeFalse();
});

it('still allows editing a closed batch so a mis-set status can be corrected', function () {
    // A deliberate divergence from the Task 9 plan text, recorded here because
    // it is a decision rather than an omission. The spec gates ENROLMENTS and
    // INSTRUCTOR CHANGES on status, not the record itself. Gating update() too
    // would freeze a completed batch — including its own status column — so a
    // mis-clicked "completed" could never be undone from the application, and a
    // typo in a finished batch's dates would need raw SQL.
    $completed = Batch::factory()->completed()->create();
    $cancelled = Batch::factory()->cancelled()->create();

    expect($this->admin->can('update', $completed))->toBeTrue()
        ->and($this->admin->can('update', $cancelled))->toBeTrue()
        // The gate that the spec DOES ask for is still shut on both.
        ->and($this->admin->can('assignInstructor', $completed))->toBeFalse()
        ->and($this->admin->can('assignInstructor', $cancelled))->toBeFalse();
});
