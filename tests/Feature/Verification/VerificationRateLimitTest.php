<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The certificate-verification limiter — registered AND reached (P3-T08)
|--------------------------------------------------------------------------
|
| `RateLimiter::for('certificate-verification', ...)` being present in
| AppServiceProvider proves nothing on its own — P1-T03's `login` limiter was
| registered exactly the same way and protected nothing, because no route
| ever referenced it, and it was deleted for that reason. Every test in this
| file except the first drives requests through the REAL route, not through
| reflection on the provider, so a limiter that got un-wired from
| routes/web.php would fail these the same way a route that never carried it
| would.
*/

it('registers two windows on the certificate-verification limiter — 10 per minute and 100 per hour', function () {
    $callback = RateLimiter::limiter('certificate-verification');

    expect($callback)->not->toBeNull('The certificate-verification limiter is not registered at all.');

    $limits = $callback(Request::create('/verify/certificates', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));

    expect($limits)->toBeArray()->toHaveCount(2);

    $byWindow = collect($limits)->keyBy(fn (Limit $limit): int => $limit->decaySeconds);

    expect($byWindow->has(60))->toBeTrue('No per-minute window on the limiter.');
    expect($byWindow->get(60)->maxAttempts)->toBe(10);

    expect($byWindow->has(3600))->toBeTrue('No per-hour window on the limiter.');
    expect($byWindow->get(3600)->maxAttempts)->toBe(100);
});

it('refuses the eleventh request in a minute from one IP, reached through the real route', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']))
            ->assertStatus(404);
    }

    $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']))
        ->assertStatus(429);
});

it('counts the form, the submission and the lookup against the same per-IP budget', function () {
    // All three verify routes carry the SAME named limiter, keyed only by IP
    // — design section 7.2 requires the limit apply to the submission and the
    // lookup alike, and routes/web.php puts all three in one group so none
    // can drift out of it. Nine lookups plus one submission is still ten.
    for ($i = 0; $i < 9; $i++) {
        $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']))
            ->assertStatus(404);
    }

    $this->post(route('verify.certificates.submit'), ['reference' => ''])
        ->assertStatus(404);

    $this->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']))
        ->assertStatus(429);
});

it('tracks the limit per IP address, not globally across every visitor', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']))
            ->assertStatus(404);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']))
        ->assertStatus(429);

    // A different IP has never made a request, so its budget is untouched —
    // if the key were global rather than per-IP, this would also be 429.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])
        ->get(route('verify.certificates.show', ['reference' => 'not-a-real-reference']))
        ->assertStatus(404);
});
