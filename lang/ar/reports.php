<?php

declare(strict_types=1);

return [
    'navigation_group' => 'التقارير المالية',
    'empty' => 'لا توجد صفوف تطابق هذه المرشحات.',
    'profit_definition' => 'الربح = الإيراد المحصل - تكلفة الأجور المعتمدة - المصروفات المدفوعة خلال الفترة (باستثناء المصروفات المعكوسة).',
    'pages' => [
        'revenue' => [
            'navigation' => 'الإيرادات',
            'title' => 'الإيرادات حسب الدورة والدفعة',
        ],
        'outstanding_aged' => [
            'navigation' => 'الأرصدة المستحقة',
            'title' => 'الأرصدة المستحقة حسب عمر الدين',
        ],
        'payment_method' => [
            'navigation' => 'طرق الدفع',
            'title' => 'توزيع طرق الدفع',
        ],
        'daily_tender' => [
            'navigation' => 'وسائل الدفع اليومية',
            'title' => 'تقرير وسائل الدفع اليومي',
        ],
        'wage_cost' => [
            'navigation' => 'تكلفة الأجور',
            'title' => 'تكلفة الأجور',
        ],
        'profit' => [
            'navigation' => 'الربح',
            'title' => 'الربح',
        ],
        'student_payment_history' => [
            'navigation' => 'دفعات الطالب',
            'title' => 'سجل دفعات الطالب',
        ],
    ],
    'filters' => [
        'from' => 'من',
        'to' => 'إلى',
        'date' => 'التاريخ',
        'month' => 'الشهر',
        'student_id' => 'الطالب',
        'choose_student' => 'اختر طالبًا',
        'not_selected' => 'لم يُحدد',
    ],
    'actions' => [
        'apply_filters' => 'تطبيق المرشحات',
        'export_xlsx' => 'تصدير Excel',
        'export_pdf' => 'تصدير PDF',
        'download_pdf' => 'تنزيل PDF',
        'download_xlsx' => 'تنزيل Excel',
    ],
    'notifications' => [
        'xlsx_ready' => 'ملف Excel جاهز. لم تُصدر أي صفوف.|ملف Excel جاهز. صُدر صف واحد.|ملف Excel جاهز. صُدر صفان.|ملف Excel جاهز. صُدرت :count صفوف.|ملف Excel جاهز. صُدر :count صفًا.|ملف Excel جاهز. صُدر :count صف.',
        'pdf_queued' => 'أُضيف تصدير PDF إلى قائمة الانتظار.',
        'pdf_ready' => 'ملف PDF جاهز.',
    ],
    'values' => [
        'yes' => 'نعم',
        'no' => 'لا',
    ],
    'columns' => [
        'revenue' => [
            'course_code' => 'الدورة',
            'course_total' => 'إيرادات الدورة (د.ل.)',
            'batch_code' => 'الدفعة',
            'batch_total' => 'إيرادات الدفعة (د.ل.)',
        ],
        'outstanding' => [
            'charge_reference' => 'الفاتورة',
            'student_code' => 'رمز الطالب',
            'student_name' => 'الطالب',
            'due_date' => 'تاريخ الاستحقاق',
            'days_past_due' => 'أيام التأخر عن الاستحقاق',
            'bucket' => 'فئة عمر الدين',
            'outstanding' => 'المستحق (د.ل.)',
        ],
        'outstanding_aged' => [
            'charge_reference' => 'الفاتورة',
            'student_code' => 'رمز الطالب',
            'student_name' => 'الطالب',
            'due_date' => 'تاريخ الاستحقاق',
            'days_past_due' => 'أيام التأخر عن الاستحقاق',
            'bucket' => 'فئة عمر الدين',
            'outstanding' => 'المستحق (د.ل.)',
        ],
        'payment_method' => [
            'method' => 'طريقة الدفع',
            'total' => 'الإجمالي (د.ل.)',
        ],
        'daily_tender' => [
            'method' => 'طريقة الدفع',
            'total' => 'الإجمالي (د.ل.)',
        ],
        'wage_cost' => [
            'staff_name' => 'الموظف',
            'total' => 'تكلفة الأجور (د.ل.)',
        ],
        'profit' => [
            'revenue' => 'الإيراد المحصل (د.ل.)',
            'wage_cost' => 'تكلفة الأجور (د.ل.)',
            'expenses' => 'المصروفات المدفوعة (د.ل.، باستثناء المعكوسة)',
            'profit' => 'الربح (د.ل.) = الإيراد - الأجور - المصروفات',
        ],
        'student_payment_history' => [
            'student_code' => 'رمز الطالب',
            'student_name' => 'الطالب',
            'charge_reference' => 'الفاتورة',
            'charge_amount' => 'مبلغ الفاتورة (د.ل.)',
            'due_date' => 'تاريخ الاستحقاق',
            'written_off' => 'مشطوب',
            'receipt_references' => 'الإيصالات',
            'receipt_amounts' => 'مبالغ الإيصالات (د.ل.)',
            'collected_total' => 'إجمالي المحصل من الطالب (د.ل.)',
        ],
    ],
    'charts' => [
        'revenue' => 'الإيراد المحصل حسب الدفعة',
        'profit' => 'الربح للفترة المحددة',
        'wage_cost' => 'تكلفة الأجور حسب الموظف',
        'payment_method' => 'الدفعات المحصلة حسب الطريقة',
        'period' => ':from – :to',
        'date_format' => 'j M Y',
        'sign_positive' => '+',
        'sign_negative' => '−',
        'bars' => [
            'revenue' => 'الإيراد المحصل',
            'wage_cost' => 'تكلفة الأجور',
            'expenses' => 'المصروفات',
            'profit' => 'الربح',
            'loss' => 'الخسارة',
        ],
    ],
    'presentation' => [
        'queued_hint' => 'تعمل عمليات التصدير في الخلفية. سيصلك إشعار عند جاهزية الملف.',
        'history_hint' => 'يمكن تنزيل الصادرات المكتملة التي نجحت جميع صفوفها فقط. لا يُقدم ملف جزئي مطلقًا. اطلب تصديرًا جديدًا من تقريره عند الحاجة.',
        'view_exports' => 'عرض سجل التصدير',
        'period_total' => 'إجمالي الفترة',
        'currency' => 'د.ل',
        'rows' => 'صفوف التقرير',
        'summaries' => [
            'revenue' => 'الإيراد المحصل خلال الفترة المطبقة، موزعًا على الدفعات المدرجة أدناه.',
            'payment_method' => 'الدفعات المحصلة خلال الفترة المطبقة، موزعة على طرق الدفع المدرجة أدناه.',
            'wage_cost' => 'تكلفة الأجور المعتمدة خلال الفترة المطبقة، موزعة على الموظفين المدرجين أدناه.',
        ],
    ],
];
