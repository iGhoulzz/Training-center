<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Authorization for staff profiles and their certificates, asserted in both
 * directions for every role. Negative assertions carry the weight here: a
 * policy that grants correctly but denies nothing reads as working right up
 * until someone checks.
 *
 * Roles are assigned through SystemRoleWriter, the trusted actorless path, for
 * the same reason the seeder uses it — there is no acting principal in a
 * fixture, so the request-path Actions are the wrong tool.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $system = app(SystemRoleWriter::class);

    $this->superAdmin = User::factory()->create();
    $system->assignRoles($this->superAdmin, 'super_admin');

    $this->admin = User::factory()->create();
    $system->assignRoles($this->admin, 'admin');

    $this->staff = User::factory()->create();
    $system->assignRoles($this->staff, 'staff');

    $this->student = User::factory()->create();
    $system->assignRoles($this->student, 'student');

    $this->profile = StaffProfile::factory()->create();
    $this->certificate = StaffCertificate::factory()
        ->for($this->profile, 'staffProfile')
        ->create();
});

it('lets a super admin manage staff profiles', function () {
    expect($this->superAdmin->can('viewAny', StaffProfile::class))->toBeTrue()
        ->and($this->superAdmin->can('view', $this->profile))->toBeTrue()
        ->and($this->superAdmin->can('create', StaffProfile::class))->toBeTrue()
        ->and($this->superAdmin->can('update', $this->profile))->toBeTrue()
        ->and($this->superAdmin->can('delete', $this->profile))->toBeTrue()
        ->and($this->superAdmin->can('deleteAny', StaffProfile::class))->toBeTrue();
});

it('lets an admin manage staff profiles', function () {
    expect($this->admin->can('viewAny', StaffProfile::class))->toBeTrue()
        ->and($this->admin->can('view', $this->profile))->toBeTrue()
        ->and($this->admin->can('create', StaffProfile::class))->toBeTrue()
        ->and($this->admin->can('update', $this->profile))->toBeTrue()
        ->and($this->admin->can('delete', $this->profile))->toBeTrue()
        ->and($this->admin->can('deleteAny', StaffProfile::class))->toBeTrue();
});

it('forbids staff from creating, editing, or deleting a staff profile', function () {
    expect($this->staff->can('create', StaffProfile::class))->toBeFalse()
        ->and($this->staff->can('update', $this->profile))->toBeFalse()
        ->and($this->staff->can('delete', $this->profile))->toBeFalse()
        ->and($this->staff->can('deleteAny', StaffProfile::class))->toBeFalse()
        // The staff role holds no staff_profile permission at all, so it cannot
        // read the register either. Asserted so a later grant is a deliberate
        // edit to this line rather than an unnoticed widening.
        ->and($this->staff->can('viewAny', StaffProfile::class))->toBeFalse()
        ->and($this->staff->can('view', $this->profile))->toBeFalse();
});

it('gives the student role no access to staff profiles', function () {
    expect($this->student->can('viewAny', StaffProfile::class))->toBeFalse()
        ->and($this->student->can('view', $this->profile))->toBeFalse()
        ->and($this->student->can('create', StaffProfile::class))->toBeFalse()
        ->and($this->student->can('update', $this->profile))->toBeFalse()
        ->and($this->student->can('delete', $this->profile))->toBeFalse()
        ->and($this->student->can('deleteAny', StaffProfile::class))->toBeFalse();
});

it('lets a super admin manage staff certificates', function () {
    expect($this->superAdmin->can('viewAny', StaffCertificate::class))->toBeTrue()
        ->and($this->superAdmin->can('view', $this->certificate))->toBeTrue()
        ->and($this->superAdmin->can('create', StaffCertificate::class))->toBeTrue()
        ->and($this->superAdmin->can('update', $this->certificate))->toBeTrue()
        ->and($this->superAdmin->can('delete', $this->certificate))->toBeTrue()
        ->and($this->superAdmin->can('deleteAny', StaffCertificate::class))->toBeTrue();
});

it('lets an admin manage staff certificates', function () {
    expect($this->admin->can('viewAny', StaffCertificate::class))->toBeTrue()
        ->and($this->admin->can('view', $this->certificate))->toBeTrue()
        ->and($this->admin->can('create', StaffCertificate::class))->toBeTrue()
        ->and($this->admin->can('update', $this->certificate))->toBeTrue()
        ->and($this->admin->can('delete', $this->certificate))->toBeTrue()
        ->and($this->admin->can('deleteAny', StaffCertificate::class))->toBeTrue();
});

it('forbids staff and students from reaching a certificate document', function () {
    // view() is the check the download route must make. These documents carry
    // national ID numbers, so the denial is the point.
    expect($this->staff->can('view', $this->certificate))->toBeFalse()
        ->and($this->staff->can('viewAny', StaffCertificate::class))->toBeFalse()
        ->and($this->staff->can('create', StaffCertificate::class))->toBeFalse()
        ->and($this->staff->can('update', $this->certificate))->toBeFalse()
        ->and($this->staff->can('delete', $this->certificate))->toBeFalse()
        ->and($this->staff->can('deleteAny', StaffCertificate::class))->toBeFalse()
        ->and($this->student->can('view', $this->certificate))->toBeFalse()
        ->and($this->student->can('viewAny', StaffCertificate::class))->toBeFalse()
        ->and($this->student->can('create', StaffCertificate::class))->toBeFalse()
        ->and($this->student->can('update', $this->certificate))->toBeFalse()
        ->and($this->student->can('delete', $this->certificate))->toBeFalse()
        ->and($this->student->can('deleteAny', StaffCertificate::class))->toBeFalse();
});

it('does not let a user see their own profile without the permission', function () {
    // Owning the record is not an authorization rule here. If self-service is
    // ever wanted it has to be added deliberately, not inherited by accident.
    $self = User::factory()->create();
    app(SystemRoleWriter::class)->assignRoles($self, 'staff');
    $ownProfile = StaffProfile::factory()->for($self)->create();

    expect($self->can('view', $ownProfile))->toBeFalse()
        ->and($self->can('update', $ownProfile))->toBeFalse();
});

it('authorizes on the permission, not the role name', function () {
    // The project's first non-negotiable. A user with no role at all, holding
    // the permission directly, is authorized; the same user without it is not.
    $unroled = User::factory()->create();

    expect($unroled->can('viewAny', StaffProfile::class))->toBeFalse();

    $unroled->givePermissionTo('view_any_staff_profile', 'view_staff_profile');

    expect($unroled->fresh()?->can('viewAny', StaffProfile::class))->toBeTrue()
        ->and($unroled->fresh()?->can('view', $this->profile))->toBeTrue()
        // Still denied everything it was not granted.
        ->and($unroled->fresh()?->can('update', $this->profile))->toBeFalse()
        ->and($unroled->fresh()?->can('delete', $this->profile))->toBeFalse()
        ->and($unroled->fresh()?->can('view', $this->certificate))->toBeFalse();
});
