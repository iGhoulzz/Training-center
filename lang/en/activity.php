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
         * Phase 3 (P3-T04). Same rule as the Finance block below: a model
         * using RecordsActivity needs a label here or the log shows a raw
         * class name, and LocalizationTest finds them by scanning for the
         * trait rather than from a list — so this entry was required by a
         * failing build, not remembered.
         *
         * "Student certificate", not "Certificate": StaffCertificate is a
         * scanned instructor credential and this is a document the centre
         * issued to a student. An audit line naming the wrong one sends a
         * reader to the wrong record.
         */
        'StudentCertificate' => 'Student certificate',

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

        // Phase 4 (P4-T03). A document in the public library.
        'Article' => 'Article',

        // Phase 4 (P4-T04). Money paid out other than wages, and the managed list
        // it is recorded against.
        'Expense' => 'Expense',
        'ExpenseCategory' => 'Expense category',
    ],

    // The composite shapes. Separator AND order are localisable — see
    // lang/en/enrollment.php for why these are keys rather than interpolation.
    'change_line' => ':field: :from → :to',
    'change_added' => ':field: :to',
    'empty_value' => '—',
    'no_changes' => 'No field changes recorded',
    'append_only_description' => 'Append-only · cleaning is refused',
    'field' => [
        'course_id' => 'course_id',
        'code' => 'code',
        'start_date' => 'start_date',
        'end_date' => 'end_date',
        'capacity' => 'capacity',
        'total_hours' => 'total_hours',
        'price' => 'price',
        'status' => 'status',
        'name_en' => 'name_en',
        'name_ar' => 'name_ar',
        'description_en' => 'description_en',
        'description_ar' => 'description_ar',
        'default_price' => 'default_price',
        'is_active' => 'is_active',
        'student_id' => 'student_id',
        'batch_id' => 'batch_id',
        'enrolled_at' => 'enrolled_at',
        'completed_at' => 'completed_at',
        'user_id' => 'user_id',
        'student_code' => 'student_code',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'email' => 'email',
        'phone' => 'phone',
        'national_id' => 'national_id',
        'date_of_birth' => 'date_of_birth',
        'gender' => 'gender',
        'address' => 'address',
        'notes' => 'notes',
        'enrollment_id' => 'enrollment_id',
        'student_name' => 'student_name',
        'course_name' => 'course_name',
        'completed_on' => 'completed_on',
        'issued_at' => 'issued_at',
        'issued_by' => 'issued_by',
        'replaces_certificate_id' => 'replaces_certificate_id',
        'revoked_at' => 'revoked_at',
        'revoked_by' => 'revoked_by',
        'revocation_reason' => 'revocation_reason',
        'list_price' => 'list_price',
        'discount_id' => 'discount_id',
        'discount_percentage' => 'discount_percentage',
        'amount' => 'amount',
        'due_date' => 'due_date',
        'written_off_at' => 'written_off_at',
        'written_off_by' => 'written_off_by',
        'written_off_reason' => 'written_off_reason',
        'name' => 'name',
        'percentage' => 'percentage',
        'expense_category_id' => 'expense_category_id',
        'paid_on' => 'paid_on',
        'description' => 'description',
        'recorded_by' => 'recorded_by',
        'reversed_at' => 'reversed_at',
        'reversed_by' => 'reversed_by',
        'reversal_reason' => 'reversal_reason',
        'received_at' => 'received_at',
        'payment_id' => 'payment_id',
        'charge_id' => 'charge_id',
        'method' => 'method',
        'external_reference' => 'external_reference',
        'payroll_run_id' => 'payroll_run_id',
        'staff_compensation_id' => 'staff_compensation_id',
        'batch_instructor_id' => 'batch_instructor_id',
        'corrects_payroll_line_id' => 'corrects_payroll_line_id',
        'segment_start' => 'segment_start',
        'segment_end' => 'segment_end',
        'frozen_rate' => 'frozen_rate',
        'frozen_hours' => 'frozen_hours',
        'frozen_days' => 'frozen_days',
        'frozen_days_in_month' => 'frozen_days_in_month',
        'computed_amount' => 'computed_amount',
        'posting_period_start' => 'posting_period_start',
        'reason' => 'reason',
        'finalized_at' => 'finalized_at',
        'payroll_line_id' => 'payroll_line_id',
        'created_by' => 'created_by',
        'type' => 'type',
        'period_start' => 'period_start',
        'period_end' => 'period_end',
        'finalized_by' => 'finalized_by',
        'effective_from' => 'effective_from',
        'effective_to' => 'effective_to',
        'title_en' => 'title_en',
        'title_ar' => 'title_ar',
        'slug' => 'slug',
        'topic' => 'topic',
        'authors' => 'authors',
        'issued_on' => 'issued_on',
        'original_filename' => 'original_filename',
        'path' => 'path',
        'published_at' => 'published_at',
        'staff_profile_id' => 'staff_profile_id',
        'title' => 'title',
        'expires_on' => 'expires_on',
        'job_title' => 'job_title',
        'hire_date' => 'hire_date',
        'employment_type' => 'employment_type',
        'qualifications' => 'qualifications',
        'guard_name' => 'guard_name',
        'locale' => 'locale',
        'must_change_password' => 'must_change_password',
        'added' => 'added',
        'removed' => 'removed',
        'assigned_hours' => 'assigned_hours',
    ],
];
