<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Arabic: the public certificate verifier (P4-T17a)
|--------------------------------------------------------------------------
|
| The Arabic of lang/en/verify.php. DRAFT: the wording is pending the owner's (or a
| translator's) review and is meant to be revised freely. What is not free is the
| shape: the keys must equal the English file's exactly, and every :placeholder must
| survive (it may move within its sentence, never be translated). Both are enforced
| by tests/Feature/Localisation/CatalogueParityTest.php.
|
| THE NOT-FOUND PAGE STILL RENDERS NOTHING BUT THESE KEYS. Its bytes must be
| identical for a malformed, an unknown and an empty lookup, in Arabic as in English,
| so no line here may be made to depend on what was submitted.
|
| Modern Standard Arabic, in the formal register a Libyan legal-studies and training
| centre uses with the public. The reader is addressed in the respectful plural,
| which is gender-neutral, or not at all. The three statuses use the glossary's
| terms: صالحة (valid), مستبدَلة (replaced), ملغاة (revoked).
*/

return [
    'page_title' => 'التحقق من الشهادة',

    'form_heading' => 'التحقق من شهادة',
    'form_intro' => 'يرجى إدخال الرقم المرجعي المطبوع على الشهادة للتأكد من أنها صادرة عن المركز.',
    'reference_label' => 'الرقم المرجعي للشهادة',
    'reference_placeholder' => 'مثال: TC-2026-7K4M9Q2R',
    'submit_button' => 'تحقق',

    'show_heading' => 'التحقق من الشهادة',
    'field_status' => 'الحالة',
    'field_student' => 'الطالب',
    'field_course' => 'الدورة',
    'field_completed_on' => 'تاريخ الإتمام',
    'field_issued_at' => 'تاريخ الإصدار',
    'field_confirmation' => 'جهة التأكيد',

    // The status as a single word, in the glossary's terms.
    'status_valid' => 'صالحة',
    'status_revoked' => 'ملغاة',
    'status_replaced' => 'مستبدَلة',

    // One sentence per status, as in English. Only the revoked one interpolates the
    // date, which the controller fills in as YYYY-MM-DD.
    'status_message_valid' => 'هذه الشهادة صالحة.',
    'status_message_revoked' => 'هذه الشهادة ملغاة بتاريخ :date ولم تعد صالحة.',
    'status_message_replaced' => 'هذه الشهادة مستبدَلة بشهادة أحدث، ولم تعد سارية.',

    'verify_another' => 'التحقق من شهادة أخرى',

    'not_found_heading' => 'لم يُعثر على الشهادة',
    'not_found_body' => 'تعذّر التحقق من شهادة بهذا الرقم المرجعي. يرجى مراجعة الرقم المرجعي كما هو مطبوع تمامًا ثم المحاولة مرة أخرى.',

    'eyebrow' => 'التأكد من الشهادة',
    'back_to_site' => 'العودة إلى الموقع العام',
    'reference_hint' => 'يرجى إدخال الرقم المرجعي كاملًا كما هو مطبوع على الشهادة.',
    'result_intro' => 'يرجى مطابقة البيانات المطبوعة على الشهادة مع سجل المركز.',
    'privacy_note' => 'يعرض التحقق بيانات الشهادة فقط، أما بيانات التواصل مع الطالب والمعلومات المالية فتبقى سرّية.',
];
