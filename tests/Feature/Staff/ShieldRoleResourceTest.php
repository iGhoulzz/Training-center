<?php

declare(strict_types=1);

use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\EditRole;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as ShieldCreateRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Shield integration (P1-T04c). Shield's own role pages write permissions by
 * calling Role::syncPermissions() directly — an unauthorized mutation surface
 * once the model guards are gone. The app-owned RoleResource replaces them and
 * routes writes through UpdateRolePermissionsAction. These tests pin that the
 * replacement is the only role resource on the panel and that its write pages
 * are the app-owned ones.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('registers the app-owned role resource and stands Shield down', function () {
    $roleResources = array_values(array_filter(
        Filament::getPanel('admin')->getResources(),
        fn (string $resource): bool => str_contains($resource, 'RoleResource'),
    ));

    expect($roleResources)->toBe([RoleResource::class])
        ->and(RoleResource::getModel())->toBe(Role::class);
});

it('routes the role write pages through app-owned pages, not Shield defaults', function () {
    // The app pages override afterSave()/afterCreate() to call the Action; the
    // methods must be declared on the app classes, not inherited from Shield.
    expect((new ReflectionMethod(EditRole::class, 'afterSave'))->class)->toBe(EditRole::class)
        ->and((new ReflectionMethod(CreateRole::class, 'afterCreate'))->class)->toBe(CreateRole::class)
        // ...and they are the app pages sitting on top of Shield's form/table.
        ->and(is_subclass_of(EditRole::class, ShieldEditRole::class))->toBeTrue()
        ->and(is_subclass_of(CreateRole::class, ShieldCreateRole::class))->toBeTrue();
});

it('lets an authorized super admin open the roles list', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->get('/admin/shield/roles')
        ->assertSuccessful();
});

it('forbids staff from the roles list', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)
        ->get('/admin/shield/roles')
        ->assertForbidden();
});
