<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student portal
|--------------------------------------------------------------------------
|
| T1 ships the panel itself; its four pages arrive in T7, which extends this
| file. The brand is here rather than hardcoded in StudentPanelProvider because
| it is the one user-facing string the panel renders on its own — the login page
| and the password form draw everything else from auth.php.
|
| lang/ar/portal.php ships empty. Arabic arrives in phase 4; the structure is
| enforced from commit one, and LocalizationTest derives its Arabic-empty dataset
| from this directory.
|
| T7's three read-only pages reuse existing catalogues wherever one already
| exists — EnrollmentStatus::label() and StudentStatus::label() read
| enrollment.php, CertificateStatus::label() reads certificates.php — rather
| than duplicating a status vocabulary this file has no reason to own a second
| copy of. Everything below is specific to how the portal's own pages present
| that data: headings, column labels, and the composite strings design section
| 12 requires each to have its own key rather than being assembled in code.
*/

return [
    'brand' => 'Student Portal',

    /*
    |--------------------------------------------------------------------------
    | Overview — name, student code, status
    |--------------------------------------------------------------------------
    */
    'overview_title' => 'Overview',
    'overview_navigation_label' => 'Overview',
    'overview_name' => 'Name',
    'overview_student_code' => 'Student code',
    'overview_status' => 'Status',

    /*
    |--------------------------------------------------------------------------
    | My enrolments — course, batch, dates, status, and (view_own_certificate
    | only) the certificate reference and status
    |--------------------------------------------------------------------------
    */
    'my_enrollments_title' => 'My enrolments',
    'my_enrollments_navigation_label' => 'My enrolments',
    'enrollments_course' => 'Course',
    'enrollments_batch' => 'Batch',
    'enrollments_enrolled_on' => 'Enrolled on',
    'enrollments_completed_on' => 'Completed on',
    'enrollments_status' => 'Status',
    'enrollments_certificate_reference' => 'Certificate reference',
    'enrollments_certificate_status' => 'Certificate status',
    'no_enrollments' => 'You are not enrolled in anything yet.',
    // Placeholders for "nothing to show here" — not completed yet, no valid
    // certificate — following the same em dash lang/en/certificates.php's
    // no_charge already uses for the identical kind of blank.
    'not_completed' => '—',
    'no_certificate' => '—',

    /*
    |--------------------------------------------------------------------------
    | My balance — per-enrolment outstanding and a total
    |--------------------------------------------------------------------------
    */
    'my_balance_title' => 'My balance',
    'my_balance_navigation_label' => 'My balance',
    'balance_enrollment' => 'Enrolment',
    'balance_outstanding' => 'Outstanding',
    'balance_total' => 'Total',
    // A composite string with its own key rather than assembled by joining
    // fragments — design section 12. The enrolment is identified by id alone;
    // see MyBalance's own docblock for why no course/batch name is shown here.
    'balance_enrollment_row' => 'Enrolment #:id',
    // Matches charges.amount_lyd / collect.amount_lyd / payments.amount_lyd —
    // each catalogue keeps its own copy rather than sharing one across domains.
    'amount_lyd' => ':amount LYD',
];
