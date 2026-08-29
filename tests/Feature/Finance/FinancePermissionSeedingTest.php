<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| The phase 2 permission set (design §10), asserted exactly
|--------------------------------------------------------------------------
|
| §10 says the list is exhaustive AND enforced. Two failures it is written
| against, both of which pass a "does the seeder run?" test:
|
|   AN ABILITY THAT IS MISSING. Spatie throws PermissionDoesNotExist for an
|   unknown name rather than returning false, so an unseeded grant breaks the
|   seeder itself — but an unseeded ability that no role is granted just makes
|   its policy fail closed for everybody, silently, including super admin.
|
|   AN ABILITY THAT SHOULD NOT EXIST. `update_charge` and `delete_payment` have
|   policies that refuse unconditionally; seeding them anyway invites somebody to
|   wire them up later, which is how an immutable row stops being one.
|
| So the finance surface is asserted as a closed set in both directions, and
| every role's grant is asserted by what it does NOT hold as much as by what it
| does. §14: "Every one of those denials is a negative test."
*/
uses(RefreshDatabase::class);

/** The five finance resources, which take READ permissions only. */
const FINANCE_RESOURCES = ['charge', 'payment', 'discount', 'staff_compensation', 'payroll_run'];

/** Every finance read, spelled out rather than generated from the seeder's own list. */
const FINANCE_READS = [
    'view_any_charge', 'view_charge',
    'view_any_payment', 'view_payment',
    'view_any_discount', 'view_discount',
    'view_any_staff_compensation', 'view_staff_compensation',
    'view_any_payroll_run', 'view_payroll_run',
];

/**
 * Every finance write there is. Three.
 *
 * `create_staff_compensation` and no update counterpart: the only legitimate
 * change to a rate is a new effective-dated row, so *create* IS the write ability
 * for that table. `delete_payroll_run` removes a draft; a finalized run is
 * refused by the policy whoever holds it.
 */
const FINANCE_WRITES = ['create_payment', 'create_staff_compensation', 'delete_payroll_run'];

/**
 * The ten custom abilities — bare verbs, because each names an act, not a row.
 *
 * Nine of them are phase 2's. The tenth, view_own_balance, is phase 3's student
 * self-read; see its own note below.
 */
const FINANCE_CUSTOM = [
    'manage_pricing',
    'apply_discount',
    'adjust_charge',
    'write_off_charge',
    'reverse_payment',
    'run_payroll',
    'finalize_payroll',
    'view_financial_report',
    'export_financial_report',
    /*
     * THE FIRST NON-STAFF FINANCE READ (P3-T01).
     *
     * A student reading their own outstanding balance on the portal. It is a
     * portal ability in phase 3 design §8.2's grant table, and it is financial
     * HERE, because this file's property is "nothing outside this set is
     * financial" — and an ability whose entire subject is money would make that
     * sentence false from the non-finance list, hiding the student from every
     * finance assertion below.
     *
     * The permission is not what scopes it to one student. AuthenticatedStudent
     * decides whose balance is read, and T7's two-student tests are what prove
     * the scoping; this grants the capability, nothing more.
     */
    'view_own_balance',
];

/**
 * Abilities design §10 deliberately does NOT seed.
 *
 * Their policies refuse unconditionally and each has a test that grants the
 * permission anyway and proves the refusal — a stronger statement than "the
 * permission does not exist". This file makes the weaker statement too, because
 * the two fail differently: a policy can be rewritten, and this catches the
 * ability reappearing before anything is wired to it.
 *
 * No `create_discount` either: a discount definition is created, deactivated and
 * deleted under `manage_pricing`. Setting the rates the centre charges is one
 * capability, wherever it happens to be expressed.
 */
const FINANCE_NEVER_SEEDED = [
    'create_charge', 'update_charge', 'delete_charge',
    'update_payment', 'delete_payment',
    'update_staff_compensation',
    'update_payroll_run',
    'update_discount', 'create_discount',
];

/** What an admin may reach: read everything, record money, choose a discount. */
const ADMIN_FINANCE_GRANTS = [
    ...FINANCE_READS,
    'create_payment',
    'apply_discount',
    'view_financial_report',
    'export_financial_report',
];

/**
 * What an admin may NOT reach. §10: "Admins record money but cannot set a price,
 * manage a discount definition, adjust a charge, write off a debt, reverse a
 * payment, or touch compensation or payroll."
 */
const ADMIN_FINANCE_DENIALS = [
    'manage_pricing',
    'adjust_charge',
    'write_off_charge',
    'reverse_payment',
    'run_payroll',
    'finalize_payroll',
    // The two writes that are seeded but are super admin's alone. Easy to leave
    // out of a denial list precisely because they are not "custom abilities".
    'create_staff_compensation',
    'delete_payroll_run',
];

/** Every finance ability the system has. Nothing outside this set is financial. */
function financeAbilities(): array
{
    return [...FINANCE_READS, ...FINANCE_WRITES, ...FINANCE_CUSTOM];
}

/**
 * The eight resources that take the standard phase 1 CRUD set. Kept here as an
 * independent list rather than read off `RolePermissionSeeder::RESOURCES` —
 * that constant is private, and reflecting it in would let a name added to
 * the wrong list agree with itself instead of being caught below.
 */
const NON_FINANCE_CRUD_RESOURCES = [
    'user', 'staff_profile', 'staff_certificate', 'student', 'course', 'batch', 'enrollment', 'role',
];

/**
 * Every non-finance ability the seeder creates that is not plain CRUD: the
 * activity log's two reads, the non-finance custom abilities, and the extra
 * Role abilities Shield's generated policy needs.
 */
const NON_FINANCE_CUSTOM = [
    'view_any_activity', 'view_activity',
    'access_admin_panel', 'assign_role', 'reset_user_password', 'assign_instructor',
    'manage_settings', 'update_assigned_batch_enrollment',
    'delete_any_role', 'force_delete_role', 'force_delete_any_role',
    'restore_role', 'restore_any_role', 'replicate_role', 'reorder_role',
    /*
     * Phase 3 (P3-T01). Thirteen of the fourteen new abilities; the fourteenth,
     * view_own_balance, is financial and sits in FINANCE_CUSTOM above.
     *
     * The certificate register is not a financial record: issuance is refused by
     * nothing financial and warns on an outstanding balance without consulting
     * it (phase 3 design §5.4).
     */
    'access_student_portal',
    'view_own_student_record', 'view_own_enrollment', 'view_own_certificate',
    'complete_enrollment', 'complete_assigned_batch_enrollment',
    'issue_portal_credential', 'reset_portal_credential',
    'view_any_student_certificate', 'view_student_certificate',
    'issue_student_certificate', 'replace_student_certificate', 'revoke_student_certificate',
];

/** Every permission the seeder creates that has nothing to do with finance. */
function nonFinanceAbilities(): array
{
    $crud = [];

    foreach (NON_FINANCE_CRUD_RESOURCES as $resource) {
        foreach (['view_any', 'view', 'create', 'update', 'delete'] as $action) {
            $crud[] = "{$action}_{$resource}";
        }
    }

    return [...$crud, ...NON_FINANCE_CUSTOM];
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($user, $role);

        return $user->refresh();
    };
});

/*
|--------------------------------------------------------------------------
| The set exists, exactly
|--------------------------------------------------------------------------
*/

it('seeds every finance ability design §10 names', function (string $ability) {
    expect(Permission::query()->where('name', $ability)->where('guard_name', 'web')->exists())
        ->toBeTrue("{$ability} is not seeded. Its policy now fails closed for everybody, super admin included.");
})->with(financeAbilities());

it('seeds twenty-three finance abilities and no more', function () {
    /*
     * The count, so a name QUIETLY ADDED to the seeder shows up here rather than
     * only in whatever it was added for. Ten reads, three writes, ten custom.
     */
    expect(financeAbilities())->toHaveCount(23)
        ->and(array_unique(financeAbilities()))->toHaveCount(23);

    /*
     * THE REAL CLOSED-SET CHECK. This used to read
     * `Permission::query()->whereIn('name', financeAbilities())->pluck('name')`
     * and assert THAT had twenty-two rows — filtering the seeded permissions
     * by the very list being checked, so it could only ever agree with
     * itself. A reviewer added 'refund_payment' to
     * `RolePermissionSeeder::CUSTOM`, and it was granted to super admin, and
     * this assertion (and the per-name one above it) stayed green: neither
     * one ever looks at a permission this file did not already name.
     *
     * This version starts from every permission the seeder actually wrote,
     * removes the ones independently known to be non-financial
     * (`nonFinanceAbilities()`, spelled out by hand rather than reflected off
     * the seeder's own private constants — same reason as above), and
     * requires what is left to be exactly `financeAbilities()`. A name the
     * seeder produces that this file recognises as neither finance nor
     * non-finance is named in the failure rather than passing silently.
     */
    $allSeeded = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

    $unrecognised = array_values(array_diff($allSeeded, financeAbilities(), nonFinanceAbilities()));

    expect($unrecognised)->toBe(
        [],
        'The seeder created permissions this test does not recognise as finance or as '
        .'non-finance: '.implode(', ', $unrecognised).'. Add each one to financeAbilities() in '
        .'this file if design §10 calls for it, or to nonFinanceAbilities() if it does not.',
    );

    $financeSeeded = array_values(array_intersect($allSeeded, financeAbilities()));
    sort($financeSeeded);
    $expected = financeAbilities();
    sort($expected);

    expect($financeSeeded)->toHaveCount(23)
        ->toBe($expected, 'The seeded finance permissions no longer match financeAbilities() exactly.');
});

it('creates no CRUD ability on a finance resource beyond the reads and the three writes', function () {
    /*
     * THE CLOSED SET, from the other direction. The list above says what must
     * exist; this says nothing else may. `staff_profile` takes the standard CRUD
     * set, so adding a finance resource to that list by mistake would mint
     * `update_charge`, `delete_payment` and their siblings in one line — and
     * every one of them would look plausible sitting in the table.
     *
     * The pattern is anchored on a CRUD action prefix so it does not sweep up the
     * custom verbs that happen to end in a resource name — `apply_discount`,
     * `adjust_charge`, `write_off_charge` and `reverse_payment` all do.
     */
    $crudActions = 'view_any|view|create|update|delete|restore|force_delete|replicate|reorder|delete_any|force_delete_any|restore_any';
    $pattern = '/^('.$crudActions.')_('.implode('|', FINANCE_RESOURCES).')$/D';

    $found = Permission::query()
        ->pluck('name')
        ->filter(fn (string $name): bool => preg_match($pattern, $name) === 1)
        ->sort()
        ->values()
        ->all();

    $expected = [...FINANCE_READS, ...FINANCE_WRITES];
    sort($expected);

    expect($found)->toBe(
        $expected,
        'The CRUD abilities on the finance resources are no longer exactly what design §10 lists.',
    );
});

it('does not create the write abilities design §10 refuses to seed', function (string $ability) {
    /*
     * Asserted by absence from the table, not by a role check: Spatie throws
     * PermissionDoesNotExist for an unknown name, so hasPermissionTo() on one of
     * these would blow up rather than return false — which is a passing test for
     * the wrong reason waiting to happen.
     */
    expect(Permission::query()->where('name', $ability)->exists())
        ->toBeFalse("{$ability} is seeded, and design §10 says it must not be. Its policy refuses unconditionally, so the only thing this ability can do is invite somebody to wire it up.");
})->with(FINANCE_NEVER_SEEDED);

it('gives charge issuance no ability of its own', function () {
    /*
     * §10's one deliberate deviation. Staff hold `create_enrollment` and no charge
     * permission, so if issuing the bill demanded its own ability a staff member
     * could not complete a walk-in — the scenario the system exists for.
     * `EnrollAndBillAction` raises the bill as a system consequence of a permitted
     * act, which is only possible while `create_charge` does not exist.
     */
    expect(Permission::query()->where('name', 'create_charge')->exists())->toBeFalse()
        ->and(Role::findByName('staff')->hasPermissionTo('create_enrollment'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Super admin
|--------------------------------------------------------------------------
*/

it('gives super_admin every finance ability', function () {
    $superAdmin = Role::findByName('super_admin');

    foreach (financeAbilities() as $ability) {
        expect($superAdmin->hasPermissionTo($ability))->toBeTrue(
            "super_admin does not hold {$ability}, so nobody in the system does.",
        );
    }
});

/*
|--------------------------------------------------------------------------
| Admin — the fourteen, and the denials that matter more
|--------------------------------------------------------------------------
*/

it('grants admin exactly fourteen finance abilities', function () {
    $admin = Role::findByName('admin');

    $held = array_values(array_filter(
        financeAbilities(),
        fn (string $ability): bool => $admin->hasPermissionTo($ability),
    ));

    sort($held);
    $expected = ADMIN_FINANCE_GRANTS;
    sort($expected);

    expect($expected)->toHaveCount(14)
        ->and($held)->toBe(
            $expected,
            "Admin's finance grant no longer matches design §10.",
        );
});

it('denies admin every ability that sets a rate or undoes a fact', function (string $ability) {
    /*
     * The half where authorization bugs actually hide. A grant that is too wide
     * behaves correctly in every happy-path test in the suite — the admin can do
     * the thing, the screen works — and is only visibly wrong the day somebody
     * writes off a debt that should have needed a super admin.
     *
     * Asserted at the ROLE and at a real USER: the role row is what the seeder
     * writes, and `$user->can()` is what the application actually consults. They
     * can disagree — a permission granted directly to a user, a guard mismatch —
     * and the second is the one that decides.
     */
    expect(Role::findByName('admin')->hasPermissionTo($ability))->toBeFalse(
        "The admin role holds {$ability}, which design §10 reserves for super admin.",
    );

    expect(($this->actorWith)('admin')->can($ability))->toBeFalse(
        "An admin user can {$ability}, which design §10 reserves for super admin.",
    );
})->with(ADMIN_FINANCE_DENIALS);

it('lets an admin record money and read every figure', function () {
    /*
     * The control for the denials above. A role holding nothing at all would pass
     * every negative assertion in this file, so the positive half has to be here
     * too — and `create_payment` is the one §10 singles out as easy to omit and
     * load-bearing.
     */
    $admin = ($this->actorWith)('admin');

    expect($admin->can('create_payment'))->toBeTrue()
        ->and($admin->can('apply_discount'))->toBeTrue()
        ->and($admin->can('view_any_charge'))->toBeTrue()
        ->and($admin->can('view_any_payroll_run'))->toBeTrue()
        ->and($admin->can('view_financial_report'))->toBeTrue()
        ->and($admin->can('export_financial_report'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Staff — nothing financial at all
|--------------------------------------------------------------------------
*/

it('gives staff nothing financial whatsoever', function () {
    /*
     * Not a reading permission, not apply_discount, nothing. Stated as an
     * INTERSECTION over the whole finance set rather than as a list of denials,
     * because a list only denies the abilities somebody remembered to write down
     * and a new finance ability would be granted-by-omission from that list.
     *
     * A walk-in enrolment still completes: `EnrollAndBillAction` raises the bill
     * off `create_enrollment`, so no charge ability is required to do the job.
     * Staff enrol at full price and the discount selector does not render.
     */
    $role = Role::findByName('staff');
    $user = ($this->actorWith)('staff');

    $heldByRole = array_values(array_filter(
        financeAbilities(),
        fn (string $ability): bool => $role->hasPermissionTo($ability),
    ));

    $heldByUser = array_values(array_filter(
        financeAbilities(),
        fn (string $ability): bool => $user->can($ability),
    ));

    expect($heldByRole)->toBe([], 'The staff role holds finance abilities: '.implode(', ', $heldByRole))
        ->and($heldByUser)->toBe([], 'A staff user can reach finance abilities: '.implode(', ', $heldByUser))
        // And still does the job the centre exists for.
        ->and($user->can('create_enrollment'))->toBeTrue()
        ->and($user->can('access_admin_panel'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Student — five portal abilities, exactly one of them financial (P3-T01)
|--------------------------------------------------------------------------
|
| This section previously asserted the student role held NOTHING, and its heading
| said "until phase 3". This is phase 3.
|
| The student now holds five abilities, and view_own_balance is the first
| non-staff finance read in the system. The assertion is therefore not relaxed to
| "some finance abilities" — it is narrowed to exactly which one, so a second
| finance ability reaching students fails here rather than passing as growth
| within a vague allowance.
*/

it('gives student exactly one finance ability and nothing else financial', function () {
    $role = Role::findByName('student');
    $user = ($this->actorWith)('student');

    $held = array_values(array_filter(
        financeAbilities(),
        fn (string $ability): bool => $user->can($ability),
    ));

    expect($role->permissions)->toHaveCount(5)
        ->and($held)->toBe(
            ['view_own_balance'],
            'A student can reach finance abilities beyond their own balance: '.implode(', ', $held),
        )
        // Reading your own balance is not reading anyone else's, and it is
        // certainly not the register: every staff-facing finance read stays shut.
        ->and($user->can('view_any_charge'))->toBeFalse()
        ->and($user->can('view_any_payment'))->toBeFalse()
        ->and($user->can('view_financial_report'))->toBeFalse()
        // Students reach the portal, never the admin panel.
        ->and($user->can('access_admin_panel'))->toBeFalse()
        ->and($user->can('access_student_portal'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Shape
|--------------------------------------------------------------------------
*/

it('spells the custom finance abilities as bare verbs, not as CRUD on a resource', function () {
    /*
     * §10: custom abilities are bare verbs because each names an ACT, not a row.
     * `run_payroll` and `finalize_payroll` are two separate decisions about the
     * same table, and `manage_pricing` gates course prices, batch prices and the
     * discount definitions as one capability — none of which a `{action}_{model}`
     * name can express.
     */
    foreach (FINANCE_CUSTOM as $ability) {
        expect($ability)->not->toStartWith('view_any_')
            ->and(str_contains($ability, ':'))->toBeFalse();
    }

    // Shield's default pascal/colon form must not have crept back in anywhere.
    expect(Permission::query()->where('name', 'like', '%:%')->count())->toBe(0);
});
