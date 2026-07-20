<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Resources that receive the standard CRUD permission set.
     *
     * 'role' is included so that negative assertions work: Spatie throws
     * PermissionDoesNotExist for an unknown permission name rather than
     * returning false, so a permission must exist for a test to prove a
     * role does NOT have it.
     */
    private const RESOURCES = [
        'user', 'staff_profile', 'student', 'course', 'batch', 'enrollment',
        'activity', 'role',
    ];

    private const ACTIONS = ['view_any', 'view', 'create', 'update', 'delete'];

    /** Abilities that are not plain CRUD. */
    private const CUSTOM = [
        'access_admin_panel',
        'assign_role',
        'reset_user_password',
        'assign_instructor',
        'manage_settings',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                Permission::findOrCreate("{$action}_{$resource}", 'web');
            }
        }

        foreach (self::CUSTOM as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions(Permission::all());

        Role::findOrCreate('admin', 'web')->syncPermissions([
            ...$this->crudFor('student'),
            ...$this->crudFor('course'),
            ...$this->crudFor('batch'),
            ...$this->crudFor('enrollment'),
            ...$this->crudFor('staff_profile'),
            ...$this->crudFor('user'),
            'view_any_activity', 'view_activity',
            'access_admin_panel',
            'reset_user_password',
            'assign_instructor',
        ]);

        Role::findOrCreate('staff', 'web')->syncPermissions([
            'view_any_student', 'view_student',
            'view_any_course', 'view_course',
            'view_any_batch', 'view_batch',
            'view_any_enrollment', 'view_enrollment',
            'create_enrollment', 'update_enrollment',
            'access_admin_panel',
        ]);

        // Students reach the portal in phase 3, never the admin panel.
        Role::findOrCreate('student', 'web')->syncPermissions([]);
    }

    /** @return array<int, string> */
    private function crudFor(string $resource): array
    {
        return array_map(
            fn (string $action): string => "{$action}_{$resource}",
            self::ACTIONS,
        );
    }
}
