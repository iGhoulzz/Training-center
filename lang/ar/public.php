<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Arabic: the public site (phase 4, P4-T17a)
|--------------------------------------------------------------------------
|
| The Arabic of lang/en/public.php. DRAFT: the wording is pending the owner's (or
| a translator's) review and is meant to be revised freely. What is not free is the
| shape: the keys must equal the English file's exactly, and every :placeholder must
| survive (it may move within its sentence, never be translated). Both are enforced
| by tests/Feature/Localisation/CatalogueParityTest.php.
|
| Modern Standard Arabic, in the formal register a Libyan legal-studies and
| training centre uses with the public, written as natural sentences rather than
| word for word. The reader is addressed in the respectful plural, which is
| gender-neutral, or not at all. Numbers keep Western digits, as Libya writes them.
|
| The centre's name is not in this file: it comes from config/public_site.php
| (`name_ar`). Some values are the English character for character, on purpose:
| `home.verify_placeholder` (a sample reference) and `programmes.date_format` (a PHP
| date pattern), which the parity test lists in CATALOGUE_PARITY_IDENTICAL, and
| `footer.copyright`, `home.heading` and `page_title`, which hold only placeholders
| and punctuation and have nothing to translate.
*/

return [
    'meta' => [
        'description' => 'دورات تدريبية وشهادات يصدرها المركز، مع سجل عام يمكن من خلاله التحقق من أي شهادة.',
    ],

    'skip_to_content' => 'انتقل إلى المحتوى الرئيسي',

    'header' => [
        'contact_label' => 'بيانات التواصل',
    ],

    'nav' => [
        'label' => 'التنقل الرئيسي',
        'menu' => 'القائمة',
        'verify' => 'التحقق',
    ],

    'portal' => [
        'link' => 'بوابة الطالب',
        'note' => 'ليس لديكم بيانات دخول بعد؟ يصدرها لكم مكتب التسجيل عند تسجيلكم، ويشترط لحساب البوابة وجود بريد إلكتروني في سجل الطالب.',
    ],

    'page_title' => ':page | :name',

    'links' => [
        'about' => 'من نحن',
        'programmes' => 'البرامج التدريبية',
        'publications' => 'المنشورات',
        'verify_certificate' => 'التحقق من شهادة',
        'contact' => 'تواصل معنا',
    ],

    'footer' => [
        'description' => 'دورات تدريبية وشهادات يمكن لأي شخص التحقق منها في السجل العام.',
        'centre_heading' => 'المركز',
        'services_heading' => 'الخدمات',
        'copyright' => '© :year :name',
    ],

    'home' => [
        'heading' => ':name',
        // Must occur inside config('public_site.brand.name_ar'), as the English phrase
        // occurs inside the English name: the home page sets it apart wherever it
        // finds it in the heading. "للدراسات القانونية" is the Arabic name's own words
        // (the "ل" joins "الدراسات"), so it is not the glossary's bare "الدراسات
        // القانونية", which the name does not contain.
        'heading_emphasis' => 'للدراسات القانونية',
        'lead' => 'تدريب مهني وقانوني، وشهادات يمكن لأي شخص التحقق منها في سجل عام.',
        'programmes_link' => 'استعراض البرامج التدريبية',

        'about_heading' => 'عن المركز',
        'about_title' => 'تعليم قانوني يُقاس بالممارسة لا بالنظرية',
        'about_body' => 'يقدّم المركز برامج تدريبية مهنية وقانونية. وتُدرَج كل شهادة يصدرها في سجل عام، فيستطيع أي شخص التأكد منها دون الرجوع إلى المركز.',
        'about_register' => 'تُسجَّل الشهادات الصادرة عن المركز في سجل عام. ويمكن لأي جهة عمل أو محكمة أو نقابة محامين التأكد من أي شهادة في ثوانٍ باستخدام أداة التحقق في هذه الصفحة.',

        'programmes_title' => 'التسجيل مفتوح',
        'request_seat' => 'طلب حجز مقعد',

        'verify_eyebrow' => 'السجل العام',
        'verify_heading' => 'التحقق من شهادة',
        'verify_lead' => 'يرجى إدخال الرقم المرجعي المطبوع على الشهادة. يعرض السجل اسم حامل الشهادة والبرنامج التدريبي وتاريخ الإصدار، ويبيّن بوضوح إن كانت الشهادة مستبدَلة أو ملغاة.',
        'verify_label' => 'الرقم المرجعي للشهادة',
        'verify_placeholder' => 'TC-2026-XXXXXXXX',
        'verify_submit' => 'تحقق',
        'verify_note' => 'لا يُحفظ أي شيء من عملية الاستعلام. ولا يعرض السجل إلا ما هو مطبوع على الشهادة نفسها.',

        'publications_title' => 'أبحاث وتعليقات قانونية',
        'publications_intro' => 'منشورات صادرة عن المركز وقابلة للتنزيل بصيغة PDF الأصلية. ويحمل كل منها تاريخ إصداره.',
        'publications_all' => 'جميع المنشورات',
    ],

    'about' => [
        'heading' => 'عن المركز',
        'intro' => 'يقدّم المركز برامج تدريبية مهنية وقانونية. وتُعرض البرامج في صفحة البرامج التدريبية مع عدد ساعات كل برنامج، وعند جدولة دفعة جديدة يُعرض أيضًا موعد بدايتها وتكلفتها.',
        'register' => 'تُسجَّل كل شهادة يصدرها المركز في سجل عام. ويستطيع كل من يملك الرقم المرجعي المطبوع على الشهادة التحقق منها عبر أداة التحقق، فلا حاجة إلى مراجعة المركز لتأكيدها.',
        'questions' => 'للاستفسار عن برنامج تدريبي أو عن موعد دفعة، يمكنكم مراسلة المركز أو الاتصال به.',

        'certification_heading' => 'شهادات قابلة للتحقق',
        'certification_body' => 'تُدرَج كل شهادة في سجل يمكن لأي شخص التحقق منه عبر الإنترنت.',

        'programmes_link' => 'عرض البرامج التدريبية',
        'contact_link' => 'التواصل مع المركز',
    ],

    'programmes' => [
        'heading' => 'البرامج التدريبية',
        'intro' => 'الدورات التي يقدّمها المركز حاليًا. وتُظهر الدورة التي لها دفعة مجدولة موعد بدايتها وتكلفتها.',
        'empty' => 'لا توجد برامج تدريبية معروضة حاليًا. يرجى التواصل مع المركز للاستفسار عن البرامج القادمة.',

        // Arabic's six plural forms, in the order Laravel numbers them: zero, one,
        // two, few (3-10), many (11-99), other (100 and over).
        'hours' => ':count ساعة|ساعة واحدة|ساعتان|:count ساعات|:count ساعة|:count ساعة',
        'currency' => 'د.ل',
        'starts' => 'يبدأ في :date',
        'date_format' => 'd M Y',

        'no_intake' => 'لم يُعلن عن موعد دفعة جديدة بعد.',
        'contact_cta' => 'تواصل معنا بشأن الدفعة القادمة',

        'back' => 'جميع البرامج التدريبية',
        'ask_heading' => 'هل يهمّكم هذا البرنامج التدريبي؟',
        'ask_body' => 'يمكنكم مراسلة المركز أو الاتصال به للاستفسار عن هذا البرنامج التدريبي.',
        'ask_link' => 'التواصل مع المركز',
    ],

    'contact' => [
        'heading' => 'تواصل معنا',
        'intro' => 'للاستفسار عن برنامج تدريبي أو عن موعد دفعة، يمكنكم مراسلة المركز أو الاتصال به.',
        'eyebrow' => 'زورونا',
        'title' => 'تفضلوا بزيارة المركز',
        'email_label' => 'البريد الإلكتروني',
        'phone_label' => 'الهاتف',
        'directions_title' => 'كيفية الوصول إلى المركز',
        'directions_note' => 'يفتح موقع المركز في تطبيق الخرائط الخاص بكم.',
        'directions' => 'عرض الاتجاهات',
        'directions_new_tab' => '(يُفتح في علامة تبويب جديدة)',
    ],
];
