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
];
