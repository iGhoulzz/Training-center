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
    'deleted_account' => ':name (account deleted)',
    'unknown_actor' => 'Deleted account',
    'details' => 'Details',
    'subject_reference' => ':type #:id',
    'property_line' => ':key: :value',
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
        'receipt_generated' => 'Receipt generated',
        'logged_in' => 'Signed in',
        'logged_out' => 'Signed out',
        'login_failed' => 'Failed sign-in attempt',
        /*
         * Instructor hour allocations (P1-T15, group 3 finding H2). Assignment
         * and a change of hours are separate events because they answer
         * different questions in a phase 2 payroll dispute: "who put Sara on
         * this batch" and "who moved her from 18 hours to 30".
         */
        'instructor_assigned' => 'Instructor assigned',
        'instructor_hours_changed' => 'Instructor hours changed',
        'instructor_removed' => 'Instructor removed',
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

        /*
         * Phase 2 (P2-T01). Every Finance model using RecordsActivity needs a
         * label here or the log shows a raw class name. LocalizationTest finds
         * them by scanning for the trait rather than from a hardcoded list, so
         * a model added later fails the build until somebody names it — which
         * is how this block came to exist rather than being remembered.
         *
         * These read as the centre says them, not as the schema spells them: a
         * charge is a "bill" and a payment is a "receipt" throughout the
         * enrol-and-collect flow, and an audit line a person reads should use
         * the word they were handed at the desk.
         */
        'Discount' => 'Discount',
        'Charge' => 'Bill',
        'Payment' => 'Receipt',
        'PaymentTender' => 'Payment tender',
        'PaymentAllocation' => 'Payment allocation',
        'StaffCompensation' => 'Compensation rate',
        'PayrollRun' => 'Payroll run',
        'PayrollLine' => 'Payroll line',
        'PayrollLineAdjustment' => 'Payroll line adjustment',
    ],

    // The composite shapes. Separator AND order are localisable — see
    // lang/en/enrollment.php for why these are keys rather than interpolation.
    'change_line' => ':field: :from → :to',
    'change_added' => ':field: :to',
    'empty_value' => '—',
    'no_changes' => 'No field changes recorded',
];
