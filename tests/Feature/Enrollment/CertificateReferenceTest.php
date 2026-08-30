<?php

declare(strict_types=1);

use App\Domain\Enrollment\Support\CertificateReference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * This file writes nothing — it mints strings and freezes a clock — so
 * DatabaseIsolationTest's READ_ONLY_FEATURE_TESTS list would also satisfy it.
 * RefreshDatabase is used instead because that list is a claim nothing
 * verifies: it checks only that the named file still exists. A later test
 * added here that did touch the database would leak its rows into whichever
 * file ran next, silently. Isolation costs a transaction per test and cannot
 * go stale.
 */
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The public reference (P3-T04)
|--------------------------------------------------------------------------
|
| DETERMINISTIC ON PURPOSE. The obvious test — generate ten thousand references
| and assert no duplicates — asserts a property of ONE RUN, cannot fail
| reproducibly, and is the shape this project has already been bitten by: a suite
| that rolls dice is green locally and red for two hours a day.
|
| So the randomness is injected. Every test below drives it with a fixed
| sequence, and the clock is frozen. Nothing here depends on chance.
|
| WHAT THIS VALUE IS FOR shapes every decision in it: it is printed on a physical
| certificate, read off that paper by a stranger, and typed into a PUBLIC
| verifier that does exact-match lookup only. So it is uppercase, it avoids
| characters people confuse when transcribing, and it is deliberately NOT
| sequential — design section 6.5 requires neighbours be non-enumerable, which is
| exactly the opposite of the ENR-/CHG-/RCT- series.
*/

it('renders the canonical shape', function () {
    $issuedAt = CarbonImmutable::parse('2026-07-01 09:00:00');

    // A picker that always chooses the first character of the alphabet, so the
    // suffix is knowable without reproducing the generator's arithmetic here.
    $reference = (new CertificateReference(fn (int $max): int => 0))->mint($issuedAt);

    $first = CertificateReference::ALPHABET[0];

    expect($reference)->toBe('TC-2026-'.str_repeat($first, 8));
});

it('takes its year from Tripoli, not from UTC', function () {
    /*
     * The application runs in UTC (config/app.php). At this instant Tripoli is
     * already into the next year, and the certificate is being issued there.
     *
     * Without a Tripoli reading this returns 2026 — a reference whose year
     * disagrees with the issued_at date printed beside it on the same document.
     */
    $issuedAt = CarbonImmutable::parse('2026-12-31 23:30:00');

    $reference = (new CertificateReference(fn (int $max): int => 0))->mint($issuedAt);

    expect($reference)->toStartWith('TC-2027-');
});

it('draws eight characters from the alphabet', function () {
    $issuedAt = CarbonImmutable::parse('2026-07-01 09:00:00');

    // A fixed walk through the alphabet: indexes 0..7.
    $index = 0;
    $reference = (new CertificateReference(function (int $max) use (&$index): int {
        return $index++;
    }))->mint($issuedAt);

    $expected = substr(CertificateReference::ALPHABET, 0, 8);

    expect($reference)->toBe('TC-2026-'.$expected)
        ->and(substr($reference, 8))->toHaveLength(8);
});

it('pins the exact alphabet', function () {
    /*
     * SET EQUALITY, not just the absence of the confusable characters.
     *
     * Asserting only that 0/O/1/I/L are missing passes just as happily against a
     * three-character alphabet — an entropy collapse that would make references
     * guessable while every other test here stayed green.
     */
    expect(CertificateReference::ALPHABET)->toBe('23456789ABCDEFGHJKMNPQRSTUVWXYZ')
        ->and(strlen(CertificateReference::ALPHABET))->toBe(31)
        ->and(count(array_unique(str_split(CertificateReference::ALPHABET))))->toBe(31);
});

it('excludes characters people confuse when transcribing', function () {
    /*
     * The reference is read off paper and typed into the verifier by hand, and
     * the verifier matches exactly — so a character a reader cannot tell apart
     * from another is a failed verification of a genuine certificate.
     *
     * Asserted against the alphabet constant rather than against generated
     * output, because generated output only ever demonstrates the characters it
     * happened to draw.
     */
    expect(CertificateReference::ALPHABET)
        ->not->toContain('0')
        ->not->toContain('O')
        ->not->toContain('1')
        ->not->toContain('I')
        ->not->toContain('L')
        ->and(CertificateReference::ALPHABET)->toBe(strtoupper(CertificateReference::ALPHABET));
});

it('never returns the same reference for two different draws', function () {
    $issuedAt = CarbonImmutable::parse('2026-07-01 09:00:00');

    $sequence = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
    $position = 0;
    $mint = new CertificateReference(function (int $max) use ($sequence, &$position): int {
        return $sequence[$position++];
    });

    expect($mint->mint($issuedAt))->not->toBe($mint->mint($issuedAt));
});

it('returns the same reference when the randomness repeats, rather than hiding a collision', function () {
    /*
     * THE CALLER HANDLES COLLISIONS, NOT THIS CLASS.
     *
     * T5's issue Action retries on the reference's unique index and must be able
     * to distinguish that from the one-valid-certificate index. A generator that
     * silently re-drew until it found something unused would swallow the signal,
     * hide how often it happens, and — since it cannot see the table — be lying
     * about uniqueness anyway.
     *
     * So identical randomness yields an identical reference, and the database is
     * the only thing that decides whether one is already taken.
     */
    $issuedAt = CarbonImmutable::parse('2026-07-01 09:00:00');

    $mint = new CertificateReference(fn (int $max): int => 3);

    expect($mint->mint($issuedAt))->toBe($mint->mint($issuedAt));
});

it('defaults to real randomness when none is injected', function () {
    // The container resolves it with no arguments, so the default path is the
    // one production uses and must actually work.
    $issuedAt = CarbonImmutable::parse('2026-07-01 09:00:00');

    $reference = app(CertificateReference::class)->mint($issuedAt);

    expect($reference)->toMatch('/^TC-2026-['.CertificateReference::ALPHABET.']{8}$/');
});
