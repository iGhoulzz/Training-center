<?php

declare(strict_types=1);

return [
    'title' => 'سجل التصدير',
    'navigation_label' => 'سجل التصدير',
    'subheading' => 'تعرض هذه الصفحة ملفات التقارير الجدولية المصدرة؛ تصل تقارير PDF كإشعارات ولا تُدرج هنا.',
    'empty' => 'لم تصدر أي تقارير جدولية بعد.',
    'other_export' => 'تصدير آخر',
    'columns' => [
        'report' => 'التقرير',
        'requested_at' => 'وقت الطلب',
        'rows' => 'الصفوف',
        'status' => 'الحالة',
    ],
    'statuses' => [
        'queued' => 'في قائمة الانتظار',
        'overdue' => 'متأخر',
        'ready' => 'جاهز',
        'failed' => 'فشل',
        'unavailable' => 'لم يعد متاحًا',
    ],
    'actions' => [
        'download' => 'تنزيل',
    ],
];
