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

it('refuses a coherently corrupted payment reference and matching receipt path', function (): void {
    $canary = 'coherently-corrupted-receipt-canary';
    $corruptReference = 'RCT-1999-999999';
    $corruptPath = 'receipts/'.$corruptReference.'.pdf';
    $this->payment->update([
        'reference' => $corruptReference,
        'receipt_path' => $corruptPath,
    ]);
    Storage::disk('private')->put($corruptPath, $canary);

    $response = $this->actingAs(($this->actorWith)('view_payment'))->get($this->url);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($canary);
    Storage::disk('private')->assertExists($corruptPath);
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

/*
|--------------------------------------------------------------------------
| The limiter's store, and why it is not the database one (P3.5-T16)
|--------------------------------------------------------------------------
|
| T11 measured 2 HTTP 500s in 240 downloads of this receipt route, both MySQL
| 1213 deadlocks inside Illuminate\Cache\RateLimiter. Laravel binds the limiter
| to `cache.limiter` rather than to the default store, and that key did not
| exist — so the limiter inherited `cache.default`, which is the database.
|
| InnoDB's report, read from the Linux target, names the cycle: two concurrent
| `insert ignore into cache` statements for one key each take a shared record
| lock during the duplicate-key check, then each needs an exclusive one on that
| same record. All four sides read "locks rec but not gap", so no gap lock is
| involved and an isolation-level change would not help. The record is
| delete-marked, left by the expiry DELETE that `DatabaseStore::many()` issues
| during a READ.
|
| THIS GUARD CHECKS THE SHIPPED CONFIGURATION, NOT THIS PROCESS'S.
| `phpunit.xml` pins the suite to array stores, so asserting
| `config('cache.limiter')` alone would read `array` and pass whether or not
| the fix exists — it would agree with the test environment rather than with
| the deployment. What has to hold is that the shipped config names a limiter
| store at all, and that the value a deployment is handed is not the database.
*/

it('keeps the rate limiter off the database cache store', function (): void {
    /*
     * The key must EXIST. Without it the limiter silently inherits
     * `cache.default`, which is exactly how this defect arrived, and no value
     * anywhere would look wrong.
     */
    $cacheConfig = require base_path('config/cache.php');

    expect(array_key_exists('limiter', $cacheConfig))->toBeTrue(
        'config/cache.php declares no `limiter` store, so the rate limiter inherits `cache.default` '
        .'— the database store, whose concurrent inserts deadlock (P3.5-T16).',
    );

    /*
     * And the value a real deployment is handed must not be the database
     * store. `.env.example` is what a deployment is built from, so that is the
     * artefact to assert on; this process reads the array pin from phpunit.xml.
     */
    $envExample = (string) file_get_contents(base_path('.env.example'));

    /*
     * `\R` rather than `$`: this file is checked out with CRLF endings on
     * Windows, and `\S+$` cannot match past the carriage return. The same
     * anchoring trap has bitten this repository before.
     */
    preg_match('/^CACHE_LIMITER=(\S+)\R/m', $envExample, $matches);

    expect($matches)->not->toBeEmpty('.env.example does not set CACHE_LIMITER at all, so a deployment '
        .'built from it leaves the limiter inheriting CACHE_STORE — the database.')
        ->and($matches[1])->not->toBe(
            'database',
            '.env.example hands deployments the database store for the rate limiter, which is the '
            .'configuration P3.5-T16 measured deadlocking.',
        );

    // The suite's own pin must not reintroduce it either.
    expect(config('cache.limiter'))->not->toBe('database');
})->group('finance');
