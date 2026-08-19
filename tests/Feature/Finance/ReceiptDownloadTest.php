<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');

    $this->payment = Payment::factory()->create([
        'receipt_disk' => 'private',
        'receipt_path' => null,
    ]);
    $this->payment->update(['receipt_path' => 'receipts/'.$this->payment->reference.'.pdf']);
    $this->bytes = '%PDF-1.4 receipt bytes';
    Storage::disk('private')->put($this->payment->receipt_path, $this->bytes);
    $this->url = route('finance.receipts.download', $this->payment);
    $this->actorWith = function (string ...$permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(...$permissions);

        return $actor->fresh();
    };
});

it('downloads a receipt only for an actor authorized by the payment policy', function (): void {
    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertOk();
    expect($response->streamedContent())->toBe($this->bytes)
        ->and($response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('refuses receipt downloads without the payment view permission', function (): void {
    $this->actingAs(($this->actorWith)('view_any_payment'))
        ->get($this->url)
        ->assertForbidden();
});

it('refuses a guest and never streams receipt bytes', function (): void {
    $response = $this->get($this->url);

    $response->assertForbidden();
    expect($response->getContent())->not->toContain($this->bytes);
});

it('refuses an inactive payment viewer and never streams receipt bytes', function (): void {
    $actor = ($this->actorWith)('view_payment');
    $actor->update(['is_active' => false]);

    $response = $this->actingAs($actor->fresh())->get($this->url);

    $response->assertForbidden();
    expect($response->getContent())->not->toContain($this->bytes);
});

it('invalidates a payment viewer session after its password changes', function (): void {
    $actor = ($this->actorWith)('view_payment');
    $this->actingAs($actor)->get($this->url)->assertOk();

    $actor->forceFill(['password' => Hash::make('receipt-session-reset')])->save();
    $response = $this->get($this->url);

    $response->assertRedirect('/admin/login');
    expect($response->getContent())->not->toContain($this->bytes)
        ->and(auth()->check())->toBeFalse();
});

it('refuses a receipt row pointing outside the receipt namespace', function (): void {
    $canary = 'staff-certificate-bytes-a-payment-viewer-must-not-read';
    $this->payment->update(['receipt_path' => 'staff-certificates/01J0000000000000000000000A.pdf']);
    Storage::disk('private')->put((string) $this->payment->receipt_path, $canary);

    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($canary);
});

it('refuses a receipt row whose disk is not private', function (): void {
    $canary = 'foreign-disk-receipt-canary';
    Storage::fake('local');
    Storage::disk('local')->put((string) $this->payment->receipt_path, $canary);
    $this->payment->update(['receipt_disk' => 'local']);

    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($canary);
    Storage::disk('local')->assertExists((string) $this->payment->receipt_path);
});

it('refuses traversal and never streams its canary bytes', function (): void {
    $canary = 'receipt-traversal-canary';
    $this->payment->update(['receipt_path' => 'receipts/../staff-certificates/secret.pdf']);
    Storage::disk('private')->put((string) $this->payment->receipt_path, $canary);

    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($canary);
});

it('refuses an orphaned receipt row', function (): void {
    Storage::disk('private')->delete((string) $this->payment->receipt_path);

    $this->actingAs(($this->actorWith)('view_payment'))
        ->get($this->url)
        ->assertNotFound();
});

it('refuses a valid receipt path belonging to a different payment', function (): void {
    $other = Payment::factory()->create();
    $canary = 'other-payment-receipt-canary';
    $otherPath = 'receipts/'.$other->reference.'.pdf';
    Storage::disk('private')->put($otherPath, $canary);
    $this->payment->update(['receipt_path' => $otherPath]);

    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($canary);
    Storage::disk('private')->assertExists($otherPath);
});

it('keeps a reversed payment receipt available for authorized re-download', function (): void {
    $reversingActor = User::factory()->create();

    $this->payment->update([
        'reversed_at' => now(),
        'reversed_by' => $reversingActor->getKey(),
        'reversal_reason' => 'Recorded against the wrong bill.',
    ]);

    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertOk();
    expect($response->streamedContent())->toBe($this->bytes);
});
