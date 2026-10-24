<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Jobs\GenerateReceiptJob;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Support\ReceiptViewData;
use App\Http\Middleware\AuthenticatePrivateFileSession;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The 80 mm receipt print page (P4-T24)
|--------------------------------------------------------------------------
|
| The A4 PDF is rendered once by GenerateReceiptJob and stored. The print page
| is the same receipt laid out for a thermal roll, rendered on demand from the
| same immutable snapshot and never stored. These tests pin four things:
|
|   - who may open it, mirroring ReceiptDownloadTest case for case;
|   - what it shows, with every expected figure written out by hand;
|   - that the paper and the PDF cannot disagree, because both views render
|     through ReceiptViewData;
|   - the row action that opens it.
|
| NO SUPER ADMIN ANYWHERE IN THIS FILE, ON PURPOSE. super_admin holds every
| permission, so an ability checked wrongly would still pass for it. Every actor
| below holds exactly the permissions named, and each allowed case is paired
| with one holding one permission fewer.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');

    $this->actorWith = function (string ...$permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(...$permissions);

        return $actor->refresh();
    };

    // Received at 09:30 UTC, which is 11:30 in Africa/Tripoli (UTC+2 all year).
    // Written out here and never read back from CentreCalendar.
    $this->paymentWithSnapshot = function (string $locale = 'en', ?string $discount = '17.77'): Payment {
        $payment = Payment::factory()->create(['received_at' => '2026-05-10 09:30:00']);

        PaymentReceiptSnapshot::factory()->for($payment)->create([
            'locale' => $locale,
            'student_code' => 'STU-000731',
            'student_name' => 'Layla Printwell',
            'enrollment_reference' => 'ENR-2026-000042',
            'course_code' => 'CRS-LAW-101',
            'batch_code' => 'BAT-LAW-101-A',
            'charge_reference' => 'CHG-2026-000042',
            'list_price' => '1867.321',
            'discount_percentage' => $discount,
            'final_charge' => '1535.538',
            'amount_paid' => '703.219',
            'remaining_balance' => '832.319',
            'recorded_by_name' => 'Counter Operator',
        ]);

        // Created in this order, so ordering by id lists Card first.
        PaymentTender::factory()->card()->for($payment)->create(['amount' => '201.123']);
        PaymentTender::factory()->for($payment)->create(['amount' => '502.096']);

        return $payment->refresh();
    };

    $this->payment = ($this->paymentWithSnapshot)();
    $this->url = route('finance.receipts.print', $this->payment);
});

it('prints the 80 mm receipt for an active actor holding view_payment', function (): void {
    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toStartWith('text/html');

    $html = (string) $response->getContent();
    $missing = array_values(array_filter([
        $this->payment->reference,
        'STU-000731',
        'Layla Printwell',
        'ENR-2026-000042',
        'CRS-LAW-101',
        'BAT-LAW-101-A',
        'CHG-2026-000042',
        '1867.321 LYD',
        '17.77%',
        '1535.538 LYD',
        '703.219 LYD',
        'Tender: Card',
        '201.123 LYD',
        'Tender: Cash',
        '502.096 LYD',
        '832.319 LYD',
        '2026-05-10 11:30',
        'Counter Operator',
        'Arab Advanced Center for Legal Studies and Training',
        'Payment receipt',
    ], fn (string $needle): bool => ! str_contains($html, $needle)));

    expect($missing)->toBe([]);
});

it('prints as soon as it loads, from a self-contained page', function (): void {
    $html = (string) $this->actingAs(($this->actorWith)('view_payment'))->get($this->url)->getContent();

    expect(str_contains($html, "window.addEventListener('load'"))->toBeTrue()
        ->and(str_contains($html, 'window.print()'))->toBeTrue()
        ->and(str_contains($html, 'Print again'))->toBeTrue()
        // Self-contained: no stylesheet, script or image is fetched.
        ->and(preg_match('/<(?:link|img)\b|<script[^>]*\ssrc=|url\(\s*[\'"]?https?:/i', $html))->toBe(0);
});

it('declares a valid 80 mm page size in the stylesheet', function (): void {
    $html = (string) $this->actingAs(($this->actorWith)('view_payment'))->get($this->url)->getContent();

    // The rule text, parsed: `size` takes lengths or a keyword and never a
    // length plus `auto`, which a browser drops whole before printing on Letter.
    preg_match_all('#<style>(.*?)</style>#s', $html, $styles);
    preg_match_all('/@page\s*\{([^}]*)\}/', implode("\n", $styles[1]), $rules);

    expect($rules[1])->toHaveCount(1);

    $declarations = array_values(array_filter(array_map('trim', explode(';', $rules[1][0]))));

    expect($declarations)->toBe(['size: 80mm 297mm', 'margin: 0']);
});

it('has no @page rule containing auto anywhere in the document', function (): void {
    $html = (string) $this->actingAs(($this->actorWith)('view_payment'))->get($this->url)->getContent();

    // The stylesheet's rule and the one the script writes at run time.
    preg_match_all('/@page\s*\{[^}]*\}/', $html, $rules);

    expect(count($rules[0]))->toBeGreaterThanOrEqual(2);

    $withAuto = array_values(array_filter($rules[0], fn (string $rule): bool => str_contains($rule, 'auto')));

    expect($withAuto)->toBe([]);
});

it('fits the page to the receipt before each print, on load and from the button', function (): void {
    $html = (string) $this->actingAs(($this->actorWith)('view_payment'))->get($this->url)->getContent();

    preg_match('#<script>(.*?)</script>#s', $html, $script);
    $js = $script[1] ?? '';

    // Strings are all PHP can see; the printed size is checked in a browser.
    expect(str_contains($js, "pageSize.id = 'page-size'"))->toBeTrue()
        ->and(preg_match("/function fitPage\(\)\s*\{[^}]*pageSize\.textContent = '@page \{ size: 80mm ' \+ millimetres \+ 'mm; margin: 0 \}';/s", $js))->toBe(1)
        ->and(preg_match("/addEventListener\('load',\s*function \(\) \{\s*fitPage\(\);\s*window\.print\(\);/", $js))->toBe(1)
        ->and(preg_match("/getElementById\('print-again'\)\.addEventListener\('click',\s*function \(\) \{\s*fitPage\(\);\s*window\.print\(\);/", $js))->toBe(1);
});

it('keeps the button outside the element whose height sets the page size', function (): void {
    $html = (string) $this->actingAs(($this->actorWith)('view_payment'))->get($this->url)->getContent();

    preg_match('#<main class="receipt">(.*?)</main>#s', $html, $receipt);

    expect(($receipt[1] ?? '') !== '')->toBeTrue()
        ->and(str_contains($receipt[1], 'print-again'))->toBeFalse()
        ->and(str_contains($html, 'id="print-again"'))->toBeTrue();
});

it('serves the print page as private, unsniffable and unindexed', function (): void {
    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    expect((string) $response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->headers->get('x-robots-tag'))->toBe('noindex, nofollow');
});

it('refuses the print page without the payment view permission', function (): void {
    $this->actingAs(($this->actorWith)('view_any_payment'))
        ->get($this->url)
        ->assertForbidden();
});

it('refuses an inactive payment viewer and never prints the receipt', function (): void {
    $actor = ($this->actorWith)('view_payment');
    $actor->update(['is_active' => false]);

    $response = $this->actingAs($actor->fresh())->get($this->url);

    $response->assertForbidden();
    expect(str_contains((string) $response->getContent(), $this->payment->reference))->toBeFalse();
});

it('refuses a guest and never prints the receipt', function (): void {
    // The same answer the download gives: the private-file middleware lets a
    // guest through on purpose and the controller refuses with 403, so a receipt
    // endpoint never tells an anonymous caller where to log in.
    $response = $this->get($this->url);

    $response->assertForbidden();
    expect(str_contains((string) $response->getContent(), $this->payment->reference))->toBeFalse();
});

it('sends a viewer to the panel login once the session has been invalidated', function (): void {
    $actor = ($this->actorWith)('view_payment');
    $this->actingAs($actor)->get($this->url)->assertOk();

    $actor->forceFill(['password' => Hash::make('receipt-print-session-reset')])->save();
    $response = $this->get($this->url);

    $response->assertRedirect('/admin/login');
    expect(str_contains((string) $response->getContent(), $this->payment->reference))->toBeFalse();
});

it('keeps the print route behind the session and throttle middleware, in a group of its own', function (): void {
    $middleware = Route::getRoutes()->getByName('finance.receipts.print')?->gatherMiddleware() ?? [];

    expect(in_array(AuthenticatePrivateFileSession::class, $middleware, true))->toBeTrue()
        ->and(in_array('throttle:60,1', $middleware, true))->toBeTrue();
});

it('answers 404 for a payment that has no receipt snapshot', function (): void {
    $bare = Payment::factory()->create();

    $this->actingAs(($this->actorWith)('view_payment'))
        ->get(route('finance.receipts.print', $bare))
        ->assertNotFound();
});

it('refuses before it reveals whether a snapshot exists', function (): void {
    $bare = Payment::factory()->create();

    $this->actingAs(($this->actorWith)('view_any_payment'))
        ->get(route('finance.receipts.print', $bare))
        ->assertForbidden();
});

it('prints before the PDF exists, because it reads the snapshot and not the stored file', function (): void {
    expect($this->payment->receipt_path)->toBeNull();

    $this->actingAs(($this->actorWith)('view_payment'))->get($this->url)->assertOk();

    Storage::disk('private')->assertDirectoryEmpty('');
});

it('prints a reversed payment, whose receipt was issued', function (): void {
    $this->payment->update([
        'reversed_at' => now(),
        'reversed_by' => ($this->actorWith)('reverse_payment')->getKey(),
        'reversal_reason' => 'Recorded against the wrong bill.',
    ]);

    $this->actingAs(($this->actorWith)('view_payment'))->get($this->url)->assertOk();
});

it('renders an Arabic snapshot right-to-left and puts the app locale back', function (): void {
    $arabic = ($this->paymentWithSnapshot)('ar');
    app()->setLocale('en');

    $response = $this->actingAs(($this->actorWith)('view_payment'))
        ->get(route('finance.receipts.print', $arabic));

    $response->assertOk();
    $html = (string) $response->getContent();

    expect(str_contains($html, '<html dir="rtl" lang="ar">'))->toBeTrue()
        ->and(app()->getLocale())->toBe('en');
});

it('isolates each reference and amount inside its value cell, never on the cell itself', function (): void {
    // A cell carrying dir="ltr" resolves margin-inline-start to the left margin,
    // which in an Arabic row puts the value against its label. Only a browser
    // shows the layout, so this pins the markup that produces the right one.
    $arabic = ($this->paymentWithSnapshot)('ar');
    $html = (string) $this->actingAs(($this->actorWith)('view_payment'))
        ->get(route('finance.receipts.print', $arabic))
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $isolated = fn (string $text): int => $xpath->query(
        "//span[@class='value']/span[@dir='ltr'][normalize-space(.)='{$text}']",
    )->length;

    expect($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' value ')][@dir or contains(concat(' ', normalize-space(@class), ' '), ' ltr ')]")->length)->toBe(0)
        ->and($isolated($arabic->reference))->toBe(1)
        ->and($isolated('CHG-2026-000042'))->toBe(1)
        ->and($isolated('832.319 د.ل.'))->toBe(1)
        ->and($isolated('201.123 د.ل.'))->toBe(1);
});

it('renders an English snapshot left-to-right', function (): void {
    $html = (string) $this->actingAs(($this->actorWith)('view_payment'))->get($this->url)->getContent();

    expect(str_contains($html, '<html dir="ltr" lang="en">'))->toBeTrue();
});

it('restores the locale after rendering, even when the view fails', function (): void {
    $arabic = ($this->paymentWithSnapshot)('ar');
    app()->setLocale('en');
    $viewData = app(ReceiptViewData::class);

    expect(fn () => $viewData->render('finance.no-such-receipt-view', $arabic))
        ->toThrow(InvalidArgumentException::class)
        ->and(app()->getLocale())->toBe('en');

    $viewData->render('finance.receipt-print', $arabic);

    expect(app()->getLocale())->toBe('en');
});

it('refuses to render a payment that has no snapshot', function (): void {
    $bare = Payment::factory()->create();

    expect(fn () => app(ReceiptViewData::class)->render('finance.receipt-print', $bare))
        ->toThrow(ModelNotFoundException::class);
});

it('shows the discount as a percentage, and 0% when there is none', function (): void {
    $actor = ($this->actorWith)('view_payment');

    $none = ($this->paymentWithSnapshot)('en', null);
    $half = ($this->paymentWithSnapshot)('en', '12.50');

    $noneHtml = (string) $this->actingAs($actor)->get(route('finance.receipts.print', $none))->getContent();
    $halfHtml = (string) $this->actingAs($actor)->get(route('finance.receipts.print', $half))->getContent();

    expect(str_contains($noneHtml, '>0%<'))->toBeTrue()
        ->and(str_contains($noneHtml, '0.00'))->toBeFalse()
        ->and(str_contains($halfHtml, '>12.50%<'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The paper and the PDF cannot disagree
|--------------------------------------------------------------------------
*/

it('renders the A4 view and the 80 mm view from the same data', function (): void {
    $viewData = app(ReceiptViewData::class);
    $a4 = $viewData->render('finance.receipt', $this->payment);
    $thermal = $viewData->render('finance.receipt-print', $this->payment);

    $shared = [
        $this->payment->reference,
        'STU-000731',
        'Layla Printwell',
        'ENR-2026-000042',
        'CRS-LAW-101',
        'BAT-LAW-101-A',
        'CHG-2026-000042',
        '1867.321 LYD',
        '17.77%',
        '1535.538 LYD',
        '703.219 LYD',
        '201.123 LYD',
        '502.096 LYD',
        '832.319 LYD',
        'Tender: Card',
        'Tender: Cash',
        '2026-05-10 11:30',
        'Counter Operator',
    ];

    $missingFromA4 = array_values(array_filter($shared, fn (string $needle): bool => ! str_contains($a4, $needle)));
    $missingFromThermal = array_values(array_filter($shared, fn (string $needle): bool => ! str_contains($thermal, $needle)));

    expect($missingFromA4)->toBe([])
        ->and($missingFromThermal)->toBe([]);
});

it('prints the same figures on the stored PDF and on the printed page', function (): void {
    $actor = ($this->actorWith)('view_payment');
    $reference = $this->payment->reference;

    // The real job, writing the real PDF, then the real route.
    app()->call([new GenerateReceiptJob((int) $this->payment->getKey()), 'handle']);

    $pdf = Storage::disk('private')->get('receipts/'.$reference.'.pdf');
    $html = (string) $this->actingAs($actor)->get($this->url)->getContent();

    $shared = [
        $reference,
        'STU-000731',
        'Layla Printwell',
        '1867.321 LYD',
        '17.77%',
        '1535.538 LYD',
        '703.219 LYD',
        '201.123 LYD',
        '502.096 LYD',
        '832.319 LYD',
        'Tender: Card',
        'Tender: Cash',
        '2026-05-10 11:30',
        'Counter Operator',
    ];

    $missingFromPdf = array_values(array_filter($shared, fn (string $needle): bool => ! str_contains(
        (string) $pdf,
        mb_convert_encoding($needle, 'UTF-16BE', 'UTF-8'),
    )));
    $missingFromHtml = array_values(array_filter($shared, fn (string $needle): bool => ! str_contains($html, $needle)));

    expect($missingFromPdf)->toBe([])
        ->and($missingFromHtml)->toBe([]);
});

it('shows the PDF discount line as a percentage', function (): void {
    app()->call([new GenerateReceiptJob((int) $this->payment->getKey()), 'handle']);

    $pdf = (string) Storage::disk('private')->get('receipts/'.$this->payment->reference.'.pdf');

    expect(str_contains($pdf, mb_convert_encoding('17.77%', 'UTF-16BE', 'UTF-8')))->toBeTrue();
});

it('keeps both views off physical CSS properties', function (): void {
    $template = File::get(resource_path('views/finance/receipt-print.blade.php'));

    expect(preg_match('/(?:margin|padding)-(?:left|right)|text-align:\s*(?:left|right)|(?<![\w-])(?:left|right)\s*:/i', $template))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The row action
|--------------------------------------------------------------------------
*/

it('offers the print action for a payment with a snapshot and links to the print route', function (): void {
    $viewer = ($this->actorWith)('access_admin_panel', 'view_any_payment', 'view_payment');
    $bare = Payment::factory()->create();

    Livewire::actingAs($viewer)
        ->test(ListPayments::class)
        ->assertTableActionVisible('printReceipt', $this->payment)
        ->assertTableActionHasUrl('printReceipt', route('finance.receipts.print', $this->payment), $this->payment)
        ->assertTableActionShouldOpenUrlInNewTab('printReceipt', $this->payment)
        ->assertTableActionHidden('printReceipt', $bare);
});

it('hides the print action from an actor who can list payments but may not view them', function (): void {
    $lister = ($this->actorWith)('access_admin_panel', 'view_any_payment');

    Livewire::actingAs($lister)
        ->test(ListPayments::class)
        ->assertTableActionHidden('printReceipt', $this->payment);

    $this->actingAs($lister)->get($this->url)->assertForbidden();
});

it('sits beside the download action without changing it', function (): void {
    $viewer = ($this->actorWith)('access_admin_panel', 'view_any_payment', 'view_payment');
    $withReceipt = Payment::factory()->create(['receipt_disk' => 'private', 'receipt_path' => null]);
    $withReceipt->update(['receipt_path' => 'receipts/'.$withReceipt->reference.'.pdf']);

    Livewire::actingAs($viewer)
        ->test(ListPayments::class)
        ->assertTableActionVisible('downloadReceipt', $withReceipt)
        ->assertTableActionHasUrl('downloadReceipt', route('finance.receipts.download', $withReceipt), $withReceipt)
        ->assertTableActionHidden('downloadReceipt', $this->payment);
});

it('decides the print action for every row without a query per row', function (): void {
    // withExists('receiptSnapshot') answers the visibility in the table's own
    // statement. Reading the relation in visible() instead would add one query
    // per row, which the lazy-loading guard cannot see because exists() is a
    // fresh query, not a lazy load. So the statements are counted directly.
    $viewer = ($this->actorWith)('access_admin_panel', 'view_any_payment', 'view_payment');

    $measure = function () use ($viewer): int {
        $statements = captureStatements();
        Livewire::actingAs($viewer)->test(ListPayments::class)->assertSuccessful();

        return collect($statements)
            ->filter(fn (array $statement): bool => str_contains($statement['sql'], 'payment_receipt_snapshots'))
            ->count();
    };

    ($this->paymentWithSnapshot)();
    $two = $measure();

    foreach (range(1, 6) as $ignored) {
        ($this->paymentWithSnapshot)();
    }
    $eight = $measure();

    expect(Payment::query()->count())->toBe(8)
        ->and($eight)->toBe($two);
});

it('prints the centre name in the receipt language', function (string $locale, string $configKey, string $otherKey): void {
    $payment = ($this->paymentWithSnapshot)($locale);
    $html = (string) $this->actingAs(($this->actorWith)('view_payment'))
        ->get(route('finance.receipts.print', $payment))
        ->getContent();

    expect(str_contains($html, e((string) config($configKey))))->toBeTrue()
        ->and(str_contains($html, e((string) config($otherKey))))->toBeFalse();
})->with([
    'English' => ['en', 'public_site.brand.name', 'public_site.brand.name_ar'],
    'Arabic' => ['ar', 'public_site.brand.name_ar', 'public_site.brand.name'],
]);
