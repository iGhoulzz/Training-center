<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * The demo's fictional people and the history written for them, as data.
 *
 * Everything here is invented. Emails are @example.test, phone numbers use an
 * all-zero middle block (+218 91 000 0NNN) that no operator allocates, and
 * addresses are "Demo Street N". Names are free text and mix Latin and Arabic
 * script on purpose, the way a Libyan centre's register does.
 *
 * Day offsets are relative to the day the command runs (negative is the past),
 * which is why the history always ends today and the ageing buckets are always
 * populated: a charge raised 86 days ago is 86 days past due whenever you run
 * this. Two runs on different days differ in dates, not in shape.
 */
final class DemoScenarios
{
    /**
     * The 48 people on the register: [first name, last name, gender].
     *
     * @return array<int, array{string, string, string}>
     */
    public static function students(): array
    {
        return [
            1 => ['Amal', 'Haddad', 'female'],
            2 => ['محمد', 'الهادي', 'male'],
            3 => ['Karim', 'Nasser', 'male'],
            4 => ['فاطمة', 'المبروك', 'female'],
            5 => ['Dalia', 'Mansour', 'female'],
            6 => ['يوسف', 'الفيتوري', 'male'],
            7 => ['Ziad', 'Farouk', 'male'],
            8 => ['مريم', 'بن عمران', 'female'],
            9 => ['Huda', 'Barakat', 'female'],
            10 => ['خالد', 'الزروق', 'male'],
            11 => ['Samir', 'Jaber', 'male'],
            12 => ['سارة', 'المصراتي', 'female'],
            13 => ['Nadia', 'Qasim', 'female'],
            14 => ['عمر', 'الورفلي', 'male'],
            15 => ['Fadi', 'Rahman', 'male'],
            16 => ['ليلى', 'الترهوني', 'female'],
            17 => ['Layla', 'Saad', 'female'],
            18 => ['حسن', 'الغرياني', 'male'],
            19 => ['Bilal', 'Karam', 'male'],
            20 => ['نور', 'العبيدي', 'female'],
            21 => ['Rima', 'Aziz', 'female'],
            22 => ['إبراهيم', 'الككلي', 'male'],
            23 => ['Tamer', 'Hakim', 'male'],
            24 => ['هدى', 'الصغير', 'female'],
            25 => ['Yasmin', 'Fahd', 'female'],
            26 => ['سالم', 'الشاوش', 'male'],
            27 => ['Osama', 'Raslan', 'male'],
            28 => ['رنا', 'الزليطني', 'female'],
            29 => ['Lina', 'Darwish', 'female'],
            30 => ['طارق', 'الدرسي', 'male'],
            31 => ['Hadi', 'Masri', 'male'],
            32 => ['منى', 'الجدي', 'female'],
            33 => ['Maha', 'Sabri', 'female'],
            34 => ['أحمد', 'المبروك', 'male'],
            35 => ['Walid', 'Tamimi', 'male'],
            36 => ['عائشة', 'الفيتوري', 'female'],
            37 => ['Sawsan', 'Ghani', 'female'],
            38 => ['علي', 'بن عمران', 'male'],
            39 => ['Ramy', 'Shaker', 'male'],
            40 => ['زينب', 'الزروق', 'female'],
            41 => ['Noor', 'Habib', 'female'],
            42 => ['Adel', 'Zaki', 'male'],
            43 => ['دينا', 'المصراتي', 'female'],
            44 => ['Majed', 'Rizk', 'male'],
            45 => ['Salwa', 'Hamdan', 'female'],
            46 => ['Jamal', 'Awad', 'male'],
            // Never enrolled: an enquiry the desk keeps, and one it later removes.
            47 => ['Rami', 'Salem', 'male'],
            48 => ['Reem', 'Yacoub', 'female'],
        ];
    }

    /**
     * The five catalogue courses, in the order they are created.
     *
     * @return array<string, array{name_en: string, name_ar: string, description_en: string, description_ar: string, hours: int, price: string}>
     */
    public static function courses(): array
    {
        return [
            'ENG-B1' => [
                'name_en' => 'English Conversation B1',
                'name_ar' => 'المحادثة بالإنجليزية - المستوى B1',
                'description_en' => 'Speaking and listening practice for learners moving from basic to confident everyday English.',
                'description_ar' => 'تدريب على المحادثة والاستماع للمتعلمين الذين ينتقلون من الإنجليزية الأساسية إلى استخدامها بثقة في الحياة اليومية.',
                'hours' => 60,
                'price' => '450.000',
            ],
            'WEB-101' => [
                'name_en' => 'Web Development Basics',
                'name_ar' => 'أساسيات تطوير الويب',
                'description_en' => 'HTML, CSS and a first look at JavaScript, ending in a small published site.',
                'description_ar' => 'لغة HTML وCSS ونظرة أولى على JavaScript، وتنتهي بإنشاء موقع صغير منشور.',
                'hours' => 80,
                'price' => '850.000',
            ],
            'ACC-201' => [
                'name_en' => 'Accounting Fundamentals',
                'name_ar' => 'أساسيات المحاسبة',
                'description_en' => 'Bookkeeping, the trial balance and the main financial statements for small businesses.',
                'description_ar' => 'مسك الدفاتر وميزان المراجعة والقوائم المالية الرئيسية للأعمال الصغيرة.',
                'hours' => 100,
                'price' => '1200.000',
            ],
            'XLS-110' => [
                'name_en' => 'Spreadsheet Skills',
                'name_ar' => 'مهارات الجداول الإلكترونية',
                'description_en' => 'Formulas, tables and charts for everyday office work.',
                'description_ar' => 'الصيغ والجداول والرسوم البيانية للأعمال المكتبية اليومية.',
                'hours' => 30,
                'price' => '300.000',
            ],
            'DAT-301' => [
                'name_en' => 'Data Analysis with Python',
                'name_ar' => 'تحليل البيانات باستخدام بايثون',
                'description_en' => 'Cleaning, summarising and charting real datasets with Python.',
                'description_ar' => 'تنظيف مجموعات بيانات حقيقية وتلخيصها ورسمها باستخدام بايثون.',
                'hours' => 90,
                'price' => '1750.000',
            ],
        ];
    }

    /**
     * The nine batches: three finished, four running, two upcoming.
     *
     * `instructors` is [account key => assigned hours]. `removed` is an
     * instructor assigned when the batch was opened and taken off again, [key =>
     * [hours, days after the batch starts that they are removed]].
     *
     * @return array<string, array{
     *     course: string, code: string, start: int, end: int, capacity: int,
     *     state: string, instructors: array<string, int>,
     *     removed?: array<string, array{int, int}>, price?: string, hours?: int
     * }>
     */
    public static function batches(): array
    {
        return [
            'F1' => ['course' => 'ENG-B1', 'code' => 'ENG-B1-01', 'start' => -165, 'end' => -105, 'capacity' => 12, 'state' => 'finished', 'instructors' => ['omar' => 40]],
            'F2' => ['course' => 'XLS-110', 'code' => 'XLS-110-01', 'start' => -150, 'end' => -100, 'capacity' => 10, 'state' => 'finished', 'instructors' => ['hana' => 30]],
            'F3' => ['course' => 'WEB-101', 'code' => 'WEB-101-01', 'start' => -140, 'end' => -75, 'capacity' => 12, 'state' => 'finished', 'instructors' => ['salma' => 60, 'yousef' => 20]],
            'R1' => ['course' => 'ACC-201', 'code' => 'ACC-201-01', 'start' => -80, 'end' => 30, 'capacity' => 12, 'state' => 'running', 'instructors' => ['yousef' => 50], 'removed' => ['salma' => [30, 20]]],
            'R2' => ['course' => 'ENG-B1', 'code' => 'ENG-B1-02', 'start' => -45, 'end' => 45, 'capacity' => 14, 'state' => 'running', 'instructors' => ['omar' => 45]],
            'R3' => ['course' => 'DAT-301', 'code' => 'DAT-301-01', 'start' => -30, 'end' => 60, 'capacity' => 10, 'state' => 'running', 'instructors' => ['salma' => 60], 'hours' => 80],
            'R4' => ['course' => 'WEB-101', 'code' => 'WEB-101-02', 'start' => -14, 'end' => 76, 'capacity' => 12, 'state' => 'running', 'instructors' => ['salma' => 30], 'price' => '800.000'],
            'U1' => ['course' => 'XLS-110', 'code' => 'XLS-110-02', 'start' => 10, 'end' => 40, 'capacity' => 12, 'state' => 'upcoming', 'instructors' => []],
            'U2' => ['course' => 'ACC-201', 'code' => 'ACC-201-02', 'start' => 25, 'end' => 85, 'capacity' => 12, 'state' => 'upcoming', 'instructors' => ['yousef' => 20]],
        ];
    }

    /**
     * Every enrolment, with what happened to its bill and to the student.
     *
     * `pay` is a space-separated plan of `share@days:tender`: the share is a
     * whole percentage of what the bill is worth at that moment, or `rest` for
     * whatever is still owed; the days count from the enrolment; the tender is
     * cash, card, bank, other (a cheque), or two joined with `+` for a split handover. A step
     * dated after today is not recorded, so a recent bill keeps its later
     * installments owing. `none` collects nothing.
     *
     * `by` is the front-desk account that enrols the student; a discount needs
     * `apply_discount`, which the front desk does not hold, so those are the
     * admin's. `offset` counts days from the batch's start.
     *
     * @return list<array{
     *     student: int, batch: string, offset: int, by: string, discount: ?string, pay: string,
     *     end: ?string, end_after: int, certificate: ?string,
     *     adjust: ?array{after: int, to: string, reason: string},
     *     writeoff: ?array{after: int, reason: string},
     *     reverse: ?array{payment: int, after: int, reason: string}
     * }>
     */
    public static function enrolments(): array
    {
        $rows = [];

        $add = static function (
            int $student,
            string $batch,
            int $offset,
            string $by,
            ?string $discount,
            string $pay,
            ?string $end = null,
            int $endAfter = 0,
            ?string $certificate = null,
            ?array $adjust = null,
            ?array $writeoff = null,
            ?array $reverse = null,
        ) use (&$rows): void {
            $rows[] = [
                'student' => $student,
                'batch' => $batch,
                'offset' => $offset,
                'by' => $by,
                'discount' => $discount,
                'pay' => $pay,
                'end' => $end,
                'end_after' => $endAfter,
                'certificate' => $certificate,
                'adjust' => $adjust,
                'writeoff' => $writeoff,
                'reverse' => $reverse,
            ];
        };

        // F1: English B1, finished. Seven completed (two with certificate trouble), one withdrew.
        $add(1, 'F1', -12, 'frontdesk1', null, '100@0:cash', 'complete', 0, 'issue+revoke');
        $add(2, 'F1', -12, 'frontdesk1', null, '100@0:card', 'complete', 0, 'issue+replace');
        $add(3, 'F1', -10, 'frontdesk2', null, '50@0:bank rest@30:cash', 'complete', 0, 'issue');
        $add(4, 'F1', -9, 'frontdesk2', null, '40@0:cash 30@25:cash rest@50:card', 'complete', 0, 'issue');
        $add(5, 'F1', -8, 'frontdesk1', null, '100@0:bank', 'complete', 0, 'issue');
        $add(6, 'F1', -5, 'frontdesk1', null, '30@0:cash', 'complete');
        $add(7, 'F1', -4, 'frontdesk2', null, 'none', 'withdraw', 6, null, null, [
            'after' => 60,
            'reason' => 'Withdrew in the first week; the centre agreed not to pursue the fee.',
        ]);
        $add(8, 'F1', 0, 'admin', 'early', '100@0:cash+card', 'complete', 0, 'issue');

        // F2: Spreadsheet Skills, finished.
        $add(9, 'F2', -10, 'frontdesk1', null, '100@0:cash', 'complete', 0, 'issue');
        $add(10, 'F2', -9, 'admin', 'early', '50@0:cash rest@20:cash', 'complete', 0, 'issue');
        $add(11, 'F2', -7, 'frontdesk2', null, 'none', 'complete');
        $add(12, 'F2', -6, 'frontdesk1', null, '100@0:other', 'withdraw', 14);
        $add(13, 'F2', -4, 'frontdesk2', null, '100@0:bank', 'complete', 0, 'issue');
        $add(14, 'F2', -3, 'admin', 'early', '40@0:card 30@20:card rest@40:card', 'complete', 0, 'issue');

        // F3: Web Development Basics, finished. One bill adjusted down, one up, one installment reversed.
        $add(15, 'F3', -10, 'frontdesk1', null, '100@0:card', 'complete', 0, 'issue');
        $add(16, 'F3', -9, 'frontdesk2', null, '50@1:cash rest@30:cash', 'complete', 0, 'issue', [
            'after' => 0,
            'to' => '800.000',
            'reason' => 'The wrong batch price was applied at the desk; corrected to the price quoted to the student.',
        ]);
        $add(17, 'F3', -8, 'frontdesk1', null, '50@0:cash rest@30:bank', 'complete', 0, 'issue', null, null, [
            'payment' => 2,
            'after' => 60,
            'reason' => 'The bank transfer was returned by the sending bank.',
        ]);
        $add(18, 'F3', -6, 'frontdesk2', null, '40@0:cash 30@20:cash rest@45:cash', 'complete', 0, 'issue', [
            'after' => 5,
            'to' => '900.000',
            'reason' => 'Printed course pack added to the bill at the student\'s request.',
        ]);
        $add(19, 'F3', -5, 'frontdesk1', null, 'none', 'complete');
        $add(20, 'F3', -3, 'frontdesk2', null, '100@0:card', 'complete', 0, 'issue');
        $add(21, 'F3', -2, 'frontdesk1', null, '30@0:cash', 'withdraw', 12, null, null, [
            'after' => 70,
            'reason' => 'Withdrew mid-course; the remaining balance was forgiven by the owner.',
        ]);
        $add(3, 'F3', -1, 'frontdesk2', null, '100@0:cash', 'complete', 0, 'issue');

        // R1: Accounting, running. The oldest open bills are here.
        $add(22, 'R1', -13, 'frontdesk1', null, '100@0:card');
        $add(23, 'R1', -10, 'frontdesk2', null, '50@0:cash rest@30:cash');
        $add(24, 'R1', -9, 'frontdesk1', null, '40@0:cash 30@30:cash rest@60:bank');
        $add(25, 'R1', -8, 'frontdesk2', null, '100@0:cash', null, 0, null, null, null, [
            'payment' => 1,
            'after' => 5,
            'reason' => 'Recorded against the wrong student; the money belongs to another bill.',
        ]);
        $add(26, 'R1', -6, 'frontdesk1', null, '50@0:cash');
        $add(27, 'R1', -12, 'frontdesk2', null, 'none');
        $add(28, 'R1', -3, 'frontdesk1', null, '34@0:cash', 'withdraw', 10);
        $add(29, 'R1', 2, 'admin', 'family', '100@0:bank');

        // R2: English B1, second intake, at the raised course price.
        $add(30, 'R2', -12, 'frontdesk1', null, '100@0:cash');
        $add(31, 'R2', -10, 'admin', 'promo', '50@0:cash rest@30:cash');
        $add(32, 'R2', -8, 'frontdesk2', null, '40@0:card 30@30:card rest@60:card');
        $add(33, 'R2', -6, 'frontdesk1', null, 'none');
        $add(34, 'R2', -4, 'frontdesk2', null, '40@0:cash');
        $add(35, 'R2', -3, 'frontdesk1', null, '30@0:cash', 'withdraw', 8);
        $add(36, 'R2', -2, 'admin', 'early', '100@0:cash+card');

        // R3: Data Analysis, running.
        $add(37, 'R3', -12, 'frontdesk2', null, '100@0:cash+bank');
        $add(38, 'R3', -10, 'frontdesk1', null, '50@0:card rest@30:card');
        $add(39, 'R3', -8, 'frontdesk2', null, '30@0:cash');
        $add(40, 'R3', -6, 'frontdesk1', null, 'none');
        $add(41, 'R3', -3, 'admin', 'family', '100@0:bank');

        // R4: Web Development, running, billed at the batch price.
        $add(42, 'R4', -12, 'frontdesk1', null, '100@0:cash');
        $add(43, 'R4', -10, 'frontdesk2', null, '50@0:card');
        $add(44, 'R4', -6, 'frontdesk1', null, 'none');
        $add(45, 'R4', -4, 'frontdesk2', null, '40@0:cash 30@14:cash rest@28:cash');
        $add(46, 'R4', -2, 'frontdesk1', null, '100@0:card');

        // Upcoming batches already taking enrolments, billed and mostly unpaid.
        $add(8, 'U1', -16, 'frontdesk2', null, 'none');
        $add(33, 'U1', -13, 'frontdesk1', null, '100@0:cash');
        $add(5, 'U2', -27, 'frontdesk2', null, '30@0:cash');
        $add(22, 'U2', -26, 'admin', 'early', 'none');

        return $rows;
    }
}
