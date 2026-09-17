<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use App\Support\CentreCalendar;
use Closure;
use DateTimeInterface;

/**
 * Mints the generated student and batch codes.
 *
 * `STU-{year}-{6 characters}` and `BAT-{year}-{6 characters}` — for example
 * `STU-2026-7K4M9Q`. Both are 15 characters, inside `students.student_code`
 * (varchar 30) and `batches.code` (varchar 40).
 *
 * WHY THIS IS CertificateReference's SHAPE AND NOT Reference's
 * ============================================================
 * The finance series (`ENR-`, `CHG-`, `RCT-`) are sequential, and a sequential
 * value cannot be known until the row has an AUTO_INCREMENT id, so Finance
 * inserts a placeholder and replaces it. That is unusable here, for two reasons
 * the phase 3.5 plan measured rather than assumed:
 *
 *   - `PLACEHOLDER-` plus a UUID is 48 characters, and fits neither column.
 *   - Both columns are audited attributes. `Reference.php` records that a
 *     transaction does not stop model events logging the placeholder; Finance
 *     escapes that only because its references are NOT audited. Copying the
 *     shape would write an internal placeholder into the append-only log.
 *
 * A random suffix is known BEFORE the insert, so the row is written once, with
 * its real code, and the log sees only that.
 *
 * THE ALPHABET IS CertificateReference's, BY REFERENCE
 * ----------------------------------------------------
 * These codes are read off paper and typed back, so the same transcription
 * pairs (`0`/`O`, `1`/`I`/`L`) are excluded for the same reason. The constant
 * is used, not copied: a second alphabet would drift from the first.
 *
 * Six characters over 31 symbols is about 887 million combinations per year
 * and prefix — not a secret and not claimed as one. These codes need to be
 * unambiguous and short, not unguessable.
 *
 * THE INSTANT IS PASSED IN, AND COLLISIONS ARE THE CALLER'S
 * ---------------------------------------------------------
 * Exactly CertificateReference's two rules, for the same reasons: the caller
 * captures one instant and uses it for both the code's year and the row's
 * `created_at`, and this class never checks the table or redraws, because only
 * the inserting Action can tell a generated collision (retry) from a typed one
 * (refuse) or an unrelated unique index (rethrow).
 */
final class IdentifierCode
{
    /** How many random characters follow the year. */
    public const SUFFIX_LENGTH = 6;

    public const STUDENT_PREFIX = 'STU';

    public const BATCH_PREFIX = 'BAT';

    /** @var Closure(int): int a picker returning an index from zero up to its argument */
    private readonly Closure $pickIndex;

    /**
     * @param  (Closure(int): int)|null  $pickIndex  injected by tests so the output is
     *                                               reproducible; production uses random_int
     */
    public function __construct(?Closure $pickIndex = null)
    {
        $this->pickIndex = $pickIndex ?? static fn (int $max): int => random_int(0, $max);
    }

    /** A new student code for a record created at $createdAt. */
    public function student(DateTimeInterface $createdAt): string
    {
        return $this->mint(self::STUDENT_PREFIX, $createdAt);
    }

    /** A new batch code for a record created at $createdAt. */
    public function batch(DateTimeInterface $createdAt): string
    {
        return $this->mint(self::BATCH_PREFIX, $createdAt);
    }

    private function mint(string $prefix, DateTimeInterface $createdAt): string
    {
        return sprintf('%s-%d-%s', $prefix, CentreCalendar::yearOf($createdAt), $this->suffix());
    }

    private function suffix(): string
    {
        $alphabet = CertificateReference::ALPHABET;
        $max = strlen($alphabet) - 1;
        $suffix = '';

        for ($i = 0; $i < self::SUFFIX_LENGTH; $i++) {
            $suffix .= $alphabet[($this->pickIndex)($max) % ($max + 1)];
        }

        return $suffix;
    }
}
