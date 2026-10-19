<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Arabic: publications, the staff screens and the public library (P4-T17a)
|--------------------------------------------------------------------------
|
| The Arabic of lang/en/publications.php. DRAFT: the wording is pending the owner's
| (or a translator's) review and is meant to be revised freely. What is not free is
| the shape: the keys must equal the English file's exactly, every :placeholder must
| survive, and a pluralised line keeps Arabic's six forms. All three are enforced by
| tests/Feature/Localisation/CatalogueParityTest.php.
|
| PLURALS. Laravel numbers Arabic's forms 0 to 5: zero, one, two, few (3-10), many
| (11-99), other (100 and over). It reads the form from `count % 100`, so 103 is
| "few" and 111 "many". A pluralised line therefore has six plain segments in that
| order, never the English two and never explicit `[3,10]` ranges, which would be
| tested against the whole number. A form that says its number in words ("منشور
| واحد", "منشوران") leaves :count out; every other placeholder stays in all six.
|
| Modern Standard Arabic, in the formal register a Libyan legal-studies and training
| centre uses with the public. The staff screens are impersonal, and the public pages
| address the reader in the respectful plural, which is gender-neutral. Numbers keep
| Western digits, as Libya writes them.
|
| Two values are the English character for character, on purpose: `public.pdf`, the
| name of a file format, and `public.date_format`, a PHP date pattern. The parity
| test lists both in CATALOGUE_PARITY_IDENTICAL.
*/

return [
    'article' => 'مقال',
    'articles' => 'المقالات',

    // Fields. Also the names a refusal uses.
    'title_en' => 'العنوان (بالإنجليزية)',
    'title_ar' => 'العنوان (بالعربية)',
    'title_ar_help' => 'اختياري. إذا تُرك هذا الحقل فارغًا، يرى قرّاء النسخة العربية من الموقع العنوان الإنجليزي.',
    'slug' => 'عنوان الرابط',
    'slug_help' => 'الجزء الأخير من العنوان العام للمقال. يصبح ثابتًا بعد نشر المقال، فتبقى الروابط التي جرت مشاركتها تعمل.',
    'description_en' => 'الوصف (بالإنجليزية)',
    'description_ar' => 'الوصف (بالعربية)',
    'description_ar_help' => 'اختياري. إذا تُرك هذا الحقل فارغًا، يرى قرّاء النسخة العربية من الموقع الوصف الإنجليزي.',
    'topic' => 'الموضوع',
    'topic_help' => 'تُجمع المقالات ذات الموضوع الواحد معًا للقرّاء.',
    'authors' => 'المؤلفون',
    'issued_on' => 'تاريخ الإصدار',
    'pdf_file' => 'ملف PDF',
    'pdf_file_help' => 'مستند PDF لا يتجاوز حجمه :megabytes ميغابايت.',
    'replacement_pdf_file' => 'استبدال ملف PDF',
    'replacement_pdf_file_help' => 'يُترك هذا الحقل فارغًا للإبقاء على الملف الحالي. يحل الملف الجديد محل القديم لدى القرّاء بمجرد الحفظ. مستند PDF لا يتجاوز حجمه :megabytes ميغابايت.',
    'original_filename' => 'اسم الملف',

    // The list.
    'status' => 'الحالة',
    'published' => 'منشور',
    'unpublished' => 'غير منشور',
    'published_at' => 'تاريخ النشر',
    'download_count' => 'التنزيلات',
    'download_count_help' => 'عدد تقريبي، لأن الاحتساب محدود بمعدل معين ولا تُستبعد فيه التنزيلات المتكررة من القارئ نفسه.',
    'all_articles' => 'جميع المقالات',
    'create_article' => 'إضافة مقال',
    'view_article' => 'عرض',
    'edit_article' => 'تعديل',

    // Publishing and withdrawing.
    'publish' => 'نشر',
    'publish_modal_heading' => 'هل تريد نشر هذا المقال؟',
    'publish_modal_description' => 'سيظهر المقال على الموقع العام، وسيتمكن أي شخص من تنزيل ملف PDF الخاص به.',
    'published_successfully' => 'تم نشر المقال',
    'unpublish' => 'إلغاء النشر',
    'unpublish_modal_heading' => 'هل تريد إلغاء نشر هذا المقال؟',
    'unpublish_modal_description' => 'سيختفي المقال من الموقع العام فورًا، ويتوقف رابط تنزيله عن العمل. يُحتفظ بالمقال وبملف PDF الخاص به، ويمكن نشره مرة أخرى.',
    'unpublished_successfully' => 'تم إلغاء نشر المقال',

    // Refusals.
    'already_published' => 'هذا المقال منشور بالفعل.',
    'already_unpublished' => 'هذا المقال غير منشور.',
    'slug_locked' => 'لا يمكن تغيير عنوان الرابط ما دام المقال منشورًا. يرجى إلغاء نشره أولًا.',
    'refused_unauthorized' => 'ليست لديك صلاحية لتنفيذ هذا الإجراء.',
    'refused_invalid' => 'تعذّر حفظ المقال. يرجى مراجعة البيانات والمحاولة مرة أخرى.',
    'storage_unavailable' => 'تعذّر تخزين ملف PDF، ولذلك لم يُحفظ أي شيء. يرجى المحاولة مرة أخرى، وإبلاغ المسؤول إذا تكررت المشكلة.',

    // The public library.
    'public' => [
        'heading' => 'المنشورات',
        'intro' => 'أوراق وأدلة نشرها المركز، مرتبة من الأحدث إلى الأقدم. ويُنزَّل كل منها بصيغة PDF الأصلية.',
        'library_eyebrow' => 'المكتبة',
        'back_home' => 'العودة إلى المركز',

        'search_label' => 'البحث في المنشورات',
        'search_placeholder' => 'بحث في العناوين والمواضيع والمؤلفين والأوصاف',
        'search_submit' => 'بحث',

        'topics_label' => 'المواضيع',
        'topic_all' => 'الكل',

        'sort_newest' => 'الأحدث أولًا',
        'sort_downloads' => 'الأكثر تنزيلًا',
        // What a screen reader says the sort button does: the current order, then
        // the one it switches to.
        'sort_switch' => 'الترتيب الحالي: :current. التبديل إلى: :next.',
        'downloads_note' => 'أعداد التنزيل تقريبية.',

        // Six plural forms each: zero, one, two, few, many, other. See the header.
        'count' => 'لا توجد منشورات|منشور واحد|منشوران|:count منشورات|:count منشورًا|:count منشور',
        'count_in_topic' => 'لا توجد منشورات في :topic|منشور واحد في :topic|منشوران في :topic|:count منشورات في :topic|:count منشورًا في :topic|:count منشور في :topic',
        'count_matching' => 'لا توجد منشورات مطابقة للبحث “:search”|منشور واحد مطابق للبحث “:search”|منشوران مطابقان للبحث “:search”|:count منشورات مطابقة للبحث “:search”|:count منشورًا مطابقًا للبحث “:search”|:count منشور مطابق للبحث “:search”',
        'count_in_topic_matching' => 'لا توجد منشورات في :topic مطابقة للبحث “:search”|منشور واحد في :topic مطابق للبحث “:search”|منشوران في :topic مطابقان للبحث “:search”|:count منشورات في :topic مطابقة للبحث “:search”|:count منشورًا في :topic مطابقًا للبحث “:search”|:count منشور في :topic مطابق للبحث “:search”',

        'empty_library' => 'لا توجد منشورات معروضة حاليًا.',
        'empty_heading' => 'لا توجد نتائج مطابقة لهذا البحث',
        'empty_body' => 'يمكنكم تجربة موضوع آخر أو مسح حقل البحث.',
        'empty_clear' => 'عرض جميع المنشورات',

        'downloads' => 'لا تنزيلات|تنزيل واحد|تنزيلان|:count تنزيلات|:count تنزيلًا|:count تنزيل',
        'download_pdf' => 'تنزيل PDF',
        'download_pdf_named' => 'تنزيل PDF: :title',
        // The short label on a library card's PDF link and on a document thumbnail.
        'pdf' => 'PDF',
        // There is no online reader, so the second button opens the article's page.
        'read_more' => 'قراءة المزيد',
        'read_more_named' => 'قراءة المزيد عن :title',

        'pagination_label' => 'صفحات المنشورات',
        'previous' => 'الصفحة السابقة',
        'next' => 'الصفحة التالية',
        'page_of' => 'الصفحة :current من :last',

        // A PHP date() pattern, rendered in the reader's locale.
        'date_format' => 'd M Y',
        'issued' => 'صدر في :date',

        'back' => 'جميع المنشورات',
        'original_filing' => 'النسخة الأصلية',
        'uploaded_by' => 'رُفع بواسطة الإدارة',
        'full_text_note' => 'يُوزَّع النص الكامل لهذه الورقة بصيغة PDF الأصلية كما أُودع لدى المركز تمامًا. يمكنكم تنزيله من الأعلى لقراءة الورقة كاملة بما فيها من حجج وجداول ومراجع قانونية.',
        'more_in' => 'المزيد في :topic',
    ],

    // The upload field's validation wording (P4-T31). A draft, like the rest of
    // this catalogue, until the owner or a translator reviews it.
    'pdf_file_attribute' => 'ملف PDF',
    'pdf_file_not_pdf' => 'يجب أن يكون الملف بصيغة PDF.',
];
