<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Models\Payment;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Http\Middleware\AuthenticatePrivateFileSession;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The receipt download action (P35-T06)
|--------------------------------------------------------------------------
|
| A finished feature was unreachable: GenerateReceiptJob renders the PDF, the
| payment row records where it lives, ReceiptDownloadController serves it, and
| finance.receipts.download routes to it — and nothing in the panel linked to
| that route.
|
| These tests are about the ACTION. What the route enforces — the policy,
| is_active, the private disk, the canonical path, session integrity — belongs
| to ReceiptDownloadTest, and nothing here re-proves or weakens it. What this
| file does assert is that the action points at that exact route, so every one
| of those guards applies to anything the button serves.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');

    $superAdmin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($superAdmin, 'super_admin');
    $this->superAdmin = $superAdmin->refresh();

    // A receipt as GenerateReceiptJob leaves one, built the same way
    // ReceiptDownloadTest builds it: a canonical path on the private disk,
    // with the bytes actually there.
    $this->paymentWithReceipt = function (): Payment {
        $payment = Payment::factory()->create(['receipt_disk' => 'private', 'receipt_path' => null]);
        $payment->update(['receipt_path' => 'receipts/'.$payment->reference.'.pdf']);
        Storage::disk('private')->put((string) $payment->receipt_path, '%PDF-1.4 '.$payment->reference);

        return $payment->refresh();
    };
});

it('offers the download only once a receipt path is recorded', function (): void {
    $withReceipt = ($this->paymentWithReceipt)();

    // GenerateReceiptJob is queued, so a payment spends time with no receipt
    // path recorded. The visibility rule is the recorded path, not the file:
    // a path to a missing file stays visible and the controller answers 404.
    $pending = Payment::factory()->create(['receipt_path' => null]);

    Livewire::actingAs($this->superAdmin)
        ->test(ListPayments::class)
        ->assertTableActionVisible('downloadReceipt', $withReceipt)
        ->assertTableActionHidden('downloadReceipt', $pending);
});

it('links to the guarded receipt route, which serves the bytes', function (): void {
    $payment = ($this->paymentWithReceipt)();
    $url = route('finance.receipts.download', $payment);

    Livewire::actingAs($this->superAdmin)
        ->test(ListPayments::class)
        ->assertTableActionHasUrl('downloadReceipt', $url, $payment);

    $response = $this->actingAs($this->superAdmin)->get($url);

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 '.$payment->reference);
});

it('keeps the linked route behind its session and throttle guards', function (): void {
    /*
     * The action is a link with no server handler of its own, so the route is
     * the entire boundary for what it serves. Pinned here so that neither the
     * button nor the route can shed those guards without this failing.
     */
    $middleware = Route::getRoutes()->getByName('finance.receipts.download')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain(AuthenticatePrivateFileSession::class)
        ->and($middleware)->toContain('throttle:60,1');
});

it('hides the action from an actor who can list payments but is refused the download', function (): void {
    /*
     * view_any_payment opens the list; ReceiptDownloadController requires
     * view_payment. Without authorize('view') this actor would see a button
     * that answers 403. The two assertions are paired deliberately — the
     * button is hidden exactly where the route refuses — so the gate and the
     * boundary cannot drift apart unnoticed.
     */
    $payment = ($this->paymentWithReceipt)();

    $lister = User::factory()->create(['is_active' => true]);
    $lister->givePermissionTo('access_admin_panel', 'view_any_payment');
    $lister = $lister->refresh();

    Livewire::actingAs($lister)
        ->test(ListPayments::class)
        ->assertTableActionHidden('downloadReceipt', $payment);

    $this->actingAs($lister)
        ->get(route('finance.receipts.download', $payment))
        ->assertForbidden();
});

it('keeps the download available after the payment is reversed', function (): void {
    /*
     * Already decided, and proved at the route, by ReceiptDownloadTest: the
     * receipt was issued, and reversal removes the payment from every balance
     * without un-issuing the paper. Unlike reverseAction(), this action must
     * not hide on reversal.
     */
    $payment = ($this->paymentWithReceipt)();
    $payment->update([
        'reversed_at' => now(),
        'reversed_by' => $this->superAdmin->getKey(),
        'reversal_reason' => 'Recorded against the wrong bill.',
    ]);

    Livewire::actingAs($this->superAdmin)
        ->test(ListPayments::class)
        ->assertTableActionVisible('downloadReceipt', $payment->refresh());
});
