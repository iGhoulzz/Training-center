<?php

declare(strict_types=1);

use Tooling\BoostGuidelines;
use Tooling\Repo;

it('replaces only the marked block byte for byte', function () {
    $original = "before\r\n\r\n\r\n<laravel-boost-guidelines>\nold\n</laravel-boost-guidelines>\r\nafter-without-newline";
    $updated = "normalized\n<laravel-boost-guidelines>\nnew\n</laravel-boost-guidelines>\nchanged";

    expect(BoostGuidelines::withUpdatedBlock($original, $updated, 'AGENTS.md'))->toBe(
        "before\r\n\r\n\r\n<laravel-boost-guidelines>\nnew\n</laravel-boost-guidelines>\r\nafter-without-newline",
    );
});

it('requires exactly one ordered marker pair in original and updated content', function (string $contents) {
    BoostGuidelines::split($contents, 'AGENTS.md');
})->with([
    'missing pair' => ['plain project instructions'],
    'second opening marker' => ['<laravel-boost-guidelines><laravel-boost-guidelines></laravel-boost-guidelines>'],
    'second closing marker' => ['<laravel-boost-guidelines></laravel-boost-guidelines></laravel-boost-guidelines>'],
    'reversed markers' => ['</laravel-boost-guidelines><laravel-boost-guidelines>'],
])->throws(RuntimeException::class);

it('provides one committed manual update command', function () {
    $composer = json_decode((string) file_get_contents(Repo::root().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['boost:update'] ?? null)->toBe([
        '@php scripts/bin/update-boost.php',
    ]);
});
