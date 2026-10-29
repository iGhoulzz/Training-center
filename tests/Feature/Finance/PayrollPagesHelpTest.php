<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ReviewPayrollRun;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\CreateStaffCompensation;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\ListStaffCompensations;
use App\Domain\Finance\Models\PayrollRun;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| "How to read this page" on the payroll and compensation pages (P4-T37)
|--------------------------------------------------------------------------
|
| The same help panel the Charges list got, on four more pages: the payroll runs
| list, one run's review page, the compensation list and the new-rate form. Each
| panel states what the page is, what its figures mean and what each button does
| and must not be used for; the compensation pages say that a raise adds a rate
| from a date and never edits one, and that past runs keep the rate of the time.
|
| THE SENTINELS ARE WRITTEN OUT BY HAND, IN EACH LANGUAGE, not read back from the
| catalogue, so a key that falls back to its own name, or to the English text on
| the Arabic page, fails here.
|
| EVERY ACTOR HOLDS EXACTLY THE PERMISSIONS NAMED, NEVER A ROLE.
*/
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };

    // The page text: markup and Livewire snapshot removed, entities decoded, and
    // the \uXXXX escapes a tooltip's JSON carries turned back into characters.
    $this->textOf = fn (string $html): string => html_entity_decode(preg_replace_callback(
        '/\\\\u([0-9a-f]{4})/i',
        fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8'),
        preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $html))),
    ), ENT_QUOTES | ENT_HTML5);

    // The raw markup, same decoding, for the attributes strip_tags removes.
    $this->markupOf = fn (string $html): string => html_entity_decode(preg_replace_callback(
        '/\\\\u([0-9a-f]{4})/i',
        fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8'),
        preg_replace('/\s+wire:snapshot="[^"]*"/', '', $html),
    ), ENT_QUOTES | ENT_HTML5);
});

/** @return array<string, array{string, array<string, string>}> */
dataset('runs list sentinels', [
    'English' => ['en', [
        'heading' => 'How to read this page',
        'what' => 'Each card is one payroll run: a batch of pay worked out and checked here before it is approved',
        'posted' => 'Finalized means posted, not paid. Paying staff happens outside this system',
        'monthly' => 'A salary is split by calendar month and by rate, so a raise in the middle of the period gives two correctly priced lines',
        'instructor' => 'Each assignment is paid as one lump, and only once',
        'adjustment' => 'Corrects one line of a finalized run, with a signed amount and a reason. The original line never changes',
        'badge' => 'The badge says Draft (still being checked, and it can be deleted) or Finalized (approved and locked)',
        'total' => 'Frozen line total is the sum of the run\'s lines, before bonuses and deductions',
        'rates' => 'A later raise never changes a finalized run, which keeps the rate that applied at the time',
        'reports' => 'A correction counts in the month of the line it corrects',
        'create' => 'Starts a new draft run. Nothing is posted until you finalize it',
        'review' => 'Opens the run so you can check its lines. It changes nothing',
    ]],
    'Arabic' => ['ar', [
        'heading' => 'كيف تقرأ هذه الصفحة',
        'what' => 'تمثل كل بطاقة مسير رواتب واحدًا: دفعة من الرواتب تُحتسب وتُراجع هنا قبل اعتمادها',
        'posted' => 'معتمد تعني مرحّل إلى الدفاتر وليس مدفوعًا. ويتم دفع الرواتب خارج هذا النظام',
        'monthly' => 'فإذا حدثت زيادة في منتصف الفترة ظهر بندان بسعر صحيح لكل منهما',
        'instructor' => 'يُصرف كل إسناد دفعة واحدة، ومرة واحدة فقط',
        'adjustment' => 'ولا يتغير البند الأصلي أبدًا',
        'badge' => 'تبيّن الشارة الحالة: مسودة (ما زالت قيد المراجعة ويمكن حذفها) أو معتمد (تم اعتمادها وأُغلقت)',
        'total' => 'إجمالي البند المثبت هو مجموع بنود المسير قبل المكافآت والخصومات',
        'rates' => 'لا تغيّر الزيادة اللاحقة مسيرًا معتمدًا، فهو يحتفظ بالمعدل الذي كان ساريًا وقتها',
        'reports' => 'ويُحسب التصحيح في شهر البند الذي يصححه',
        'create' => 'يبدأ مسيرًا جديدًا بصفة مسودة. لا يُرحَّل شيء إلى أن تعتمده',
        'review' => 'يفتح المسير لتراجع بنوده. ولا يغيّر شيئًا',
    ]],
]);

it('explains the payroll runs list in :dataset', function (string $locale, array $sentinels) {
    app()->setLocale($locale);
    PayrollRun::factory()->create();
    $actor = ($this->actor)(['view_any_payroll_run']);

    $text = ($this->textOf)(Livewire::actingAs($actor)->test(ListPayrollRuns::class)->html());

    foreach ($sentinels as $phrase) {
        expect($text)->toContain($phrase);
    }
})->with('runs list sentinels');

/** @return array<string, array{string, array<string, string>}> */
dataset('review sentinels', [
    'English' => ['en', [
        'heading' => 'How to read this page',
        'what' => 'This is one payroll run with every line in it. Check the lines here before you finalize',
        'rate' => 'Rate is the monthly salary or the hourly rate that applied. Hours are shown on instructor lines only',
        'amount' => 'Total after bonuses and deductions adds the signed bonuses and deductions on that line',
        'frozen' => 'Finalizing fixes each line\'s rate, hours and amount for good, so a later raise does not change them',
        'add' => 'Not for correcting a finalized run: use an Adjustment run for that',
        'finalize' => 'Approves the run and posts it to the books. Afterwards nothing on it can be edited, added to or deleted. It does not pay anyone',
        'delete' => 'Deletes this draft run and its lines. A finalized run can never be deleted',
    ]],
    'Arabic' => ['ar', [
        'heading' => 'كيف تقرأ هذه الصفحة',
        'what' => 'هذا مسير رواتب واحد بكل بنوده. راجع البنود هنا قبل الاعتماد',
        'rate' => 'معدل الأجر هو الراتب الشهري أو الأجر بالساعة الساري. وتظهر الساعات في بنود المدربين فقط',
        'amount' => 'فيضيف إليه المكافآت والخصومات الموجبة والسالبة على ذلك البند',
        'frozen' => 'ويثبّت الاعتماد معدل كل بند وساعاته ومبلغه نهائيًا، فلا تغيّرها زيادة لاحقة',
        'add' => 'وليست لتصحيح مسير معتمد؛ لذلك استخدم مسير تسوية',
        'finalize' => 'تعتمد المسير وترحّله إلى الدفاتر. وبعدها لا يمكن تعديل شيء فيه أو الإضافة إليه أو حذفه. وهي لا تدفع لأحد شيئًا',
        'delete' => 'أما المسير المعتمد فلا يمكن حذفه أبدًا',
    ]],
]);

it('explains a payroll run\'s review page in :dataset', function (string $locale, array $sentinels) {
    app()->setLocale($locale);
    $run = PayrollRun::factory()->create();
    $actor = ($this->actor)(['view_any_payroll_run', 'view_payroll_run']);

    $text = ($this->textOf)(Livewire::actingAs($actor)->test(ReviewPayrollRun::class, ['record' => $run->getKey()])->html());

    foreach ($sentinels as $phrase) {
        expect($text)->toContain($phrase);
    }
})->with('review sentinels');

/** @return array<string, array{string, array<string, string>}> */
dataset('compensation list sentinels', [
    'English' => ['en', [
        'heading' => 'How to read this page',
        'history' => 'This is the history of pay rates: a monthly salary or an hourly rate, each from a date. One person can have both',
        'raise' => 'A raise adds a new rate from a date. It never edits the old one: the old rate ends the day before and stays in the history',
        'past runs' => 'Past payroll runs keep the rate that applied at the time, so a raise never changes pay already worked out',
        'amount' => 'Amount is the monthly salary, or the rate per hour, depending on the type',
        'dates' => 'Current means it has no end date yet',
        'overlap' => 'Two rates of the same type for one person cannot overlap',
        'button' => 'Adds a new rate from the date you choose, for a raise, a cut or a first rate. It cannot edit or delete an existing rate',
    ]],
    'Arabic' => ['ar', [
        'heading' => 'كيف تقرأ هذه الصفحة',
        'history' => 'هذا سجل معدلات الأجر: راتب شهري أو أجر بالساعة، لكل منهما تاريخ سريان. ويمكن أن يكون للشخص الواحد النوعان معًا',
        'raise' => 'تضيف الزيادة معدلًا جديدًا من تاريخ معين، ولا تعدّل المعدل القديم أبدًا: ينتهي المعدل القديم في اليوم السابق ويبقى في السجل',
        'past runs' => 'تحتفظ مسيرات الرواتب السابقة بالمعدل الذي كان ساريًا وقتها، فلا تغيّر الزيادة رواتب سبق احتسابها',
        'amount' => 'المبلغ هو الراتب الشهري أو الأجر عن الساعة بحسب النوع',
        'dates' => 'وحالي تعني أنه لا تاريخ انتهاء له بعد',
        'overlap' => 'لا يجوز أن يتداخل معدلان من النوع نفسه للشخص الواحد',
        'button' => 'تضيف معدلًا جديدًا من التاريخ الذي تختاره، لزيادة أو تخفيض أو معدل أول. ولا يمكنها تعديل معدل قائم أو حذفه',
    ]],
]);

it('explains the compensation list, and the effective-dated rule, in :dataset', function (string $locale, array $sentinels) {
    app()->setLocale($locale);
    $actor = ($this->actor)(['view_any_staff_compensation']);

    $text = ($this->textOf)(Livewire::actingAs($actor)->test(ListStaffCompensations::class)->html());

    foreach ($sentinels as $phrase) {
        expect($text)->toContain($phrase);
    }
})->with('compensation list sentinels');

/** @return array<string, array{string, array<string, string>}> */
dataset('compensation create sentinels', [
    'English' => ['en', [
        'heading' => 'How to read this page',
        'adds' => 'Saving adds a new rate from the effective date. The employee\'s earlier rate of the same type ends the day before',
        'never edits' => 'It never edits the old rate, and past payroll runs keep the rate that applied at the time',
        'date' => 'The date must come after the start of the rate it replaces. A date that overlaps the existing history is refused',
        'amount' => 'Amount is a monthly salary or an hourly rate, in LYD, depending on the type you choose',
    ]],
    'Arabic' => ['ar', [
        'heading' => 'كيف تقرأ هذه الصفحة',
        'adds' => 'يضيف الحفظ معدلًا جديدًا من تاريخ السريان. وينتهي معدل الموظف السابق من النوع نفسه في اليوم السابق',
        'never edits' => 'لا يعدّل الحفظ المعدل القديم، وتحتفظ مسيرات الرواتب السابقة بالمعدل الذي كان ساريًا وقتها',
        'date' => 'يجب أن يكون التاريخ بعد بداية المعدل الذي سيحل محله. ويُرفض أي تاريخ يتداخل مع السجل القائم',
        'amount' => 'المبلغ هو راتب شهري أو أجر بالساعة، بالدينار الليبي، بحسب النوع الذي تختاره',
    ]],
]);

it('explains the new-rate form in :dataset', function (string $locale, array $sentinels) {
    app()->setLocale($locale);
    $actor = ($this->actor)(['view_any_staff_compensation', 'create_staff_compensation']);

    $text = ($this->textOf)(Livewire::actingAs($actor)->test(CreateStaffCompensation::class)->html());

    foreach ($sentinels as $phrase) {
        expect($text)->toContain($phrase);
    }
})->with('compensation create sentinels');

it('keeps every payroll and compensation panel open by default and remembers a collapse per browser', function (string $page, string $id, array $permissions) {
    $run = PayrollRun::factory()->create();
    $html = ($this->markupOf)(Livewire::actingAs(($this->actor)($permissions))->test($page, str_contains($page, 'Review') ? ['record' => $run->getKey()] : [])->html());

    expect($html)->toContain("id=\"{$id}\"")
        ->and($html)->toMatch('/\$persist\(false\)\.as\(`section-\$\{[^}]*'.preg_quote($id, '/').'[^}]*\}-isCollapsed`\)/');
})->with([
    'runs list' => [ListPayrollRuns::class, 'payroll-runs-help', ['view_any_payroll_run']],
    'review page' => [ReviewPayrollRun::class, 'payroll-run-help', ['view_any_payroll_run', 'view_payroll_run']],
    'compensation list' => [ListStaffCompensations::class, 'staff-compensation-help', ['view_any_staff_compensation']],
    'new-rate form' => [CreateStaffCompensation::class, 'staff-compensation-create-help', ['view_any_staff_compensation', 'create_staff_compensation']],
]);

/** @return array<string, array{string, array<string, string>}> */
dataset('payroll tooltips', [
    'English' => ['en', [
        'Start a new draft run. Nothing is posted until you finalize it.',
        'Open this run to check its lines. Changes nothing.',
        'Add a signed bonus or deduction to a line of this draft run. Not for correcting a finalized run.',
        'Approve and post this run. It cannot be changed afterwards, and it does not pay anyone.',
        'Delete this draft run and its lines. A finalized run cannot be deleted.',
        'Add a new rate from a date. An existing rate is never edited.',
    ]],
    'Arabic' => ['ar', [
        'ابدأ مسيرًا جديدًا بصفة مسودة. لا يُرحَّل شيء إلى أن تعتمده.',
        'افتح هذا المسير لتراجع بنوده. لا يغيّر شيئًا.',
        'أضف مكافأة أو خصمًا إلى بند من هذه المسودة. ليس لتصحيح مسير معتمد.',
        'اعتمد هذا المسير وارحّله. لا يمكن تغييره بعد ذلك، ولا يدفع لأحد شيئًا.',
        'احذف هذه المسودة وبنودها. لا يمكن حذف مسير معتمد.',
        'أضف معدلًا جديدًا من تاريخ معين. لا يُعدَّل المعدل القائم أبدًا.',
    ]],
]);

it('puts a translated one-line tooltip on every payroll and compensation button in :dataset', function (string $locale, array $tooltips) {
    app()->setLocale($locale);
    [$create, $review, $add, $finalize, $delete, $change] = $tooltips;
    $run = PayrollRun::factory()->create();
    $permissions = ['view_any_payroll_run', 'view_payroll_run', 'run_payroll', 'finalize_payroll', 'delete_payroll_run', 'view_any_staff_compensation', 'create_staff_compensation'];
    $actor = ($this->actor)($permissions);

    $list = ($this->markupOf)(Livewire::actingAs($actor)->test(ListPayrollRuns::class)->html());
    $reviewPage = ($this->markupOf)(Livewire::actingAs($actor)->test(ReviewPayrollRun::class, ['record' => $run->getKey()])->html());
    $compensation = ($this->markupOf)(Livewire::actingAs($actor)->test(ListStaffCompensations::class)->html());

    expect($list)->toContain($create)
        ->and($list)->toContain($review)
        ->and($reviewPage)->toContain($add)
        ->and($reviewPage)->toContain($finalize)
        ->and($reviewPage)->toContain($delete)
        ->and($compensation)->toContain($change);
})->with('payroll tooltips');

it('still lists the runs for an actor who may only read them', function () {
    $run = PayrollRun::factory()->create();

    Livewire::actingAs(($this->actor)(['view_any_payroll_run']))
        ->test(ListPayrollRuns::class)
        ->assertCanSeeTableRecords([$run]);

    Livewire::actingAs(($this->actor)([]))->test(ListPayrollRuns::class)->assertForbidden();
});

it('shows the new-rate form, and its panel, only to an actor who may create a rate', function () {
    Livewire::actingAs(($this->actor)(['view_any_staff_compensation']))->test(CreateStaffCompensation::class)->assertForbidden();
    Livewire::actingAs(($this->actor)(['view_any_staff_compensation', 'create_staff_compensation']))->test(CreateStaffCompensation::class)->assertSuccessful();
});
