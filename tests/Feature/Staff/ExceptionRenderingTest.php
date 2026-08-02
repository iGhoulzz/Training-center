<?php

declare(strict_types=1);

use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The typed domain exceptions must render as handled outcomes, never a raw 500,
 * so Filament/Livewire and any HTTP caller treat them correctly:
 *
 *   - authorization refusals (guards 1/2/4) → 403
 *   - the last-super-admin business rule (guard 3) → 422
 *   - a storage failure (P1-T15, domain-integrity finding 3) → 503
 *
 * The mapping lives in bootstrap/app.php.
 */
it('renders the last-super-admin business rule as HTTP 422', function () {
    Route::get('/__t__/last-super-admin', fn () => throw new LastSuperAdminException);

    $this->get('/__t__/last-super-admin')->assertStatus(422);
});

it('renders the last-super-admin business rule as a 422 JSON message for API callers', function () {
    Route::get('/__t__/last-super-admin-json', fn () => throw new LastSuperAdminException);

    $this->getJson('/__t__/last-super-admin-json')
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

it('renders an authorization refusal as HTTP 403', function () {
    Route::get('/__t__/authz', fn () => throw new AuthorizationException);

    $this->get('/__t__/authz')->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Storage failures (P1-T15, domain-integrity finding 3)
|--------------------------------------------------------------------------
|
| The private disk is configured with throw => false, so every call site
| converts a false return into FileStorageException. Nothing rendered it and
| nothing caught it, so a full or read-only disk reached the administrator as an
| unexplained 500 — the one failure where knowing WHICH thing broke is the whole
| difference between a five-minute fix and an outage.
|
| 503 rather than 500, deliberately: the request was well-formed and the actor
| was entitled: the server's storage is temporarily unable to take it. That is
| what 503 means, and it is the code that tells a caller to retry rather than to
| change the request.
*/

it('renders a storage failure as HTTP 503', function () {
    Route::get('/__t__/storage', fn () => throw FileStorageException::writeFailed('private', 'x.pdf'));

    $this->get('/__t__/storage')->assertStatus(503);
});

it('renders a storage failure as a 503 JSON message for API callers', function () {
    Route::get('/__t__/storage-json', fn () => throw FileStorageException::writeFailed('private', 'x.pdf'));

    $this->getJson('/__t__/storage-json')
        ->assertStatus(503)
        ->assertJsonStructure(['message']);
});

it('never puts the disk name or the stored path in what the caller is shown', function () {
    /*
     * The exception carries both so an operator can read them in the log. A
     * response body is not a log: `path` is the generated storage layout and
     * `disk` names an internal filesystem, and neither tells the administrator
     * anything they can act on. The rendered message is the translated
     * explanation only.
     */
    Route::get(
        '/__t__/storage-leak',
        fn () => throw FileStorageException::writeFailed('private', 'staff-certificates/01JSECRETPATH.pdf'),
    );

    $response = $this->get('/__t__/storage-leak');

    $response->assertStatus(503);

    expect($response->getContent())->not->toContain('01JSECRETPATH')
        ->and($response->getContent())->not->toContain('staff-certificates/');
});

it('still lets the purge job throw, because throwing is how it retries', function () {
    /*
     * THE ONE PLACE THIS EXCEPTION MUST NOT BE SOFTENED.
     *
     * PurgeDeletedFileJob throws on a failed unlink so Laravel retries it, and
     * after $tries it lands in failed_jobs where the sweep can find its receipt.
     * A renderer only applies to HTTP responses, so it cannot reach the job —
     * this asserts that rather than assuming it, because "handle the exception
     * everywhere" is exactly the change that would quietly convert a retryable
     * data-destruction failure into a silent success.
     */
    Storage::fake('private');

    $path = 'staff-certificates/'.Str::ulid()->toString().'.pdf';
    Storage::disk('private')->put($path, 'bytes');

    $pending = PendingFileDeletion::query()->create([
        'disk' => 'private',
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);

    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::shouldReceive('disk')->with('private')->andReturn($failing);

    expect(fn () => (new PurgeDeletedFileJob((int) $pending->getKey()))->handle())
        ->toThrow(FileStorageException::class);
})->uses(RefreshDatabase::class);
