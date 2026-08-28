<?php

declare(strict_types=1);

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('creates the four roles', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::pluck('name')->all())
        ->toContain('super_admin', 'admin', 'staff', 'student');
});

it('gives super_admin every permission', function () {
    $this->seed(RolePermissionSeeder::class);

    $superAdmin = Role::findByName('super_admin');
    $total = Permission::count();

    expect($superAdmin->permissions)->toHaveCount($total);
});

it('denies staff any user-management permission', function () {
    $this->seed(RolePermissionSeeder::class);

    $staff = Role::findByName('staff');

    expect($staff->hasPermissionTo('create_user'))->toBeFalse()
        ->and($staff->hasPermissionTo('update_user'))->toBeFalse()
        ->and($staff->hasPermissionTo('delete_user'))->toBeFalse()
        ->and($staff->hasPermissionTo('view_any_activity'))->toBeFalse();
});

it('denies admin the ability to manage roles', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName('admin')->hasPermissionTo('update_role'))->toBeFalse();
});

it('grants admin panel access to staff roles but not students', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName('super_admin')->hasPermissionTo('access_admin_panel'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo('access_admin_panel'))->toBeTrue()
        ->and(Role::findByName('staff')->hasPermissionTo('access_admin_panel'))->toBeTrue()
        ->and(Role::findByName('student')->hasPermissionTo('access_admin_panel'))->toBeFalse();
});

it('generates permissions in snake_case, not Shield default pascal', function () {
    $this->seed(RolePermissionSeeder::class);

    $names = Permission::pluck('name');

    expect($names)->toContain('view_any_role')
        ->and($names->filter(fn (string $n): bool => str_contains($n, ':')))->toBeEmpty();
});

/**
 * Guards against seeder/policy drift.
 *
 * Shield generates policies referencing abilities the seeder may not create.
 * When that happens the check fails closed — the action silently returns false
 * for everyone, including super_admin — which is easy to miss because nothing
 * errors. This asserts every permission any policy references actually exists.
 */
it('seeds every permission referenced by any policy', function () {
    $this->seed(RolePermissionSeeder::class);

    $seeded = Permission::pluck('name')->all();

    /*
     * Scans all of app/, not just app/Policies. Domain policies live under
     * app/Domain/<Context>/Policies (UserPolicy is the first), and a directory
     * scan that misses them is worse than no scan at all — it reads as
     * coverage while silently ignoring the policies that matter most.
     */
    $policies = [];

    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
        ) as $file
    ) {
        if (str_ends_with($file->getFilename(), 'Policy.php')) {
            $policies[] = $file->getPathname();
        }
    }

    expect($policies)->not->toBeEmpty('Found no policies to scan — the scan is broken.');

    $missing = [];

    foreach ($policies as $policy) {
        preg_match_all(
            "/can\(\s*'([a-z0-9_]+)'/",
            (string) file_get_contents($policy),
            $matches,
        );

        foreach ($matches[1] as $ability) {
            if (! in_array($ability, $seeded, strict: true)) {
                $missing[] = basename($policy).' → '.$ability;
            }
        }
    }

    expect($missing)->toBeEmpty(
        'Policies reference permissions the seeder does not create: '
        .implode(', ', $missing),
    );
});

/*
|--------------------------------------------------------------------------
| Phase 3 (P3-T01)
|--------------------------------------------------------------------------
|
| Fourteen new abilities, seeded here and nowhere else. Four later tasks would
| otherwise each append to this seeder and to this file; phase 2 paid a wave for
| that collision, so T1 closes the seam by seeding the whole set at once.
|
| The counts are asserted explicitly because "five abilities" and "five
| view_own_* abilities" are easy to conflate, and this seeder is exactly where
| that mistake would land.
*/

it('seeds the fourteen phase 3 abilities', function () {
    $this->seed(RolePermissionSeeder::class);

    $seeded = Permission::pluck('name')->all();

    expect($seeded)->toContain(
        // Portal access, then the four own-reads. One plus four, not five reads.
        'access_student_portal',
        'view_own_student_record',
        'view_own_enrollment',
        'view_own_balance',
        'view_own_certificate',
        // Completion. The scoped enrolment-editing grant already existed;
        // completion needs its own scoped grant for the same reason.
        'complete_enrollment',
        'complete_assigned_batch_enrollment',
        // Portal credentials, deliberately not create_user + reset_user_password.
        'issue_portal_credential',
        'reset_portal_credential',
        // Certificates: a read pair and three acts.
        'view_any_student_certificate',
        'view_student_certificate',
        'issue_student_certificate',
        'replace_student_certificate',
        'revoke_student_certificate',
    );
});

it('gives the student role exactly five abilities', function () {
    $this->seed(RolePermissionSeeder::class);

    $student = Role::findByName('student');

    expect($student->permissions->pluck('name')->sort()->values()->all())->toBe([
        'access_student_portal',
        'view_own_balance',
        'view_own_certificate',
        'view_own_enrollment',
        'view_own_student_record',
    ]);
});

it('never creates generic certificate write permissions', function () {
    // Asserted by absence from the table rather than through hasPermissionTo,
    // which throws PermissionDoesNotExist rather than returning false.
    //
    // A certificate is issued, replaced or revoked — never created, updated or
    // deleted. Seeding an ability nothing honours invites someone to wire it up
    // later, which is the reasoning already recorded above for the activity log
    // and for the finance writes.
    $this->seed(RolePermissionSeeder::class);

    $generic = [
        'create_student_certificate',
        'update_student_certificate',
        'delete_student_certificate',
        'delete_any_student_certificate',
        'force_delete_student_certificate',
        'force_delete_any_student_certificate',
        'restore_student_certificate',
        'restore_any_student_certificate',
        'replicate_student_certificate',
        'reorder_student_certificate',
    ];

    $found = Permission::whereIn('name', $generic)->pluck('name')->all();

    expect($found)->toBeEmpty(
        'Generic certificate permissions must not be seeded: '.implode(', ', $found),
    );
});

it('denies the student role every certificate permission', function () {
    // Shield's generated set is register-wide. Attaching it to the student role
    // is a one-line mistake with a blast radius over every student's record, so
    // it is asserted by name rather than left to the count above.
    $this->seed(RolePermissionSeeder::class);

    $student = Role::findByName('student');

    expect($student->hasPermissionTo('view_any_student_certificate'))->toBeFalse()
        ->and($student->hasPermissionTo('view_student_certificate'))->toBeFalse()
        ->and($student->hasPermissionTo('issue_student_certificate'))->toBeFalse()
        ->and($student->hasPermissionTo('replace_student_certificate'))->toBeFalse()
        ->and($student->hasPermissionTo('revoke_student_certificate'))->toBeFalse();
});

it('scopes completion so staff hold only the batch-scoped grant', function () {
    // P1-T11's lesson, applied to completion: holding the unrestricted ability
    // satisfies the first branch and the scoping never runs, while every scoped
    // test still passes. Staff must NOT hold complete_enrollment.
    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName('super_admin')->hasPermissionTo('complete_enrollment'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo('complete_enrollment'))->toBeTrue()
        ->and(Role::findByName('staff')->hasPermissionTo('complete_enrollment'))->toBeFalse()
        ->and(Role::findByName('staff')->hasPermissionTo('complete_assigned_batch_enrollment'))->toBeTrue()
        ->and(Role::findByName('student')->hasPermissionTo('complete_enrollment'))->toBeFalse();
});

it('lets staff issue portal credentials without staff-account creation', function () {
    // Section 4 of the system design says staff issue portal credentials, while
    // the matrix gives them nothing on staff accounts. Two narrow abilities
    // honour both; create_user + reset_user_password would not.
    $this->seed(RolePermissionSeeder::class);

    $staff = Role::findByName('staff');

    expect($staff->hasPermissionTo('issue_portal_credential'))->toBeTrue()
        ->and($staff->hasPermissionTo('reset_portal_credential'))->toBeTrue()
        ->and($staff->hasPermissionTo('create_user'))->toBeFalse()
        ->and($staff->hasPermissionTo('reset_user_password'))->toBeFalse();
});

it('grants certificate issuance to admins and reading to staff', function () {
    $this->seed(RolePermissionSeeder::class);

    $admin = Role::findByName('admin');
    $staff = Role::findByName('staff');

    expect($admin->hasPermissionTo('issue_student_certificate'))->toBeTrue()
        ->and($admin->hasPermissionTo('replace_student_certificate'))->toBeTrue()
        ->and($admin->hasPermissionTo('revoke_student_certificate'))->toBeTrue()
        ->and($admin->hasPermissionTo('view_any_student_certificate'))->toBeTrue()
        // Staff read the register and change nothing in it.
        ->and($staff->hasPermissionTo('view_any_student_certificate'))->toBeTrue()
        ->and($staff->hasPermissionTo('view_student_certificate'))->toBeTrue()
        ->and($staff->hasPermissionTo('issue_student_certificate'))->toBeFalse()
        ->and($staff->hasPermissionTo('replace_student_certificate'))->toBeFalse()
        ->and($staff->hasPermissionTo('revoke_student_certificate'))->toBeFalse();
});
