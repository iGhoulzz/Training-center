<?php

declare(strict_types=1);

use Dotenv\Dotenv;

/** @return array<string, string|null> */
function productionExampleEnvironment(): array
{
    return Dotenv::parse((string) file_get_contents(base_path('.env.example')));
}

it('ships Redis as the production queue connection', function () {
    expect(productionExampleEnvironment()['QUEUE_CONNECTION'] ?? null)->toBe('redis');
});

it('keeps sessions database backed', function () {
    expect(productionExampleEnvironment()['SESSION_DRIVER'] ?? null)->toBe('database');
});

it('ships the designed eight hour idle session lifetime', function () {
    expect(productionExampleEnvironment()['SESSION_LIFETIME'] ?? null)->toBe('480');
});

it('does not move the cache to Redis without an owner decision', function () {
    expect(productionExampleEnvironment()['CACHE_STORE'] ?? null)->toBe('database');
});

it('uses the phpredis client required by the deployment contract', function () {
    expect(productionExampleEnvironment()['REDIS_CLIENT'] ?? null)->toBe('phpredis');
});
