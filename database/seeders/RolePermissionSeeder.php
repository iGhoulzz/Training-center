<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
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
        'user', 'staff_profile', 'staff_certificate', 'student', 'course',
        'batch', 'enrollment', 'activity', 'role',
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

    /**
     * Extra abilities on the Role resource that Shield's generated RolePolicy
     * references but the standard CRUD set does not cover.
     *
     * These must be seeded rather than left to `shield:generate`. Otherwise a
     * freshly provisioned environment has a RolePolicy referencing permissions
     * that do not exist, and every one of these actions silently returns false
     * for everybody — including super_admin.
     */
    private const ROLE_EXTRA = [
        'delete_any_role',
        'force_delete_role',
        'force_delete_any_role',
        'restore_role',
        'restore_any_role',
        'replicate_role',
        'reorder_role',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Role and permission writes here run with no authenticated actor, so
        // they go through the trusted system-setup path rather than the
        // request-path Actions (which require and authorize an actor). See
        // App\Domain\Staff\Actions\SystemRoleWriter.
        $writer = app(SystemRoleWriter::class);

        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                Permission::findOrCreate("{$action}_{$resource}", 'web');
            }
        }

        foreach ([...self::CUSTOM, ...self::ROLE_EXTRA] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $writer->syncRolePermissions(
            Role::findOrCreate('super_admin', 'web'),
            Permission::all(),
        );

        $writer->syncRolePermissions(Role::findOrCreate('admin', 'web'), [
            ...$this->crudFor('student'),
            ...$this->crudFor('course'),
            ...$this->crudFor('batch'),
            ...$this->crudFor('enrollment'),
            ...$this->crudFor('staff_profile'),
            // Certificates are permissioned separately from the profile that
            // owns them: the scanned document carries a national ID number and
            // a date of birth, so "may see who teaches what" and "may open
            // everyone's identity documents" are kept as two grants. Admin
            // holds both; staff and student hold neither.
            ...$this->crudFor('staff_certificate'),
            ...$this->crudFor('user'),
            'view_any_activity', 'view_activity',
            'access_admin_panel',
            'reset_user_password',
            'assign_instructor',
            // Admins onboard staff, so they need to assign roles. The boundary
            // is guard 1, not the absence of this permission: only a super
            // admin may grant or revoke super_admin, enforced in
            // SyncUserRolesAction and UserPolicy. Without assign_role an admin
            // could create an account but never give it a role, producing an
            // account that cannot reach any panel.
            'assign_role',
        ]);

        $writer->syncRolePermissions(Role::findOrCreate('staff', 'web'), [
            'view_any_student', 'view_student',
            'view_any_course', 'view_course',
            'view_any_batch', 'view_batch',
            'view_any_enrollment', 'view_enrollment',
            'create_enrollment', 'update_enrollment',
            'access_admin_panel',
        ]);

        // Students reach the portal in phase 3, never the admin panel.
        $writer->syncRolePermissions(Role::findOrCreate('student', 'web'), []);
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
