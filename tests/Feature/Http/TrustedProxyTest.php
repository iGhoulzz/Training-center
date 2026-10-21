<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['proxies.trusted' => ['10.10.0.0/24'], 'cache.limiter' => 'array']);
    app()->forgetInstance(Illuminate\Cache\RateLimiter::class);
    RateLimiter::clearResolvedInstance(Illuminate\Cache\RateLimiter::class);
    RateLimiter::for('proxy-contract', fn (Request $request): Limit => Limit::perMinute(1)->by($request->ip()));
    Route::get('/_proxy-contract', fn (Request $request): array => [
        'scheme' => $request->getScheme(), 'host' => $request->host(), 'ip' => $request->ip(),
    ])->middleware(['signed', 'throttle:proxy-contract'])->name('proxy-contract');
    Route::getRoutes()->refreshNameLookups();
    URL::forceRootUrl('https://center.example');
    URL::forceScheme('https');
});

afterEach(function (): void {
    URL::forceRootUrl(null);
    URL::forceScheme(null);
    Request::setTrustedProxies([], 0);
});

it('keeps direct HTTPS signed links valid without trusting forwarding headers', function (): void {
    config(['proxies.trusted' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1', 'HTTPS' => 'on'])
        ->get(URL::signedRoute('proxy-contract'))
        ->assertOk()->assertExactJson(['scheme' => 'https', 'host' => 'center.example', 'ip' => '198.51.100.1']);
});

it('preserves signed HTTPS links and separate client limiter keys through an explicit proxy', function (): void {
    $url = URL::signedRoute('proxy-contract');
    $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.2', 'HTTPS' => 'off'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'center.example', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '198.51.100.11'])
        ->get(str_replace('https://center.example', 'http://origin.internal', $url))
        ->assertOk()->assertExactJson(['scheme' => 'https', 'host' => 'center.example', 'ip' => '198.51.100.11']);
    $this->withHeader('X-Forwarded-For', '198.51.100.12')
        ->get(str_replace('https://center.example', 'http://origin.internal', $url))->assertOk();
    $this->withHeader('X-Forwarded-For', '198.51.100.11')
        ->get(str_replace('https://center.example', 'http://origin.internal', $url))->assertTooManyRequests();
});

it('ignores spoofed forwarding headers from an untrusted peer', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99', 'HTTPS' => 'on'])
        ->withHeaders(['X-Forwarded-Proto' => 'http', 'X-Forwarded-Host' => 'evil.example', 'X-Forwarded-For' => '10.10.0.2'])
        ->get(URL::signedRoute('proxy-contract'))->assertOk()
        ->assertExactJson(['scheme' => 'https', 'host' => 'center.example', 'ip' => '198.51.100.99']);
});

it('defaults to no upstream trust and parses only explicit operator addresses', function (): void {
    $repository = Env::getRepository();
    $previous = $repository->get('TRUSTED_PROXIES');

    try {
        $repository->clear('TRUSTED_PROXIES');
        expect(require base_path('config/proxies.php'))->toBe(['trusted' => []]);
        $repository->set('TRUSTED_PROXIES', ' 10.10.0.0/24,2001:db8::1, 192.0.2.5 ');
        expect(require base_path('config/proxies.php'))->toBe(['trusted' => ['10.10.0.0/24', '2001:db8::1', '192.0.2.5']]);
    } finally {
        $previous === null ? $repository->clear('TRUSTED_PROXIES') : $repository->set('TRUSTED_PROXIES', $previous);
    }
});

it('refuses blanket or malformed upstream trust', function (string $value): void {
    $repository = Env::getRepository();
    $previous = $repository->get('TRUSTED_PROXIES');

    try {
        $repository->set('TRUSTED_PROXIES', $value);
        expect(fn (): mixed => require base_path('config/proxies.php'))->toThrow(InvalidArgumentException::class);
    } finally {
        $previous === null ? $repository->clear('TRUSTED_PROXIES') : $repository->set('TRUSTED_PROXIES', $previous);
    }
})->with(['*', '**', '0.0.0.0/0', '::/0', 'REMOTE_ADDR', '10.10.0.0/33', '2001:db8::/129', 'proxy.example']);
