<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use Closure;
use Illuminate\Support\Carbon;

/**
 * Mints the reference printed on a physical certificate.
 *
 * `TC-{year}-{8 characters}` — for example `TC-2026-7K4M9Q2R`.
 *
 * DELIBERATELY UNLIKE THE OTHER REFERENCE SERIES
 * ==============================================
 * `ENR-`, `CHG-` and `RCT-` are sequential and human-dictatable, because they
 * are quoted over a counter by the person already holding the document. This one
 * is read by an unauthenticated stranger against a PUBLIC verifier, so design
 * section 6.5 requires that neighbouring certificates not be enumerable: knowing
 * one reference must not let anyone walk to the next.
 *
 * Two consequences, both of which look like omissions until you know that:
 *
 *   - It is random, not a counter, and NOT derived from the row id.
 *   - Phase 2's placeholder-then-replace dance is therefore unnecessary here.
 *     That trick exists because a sequential reference cannot be known until the
 *     row has an AUTO_INCREMENT id; a random one is known before the insert.
 *
 * Do NOT "harden" the other three series into this shape, and do not make this
 * one readable by making it sequential. They answer different questions.
 *
 * THE ALPHABET IS ABOUT TRANSCRIPTION, NOT ENTROPY
 * ------------------------------------------------
 * This value is printed on paper, read by a stranger, and typed into a verifier
 * that matches EXACTLY. `0`/`O` and `1`/`I`/`L` are the pairs people transpose,
 * and every one of those is a genuine certificate failing to verify. They are
 * excluded.
 *
 * 31 characters over 8 positions is about 10^12 combinations, which is not a
 * security boundary and is not claimed as one — the verifier is rate limited
 * (T8) and exposes nothing but the printed fields. What this buys is that
 * knowing `TC-2026-7K4M9Q2R` tells you nothing about any other certificate.
 *
 * COLLISIONS ARE THE CALLER'S PROBLEM, ON PURPOSE
 * -----------------------------------------------
 * This class never checks the table and never re-draws. It cannot see whether a
 * reference is taken, so a "unique" guarantee here would be a lie; and T5's
 * issue Action needs a collision on the reference index to surface so it can
 * retry the transaction — while a collision on
 * `uniq_valid_certificate_per_enrollment` must NOT retry, because that one is a
 * correct refusal. A generator that quietly re-drew would erase the difference.
 */
final class CertificateReference
{
    /**
     * The characters a reference may contain.
     *
     * Uppercase, and without 0/O and 1/I/L — see the class docblock. Public
     * because the tests assert against the set itself rather than against
     * whichever characters a particular run happened to draw.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** How many random characters follow the year. Design section 6.5: at least eight. */
    private const LENGTH = 8;

    /**
     * The centre's timezone, stated rather than inherited.
     *
     * config/app.php runs the application in UTC. A certificate issued at
     * 01:30 Tripoli on 1 January would otherwise carry the previous year in its
     * reference while `issued_at` — read from the same instant — shows January,
     * so the document would disagree with itself.
     */
    private const TIMEZONE = 'Africa/Tripoli';

    /** @var Closure(int): int a picker returning an index in [0,] */
    private readonly Closure $pickIndex;

    /**
     * @param  (Closure(int): int)|null  $pickIndex  injected by tests so the output is
     *                                               reproducible; production uses random_int
     */
    public function __construct(?Closure $pickIndex = null)
    {
        $this->pickIndex = $pickIndex ?? static fn (int $max): int => random_int(0, $max);
    }

    /**
     * A new reference. Never checks the database — see the class docblock.
     */
    public function mint(): string
    {
        return sprintf('TC-%s-%s', $this->year(), $this->suffix());
    }

    /**
     * The issuing year, in the centre's timezone.
     *
     * Carbon::now() rather than a passed-in clock so that Carbon::setTestNow()
     * freezes it, which is how the tests pin the new-year boundary.
     */
    private function year(): string
    {
        return Carbon::now()->setTimezone(self::TIMEZONE)->format('Y');
    }

    private function suffix(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $suffix = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $suffix .= self::ALPHABET[($this->pickIndex)($max) % ($max + 1)];
        }

        return $suffix;
    }
}
