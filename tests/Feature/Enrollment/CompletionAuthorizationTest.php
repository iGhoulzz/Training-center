<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Support\CompletionRule;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| CompletionRule, exercised directly — mirrors EnrollmentPolicyTest's shape
|--------------------------------------------------------------------------
|
| CompletionRule has no Policy wrapping it (there is no Filament CRUD
| ability named "complete" for a resource to authorize against); both Actions
| and the relation manager ask it directly. So this file plays the role
| EnrollmentPolicyTest plays for EnrollmentUpdateRule: it proves the rule's own
| branching, independent of whatever the seeder happens to grant any one role.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);
    $this->rule = app(CompletionRule::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    /** Put an actor on a batch's instructor list, without going near the Action. */
    $this->assignToBatch = function (User $actor, Batch $batch): void {
        $batch->instructors()->attach($actor->getKey(), ['assigned_hours' => 10]);
    };

    /**
     * A role holding EXACTLY the named permissions, and an actor wearing it.
     *
     * BESPOKE, NOT SEEDED — the whole point of this file. P1-T11's lesson is
     * that the unrestricted permission satisfies the rule's first branch and
     * the scoping never runs, so every scoped test using a role that ALSO
     * happens to hold the unrestricted grant would keep passing even if the
     * scoping were deleted outright. A role built to hold nothing but the
     * scoped ability is the only actor that can prove the scoping branch
     * itself works, independent of what RolePermissionSeeder decides to grant
     * 'staff' this month.
     */
    $this->actorWithPermissions = function (string $role, array $permissions): User {
        $this->system->syncRolePermissions(
            Role::findOrCreate($role, 'web'),
            collect($permissions)->map(fn (string $name) => Permission::findByName($name, 'web'))->all(),
        );

        $actor = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($actor, $role);

        return $actor->refresh();
    };

    $this->course = Course::factory()->create(['total_hours' => 30]);
    $this->batch = Batch::factory()->for($this->course)->active()->create();
});

/*
|--------------------------------------------------------------------------
| The seeded roles
|--------------------------------------------------------------------------
*/

it('lets an admin complete any enrolment, on any batch', function () {
    expect($this->rule->allows(($this->actorWith)('admin'), (int) $this->batch->getKey()))->toBeTrue();
});

it('lets staff complete an enrolment on a batch they are assigned to teach', function () {
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    ($this->assignToBatch)($staff, $this->batch);

    expect($this->rule->allows($staff, (int) $this->batch->getKey()))->toBeTrue();
});

it('denies a staff actor who does not teach the batch', function () {
    expect($this->rule->allows(($this->actorWith)('staff'), (int) $this->batch->getKey()))->toBeFalse();
});

it('denies a staff actor assigned to a DIFFERENT batch', function () {
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    ($this->assignToBatch)($staff, Batch::factory()->for($this->course)->active()->create());

    expect($this->rule->allows($staff, (int) $this->batch->getKey()))->toBeFalse();
});

it('does not grant the seeded staff role the unrestricted completion ability', function () {
    // If staff ever held complete_enrollment, the scoping branch would stop
    // running and every scoped test above would still pass regardless.
    $staff = ($this->actorWith)('staff');

    expect($staff->can('complete_enrollment'))->toBeFalse()
        ->and($staff->can('complete_assigned_batch_enrollment'))->toBeTrue();
});

it('denies an actor holding neither completion ability', function () {
    expect($this->rule->allows(($this->actorWith)('student'), (int) $this->batch->getKey()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Arbitrary roles — the rule must not know the seeded role names
|--------------------------------------------------------------------------
|
| Every test above uses the seeded roles. A rule hardcoded to
| hasRole('admin')/hasRole('staff') would pass every one of them. These use
| roles the seeder has never heard of, holding exactly one completion
| permission each — the bespoke-role proof the plan calls for explicitly.
*/

it('grants unrestricted completion to ANY role holding complete_enrollment', function () {
    $actor = ($this->actorWithPermissions)('registrar', ['complete_enrollment']);

    expect($this->rule->allows($actor, (int) $this->batch->getKey()))->toBeTrue();
});

it('grants scoped completion to ANY role holding only complete_assigned_batch_enrollment, when assigned', function () {
    $actor = ($this->actorWithPermissions)('visiting_tutor', ['complete_assigned_batch_enrollment']);
    ($this->assignToBatch)($actor, $this->batch);

    expect($this->rule->allows($actor, (int) $this->batch->getKey()))->toBeTrue();
});

it('denies that same bespoke role on a batch it is not assigned to', function () {
    // Without this, a rule returning true for anyone holding the scoped
    // permission — regardless of assignment — would pass the test above too.
    $actor = ($this->actorWithPermissions)('visiting_tutor', ['complete_assigned_batch_enrollment']);

    expect($this->rule->allows($actor, (int) $this->batch->getKey()))->toBeFalse();
});

it('grants nothing to a bespoke role holding neither completion permission', function () {
    $actor = ($this->actorWithPermissions)('observer', ['view_enrollment']);
    ($this->assignToBatch)($actor, $this->batch);

    expect($this->rule->allows($actor, (int) $this->batch->getKey()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Direct permissions, with no role at all
|--------------------------------------------------------------------------
|
| The arbitrary-role tests above prove the rule does not hardcode a SEEDED
| role name. They do not prove it asks $user->can() rather than, say,
| $user->roles->isNotEmpty(). These actors hold no role whatsoever.
*/

it('grants unrestricted completion to a roleless user holding complete_enrollment directly', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('complete_enrollment');

    expect($actor->refresh()->roles)->toBeEmpty()
        ->and($this->rule->allows($actor, (int) $this->batch->getKey()))->toBeTrue();
});

it('grants scoped completion to a roleless user assigned to the batch', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('complete_assigned_batch_enrollment');
    ($this->assignToBatch)($actor->refresh(), $this->batch);

    expect($this->rule->allows($actor->refresh(), (int) $this->batch->getKey()))->toBeTrue();
});

it('denies a roleless user with the scoped permission on a batch they are not on', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('complete_assigned_batch_enrollment');

    expect($this->rule->allows($actor->refresh(), (int) $this->batch->getKey()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The locking flag
|--------------------------------------------------------------------------
*/

it('answers identically whether or not locking is requested, absent a race', function () {
    // The single-connection behavioural proof that locking: true does not
    // change the ANSWER outside a race — only that it is current.
    // CompletionConcurrencyTest proves the case where it does matter.
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();
    ($this->assignToBatch)($staff, $this->batch);

    expect($this->rule->allows($staff, (int) $this->batch->getKey(), locking: false))->toBeTrue()
        ->and($this->rule->allows($staff, (int) $this->batch->getKey(), locking: true))->toBeTrue();
});

it('references only permissions the seeder actually creates', function () {
    // The drift test. Spatie throws PermissionDoesNotExist for an unknown name
    // rather than returning false, so a rule naming an unseeded permission
    // fails closed for everybody — including super_admin — at runtime.
    $referenced = ['complete_enrollment', 'complete_assigned_batch_enrollment'];

    $missing = collect($referenced)
        ->reject(fn (string $name): bool => Permission::query()->where('name', $name)->exists())
        ->all();

    expect($missing)->toBeEmpty(
        'CompletionRule references permissions RolePermissionSeeder does not create: '
        .implode(', ', $missing),
    );
});
