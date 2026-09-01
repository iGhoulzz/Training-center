<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student certificates — the register and its three transitions (P3-T05)
|--------------------------------------------------------------------------
|
| CertificateStatus::label() (T4) already reads certificate_status.{value}
| from this file — those three keys exist from this task, since T4's own file
| scope explicitly leaves this catalogue to T5. Everything else here is
| IssueStudentCertificateAction / ReplaceStudentCertificateAction /
| RevokeStudentCertificateAction's refusals, and StudentCertificateResource's
| labels and forms.
|
| Every value below is a whole sentence or label, never a fragment assembled
| in code — design section 12 requires a composite string to be its own
| translation key because the separator and ordering are both localisable.
*/

return [
    // CertificateStatus::label() — the three, and only three, values
    // chk_student_certificates_status admits.
    'certificate_status' => [
        'valid' => 'Valid',
        'revoked' => 'Revoked',
        'replaced' => 'Replaced',
    ],

    // IssueStudentCertificateAction's refusals.
    'enrollment_not_completed' => 'A certificate can only be issued for an enrolment that has been marked completed.',
    'certificate_already_issued' => 'A valid certificate already exists for this enrolment.',

    // ReplaceStudentCertificateAction's and RevokeStudentCertificateAction's
    // shared refusal — there is no valid certificate to act on.
    'no_valid_certificate' => 'There is no valid certificate for this enrolment to act on.',

    /*
    |--------------------------------------------------------------------------
    | StudentCertificateResource — labels, columns and the three actions
    |--------------------------------------------------------------------------
    */

    'certificate' => 'Certificate',
    'certificates' => 'Certificates',

    'reference_number' => 'Reference',
    'student' => 'Student',
    'course' => 'Course',
    'completed_on' => 'Completed on',
    'issued_at' => 'Issued on',
    'issued_by' => 'Issued by',
    'status' => 'Status',
    'revoked_at' => 'Revoked on',
    'revoked_by' => 'Revoked by',
    'revocation_reason' => 'Revocation reason',
    'replaces' => 'Replaces',
    'reason' => 'Reason',

    // Composite strings, each with its own key rather than assembled by
    // joining fragments — design section 12.
    'enrollment_option_label' => ':code — :name — :course',
    'outstanding_balance_lyd' => ':amount LYD',
    'no_charge' => '—',

    // IssueStudentCertificateAction's form — a header action on the register,
    // not a per-record one: issuing has no existing certificate row to attach
    // to yet, only a completed enrolment.
    'issue' => 'Issue certificate',
    'issue_modal_heading' => 'Issue a certificate',
    'enrollment' => 'Enrolment',
    'outstanding_balance' => 'Outstanding balance',
    'outstanding_balance_hint' => 'Shown for information only. An outstanding balance never blocks issuance.',
    'issued_successfully' => 'Certificate issued.',

    // ReplaceStudentCertificateAction's form — a record action on the
    // currently valid certificate.
    'replace' => 'Replace',
    'replace_modal_heading' => 'Replace this certificate',
    'replace_modal_description' => 'The current certificate will be marked replaced and a new one issued in its place, carrying the student and course details as they stand now.',
    'replaced_successfully' => 'Certificate replaced.',

    // RevokeStudentCertificateAction's form — a record action on the
    // currently valid certificate.
    'revoke' => 'Revoke',
    'revoke_modal_heading' => 'Revoke this certificate',
    'revoke_reason_hint' => 'Required. This does not delete the certificate — it stays on the register, marked revoked.',
    'revoked_successfully' => 'Certificate revoked.',
];
