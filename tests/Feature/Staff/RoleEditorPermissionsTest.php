<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\EditRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
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
 * The cure is a tab for the permissions no other tab offers. These tests drive
 * the rendered form rather than reading config back, so they hold however the
 * tab is built.
 *
 * THE ACTOR IS A SUPER ADMIN BECAUSE ONLY A SUPER ADMIN MAY EDIT ROLES.
 * RolePolicy decides that, and it is unchanged here. Nothing in this file is an
 * authorization test; RolePolicyTest and SecurityReviewRegressionTest own those.
 */
uses(RefreshDatabase::class);

/**
 * Every checkbox list the mounted form really renders, keyed by field name,
 * each mapped to the permission names it offers.
 *
 * A component inside a hidden tab is not returned (the default of
 * getFlatComponents()), so a tab that is switched off offers nothing — which is
 * what a person using the form experiences.
 *
 * @return array<string, array<int, string>>
 */
function renderedPermissionLists(Testable $component): array
{
    $lists = [];

    foreach ($component->instance()->form->getFlatComponents() as $field) {
        if ($field instanceof CheckboxList) {
            $lists[$field->getName()] = array_map('strval', array_keys($field->getOptions()));
        }
    }

    return $lists;
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

it('offers every permission the seeder creates as a checkbox on the form', function (string $page) {
    $mounted = match ($page) {
        'edit' => Livewire::actingAs($this->superAdmin)
            ->test(EditRole::class, ['record' => Role::where('name', 'staff')->firstOrFail()->getKey()]),
        'create' => Livewire::actingAs($this->superAdmin)->test(CreateRole::class),
    };

    $offered = array_merge(...array_values(renderedPermissionLists($mounted)));
    $seeded = Permission::query()->pluck('name')->all();

    // Not vacuous: the permissions whose absence from the form caused the bug.
    expect($seeded)->toContain('access_admin_panel', 'access_student_portal', 'create_enrollment', 'view_own_balance');

    $missing = array_values(array_diff($seeded, $offered));
    sort($missing);

    // Lists the names, so a failure says which permission has no checkbox.
    expect($missing)->toBe([]);
})->with(['edit', 'create']);

it('puts no custom permission on a second tab', function () {
    $lists = renderedPermissionLists(
        Livewire::actingAs($this->superAdmin)->test(CreateRole::class),
    );

    expect($lists)->toHaveKey('custom_permissions_tab');

    $custom = $lists['custom_permissions_tab'];
    unset($lists['custom_permissions_tab']);

    // Not vacuous: there is something on the tab and something on the others.
    expect($custom)->not->toBeEmpty()->and($lists)->not->toBeEmpty();

    $duplicated = [];

    foreach ($lists as $fieldName => $names) {
        foreach (array_intersect($custom, $names) as $name) {
            $duplicated[] = "{$name} is also offered by {$fieldName}";
        }
    }

    expect($duplicated)->toBe([]);
});

it('only lists permissions the seeder creates, so ticking one never invents a permission', function () {
    $custom = renderedPermissionLists(
        Livewire::actingAs($this->superAdmin)->test(CreateRole::class),
    )['custom_permissions_tab'] ?? [];

    $unknown = array_values(array_diff($custom, Permission::query()->pluck('name')->all()));

    expect($custom)->not->toBeEmpty()->and($unknown)->toBe([]);
});

it('lets a role created in the editor be granted panel access, and its user then gets in', function () {
    $create = Livewire::actingAs($this->superAdmin)->test(CreateRole::class);

    // The field that offers view_any_student is found on the rendered form, not
    // assumed: its name is a resource class name.
    $studentField = collect(renderedPermissionLists($create))
        ->filter(fn (array $names): bool => in_array('view_any_student', $names, true))
        ->keys()
        ->first();

    expect($studentField)->toBeString();

    $create
        ->fillForm([
            'name' => 'front_desk_trainee',
            'guard_name' => 'web',
            'custom_permissions_tab' => ['access_admin_panel'],
            $studentField => ['view_any_student'],
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
    $create = Livewire::actingAs($this->superAdmin)->test(CreateRole::class);

    $studentField = collect(renderedPermissionLists($create))
        ->filter(fn (array $names): bool => in_array('view_any_student', $names, true))
        ->keys()
        ->first();

    $create
        ->fillForm([
            'name' => 'read_only_trainee',
            'guard_name' => 'web',
            $studentField => ['view_any_student'],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $role = Role::where('name', 'read_only_trainee')->firstOrFail();
    expect(sortedPermissionNames($role))->toBe(['view_any_student']);

    $user = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($user, 'read_only_trainee');

    expect($user->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('has a translation for the label of every custom permission', function () {
    $custom = renderedPermissionLists(
        Livewire::actingAs($this->superAdmin)->test(CreateRole::class),
    )['custom_permissions_tab'] ?? [];

    expect($custom)->not->toBeEmpty();

    $untranslated = array_values(array_filter(
        $custom,
        fn (string $name): bool => ! Lang::has("staff.permissions.{$name}"),
    ));

    expect($untranslated)->toBe([]);
});

it('renders each custom permission under its translated label, not a headline of its key', function () {
    $mounted = Livewire::actingAs($this->superAdmin)->test(CreateRole::class);

    $field = collect($mounted->instance()->form->getFlatComponents())
        ->first(fn ($component): bool => $component instanceof CheckboxList && $component->getName() === 'custom_permissions_tab');

    expect($field)->toBeInstanceOf(CheckboxList::class);

    $options = $field->getOptions();

    expect($options)->not->toBeEmpty();

    $wrong = [];

    foreach ($options as $name => $label) {
        if ($label !== __("staff.permissions.{$name}") || $label === Str::headline((string) $name)) {
            $wrong[] = "{$name} rendered as '{$label}'";
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

    $options = collect(Livewire::actingAs($this->superAdmin)->test(CreateRole::class)
        ->instance()->form->getFlatComponents())
        ->first(fn ($component): bool => $component instanceof CheckboxList && $component->getName() === 'custom_permissions_tab')
        ->getOptions();

    expect($options['access_admin_panel'])->toBe('SENTINEL-LABEL-4c1e');
});
