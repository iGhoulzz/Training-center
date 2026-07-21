<?php

declare(strict_types=1);

use App\Domain\Staff\Exceptions\LastSuperAdminException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;

/**
 * The escalation exceptions must render as handled business rejections, never a
 * 500, so Filament/Livewire and any HTTP caller treat them correctly:
 *   - authorization refusals (guards 1/2/4) → 403
 *   - the last-super-admin business rule (guard 3) → 422
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
