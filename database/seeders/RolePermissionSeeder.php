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
        'batch', 'enrollment', 'role',
    ];

    /**
     * The activity log gets READ permissions only, and is therefore not in the
     * CRUD list above.
     *
     * create_activity, update_activity and delete_activity are deliberately not
     * created in production. The log is append-only: entries are written by the
     * package, never by a person, and no role may edit or remove one. Seeding
     * abilities nothing may honour invites somebody to wire them up later.
     *
     * ActivityAppendOnlyTest creates delete_activity inside the test, grants it
     * to a super admin, and proves ActivityPolicy refuses anyway — which is a
     * stronger statement than "the permission does not exist".
     */
    private const ACTIVITY_READ = ['view_any_activity', 'view_activity'];

    /**
     * Finance resources, which take READ permissions only.
     *
     * Deliberately absent from the CRUD list above: the standard set would
     * create update_charge, delete_payment and their siblings, which phase 2's
     * design forbids. The handful of finance writes that do exist are named one
     * by one in FINANCE_WRITE below.
     */
    private const FINANCE_RESOURCES = [
        'charge', 'payment', 'discount', 'staff_compensation', 'payroll_run',
    ];

    /**
     * Every finance write ability there is.
     *
     * create_staff_compensation and not update_staff_compensation: the only
     * legitimate change to a rate is a new effective-dated row, so create IS the
     * write ability for that table and ChangeCompensationAction authorizes on
     * it. There is no update counterpart to omit by accident.
     *
     * delete_payroll_run removes a draft. A finalized run is refused by
     * PayrollRunPolicy whoever holds this, because posting is the point at which
     * the figures stop being provisional.
     *
     * create_charge, update_charge, delete_charge, update_payment,
     * delete_payment, update_staff_compensation, update_payroll_run and
     * update_discount are deliberately not created, for the reason already
     * recorded above for the activity log: their policies refuse
     * unconditionally, and seeding an ability nothing may honour invites
     * somebody to wire it up later. Each has a test that grants the permission
     * anyway and proves the policy still refuses — a stronger statement than
     * "the permission does not exist".
     *
     * No create_discount either. A discount definition is created, deactivated
     * and deleted under manage_pricing: setting the rates the centre charges is
     * one capability, wherever it happens to be expressed.
     *
     * Charge issuance needs no ability at all. EnrollAndBillAction raises the
     * bill as a system consequence of create_enrollment, which is what lets a
     * staff member holding nothing financial still complete a walk-in.
     */
    private const FINANCE_WRITE = [
        'create_payment',
        'create_staff_compensation',
        'delete_payroll_run',
    ];

    private const ACTIONS = ['view_any', 'view', 'create', 'update', 'delete'];

    private const READ_ACTIONS = ['view_any', 'view'];

    /** Abilities that are not plain CRUD. */
    private const CUSTOM = [
        'access_admin_panel',
        'assign_role',
        'reset_user_password',
        'assign_instructor',
        'manage_settings',
        // "May edit enrolments, but only on batches this actor is assigned to
        // teach." Separate from update_enrollment, which is unrestricted, so the
        // scope distinction in spec line 110 is carried by permissions rather
        // than by a role check. See EnrollmentPolicy::update().
        'update_assigned_batch_enrollment',
        // Phase 2 finance. These are bare verbs rather than CRUD on a resource
        // because each names an act, not a row: run_payroll and
        // finalize_payroll are two separate decisions about the same table, and
        // manage_pricing gates course prices, batch prices and the discount
        // definitions as one capability. Only apply_discount,
        // view_financial_report and export_financial_report reach below super
        // admin; see the admin grant.
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

        foreach (self::FINANCE_RESOURCES as $resource) {
            foreach (self::READ_ACTIONS as $action) {
                Permission::findOrCreate("{$action}_{$resource}", 'web');
            }
        }

        $bare = [
            ...self::ACTIVITY_READ,
            ...self::FINANCE_WRITE,
            ...self::CUSTOM,
            ...self::ROLE_EXTRA,
        ];

        foreach ($bare as $ability) {
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
            // Finance: an admin reads every figure and records money coming in,
            // but sets no rate and undoes nothing. Deliberately absent are
            // manage_pricing, adjust_charge, write_off_charge, reverse_payment,
            // run_payroll, finalize_payroll and delete_payroll_run — super
            // admin only, and every one of those denials is a negative test.
            ...$this->readFor('charge'),
            ...$this->readFor('payment'),
            ...$this->readFor('discount'),
            ...$this->readFor('staff_compensation'),
            ...$this->readFor('payroll_run'),
            'create_payment',
            // Choosing a pre-approved discount, which an admin may do, is a
            // different act from setting the centre's rates, which they may not.
            // Hence a permission of its own rather than a share of
            // manage_pricing.
            'apply_discount',
            'view_financial_report',
            'export_financial_report',
        ]);

        $writer->syncRolePermissions(Role::findOrCreate('staff', 'web'), [
            // Staff register walk-in students and see the whole register, but do
            // not amend or remove existing records: correcting a name or
            // deleting a student is an administrative act. create without
            // update is deliberate, not an oversight.
            'view_any_student', 'view_student', 'create_student',
            'view_any_course', 'view_course',
            'view_any_batch', 'view_batch',
            'view_any_enrollment', 'view_enrollment',
            // Creation is unscoped: a front-desk staffer enrols walk-ins into any
            // open batch. Editing is not — the scoped ability below is restricted
            // to batches this actor teaches. Deliberately NOT update_enrollment,
            // which is the unrestricted grant; holding it would make
            // EnrollmentPolicy::update() return true before the scoping ran.
            'create_enrollment', 'update_assigned_batch_enrollment',
            'access_admin_panel',
            // Nothing financial, deliberately — not a reading permission, not
            // apply_discount, nothing. A walk-in enrolment still completes:
            // EnrollAndBillAction raises the bill off create_enrollment above,
            // so no charge ability is required to do the job. Staff enrol at
            // full price and the discount selector does not render for them.
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

    /** @return array<int, string> */
    private function readFor(string $resource): array
    {
        return array_map(
            fn (string $action): string => "{$action}_{$resource}",
            self::READ_ACTIONS,
        );
    }
}
