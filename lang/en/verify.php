<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The public certificate verifier (design section 6.5, P3-T08)
|--------------------------------------------------------------------------
|
| Every string on all three verify/* views comes from here. The not-found view
| in particular renders no submitted value and no error bag — its whole body
| is these keys and nothing else — which is what makes the byte-identical
| assertion across a malformed GET, an unknown GET, an empty POST and a
| malformed POST possible at all.
|
| Every value is a whole sentence, never a fragment assembled in code —
| design section 12's rule for composite strings, applied even though none of
| these happen to interpolate anything today.
*/

return [
    'page_title' => 'Certificate verification',

    'form_heading' => 'Verify a certificate',
    'form_intro' => 'Enter the reference number printed on the certificate to confirm it was issued by the centre.',
    'reference_label' => 'Certificate reference',
    'reference_placeholder' => 'e.g. TC-2026-7K4M9Q2R',
    'submit_button' => 'Verify',

    'show_heading' => 'Certificate verification',
    'field_status' => 'Status',
    'field_student' => 'Student',
    'field_course' => 'Course',
    'field_completed_on' => 'Completed on',
    'field_issued_at' => 'Issued on',
    'field_confirmation' => 'Confirmed by',

    // Exactly the three values CertificateStatus admits — no interpolation,
    // because the sentence itself differs per status rather than a label
    // being dropped into one shared template.
    'status_message_valid' => 'This certificate is valid.',
    'status_message_revoked' => 'This certificate was revoked on :date and is no longer valid.',
    'status_message_replaced' => 'This certificate has been superseded by a more recent certificate and is no longer current.',

    'verify_another' => 'Verify another certificate',

    'not_found_heading' => 'Certificate not found',
    'not_found_body' => 'We could not verify a certificate with that reference. Check the reference exactly as printed and try again.',
];
