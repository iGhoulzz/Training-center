<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The `ENR-` / `CHG-` / `RCT-` document numbers, and the placeholder that lets
 * them exist on a NOT NULL UNIQUE column.
 *
 *     ENR-2026-000042    an enrolment
 *     CHG-2026-000042    the bill raised for it
 *     RCT-2026-000042    a receipt for money taken against that bill
 *
 * WHY A PLACEHOLDER, AND NOT A GENERATED COLUMN
 * ---------------------------------------------
 * The reference contains the row's own id, and MySQL forbids a generated column
 * from referencing an AUTO_INCREMENT column. The stored-generated-column
 * approach used elsewhere in this schema is therefore unavailable here: the
 * final value is unknowable until the row exists, and the row cannot be
 * inserted without one because the column is NOT NULL.
 *
 * So the row is inserted carrying a **unique placeholder**, and updated to its
 * real reference **inside the same transaction** (design §2). Both writes commit
 * together, so no other connection ever observes a placeholder, and the column
 * keeps NOT NULL UNIQUE the whole way through. The placeholder is a UUID rather
 * than a constant precisely because the column is UNIQUE — two rows inserted
 * concurrently must not collide on it.
 *
 * The alternative — a nullable column with the Actions promising to fill it —
 * was considered and rejected in design revision 3. That is a convention, not a
 * guarantee, and this project's position is that validation which matters is
 * mirrored in the database. A placeholder costs one extra UPDATE and keeps the
 * constraint real.
 *
 * THE PLACEHOLDER MUST NEVER REACH THE ACTIVITY LOG
 * -------------------------------------------------
 * Sharing a transaction does not prevent that. RecordsActivity logs on model
 * events: the insert fires `created` and would record the uuid, the replacement
 * fires `updated` and would record a change from the uuid to the real value.
 * Both would commit into a log that has no delete path for any role.
 *
 * `reference` is therefore excluded from `auditedAttributes()` on every table
 * that carries one, and nothing is lost by that — the reference is a
 * deterministic function of the subject id the log already records.
 * `RCT-2026-000042` *is* payment 42.
 *
 * WHY isPlaceholder() IS EXACT AND NOT A UUID-SHAPED GUESS
 * -------------------------------------------------------
 * Two tests in design §14 depend on this predicate: that no row survives a
 * transaction holding a placeholder, and that no entry anywhere in the activity
 * log carries one. A test whose predicate is approximate is a test that can pass
 * for the wrong reason.
 *
 * The placeholder therefore carries a marker prefix no real reference can ever
 * bear, and the pattern matches that marker followed by a full UUID, anchored at
 * both ends. It answers "is this the thing this class mints" exactly, rather
 * than "does this look like a uuid" — which would also be true of any other
 * uuid the system ever stores in a scanned column.
 *
 * PLACEHOLDER_MARKER is public so the log scanner can search for the marker
 * itself, rather than re-deriving a pattern that could drift away from this one.
 *
 * THESE ARE NOT THE PHASE 3 CERTIFICATE PATTERN — DO NOT HARDEN THEM
 * ------------------------------------------------------------------
 * A certificate reference is exposed to an unauthenticated public verifier and
 * must be unguessable. A bill reference is read aloud down a phone by the person
 * holding the bill, so sequential and human-readable is the correct trade-off
 * (design §2). Turning these into random strings would be a regression dressed
 * as a security improvement.
 *
 * **Gaps are accepted.** The owner confirmed on 2026-08-09 that these are
 * internal tracking references, not registered fiscal invoice sequences, so a
 * number burned by a rolled-back transaction is not a problem. There is no
 * counter table and no sequence lock, and there is deliberately nothing here
 * that could become one. If the centre ever falls under a gapless fiscal
 * numbering requirement, that is a schema and concurrency change, not a change
 * to this file.
 */
final class Reference
{
    /** `enrollments.reference`. */
    public const ENROLLMENT_PREFIX = 'ENR';

    /** `charges.reference` — the bill. */
    public const CHARGE_PREFIX = 'CHG';

    /** `payments.reference` — the receipt number. */
    public const PAYMENT_PREFIX = 'RCT';

    /**
     * What every placeholder starts with.
     *
     * Public so a test scanning the activity log, or a support query hunting a
     * stuck row, can search for the marker as a literal substring. Spelled as a
     * word rather than an abbreviation because whoever meets one in a log is
     * meeting it unexpectedly and should not have to look it up.
     */
    public const PLACEHOLDER_MARKER = 'PLACEHOLDER-';

    /**
     * The width every `reference` column must have.
     *
     * The longest value the column ever holds is a placeholder — the marker plus
     * a 36-character UUID, 48 characters — not a real reference, which is 15.
     * Sizing the column from the real format would make every insert fail on a
     * value nobody thought to measure.
     *
     * Named here so the migrations and this class cannot drift apart, following
     * Batch::ASSIGNED_HOURS_SUM. 64 leaves headroom without pretending to be a
     * calculation.
     */
    public const COLUMN_LENGTH = 64;

    /** `{PREFIX}-{year}-{id padded to this many digits}`. */
    private const ID_DIGITS = 6;

    /**
     * The marker followed by a complete UUID, anchored at both ends.
     *
     * Built from PLACEHOLDER_MARKER rather than repeating it, so the marker has
     * one definition. The hyphens in the marker are literal to a regex outside a
     * character class, which is what makes that concatenation safe.
     *
     * Case-insensitive on the hex digits. Str::uuid() emits lowercase, so this
     * only matters for a value something else has upper-cased on the way past —
     * and for a detector, catching that is the right direction to be wrong in.
     */
    private const PLACEHOLDER_PATTERN = '/^'.self::PLACEHOLDER_MARKER.'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /** The three series this class mints, and the only prefixes format() accepts. */
    private const PREFIXES = [
        self::ENROLLMENT_PREFIX,
        self::CHARGE_PREFIX,
        self::PAYMENT_PREFIX,
    ];

    /**
     * A value to insert with, to be replaced in the same transaction.
     *
     * Unique per call, because the column is UNIQUE and two rows can be inserted
     * concurrently. A caller that has frozen UUIDs for a test — `Str::freezeUuids()`
     * — and then creates two rows in one transaction will collide on that index.
     * That is the constraint working, not a bug here.
     */
    public static function placeholder(): string
    {
        return self::PLACEHOLDER_MARKER.Str::uuid()->toString();
    }

    /**
     * The real reference for a row that now has an id.
     *
     * The prefix is checked against the three known series, so a typo mints
     * nothing: an `XXX-2026-000001` in a column whose whole value is that it is
     * recognisable would be worse than a failure.
     *
     * THE YEAR IS THE CALLER'S DECISION, not this class's. Design §8 makes every
     * reporting period a local calendar period in `Africa/Tripoli`, and a payment
     * taken at 00:30 local on 1 January is still the previous year in UTC. The
     * caller knows which date the document is dated by — `enrolled_at` for an
     * enrolment, `received_at` for a receipt — and converts it; passing a
     * DateTimeInterface here would bury that conversion in a support class that
     * has no business owning a timezone policy.
     *
     * An id past 999,999 produces a seventh digit rather than being truncated.
     * The number stays unique and stays correct; only its lexicographic sort
     * order changes, and nothing sorts on the string.
     *
     * @throws InvalidArgumentException if the prefix is not one of the three
     *                                  series, or the year or id is not one a
     *                                  reference can be built from.
     */
    public static function format(string $prefix, int $year, int $id): string
    {
        if (! in_array($prefix, self::PREFIXES, true)) {
            throw new InvalidArgumentException(
                "Unknown reference series [{$prefix}]. Expected one of: ".implode(', ', self::PREFIXES).'.'
            );
        }

        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException("A reference needs a four-digit year, got [{$year}].");
        }

        if ($id < 1) {
            throw new InvalidArgumentException("A reference needs the id of a row that exists, got [{$id}].");
        }

        return $prefix.'-'.$year.'-'.str_pad((string) $id, self::ID_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Is this value one this class minted as a placeholder?
     *
     * Exact on shape, not approximate — see the class docblock. A real reference
     * can never satisfy this, and neither can an unrelated UUID stored somewhere
     * else, so a scan that reports clean has actually established something.
     *
     * Trimmed before matching, and the pattern is anchored with `D` so `$` means
     * end of string rather than PCRE's default "or before a final newline". The
     * two together decide the direction this errs in, and the direction is
     * chosen: a value carrying stray whitespace is still **caught**. A detector
     * that over-reports fails a test loudly and gets investigated; one that
     * misses lets a placeholder ship into an append-only log, silently, which is
     * the failure design §2 asks these tests to make impossible.
     *
     * For scanning somewhere a placeholder could be embedded in a larger value —
     * an activity log's JSON properties, say — match on PLACEHOLDER_MARKER as a
     * substring instead. This answers "is this value a placeholder", not "does
     * this text contain one", and those are different questions.
     */
    public static function isPlaceholder(string $value): bool
    {
        return preg_match(self::PLACEHOLDER_PATTERN, trim($value)) === 1;
    }
}
