<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use App\Support\CentreCalendar;
use Closure;
use DateTimeInterface;

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
     * A new reference for a certificate issued at $issuedAt.
     *
     * THE INSTANT IS PASSED IN, NOT SAMPLED HERE, AND THAT IS THE POINT.
     * =================================================================
     * The design requires the reference's year and the row's `issued_at` to come
     * from ONE reading. An earlier version of this class called `Carbon::now()`
     * internally while its callers sampled `now()` separately for the column —
     * two readings that can straddle Tripoli's new year, producing a document
     * whose printed reference disagrees with its own issue date.
     *
     * The caller captures the instant once and hands it to both.
     *
     * THE TIMEZONE IS CentreCalendar's, NOT THIS CLASS'S.
     * `CentreCalendar::yearOf()` is the single definition of what year an instant
     * falls in for this centre, and its docblock records that
     * `EnrollStudentAction` once held a private `REFERENCE_TIMEZONE` of
     * `Africa/Tripoli` before being consolidated into it. This class briefly
     * reintroduced that same private constant; it is gone.
     *
     * Never checks the database — see the class docblock.
     */
    public function mint(DateTimeInterface $issuedAt): string
    {
        return sprintf('TC-%d-%s', CentreCalendar::yearOf($issuedAt), $this->suffix());
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
