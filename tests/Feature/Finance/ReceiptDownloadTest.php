<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');

    $this->payment = Payment::factory()->create([
        'receipt_disk' => 'private',
        'receipt_path' => 'receipts/RCT-2026-000001.pdf',
    ]);
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

it('refuses a receipt row pointing outside the receipt namespace', function (): void {
    $canary = 'staff-certificate-bytes-a-payment-viewer-must-not-read';
    $this->payment->update(['receipt_path' => 'staff-certificates/01J0000000000000000000000A.pdf']);
    Storage::disk('private')->put((string) $this->payment->receipt_path, $canary);

    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($canary);
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
