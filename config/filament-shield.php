<?php

declare(strict_types=1);
use App\Domain\Staff\Filament\Resources\ActivityResource;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

return [

    /*
    |--------------------------------------------------------------------------
    | Shield Resource
    |--------------------------------------------------------------------------
    |
    | Here you may configure the built-in role management resource. You can
    | customize the URL, choose whether to show model paths, group it under
    | a cluster, and decide which permission tabs to display.
    |
    */

    'shield_resource' => [
        'slug' => 'shield/roles',
        'show_model_path' => true,
        'cluster' => null,
        'tabs' => [
            'pages' => true,
            'widgets' => true,
            'resources' => true,
            // On, or the role editor drops every permission it has no checkbox
            // for each time a role is saved (P4-T21). See `custom_permissions`.
            'custom_permissions' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | When your application supports teams, Shield will automatically detect
    | and configure the tenant model during setup. This enables tenant-scoped
    | roles and permissions throughout your application.
    |
    */

    'tenant_model' => null,

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | This value contains the class name of your user model. This model will
    | be used for role assignments and must implement the HasRoles trait
    | provided by the Spatie\Permission package.
    |
    */

    'auth_provider_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    |
    | Here you may define a super admin that has unrestricted access to your
    | application. You can choose to implement this via Laravel's gate system
    | or as a traditional role with all permissions explicitly assigned.
    |
    */

    'super_admin' => [
        'enabled' => true,
        'name' => 'super_admin',
        'define_via_gate' => false,
        'intercept_gate' => 'before',
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel User
    |--------------------------------------------------------------------------
    |
    | When enabled, Shield will create a basic panel user role that can be
    | assigned to users who should have access to your Filament panels but
    | don't need any specific permissions beyond basic authentication.
    |
    */

    'panel_user' => [
        'enabled' => true,
        'name' => 'panel_user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Builder
    |--------------------------------------------------------------------------
    |
    | You can customize how permission keys are generated to match your
    | preferred naming convention and organizational standards. Shield uses
    | these settings when creating permission names from your resources.
    |
    | Supported formats: snake, kebab, pascal, camel, upper_snake, lower_snake
    |
    */

    'permissions' => [
        'separator' => '_',
        'case' => 'snake',
        'generate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    |
    | Shield can automatically generate Laravel policies for your resources.
    | When merge is enabled, the methods below will be combined with any
    | resource-specific methods you define in the resources section.
    |
    */

    'policies' => [
        'path' => app_path('Policies'),

        /*
         * FALSE SO THAT `manage` ACTUALLY OVERRIDES (P1-T15, finding L5).
         *
         * With merge enabled, a resource's `manage` list is combined with the
         * full default method set below rather than replacing it — so the entry
         * restricting RoleResource to five abilities had been decorative since
         * it was written, and restricting ActivityResource to two would have
         * been too. The config's own comment describes `manage` as an override;
         * this is what makes that true.
         *
         * Resources absent from `manage` are unaffected and still get the full
         * set. Only the two that opt in are narrowed.
         */
        'merge' => false,
        'generate' => true,
        'methods' => [
            'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore',
            'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
        ],
        'single_parameter_methods' => [
            'viewAny',
            'create',
            'deleteAny',
            'forceDeleteAny',
            'restoreAny',
            'reorder',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Shield supports multiple languages out of the box. When enabled, you
    | can provide translated labels for permissions to create a more
    | localized experience for your international users.
    |
    */

    'localization' => [
        'enabled' => false,
        'key' => 'filament-shield::filament-shield.resource_permission_prefixes_labels',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | Here you can fine-tune permissions for specific Filament resources.
    | Use the 'manage' array to override the default policy methods for
    | individual resources, giving you granular control over permissions.
    |
    */

    'resources' => [
        'subject' => 'model',
        'manage' => [
            RoleResource::class => [
                'viewAny',
                'view',
                'create',
                'update',
                'delete',
            ],

            /*
             * READ ONLY, BECAUSE THE LOG HAS NO WRITE PATH (P1-T15, finding L5).
             *
             * Shield generates the full CRUD set per resource, so the role form
             * offered twelve *_activity permissions — create, update, delete,
             * delete_any, force_delete, force_delete_any, restore, restore_any,
             * replicate and reorder among them. RolePermissionSeeder creates
             * exactly two, so the rest could never be granted.
             *
             * Nothing was exploitable: ActivityPolicy refuses every one of them
             * whether or not it exists. The harm is that it TEACHES THE WRONG
             * THING — an administrator reading a checkbox labelled "delete
             * activity" reasonably concludes the log is deletable by somebody,
             * and the next person to act on that belief builds a feature to
             * match. The append-only rule should not be contradicted by the
             * permission screen.
             *
             * `manage` rather than `exclude`: excluding the resource would also
             * remove view_any_activity and view_activity, which the seeder does
             * create and the panel does need.
             */
            ActivityResource::class => [
                'viewAny',
                'view',
            ],
        ],
        'exclude' => [
            //
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Most Filament pages only require view permissions. Pages listed in the
    | exclude array will be skipped during permission generation and won't
    | appear in your role management interface.
    |
    */

    'pages' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            Dashboard::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Widgets
    |--------------------------------------------------------------------------
    |
    | Like pages, widgets typically only need view permissions. Add widgets
    | to the exclude array if you don't want them to appear in your role
    | management interface.
    |
    */

    'widgets' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Permissions
    |--------------------------------------------------------------------------
    |
    | Sometimes you need permissions that don't map to resources, pages, or
    | widgets. Define any custom permissions here and they'll be available
    | when editing roles in your application.
    |
    | THE ROLE EDITOR SAVES EXACTLY WHAT ITS FORM SHOWS (P4-T21).
    |
    | EditRole and CreateRole hand the ticked boxes to UpdateRolePermissionsAction,
    | which syncs the role to that list and nothing else. A permission with no
    | checkbox is therefore removed from the role by the next save, however
    | little else changed — which stripped access_admin_panel from every role the
    | first time anyone clicked Save, and meant a role created in the editor could
    | never be given panel access at all.
    |
    | So this list is every permission RolePermissionSeeder creates that no
    | resource, page or widget tab already offers: the abilities that name an act
    | (access, assign, issue, publish, reverse...) rather than a row, plus the
    | enrolment and staff-certificate sets, which have no admin resource of their
    | own. It was worked out by computing the seeded names minus the names the
    | rendered form offers, not by hand, and it is a plain list of names. The
    | labels are not here: RoleResource::getTabFormComponentForCustomPermissions()
    | reads each from `staff.permissions.<name>`.
    |
    | A new permission in the seeder that no tab offers belongs in this list, or
    | it will be dropped from every role the next time one is saved.
    | RoleEditorPermissionsTest fails until it is, and also fails if a name here
    | ever starts appearing on a second tab.
    |
    */

    'custom_permissions' => [
        'access_admin_panel',
        'access_student_portal',
        'adjust_charge',
        'apply_discount',
        'assign_instructor',
        'assign_role',
        'complete_assigned_batch_enrollment',
        'complete_enrollment',
        'create_enrollment',
        'create_staff_certificate',
        'delete_enrollment',
        'delete_staff_certificate',
        'export_financial_report',
        'finalize_payroll',
        'issue_portal_credential',
        'issue_student_certificate',
        'manage_pricing',
        'manage_settings',
        'publish_article',
        'replace_student_certificate',
        'reset_portal_credential',
        'reset_user_password',
        'reverse_expense',
        'reverse_payment',
        'revoke_student_certificate',
        'run_payroll',
        'unpublish_article',
        'update_assigned_batch_enrollment',
        'update_enrollment',
        'update_staff_certificate',
        'view_any_enrollment',
        'view_any_staff_certificate',
        'view_enrollment',
        'view_financial_report',
        'view_own_balance',
        'view_own_certificate',
        'view_own_enrollment',
        'view_own_student_record',
        'view_staff_certificate',
        'write_off_charge',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Discovery
    |--------------------------------------------------------------------------
    |
    | By default, Shield only looks for entities in your default Filament
    | panel. Enable these options if you're using multiple panels and want
    | Shield to discover entities across all of them.
    |
    */

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Policy
    |--------------------------------------------------------------------------
    |
    | Shield can automatically register a policy for role management itself.
    | This lets you control who can manage roles using Laravel's built-in
    | authorization system. Requires a RolePolicy class in your app.
    |
    */

    'register_role_policy' => true,

];
