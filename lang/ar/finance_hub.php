<?php

declare(strict_types=1);

return [
    'title' => 'المالية',
    'navigation_label' => 'المالية',
    'groups' => [
        'money_in' => [
            'heading' => 'المقبوضات',
            'description' => 'ما يُفوتر للطلاب وما يدفعونه.',
        ],
        'money_out' => [
            'heading' => 'المدفوعات',
            'description' => 'ما يدفعه المركز لموظفيه ولتكاليف تشغيله.',
        ],
        'reports' => [
            'heading' => 'التقارير',
            'description' => 'أرقام محسوبة من السجلات أعلاه.',
        ],
    ],
    'tiles' => [
        'charges' => [
            'description' => 'الفواتير الصادرة للتسجيلات والمبلغ المتبقي على كل منها.',
        ],
        'payments' => [
            'description' => 'الأموال المستلمة من الطلاب وإيصالاتها.',
        ],
        'discounts' => [
            'description' => 'الخصومات التي يمكن تطبيقها على فاتورة.',
        ],
        'enroll_and_collect' => [
            'description' => 'سجّل طالبًا في دفعة وأصدر فاتورته وحصّل الدفع في عملية واحدة.',
        ],
        'payroll_runs' => [
            'description' => 'احسب أجور الموظفين واعتمدها كل شهر.',
        ],
        'staff_compensation' => [
            'description' => 'معدل الأجر المتفق عليه مع كل موظف وتاريخ بدء سريانه.',
        ],
        'expenses' => [
            'description' => 'أموال دفعها المركز، مثل الإيجار والمستلزمات.',
        ],
        'expense_categories' => [
            'description' => 'الفئات التي يمكن تسجيل المصروف ضمنها.',
        ],
        'revenue_report' => [
            'description' => 'المبالغ المحصلة حسب الدورة والدفعة.',
        ],
        'outstanding_aged_report' => [
            'description' => 'المبالغ المتبقية على الطلاب، مجمعة حسب مدة تأخرها.',
        ],
        'payment_method_report' => [
            'description' => 'المبالغ المحصلة بكل طريقة دفع.',
        ],
        'daily_tender_report' => [
            'description' => 'مقبوضات اليوم حسب طريقة الدفع لإقفال الصندوق.',
        ],
        'wage_cost_report' => [
            'description' => 'أجور الموظفين المعتمدة خلال فترة.',
        ],
        'profit_report' => [
            'description' => 'المبالغ المحصلة مطروحًا منها الأجور والمصروفات.',
        ],
        'student_payment_history_report' => [
            'description' => 'جميع الدفعات التي سددها طالب واحد.',
        ],
    ],
];
