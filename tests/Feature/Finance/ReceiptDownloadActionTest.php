<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Models\Payment;
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
| The route's own guards are ReceiptDownloadTest's job and are not
| exhaustively re-proven here. What this file checks about the route is
| narrower: that the button links to it, that it still carries its session and
| throttle middleware, and that it serves or refuses exactly where the button
| is shown or hidden.
|
| NO SUPER ADMIN ANYWHERE IN THIS FILE, ON PURPOSE. super_admin holds
| Permission::all(), so an action authorized on the wrong ability —
| authorize('reverse'), authorize('create') — would still render for it and
| every "shown" assertion would pass green. The first version of this file
| used a super admin throughout and had exactly that blind spot; in production
| the 'reverse' mistake would remove the button from every admin, the role
| that takes money at the desk.
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

    // The least privilege that may download a receipt: open the panel, list
    // payments, view one. With exactly these three, only authorize('view')
    // renders the button — any other ability hides it and fails the test.
    $this->viewer = ($this->actorWith)('access_admin_panel', 'view_any_payment', 'view_payment');

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
    // path recorded.
    $pending = Payment::factory()->create(['receipt_path' => null]);

    Livewire::actingAs($this->viewer)
        ->test(ListPayments::class)
        ->assertTableActionVisible('downloadReceipt', $withReceipt)
        ->assertTableActionHidden('downloadReceipt', $pending);
});

it('links to the guarded receipt route, which serves the bytes to the same actor', function (): void {
    $payment = ($this->paymentWithReceipt)();
    $url = route('finance.receipts.download', $payment);

    Livewire::actingAs($this->viewer)
        ->test(ListPayments::class)
        ->assertTableActionHasUrl('downloadReceipt', $url, $payment);

    $response = $this->actingAs($this->viewer)->get($url);

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 '.$payment->reference);
});

it('keeps the receipt route behind its session and throttle middleware', function (): void {
    /*
     * This pins the ROUTE, and only the route. It says nothing about the
     * button — the previous test is what pins the button's URL to this route.
     * Together they mean the link cannot lead somewhere that has shed these
     * two guards without one of them failing.
     */
    $middleware = Route::getRoutes()->getByName('finance.receipts.download')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain(AuthenticatePrivateFileSession::class)
        ->and($middleware)->toContain('throttle:60,1');
});

it('hides the action from an actor who can list payments but is refused the download', function (): void {
    /*
     * The other half of a pair. This actor holds exactly one permission fewer
     * than the viewer the tests above use — no view_payment — and gets a
     * hidden button and a 403 from the route. The viewer, with it, gets a
     * visible button and a 200. So the gate is pinned in BOTH directions: an
     * ability too strict hides it from the viewer, an ability too loose shows
     * it to this actor.
     */
    $payment = ($this->paymentWithReceipt)();
    $lister = ($this->actorWith)('access_admin_panel', 'view_any_payment');

    Livewire::actingAs($lister)
        ->test(ListPayments::class)
        ->assertTableActionHidden('downloadReceipt', $payment);

    $this->actingAs($lister)
        ->get(route('finance.receipts.download', $payment))
        ->assertForbidden();
});

it('shows the action for a recorded path whose file is missing and leaves the 404 to the route', function (): void {
    /*
     * The visibility rule is a RECORDED PATH, not a file known to exist.
     * Checking the disk from visible() would cost a filesystem call per row
     * and become a second definition of a valid receipt, which
     * ReceiptDownloadController already owns. This pins that decision: the
     * button stays, and the route answers 404.
     */
    $payment = Payment::factory()->create(['receipt_disk' => 'private', 'receipt_path' => null]);
    $payment->update(['receipt_path' => 'receipts/'.$payment->reference.'.pdf']);
    $payment->refresh();

    Storage::disk('private')->assertMissing((string) $payment->receipt_path);

    Livewire::actingAs($this->viewer)
        ->test(ListPayments::class)
        ->assertTableActionVisible('downloadReceipt', $payment);

    $this->actingAs($this->viewer)
        ->get(route('finance.receipts.download', $payment))
        ->assertNotFound();
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
        'reversed_by' => ($this->actorWith)('reverse_payment')->getKey(),
        'reversal_reason' => 'Recorded against the wrong bill.',
    ]);

    Livewire::actingAs($this->viewer)
        ->test(ListPayments::class)
        ->assertTableActionVisible('downloadReceipt', $payment->refresh());
});
