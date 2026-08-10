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

/** The nine custom abilities — bare verbs, because each names an act, not a row. */
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

it('seeds twenty-two finance abilities and no more', function () {
    /*
     * The count, so a name QUIETLY ADDED to the seeder shows up here rather than
     * only in whatever it was added for. Ten reads, three writes, nine custom.
     */
    expect(financeAbilities())->toHaveCount(22)
        ->and(array_unique(financeAbilities()))->toHaveCount(22);

    $seeded = Permission::query()
        ->whereIn('name', financeAbilities())
        ->pluck('name')
        ->all();

    expect($seeded)->toHaveCount(22);
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
| Student — nothing, until phase 3
|--------------------------------------------------------------------------
*/

it('gives student nothing at all, financial or otherwise', function () {
    $role = Role::findByName('student');
    $user = ($this->actorWith)('student');

    $held = array_values(array_filter(
        financeAbilities(),
        fn (string $ability): bool => $user->can($ability),
    ));

    expect($role->permissions)->toHaveCount(0)
        ->and($held)->toBe([], 'A student can reach finance abilities: '.implode(', ', $held))
        // Students reach the portal in phase 3, never the admin panel.
        ->and($user->can('access_admin_panel'))->toBeFalse();
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
