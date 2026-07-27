<?php

declare(strict_types=1);

return [
    'activity' => 'Activity',
    'activity_log' => 'Activity log',

    'when' => 'When',
    'who' => 'Who',
    'action' => 'Action',
    'record' => 'Record',
    'changes' => 'Changes',
    'ip' => 'IP address',
    'system' => 'System',
    'no_ip' => 'No IP (console or scheduled task)',
    'from' => 'From',
    'until' => 'Until',
    'log' => 'Log',

    /*
     * Event labels. Keyed by the stored `event` value so the column never
     * renders a raw identifier — and so phase 4 translates the log rather than
     * reading `roles_changed` in an Arabic panel.
     */
    'event' => [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'restored' => 'Restored',
        'deleted_by_cascade' => 'Deleted with its parent',
        'roles_changed' => 'Roles changed',
        'permissions_changed' => 'Permissions changed',
        'password_reset' => 'Password reset by an administrator',
        'password_changed' => 'Password changed by the account holder',
        'photo_updated' => 'Photo updated',
        'photo_removed' => 'Photo removed',
        'logged_in' => 'Signed in',
        'logged_out' => 'Signed out',
        'login_failed' => 'Failed sign-in attempt',
    ],

    /*
     * Record-type labels, keyed by class basename. class_basename() alone would
     * put "StaffProfile" in front of a reader, and would put an English class
     * name in front of an Arabic one.
     */
    'record_type' => [
        'User' => 'Staff account',
        'Role' => 'Role',
        'StaffProfile' => 'Staff profile',
        'StaffCertificate' => 'Staff certificate',
        'Student' => 'Student',
        'Course' => 'Course',
        'Batch' => 'Batch',
        'Enrollment' => 'Enrolment',
    ],

    // The composite shapes. Separator AND order are localisable — see
    // lang/en/enrollment.php for why these are keys rather than interpolation.
    'change_line' => ':field: :from → :to',
    'change_added' => ':field: :to',
    'empty_value' => '—',
    'no_changes' => 'No field changes recorded',
];
