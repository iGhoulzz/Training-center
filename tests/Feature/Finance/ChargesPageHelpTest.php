<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The "How to read this page" panel on the Charges list (P4-T37)
|--------------------------------------------------------------------------
|
| The owner saw two bills of 850 each, 300 paid on each, add up to
| "1100.000 LYD" in the first ageing card and was confused. The page now
| explains itself: what the cards add up, what a row shows, what each button
| does. This file proves the panel renders in both languages and that the
| tooltips on the three row buttons are translated.
|
| THE SENTINELS ARE WRITTEN OUT BY HAND, IN EACH LANGUAGE. They are not read
| back from the catalogue, so a key that falls back to its own name or to the
| English text in the Arabic page fails here.
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

    // The page text with its markup and the Livewire snapshot taken out, and the
    // HTML entities decoded (an apostrophe or an arrow is an entity in the markup).
    $this->textOf = fn (string $html): string => html_entity_decode(preg_replace(
        '/\s+/u',
        ' ',
        strip_tags(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $html)),
    ), ENT_QUOTES | ENT_HTML5);

    // The owner's own example: a bill of 850 with 300 paid on it.
    $this->billWithPartPayment = function (): Charge {
        $charge = Charge::factory()->create(['list_price' => '850.000']);
        PaymentAllocation::factory()->create([
            'payment_id' => Payment::factory()->create()->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => '300.000',
        ]);

        return $charge;
    };
});

/** @return array<string, array{string, list<string>}> locale => sentinel phrases, one per key group */
dataset('help sentinels', [
    'English' => ['en', [
        'cards' => 'Each card is the total still owed on bills not yet fully paid (Unpaid and Part paid)',
        'sum' => 'two students who each owe 550.000 LYD show 1100.000 LYD together',
        'written off cards' => 'Written-off bills are not in the cards',
        'billed' => 'Billed is the bill total after any discount or adjustment',
        'paid' => 'Paid, with its percentage, is what has been collected so far',
        'owed' => 'The coloured amount is what is still owed',
        'badge' => 'The badge says Unpaid, Part paid, Settled or Written off',
        'collect' => 'Takes a payment towards what is still owed, the first one or a later installment',
        'adjust' => 'Changes the bill amount itself, with a reason',
        'write off' => 'A written-off bill still counts in the student\'s balance',
        'reversal' => 'A reversed payment no longer counts as paid, and its amount is owed again',
    ]],
    'Arabic' => ['ar', [
        'cards' => 'تعرض كل بطاقة إجمالي ما لا يزال مستحقًا على الفواتير التي لم تُسدَّد بالكامل (غير مسدد ومسدد جزئيًا)',
        'sum' => 'فإذا كان على كل من طالبين 550.000 د.ل. فتظهر البطاقة 1100.000 د.ل.',
        'written off cards' => 'الفواتير المشطوبة لا تدخل في هذه البطاقات',
        'billed' => 'المبلغ المفوتر هو إجمالي الفاتورة بعد الخصم أو التعديل',
        'paid' => 'المبلغ المدفوع ونسبته المئوية هو ما جرى تحصيله حتى الآن',
        'owed' => 'المبلغ الملوّن هو ما لا يزال مستحقًا',
        'badge' => 'الشارة تبيّن الحالة: غير مسدد أو مسدد جزئيًا أو مسدد أو مشطوب',
        'collect' => 'يسجّل دفعة من المبلغ الذي لا يزال مستحقًا، سواء كانت الأولى أو قسطًا لاحقًا',
        'adjust' => 'يغيّر مبلغ الفاتورة نفسه مع ذكر السبب',
        'write off' => 'وتبقى الفاتورة المشطوبة محسوبة في رصيد الطالب',
        'reversal' => 'الدفعة المعكوسة لا تُحتسب مدفوعة بعد الآن، ويعود مبلغها مستحقًا',
    ]],
]);

it('renders the help panel, every sentence of it, in :dataset', function (string $locale, array $sentinels) {
    app()->setLocale($locale);
    ($this->billWithPartPayment)();
    $actor = ($this->actor)(['view_any_charge']);

    $text = ($this->textOf)(Livewire::actingAs($actor)->test(ListCharges::class)->html());

    foreach ($sentinels as $phrase) {
        expect($text)->toContain($phrase);
    }
})->with('help sentinels');

it('names the heading and the three buttons beside their explanations', function () {
    ($this->billWithPartPayment)();
    $actor = ($this->actor)(['view_any_charge']);

    $page = Livewire::actingAs($actor)->test(ListCharges::class);
    $text = ($this->textOf)($page->html());

    expect($text)->toContain('How to read this page')
        ->and($text)->toContain('Collect payment Takes a payment')
        ->and($text)->toContain('Adjust Changes the bill amount')
        ->and($text)->toContain('Write off Gives up on collecting');
});

it('keeps the panel open by default and remembers a collapse per browser', function () {
    ($this->billWithPartPayment)();
    $actor = ($this->actor)(['view_any_charge']);

    $html = html_entity_decode(Livewire::actingAs($actor)->test(ListCharges::class)->html(), ENT_QUOTES | ENT_HTML5);

    // Persisted through Alpine's $persist under a stable section id; open unless
    // the browser stored otherwise.
    expect($html)->toContain('id="charges-help"')
        ->and($html)->toMatch('/\$persist\(false\)\.as\(`section-\$\{[^}]*charges-help[^}]*\}-isCollapsed`\)/');
});

it('does not show the help panel to an actor who cannot list charges', function () {
    ($this->billWithPartPayment)();

    Livewire::actingAs(($this->actor)([]))->test(ListCharges::class)->assertForbidden();
});

/** @return array<string, array{string, string, string, string}> locale => the three tooltips */
dataset('tooltips', [
    'English' => ['en',
        'Take a payment towards what is still owed. Each payment gets its own receipt.',
        'Change the bill amount itself. Not for recording money received.',
        'Give up on collecting this debt. Not for recording money received.',
    ],
    'Arabic' => ['ar',
        'تسجيل دفعة من المبلغ الذي لا يزال مستحقًا، ولكل دفعة إيصالها.',
        'تغيير مبلغ الفاتورة نفسه. ليس لتسجيل أموال مستلمة.',
        'التخلي عن تحصيل هذا الدين. ليس لتسجيل أموال مستلمة.',
    ],
]);

it('puts a translated one-line tooltip on the three row buttons in :dataset', function (string $locale, string $collect, string $adjust, string $writeOff) {
    app()->setLocale($locale);

    expect(ChargeResource::collectPaymentAction()->getTooltip())->toBe($collect)
        ->and(ChargeResource::adjustAction()->getTooltip())->toBe($adjust)
        ->and(ChargeResource::writeOffAction()->getTooltip())->toBe($writeOff);
})->with('tooltips');

it('carries the tooltips into the rendered row for an actor holding every button permission', function () {
    $charge = ($this->billWithPartPayment)();
    $actor = ($this->actor)(['view_any_charge', 'view_charge', 'create_payment', 'create_enrollment', 'adjust_charge', 'write_off_charge']);

    $html = html_entity_decode(Livewire::actingAs($actor)->test(ListCharges::class)->html(), ENT_QUOTES | ENT_HTML5);

    expect($charge->exists)->toBeTrue()
        ->and($html)->toContain('Take a payment towards what is still owed. Each payment gets its own receipt.')
        ->and($html)->toContain('Change the bill amount itself. Not for recording money received.')
        ->and($html)->toContain('Give up on collecting this debt. Not for recording money received.');
});

it('leaves the list and the card figures as they were: two bills of 850 with 300 paid show 1100.000 LYD', function () {
    $first = ($this->billWithPartPayment)();
    $second = ($this->billWithPartPayment)();
    $actor = ($this->actor)(['view_any_charge']);

    $page = Livewire::actingAs($actor)->test(ListCharges::class)->assertCanSeeTableRecords([$first, $second]);
    $text = ($this->textOf)($page->html());

    // 2 x (850 - 300) = 1100, worked out by hand.
    expect($text)->toContain('1100.000 LYD')
        ->and($text)->toContain('2 bills');
});
