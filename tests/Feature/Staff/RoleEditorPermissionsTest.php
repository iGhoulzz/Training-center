<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\EditRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ViewRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * The role editor keeps every permission (P4-T21, hotfix).
 *
 * The editor used to offer only resource, page and widget permissions, and its
 * save synced the role to exactly what the form showed. Saving a role with
 * nothing changed therefore stripped every permission the form had no checkbox
 * for: staff went from 17 permissions to 9, admin from 76 to 51, student from 5
 * to 0, and with access_admin_panel and access_student_portal gone nobody could
 * sign in. The same gap meant a role created in the editor could never be
 * granted panel access, which breaks spec section 5: "adding a fifth role must
 * not require a code change".
 *
 * The cure was a tab for the permissions no other tab offered. Since P4-T42 the
 * editor is a grid (PermissionGrid) and a permission with no box is CARRIED
 * THROUGH the save instead, which RoleGridTest pins; the tests below drive the
 * rendered form rather than reading config back, so they hold however it is built.
 *
 * THE ACTOR IS A SUPER ADMIN BECAUSE ONLY A SUPER ADMIN MAY EDIT ROLES.
 * RolePolicy decides that, and it is unchanged here. Nothing in this file is an
 * authorization test; RolePolicyTest and SecurityReviewRegressionTest own those.
 */
uses(RefreshDatabase::class);

/**
 * Every permission box the mounted form really renders, keyed by permission name,
 * each mapped to the label it is drawn with.
 *
 * A component inside a hidden section is not returned (the default of
 * getFlatComponents()), so a box that is switched off offers nothing, which is
 * what a person using the form experiences.
 *
 * @return array<string, string>
 */
function renderedPermissionBoxes(Testable $component): array
{
    $boxes = [];

    foreach ($component->instance()->form->getFlatComponents() as $field) {
        if ($field instanceof Checkbox && str_starts_with($field->getName(), 'permissions.')) {
            $boxes[substr($field->getName(), strlen('permissions.'))] = (string) $field->getLabel();
        }
    }

    return $boxes;
}

/**
 * @return array<int, string>
 */
function sortedPermissionNames(Role $role): array
{
    return $role->permissions()->pluck('name')->sort()->values()->all();
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->superAdmin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($this->superAdmin, 'super_admin');
});

it('leaves a seeded role with exactly the permissions it had when saved untouched', function (string $roleName, string $lockoutPermission) {
    $role = Role::where('name', $roleName)->firstOrFail();
    $before = sortedPermissionNames($role);

    // Not vacuous: the role holds something, including the permission whose loss
    // locked everybody out.
    expect($before)->not->toBeEmpty()
        ->and(in_array($lockoutPermission, $before, true))->toBeTrue("{$roleName} should hold {$lockoutPermission} before the save");

    Livewire::actingAs($this->superAdmin)
        ->test(EditRole::class, ['record' => $role->getKey()])
        ->call('save')
        ->assertHasNoErrors();

    // An exact comparison of sorted arrays, so a permission ADDED by the save
    // fails this as surely as one removed.
    expect(sortedPermissionNames($role->fresh()))->toBe($before);
})->with([
    'admin' => ['admin', 'access_admin_panel'],
    'staff' => ['staff', 'access_admin_panel'],
    'student' => ['student', 'access_student_portal'],
]);

/*
 * The seven permissions the grid does not draw, written out by hand from
 * RolePermissionSeeder::ROLE_EXTRA: Shield's restore, restore_any, replicate, reorder,
 * force_delete and force_delete_any, and delete_any, for the role model. Nothing in the
 * application can use them (RoleGridTest proves it), so they have no box and are carried
 * through a save instead.
 */
const HIDDEN_SEEDED_PERMISSIONS = [
    'delete_any_role',
    'force_delete_any_role',
    'force_delete_role',
    'reorder_role',
    'replicate_role',
    'restore_any_role',
    'restore_role',
];

it('draws a box for every seeded permission except the seven nothing can use', function (string $page) {
    $mounted = match ($page) {
        'edit' => Livewire::actingAs($this->superAdmin)
            ->test(EditRole::class, ['record' => Role::where('name', 'staff')->firstOrFail()->getKey()]),
        'create' => Livewire::actingAs($this->superAdmin)->test(CreateRole::class),
    };

    $offered = array_keys(renderedPermissionBoxes($mounted));
    $seeded = Permission::query()->pluck('name')->all();

    // Not vacuous: the permissions whose absence from the form caused the bug, and one of the hidden.
    expect($seeded)->toContain('access_admin_panel', 'access_student_portal', 'create_enrollment', 'view_own_balance', 'restore_role');

    $missing = array_values(array_diff($seeded, $offered));
    sort($missing);

    // Lists the names, so a failure says which permission has no checkbox.
    expect($missing)->toBe(HIDDEN_SEEDED_PERMISSIONS);
})->with(['edit', 'create']);

it('draws no permission twice', function () {
    $mounted = Livewire::actingAs($this->superAdmin)->test(CreateRole::class);

    $names = [];

    foreach ($mounted->instance()->form->getFlatComponents() as $field) {
        if ($field instanceof Checkbox && str_starts_with($field->getName(), 'permissions.')) {
            $names[] = $field->getName();
        }
    }

    // Not vacuous: ninety-eight boxes, counted by hand: 105 seeded permissions less the seven hidden.
    expect($names)->toHaveCount(98)
        ->and(array_values(array_unique($names)))->toBe($names);
});

it('only draws permissions the seeder creates, so ticking one never invents a permission', function () {
    $offered = array_keys(renderedPermissionBoxes(
        Livewire::actingAs($this->superAdmin)->test(CreateRole::class),
    ));

    $unknown = array_values(array_diff($offered, Permission::query()->pluck('name')->all()));

    expect($offered)->not->toBeEmpty()->and($unknown)->toBe([]);
});

it('lets a role created in the editor be granted panel access, and its user then gets in', function () {
    Livewire::actingAs($this->superAdmin)->test(CreateRole::class)
        ->fillForm([
            'name' => 'front_desk_trainee',
            'permissions' => ['access_admin_panel' => true, 'view_any_student' => true],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $role = Role::where('name', 'front_desk_trainee')->firstOrFail();

    expect(sortedPermissionNames($role))->toBe(['access_admin_panel', 'view_any_student']);

    $user = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($user, 'front_desk_trainee');

    expect($user->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();

    $this->actingAs($user->fresh())->get('/admin')->assertSuccessful();
});

it('keeps a role without the panel permission out of the panel', function () {
    // The pair for the test above: the same role ticked WITHOUT
    // access_admin_panel is refused, so the permission is what lets the user in
    // and not something the editor grants regardless.
    Livewire::actingAs($this->superAdmin)->test(CreateRole::class)
        ->fillForm([
            'name' => 'read_only_trainee',
            'permissions' => ['view_any_student' => true],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $role = Role::where('name', 'read_only_trainee')->firstOrFail();
    expect(sortedPermissionNames($role))->toBe(['view_any_student']);

    $user = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($user, 'read_only_trainee');

    expect($user->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('has a label and a sentence for every special permission in both languages', function () {
    $custom = array_keys(renderedPermissionBoxes(
        Livewire::actingAs($this->superAdmin)->test(CreateRole::class),
    ));

    // Every custom permission of config/filament-shield.php that is not a plain
    // view, create, edit or delete cell: the thirty special ones, counted by hand.
    $special = array_values(array_filter(
        config('filament-shield.custom_permissions'),
        fn (string $name): bool => ! preg_match('/^(view_any|view|create|update|delete)_(enrollment|staff_certificate)$/', $name),
    ));

    expect($special)->toHaveCount(30)
        ->and(array_diff($special, $custom))->toBe([]);

    foreach (['en', 'ar'] as $locale) {
        $catalogue = require lang_path("{$locale}/staff.php");

        $missing = array_values(array_filter(
            $special,
            fn (string $name): bool => blank($catalogue['permissions'][$name] ?? null) || blank($catalogue['permission_help'][$name] ?? null),
        ));

        expect($missing)->toBe([], "{$locale}: label or sentence missing");
    }
});

it('renders each special permission under its translated label, not a headline of its key', function () {
    $boxes = renderedPermissionBoxes(Livewire::actingAs($this->superAdmin)->test(CreateRole::class));

    // Read from the English file itself rather than through __(): a key missing
    // from the file makes __() return the key, and a label compared with that
    // would agree with the same mistake.
    $strings = (require lang_path('en/staff.php'))['permissions'];

    expect($boxes)->toHaveKey('access_admin_panel');

    $wrong = [];

    foreach (['access_admin_panel', 'manage_pricing', 'run_payroll', 'view_own_balance'] as $name) {
        if ($boxes[$name] !== $strings[$name] || $boxes[$name] === Str::headline($name)) {
            $wrong[] = "{$name} rendered as '{$boxes[$name]}'";
        }
    }

    expect($wrong)->toBe([]);
});

it('takes the rendered label from the translation file, not from the code', function () {
    // A sentinel nobody would write in code: if the label were hardcoded, or
    // derived from the key, this would not appear.
    app('translator')->addLines(
        ['staff.permissions.access_admin_panel' => 'SENTINEL-LABEL-4c1e'],
        app()->getLocale(),
    );

    $boxes = renderedPermissionBoxes(Livewire::actingAs($this->superAdmin)->test(CreateRole::class));

    expect($boxes['access_admin_panel'])->toBe('SENTINEL-LABEL-4c1e');
});

it('ticks every box when select all is switched on, and mirrors the boxes afterwards', function () {
    $create = Livewire::actingAs($this->superAdmin)->test(CreateRole::class);

    $offered = array_keys(renderedPermissionBoxes($create));
    sort($offered);

    // Not vacuous: ninety-eight boxes, counted by hand, including the permission whose loss
    // locked everybody out.
    expect($offered)->toHaveCount(98)
        ->and(in_array('access_admin_panel', $offered, true))->toBeTrue();

    $create->assertSet('data.select_all', false)->set('data.select_all', true);

    $ticked = array_keys(array_filter($create->get('data.permissions')));
    sort($ticked);

    // Exactly the visible boxes: the seven hidden permissions are not ticked by it.
    expect($ticked)->toBe($offered);

    // The other direction: unticking one box switches select all off again, so
    // the switch is counted by the boxes and not merely driving them.
    $create->set('data.permissions.access_admin_panel', false);

    expect($create->get('data.select_all'))->toBeFalse();
});

it('shows a role its permissions as ticked, under their translated labels', function () {
    app('translator')->addLines(
        [
            'staff.permissions.access_admin_panel' => 'SENTINEL-VIEW-HELD-91ab',
            'staff.permissions.manage_pricing' => 'SENTINEL-VIEW-NOT-HELD-5d07',
        ],
        app()->getLocale(),
    );

    $staff = Role::where('name', 'staff')->firstOrFail();

    $view = Livewire::actingAs($this->superAdmin)
        ->test(ViewRole::class, ['record' => $staff->getKey()])
        ->assertOk()
        ->assertSee('SENTINEL-VIEW-HELD-91ab')
        ->assertSee('SENTINEL-VIEW-NOT-HELD-5d07');

    // The seventeen permissions the seeder gives staff, written out by hand from
    // RolePermissionSeeder. Not manage_pricing, which is why it is shown but not ticked.
    $ticked = array_keys(array_filter($view->get('data.permissions')));
    sort($ticked);

    expect($ticked)->toBe([
        'access_admin_panel',
        'complete_assigned_batch_enrollment',
        'create_enrollment',
        'create_student',
        'issue_portal_credential',
        'reset_portal_credential',
        'update_assigned_batch_enrollment',
        'view_any_batch',
        'view_any_course',
        'view_any_enrollment',
        'view_any_student',
        'view_any_student_certificate',
        'view_batch',
        'view_course',
        'view_enrollment',
        'view_student',
        'view_student_certificate',
    ]);

    // And the page itself answers over HTTP, the way a person reaches it.
    $this->actingAs($this->superAdmin)
        ->get('/admin/shield/roles/'.$staff->getKey())
        ->assertSuccessful()
        ->assertSee('SENTINEL-VIEW-HELD-91ab');
});
