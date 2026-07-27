<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    /** Put an actor on a batch's instructor list, without going near the Action. */
    $this->assignToBatch = function (User $actor, Batch $batch): void {
        $batch->instructors()->attach($actor->getKey(), ['assigned_hours' => 10]);
    };

    /** A role holding exactly the named permissions, and an actor wearing it. */
    $this->actorWithPermissions = function (string $role, array $permissions): User {
        $this->system->syncRolePermissions(
            Role::findOrCreate($role, 'web'),
            collect($permissions)->map(fn (string $name) => Permission::findByName($name, 'web'))->all(),
        );

        $actor = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($actor, $role);

        return $actor->refresh();
    };
});

/*
|--------------------------------------------------------------------------
| The seeded roles
|--------------------------------------------------------------------------
*/

it('lets an admin edit any enrollment', function () {
    expect(Gate::forUser(($this->actorWith)('admin'))
        ->allows('update', Enrollment::factory()->create()))->toBeTrue();
});

it('lets staff edit an enrollment on a batch they are assigned to teach', function () {
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();

    $batch = Batch::factory()->active()->create();
    ($this->assignToBatch)($staff, $batch);

    expect(Gate::forUser($staff)->allows('update', Enrollment::factory()->for($batch)->create()))
        ->toBeTrue();
});

it('denies a staff actor who does not teach the batch', function () {
    // THE NEGATIVE THAT MATTERS. Staff hold update_assigned_batch_enrollment, so
    // a policy that forgot the assignment check would pass this.
    expect(Gate::forUser(($this->actorWith)('staff'))
        ->allows('update', Enrollment::factory()->create()))->toBeFalse();
});

it('denies a staff actor assigned to a DIFFERENT batch', function () {
    // Narrower than the test above: proves the check is per-record and not
    // "is this actor an instructor anywhere".
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();

    ($this->assignToBatch)($staff, Batch::factory()->active()->create());

    $someoneElses = Enrollment::factory()->for(Batch::factory()->active()->create())->create();

    expect(Gate::forUser($staff)->allows('update', $someoneElses))->toBeFalse();
});

it('grants an assigned ADMINISTRATIVE profile the same scoped edit', function () {
    /*
     * OWNERSHIP DEPENDS ON THE PIVOT AND NOTHING ELSE.
     *
     * This actor's staff profile says Administrative, not Instructor, and they
     * are nonetheless down to teach this batch. The policy must say yes: a
     * version that consulted employment_type would refuse, and would refuse
     * silently, because every other test in this file uses an instructor profile
     * and would keep passing.
     */
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->create();

    $batch = Batch::factory()->active()->create();
    ($this->assignToBatch)($staff, $batch);

    expect(Gate::forUser($staff)->allows('update', Enrollment::factory()->for($batch)->create()))
        ->toBeTrue();
});

it('denies an INSTRUCTOR profile assigned to nothing', function () {
    // The other half of the same point: teaching somewhere is not the question,
    // being on THIS batch is.
    $staff = ($this->actorWith)('staff');
    StaffProfile::factory()->for($staff)->instructor()->create();

    expect(Gate::forUser($staff)->allows('update', Enrollment::factory()->create()))->toBeFalse();
});

it('does not grant staff the unrestricted update ability', function () {
    // If staff ever hold update_enrollment, the scoping stops running and every
    // scoped test in this file still passes. This is what catches that.
    $staff = ($this->actorWith)('staff');

    expect($staff->can('update_enrollment'))->toBeFalse()
        ->and($staff->can('update_assigned_batch_enrollment'))->toBeTrue();
});

it('denies an actor holding neither update ability', function () {
    expect(Gate::forUser(($this->actorWith)('student'))
        ->allows('update', Enrollment::factory()->create()))->toBeFalse();
});

it('lets staff create an enrollment on any batch', function () {
    expect(Gate::forUser(($this->actorWith)('staff'))->allows('create', Enrollment::class))->toBeTrue();
});

it('does not let staff delete an enrollment', function () {
    expect(Gate::forUser(($this->actorWith)('staff'))
        ->allows('delete', Enrollment::factory()->create()))->toBeFalse();
});

it('lets an admin delete an enrollment', function () {
    // delete_enrollment is a real grant with a real path — DeleteEnrollmentAction
    // — rather than a permission nothing consults.
    expect(Gate::forUser(($this->actorWith)('admin'))
        ->allows('delete', Enrollment::factory()->create()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Arbitrary roles — the policy must not know the seeded names
|--------------------------------------------------------------------------
|
| Every test above uses the seeded roles, and a policy hardcoded to
| hasAnyRole(['super_admin', 'admin']) for the unrestricted case and
| hasRole('staff') for the scoped one would pass all of them.
|
| These use roles the seeder has never heard of, holding exactly one enrolment
| permission each.
*/

it('grants unrestricted update to ANY role holding update_enrollment', function () {
    $actor = ($this->actorWithPermissions)('registrar', ['update_enrollment']);

    expect(Gate::forUser($actor)->allows('update', Enrollment::factory()->create()))->toBeTrue();
});

it('grants scoped update to ANY role holding update_assigned_batch_enrollment', function () {
    $actor = ($this->actorWithPermissions)('visiting_tutor', ['update_assigned_batch_enrollment']);

    $batch = Batch::factory()->active()->create();
    ($this->assignToBatch)($actor, $batch);

    expect(Gate::forUser($actor)->allows('update', Enrollment::factory()->for($batch)->create()))
        ->toBeTrue();
});

it('denies that same arbitrary role on a batch it is not assigned to', function () {
    // Without this, a policy returning true for anyone holding the scoped
    // permission would pass the test above.
    $actor = ($this->actorWithPermissions)('visiting_tutor', ['update_assigned_batch_enrollment']);

    expect(Gate::forUser($actor)->allows('update', Enrollment::factory()->create()))->toBeFalse();
});

it('grants create to ANY role holding create_enrollment', function () {
    $actor = ($this->actorWithPermissions)('front_desk', ['create_enrollment']);

    expect(Gate::forUser($actor)->allows('create', Enrollment::class))->toBeTrue();
});

it('grants delete to ANY role holding delete_enrollment', function () {
    $actor = ($this->actorWithPermissions)('registrar_delete', ['delete_enrollment']);

    expect(Gate::forUser($actor)->allows('delete', Enrollment::factory()->create()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Direct permissions, with no role at all
|--------------------------------------------------------------------------
|
| The arbitrary-role tests prove the policy does not hardcode the SEEDED role
| names. They do not prove it asks $user->can() — a policy checking
| $user->roles->isNotEmpty() would pass every one of them.
|
| These actors hold no role whatsoever and carry the permission directly, which
| only can() resolves.
*/

it('grants unrestricted update to a roleless user holding update_enrollment directly', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('update_enrollment');

    expect($actor->refresh()->roles)->toBeEmpty()
        ->and(Gate::forUser($actor)->allows('update', Enrollment::factory()->create()))->toBeTrue();
});

it('grants scoped update to a roleless user assigned to the batch', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('update_assigned_batch_enrollment');

    $batch = Batch::factory()->active()->create();
    ($this->assignToBatch)($actor, $batch);

    expect(Gate::forUser($actor->refresh())
        ->allows('update', Enrollment::factory()->for($batch)->create()))->toBeTrue();
});

it('denies a roleless user with the scoped permission on a batch they are not on', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('update_assigned_batch_enrollment');

    expect(Gate::forUser($actor->refresh())
        ->allows('update', Enrollment::factory()->create()))->toBeFalse();
});

it('grants each remaining ability to a roleless user holding it directly', function () {
    $cases = [
        'view_any_enrollment' => fn (User $a): bool => Gate::forUser($a)->allows('viewAny', Enrollment::class),
        'view_enrollment' => fn (User $a): bool => Gate::forUser($a)->allows('view', Enrollment::factory()->create()),
        'create_enrollment' => fn (User $a): bool => Gate::forUser($a)->allows('create', Enrollment::class),
        'delete_enrollment' => fn (User $a): bool => Gate::forUser($a)->allows('delete', Enrollment::factory()->create()),
    ];

    foreach ($cases as $permission => $check) {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo($permission);

        expect($check($actor->refresh()))->toBeTrue("{$permission} did not grant its ability");

        $without = User::factory()->create(['is_active' => true]);

        expect($check($without))->toBeFalse("the ability was granted without {$permission}");
    }
});

it('references only permissions the seeder actually creates', function () {
    // The drift test. Spatie throws PermissionDoesNotExist for an unknown name
    // rather than returning false, so a policy naming an unseeded permission
    // fails closed for everybody — including super_admin — at runtime rather
    // than here.
    $referenced = ['view_any_enrollment', 'view_enrollment', 'create_enrollment',
        'update_enrollment', 'update_assigned_batch_enrollment', 'delete_enrollment'];

    $missing = collect($referenced)
        ->reject(fn (string $name): bool => Permission::query()->where('name', $name)->exists())
        ->all();

    expect($missing)->toBeEmpty(
        'EnrollmentPolicy references permissions RolePermissionSeeder does not create: '
        .implode(', ', $missing),
    );
});
