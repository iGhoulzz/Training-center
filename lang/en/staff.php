<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Staff accounts, profiles and certificates (P1-T14)
|--------------------------------------------------------------------------
|
| Help text here deliberately does NOT restate file-size limits or accepted
| formats. Those live on the Actions as constants, Filament enforces them, and
| a sentence repeating them becomes a lie the first time a constant changes.
|
| The refusal strings are whole sentences: several are exception messages as
| well as notification titles, and an exception message that reads as a
| fragment is useless in a log.
*/

return [
    // Resource labels.
    'user' => 'User',
    'users' => 'Users',
    'staff_profile' => 'Staff profile',
    'staff_profiles' => 'Staff profiles',
    'certificates' => 'Certificates',

    // Account fields.
    'name' => 'Name',
    'email' => 'Email',
    'phone' => 'Phone',
    'locale' => 'Language',
    'locale_en' => 'English',
    'locale_ar' => 'Arabic',
    'is_active' => 'Active',
    'roles' => 'Roles',
    'last_login' => 'Last login',

    // Profile fields.
    'job_title' => 'Job title',
    'employment_type' => 'Employment type',
    'hire_date' => 'Hire date',
    'qualifications' => 'Qualifications',
    'profile_photo' => 'Photo',

    // Certificate fields.
    'certificate_title' => 'Title',
    'certificate_file' => 'File',
    'issued_on' => 'Issued on',
    'expires_on' => 'Expires on',
    'expired' => 'Expired',

    // Actions.
    'create_user' => 'New user',
    'create_staff_profile' => 'New staff profile',
    'edit_staff_profile' => 'Edit staff profile',
    'reset_password' => 'Reset password',
    'change_password' => 'Change password',
    'upload_certificate' => 'Upload certificate',
    'download' => 'Download',
    'delete_photo' => 'Remove photo',

    // Empty-state placeholders.
    'never' => 'Never',
    'never_expires' => 'Does not expire',
    'no_account' => 'No account',
    'no_hire_date' => 'Not recorded',
    'no_issue_date' => 'Not recorded',
    'no_job_title' => 'No job title',

    // Helper text.
    'certificate_file_help' => 'A PDF or an image. Stored privately and only ever served to staff who may view it.',
    'profile_photo_help' => 'A square image works best. Stored privately, never on a public URL.',

    // Notifications and refusals.
    'temp_password_generated' => 'Temporary password generated',
    'password_reset_refused' => 'You are not allowed to reset this password.',
    'create_refused_no_password_permission' => 'Creating a user requires the password-reset permission, because a new account needs a temporary password.',
    'save_refused_unauthorized' => 'You are not allowed to make this change.',
    'save_refused_last_super_admin' => 'The last active super admin cannot be deactivated or stripped of the role.',
    'delete_refused_unauthorized' => 'You are not allowed to delete this user.',
    'delete_refused_last_super_admin' => 'The last active super admin cannot be deleted.',
    'photo_refused_unauthorized' => 'You are not allowed to change this photo.',
    'photo_invalid' => 'That photo could not be accepted.',
    'certificate_delete_refused' => 'You are not allowed to delete this certificate.',
    'profile_delete_refused_certificates' => 'This profile holds certificates you are not allowed to delete.',

    /*
     * Storage failures (P1-T15, domain-integrity finding 3).
     *
     * Says nothing was saved, because nothing was: the write is refused before
     * the owning row commits, and the provisional receipt is dispatched for
     * cleanup. The administrator's next question is whether to re-enter the
     * form, and this answers it.
     *
     * It names no disk and no path. Those ride on the exception for the log,
     * where an operator can act on them; in the panel they are an internal
     * storage layout nobody can do anything with.
     */
    'storage_unavailable' => 'The file could not be stored, because the server is out of space or its storage is unavailable. Nothing was saved. Report this to whoever administers the server, then try again.',

    /*
     * The append-only refusal (P1-T15, group 3 finding H1).
     * ActivityLogIsAppendOnlyException raises this as its exception message, so
     * it must exist or the refusal surfaces as a raw key.
     */
    /*
     * An event logged without being declared in ActivityEvent (P1-T15, review of
     * M5). Raised by UnregisteredActivityEventException.
     */
    'unregistered_activity_event' => 'The activity event :event is not declared in ActivityEvent, so it has no label and would be shown to an administrator as a raw value. Add a constant for it.',

    'activity_log_append_only' => 'The activity log is append-only. Entries are never removed, and no retention window applies to it.',

    /*
     * Escalation-guard messages (P1-T04c). The guards raise these as exception
     * messages, so they must exist or a refusal surfaces as a raw key.
     */
    'escalation' => [
        'last_super_admin' => 'The last active super admin cannot be removed or deactivated.',
        'requires_assign_role' => 'Managing roles and permissions requires the role-assignment permission.',
    ],

    /*
     * Enum labels, reached by interpolation from EmploymentType::label() rather
     * than by a literal __() call. See the note in lang/en/enrollment.php.
     *
     * PLURAL, and deliberately not 'employment_type', which is the field label
     * above. A key holding an array cannot also be read as a string: Filament
     * takes ->label() as string|Closure|Htmlable and fatals on an array, which
     * is exactly what a single 'employment_type' key produced.
     */
    'employment_types' => [
        'instructor' => 'Instructor',
        'administrative' => 'Administrative',
        'support' => 'Support',
    ],

    /*
     * Labels for the permissions on the role editor's custom tab (P4-T21).
     *
     * One key per name in config/filament-shield.php's `custom_permissions`,
     * read by RoleResource::getTabFormComponentForCustomPermissions(). Each says
     * what holding the permission lets someone do, because the checkbox is the
     * only place an administrator sees it. A name added to that list without a
     * key here would render as the key itself; RoleEditorPermissionsTest fails
     * first.
     *
     * Where a label says "their own" or "they teach", the permission is scoped:
     * the portal reads for a student's own rows, and the assigned-batch pair for
     * batches the actor is assigned to teach (see EnrollmentPolicy).
     */
    'permissions' => [
        'access_admin_panel' => 'Sign in to the admin panel',
        'access_student_portal' => 'Sign in to the Trainee Portal',
        'adjust_charge' => 'Adjust the amount of a charge',
        'apply_discount' => 'Apply a discount when enrolling a student',
        'assign_instructor' => 'Assign instructors to batches',
        'assign_role' => 'Assign roles to users',
        'complete_assigned_batch_enrollment' => 'Mark enrolments complete on batches they teach',
        'complete_enrollment' => 'Mark any enrolment complete',
        'create_enrollment' => 'Enrol students in batches',
        'create_staff_certificate' => 'Upload staff certificates',
        'delete_enrollment' => 'Delete enrolments',
        'delete_staff_certificate' => 'Delete staff certificates',
        'export_financial_report' => 'Export financial reports',
        'finalize_payroll' => 'Finalize a payroll run',
        'issue_portal_credential' => 'Issue a student a portal login',
        'issue_student_certificate' => 'Issue student certificates',
        'manage_pricing' => 'Set course prices, batch prices and discounts',
        'manage_settings' => 'Change system settings',
        'publish_article' => 'Publish articles',
        'replace_student_certificate' => 'Replace student certificates',
        'reset_portal_credential' => 'Reset a student\'s portal password',
        'reset_user_password' => 'Reset staff passwords (also needed to create user accounts)',
        'reverse_expense' => 'Reverse a recorded expense',
        'reverse_payment' => 'Reverse a recorded payment',
        'revoke_student_certificate' => 'Revoke student certificates',
        'run_payroll' => 'Run payroll',
        'unpublish_article' => 'Unpublish articles',
        'update_assigned_batch_enrollment' => 'Edit enrolments on batches they teach',
        'update_enrollment' => 'Edit any enrolment',
        'update_staff_certificate' => 'Edit staff certificates',
        'view_any_enrollment' => 'See the list of enrolments',
        'view_any_staff_certificate' => 'See the list of staff certificates',
        'view_enrollment' => 'Open an enrolment',
        'view_financial_report' => 'View financial reports',
        'view_own_balance' => 'See their own balance in the portal',
        'view_own_certificate' => 'See their own certificates in the portal',
        'view_own_enrollment' => 'See their own enrolments in the portal',
        'view_own_student_record' => 'See their own student record in the portal',
        'view_staff_certificate' => 'Open a staff certificate',
        'write_off_charge' => 'Write off a charge',
    ],
    /*
     * The role editor's permission grid (P4-T42): one row per area, one column per ability,
     * then the special permissions with a sentence each. Row labels use the sidebar's headings
     * (navigation.*) for the groups; `permission_help` is the sentence under each special
     * permission, keyed by its name like `permissions` above.
     */
    'grid' => [
        'role_section' => 'Role',
        'special_heading' => 'Special permissions',
        'special_hint' => 'Each of these allows one specific act. Tick only what the role needs.',
        'cell' => ':area: :ability',
        'abilities' => [
            'view_any' => 'View list',
            'view' => 'View',
            'create' => 'Create',
            'update' => 'Edit',
            'delete' => 'Delete',
        ],
        'rows' => [
            'enrollments' => 'Enrolments',
            'students' => 'Students',
            'batches' => 'Batches',
            'courses' => 'Courses',
            'student_certificates' => 'Student certificates',
            'charges' => 'Charges',
            'payments' => 'Payments',
            'discounts' => 'Discounts',
            'expenses' => 'Expenses',
            'expense_categories' => 'Expense categories',
            'payroll_runs' => 'Payroll runs',
            'staff_compensation' => 'Staff pay rates',
            'articles' => 'Articles',
            'users' => 'Users',
            'roles' => 'Roles',
            'staff_profiles' => 'Staff profiles',
            'staff_certificates' => 'Staff certificates',
            'activity' => 'Activity log',
        ],
        'special_groups' => [
            'access' => 'Access',
            'enrolment' => 'Students and enrolment',
            'finance' => 'Finance',
            'publications' => 'Publications',
            'administration' => 'Administration',
            'portal' => 'Trainee Portal (a student\'s own data)',
            'other' => 'Other',
        ],
    ],

    'permission_help' => [
        'access_admin_panel' => 'Without this a user cannot open the staff dashboard, whatever else the role holds.',
        'access_student_portal' => 'Lets a student account open the Trainee Portal.',
        'adjust_charge' => 'Corrects a bill after it was raised. The change is recorded and the earlier amount is kept.',
        'apply_discount' => 'Picks an existing discount at enrolment. It does not allow creating or changing discounts.',
        'assign_instructor' => 'Adds instructors to a batch and removes them.',
        'assign_role' => 'Gives roles to user accounts and removes them. Also needed to change what a role can do.',
        'complete_assigned_batch_enrollment' => 'Marks an enrolment complete, only on batches the person is assigned to teach.',
        'complete_enrollment' => 'Marks an enrolment complete, on any batch.',
        'export_financial_report' => 'Downloads a financial report as a file.',
        'finalize_payroll' => 'Posts a payroll run. After that its figures can no longer change.',
        'issue_portal_credential' => 'Creates a portal login for a student who has an email address.',
        'issue_student_certificate' => 'Issues a certificate to a student who completed a course.',
        'manage_pricing' => 'Sets course prices, batch prices and the discount definitions.',
        'manage_settings' => 'Changes the centre\'s system settings.',
        'publish_article' => 'Makes an article public on the website.',
        'replace_student_certificate' => 'Issues a corrected certificate in place of an earlier one.',
        'reset_portal_credential' => 'Sets a new password for a student\'s portal login.',
        'reset_user_password' => 'Sets a new password for a staff account. Also needed to create user accounts.',
        'reverse_expense' => 'Cancels a recorded expense. It stays in the history, marked as reversed.',
        'reverse_payment' => 'Cancels a recorded payment. It stays in the history, marked as reversed.',
        'revoke_student_certificate' => 'Withdraws a certificate that was issued.',
        'run_payroll' => 'Creates a payroll run and adds adjustments to its draft.',
        'unpublish_article' => 'Takes a published article off the website. The article and its PDF are kept.',
        'update_assigned_batch_enrollment' => 'Edits an enrolment, only on batches the person is assigned to teach.',
        'view_financial_report' => 'Opens the financial reports and the revenue figures.',
        'view_own_balance' => 'A student sees their own balance in the portal.',
        'view_own_certificate' => 'A student sees their own certificates in the portal.',
        'view_own_enrollment' => 'A student sees their own enrolments in the portal.',
        'view_own_student_record' => 'A student sees their own record in the portal.',
        'write_off_charge' => 'Writes off a bill that cannot be collected. The bill and the reason stay on record.',
    ],

    /*
     * Account rules (P4-T33).
     *
     * Nobody deactivates their own account: a lone administrator could lock
     * themselves out mid-task, and the last-super-admin invariant only catches the
     * case where nobody else is left. The sentence is the refusal's whole message,
     * shown as a toast title and as the exception text.
     */
    'cannot_deactivate_self' => 'You cannot deactivate your own account. Ask another administrator to do it.',
    'is_active_own_account_hint' => 'You cannot deactivate your own account.',
    'is_active_super_admin_hint' => 'A super admin cannot be deactivated here. This is done on the server.',
    'account_details' => 'Account details and access',
    'users_and_roles' => 'Users & roles',
    'users_description' => 'Administration · staff accounts and their profiles in one place',
    'profile_summary' => ':job · :type',
    'active' => 'Active',
    'deactivated' => 'Deactivated',
    'activity_link' => 'Activity',
    'payroll_link' => 'Payroll',
    'profile_details' => 'Employment details',
    'profile_documents' => 'Qualifications and photo',
    'role_details' => 'Role and permissions',
    'guard' => 'Authentication guard',
    'updated_at' => 'Last updated',

    // Admin table (P4-T41): headers for the links out of the staff list.
    'activity_column' => 'Activity log',
    'payroll_column' => 'Pay rates',
];
