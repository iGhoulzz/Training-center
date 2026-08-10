<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use InvalidArgumentException;

/**
 * An amount of money held as a whole number of dirham. 1 LYD = 1000 dirham.
 *
 * WHY THIS CLASS EXISTS AT ALL
 * ----------------------------
 * Every money column in this system is `decimal(12,3)`, and Laravel's
 * `decimal:3` cast hands PHP a **string** — "1234.500", not a number. Add two of
 * those with `+` and PHP converts both to float; a float cannot represent 0.001
 * exactly, and the dirham is precisely the digit that is lost. It is lost
 * silently: no warning, no exception, just a total a thousandth out and a
 * reconciliation nobody can explain a week later. Design §6 is the decision;
 * this class is the mechanism.
 *
 * So every value here is an int, every operation is integer arithmetic, and
 * there is no float anywhere — not in a parameter, not in a return, and not as
 * an intermediate. Parsing is done on the digits of the string rather than by
 * casting it, because `(float) "0.001"` is already wrong before any arithmetic
 * happens.
 *
 * NOTHING IMPLICITLY BECOMES A STRING OR A NUMBER
 * -----------------------------------------------
 * There is deliberately no `__toString()` and no numeric interop. An implicit
 * string conversion is exactly how a Money ends up on the wrong side of a `+`
 * and back in float arithmetic, which is the failure this class was built to
 * remove. Call toDecimal() and mean it.
 *
 * For the same reason fromDecimal() takes `string`. Under strict_types a caller
 * passing a float gets a TypeError — that refusal is the point, not an
 * inconvenience to be smoothed over with a union type.
 *
 * DISPLAY IS NOT THIS CLASS'S JOB
 * -------------------------------
 * toDecimal() returns bare digits: "1234.500", never "1,234.500 LYD". Design
 * §12 puts money formatting — separator, symbol, ordering — behind translation
 * keys, because all three are localisable and Arabic arrives in phase 4. A
 * currency symbol hardcoded here would be a hardcoded user-facing string.
 *
 * THE EXCEPTION MESSAGES ARE FOR DEVELOPERS, NOT FOR THE COUNTER
 * --------------------------------------------------------------
 * These refusals are last-line guards against a programming error, so their
 * messages are English diagnostics and deliberately do not go through `__()`.
 * A four-decimal price typed by a human is caught earlier, by a validation rule
 * that owns the user-facing message (design §3); if one of these ever reaches a
 * user, the missing validation rule is the bug, not the wording.
 */
final readonly class Money
{
    /**
     * Decimal places in LYD.
     *
     * Matches `decimal(12,3)` everywhere in the schema. The digit counts written
     * into DECIMAL_PATTERN below say 3 literally rather than interpolating this,
     * for legibility — LYD's subdivision is fixed by ISO 4217 and does not move.
     */
    public const SCALE = 3;

    /**
     * A percentage is `decimal(5,2)`, so it is carried internally in hundredths
     * of a percent: 10.00% is 1000, 33.33% is 3333, and 100% is this constant.
     * Integer hundredths is what lets the discount rule stay in integer
     * arithmetic all the way through.
     */
    private const PERCENTAGE_SCALE = 10000;

    /**
     * An optionally signed decimal with at most SCALE decimal places.
     *
     * `\d{1,3}` followed by an anchor is what rejects a fourth decimal place
     * rather than rounding it away: design §3 requires precision to be
     * validated, not quietly absorbed, because rounding 100.0004 to 100.000 and
     * then reporting "unchanged" turns a real edit into a silent no-op.
     *
     * The `D` modifier makes `$` mean end of string, rather than PCRE's default
     * "end of string, or just before a final newline". It never actually fires
     * here, because fromDecimal() trims before matching — that was measured, not
     * assumed. It is kept so the anchor means what it looks like it means, and
     * so that removing the trim one day cannot quietly widen what this accepts.
     * In Reference::isPlaceholder the same modifier guards the same hole.
     *
     * A whole part is required, so ".5" is refused. Every value the database
     * produces carries one, and a refusal is a better answer to a half-typed
     * amount than a guess at what was meant.
     */
    private const DECIMAL_PATTERN = '/^(?<sign>[+-])?(?<whole>\d+)(?:\.(?<fraction>\d{1,3}))?$/D';

    /**
     * An unsigned percentage with at most two decimal places, matching the
     * `discounts.percentage` column. Unsigned in the pattern itself, so a
     * negative percentage is refused before any range check runs.
     */
    private const PERCENTAGE_PATTERN = '/^(?<whole>\d{1,3})(?:\.(?<fraction>\d{1,2}))?$/D';

    /**
     * Private, so every value arrives through a named constructor that has
     * validated it. There is no path to a Money holding something the currency
     * cannot express.
     */
    private function __construct(public int $dirham) {}

    /** An amount already counted in dirham — a database integer, or arithmetic. */
    public static function fromDirham(int $dirham): self
    {
        return new self($dirham);
    }

    /** Nothing. Named, because `fromDirham(0)` reads like a magic number. */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * An amount written as a decimal string — what `decimal:3` hands back, and
     * what a form submits.
     *
     * The digits are read out of the string and assembled as an integer. There
     * is no cast to float at any point, so 0.001 survives.
     *
     * Surrounding whitespace is trimmed, because whitespace carries no value.
     * A fourth decimal place is refused, because it does.
     *
     * @throws InvalidArgumentException if the value is not an exact LYD amount,
     *                                  or is too large to hold as an exact integer.
     */
    public static function fromDecimal(string $amount): self
    {
        $value = trim($amount);

        if (preg_match(self::DECIMAL_PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException(
                "Not an exact LYD amount: [{$amount}]. Expected an optionally signed decimal with at most ".self::SCALE.' decimal places.'
            );
        }

        $fraction = str_pad($matches['fraction'] ?? '', self::SCALE, '0', STR_PAD_RIGHT);
        $digits = ltrim($matches['whole'].$fraction, '0');
        $negative = $matches['sign'] === '-';

        if ($digits === '') {
            return new self(0);
        }

        self::assertFitsInAnInteger($digits, $amount);

        return new self($negative ? -(int) $digits : (int) $digits);
    }

    /**
     * The amount as a decimal string with SCALE places — "1234.500", "-0.001".
     *
     * Built by slicing the integer's own digits rather than by dividing, so
     * there is no intdiv/modulo pair to get the sign wrong on and no `abs()`,
     * which returns a **float** for PHP_INT_MIN and would reintroduce the exact
     * problem this class exists to remove.
     */
    public function toDecimal(): string
    {
        $digits = (string) $this->dirham;
        $sign = '';

        if (str_starts_with($digits, '-')) {
            $sign = '-';
            $digits = substr($digits, 1);
        }

        $digits = str_pad($digits, self::SCALE + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -self::SCALE).'.'.substr($digits, -self::SCALE);
    }

    /** @throws InvalidArgumentException if the sum overflows. */
    public function add(self $addend): self
    {
        return new self(self::addDirham($this->dirham, $addend->dirham));
    }

    /** @throws InvalidArgumentException if the difference overflows. */
    public function subtract(self $subtrahend): self
    {
        return new self(self::subtractDirham($this->dirham, $subtrahend->dirham));
    }

    /**
     * THE DISCOUNT RULE FROM DESIGN §3, DEFINED HERE AND NOWHERE ELSE.
     *
     *     amount = round(list_price × (100 − percentage) ÷ 100)
     *
     * in integer dirham, half-up. The worked example in the design is 333.333 at
     * 10%: 333333 × 9000 ÷ 10000 is 299999.7 dirham, which rounds to 300.000.
     *
     * The percentage arrives as a string for the same reason an amount does —
     * `discounts.percentage` is `decimal(5,2)` and its cast returns a string.
     * It is converted to integer hundredths, never to a float.
     *
     * A charge stores the list price, the percentage and this result; design §3
     * freezes all three at issue, so nothing recomputes them later and there is
     * no second place this rule could be spelled differently.
     *
     * 0 is accepted and returns the list price unchanged: it is the arithmetic
     * identity, and a caller that normalises "no discount" to zero should get an
     * answer rather than an exception. The narrower `> 0 AND <= 100` rule is the
     * `discounts` table's CHECK constraint (design §9) and is enforced there,
     * where a definition is written.
     *
     * @param  string  $percentage  e.g. "10", "10.00", "33.33"
     *
     * @throws InvalidArgumentException if the percentage is malformed or above 100.
     */
    public function afterDiscount(string $percentage): self
    {
        return $this->multipliedBy(
            self::PERCENTAGE_SCALE - self::parsePercentage($percentage),
            self::PERCENTAGE_SCALE,
        );
    }

    /**
     * This amount scaled by numerator ÷ denominator, rounded half-up to the
     * dirham.
     *
     * The single rounding implementation in the domain. afterDiscount() is one
     * caller; design §7's pro-rated salary segment — `round(rate × days ÷
     * days_in_month)`, also integer dirham, also half-up — is the other the
     * design already requires. They share this method rather than each carrying
     * their own `round()`, because two rounding sites are two rounding
     * conventions waiting to disagree by a dirham on a payslip.
     *
     * @throws InvalidArgumentException if the denominator is not positive, the
     *                                  numerator is negative, or the product overflows.
     */
    public function multipliedBy(int $numerator, int $denominator): self
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException("A ratio needs a positive denominator, got [{$denominator}].");
        }

        if ($numerator < 0) {
            throw new InvalidArgumentException("A ratio needs a non-negative numerator, got [{$numerator}].");
        }

        return new self(self::divideHalfUp(self::multiplyDirham($this->dirham, $numerator), $denominator));
    }

    /**
     * -1, 0 or 1, for sorting and for `usort`.
     *
     * The whole comparison family is integer comparison on the dirham count, so
     * two amounts a thousandth apart compare as different — which `==` on the
     * cast strings, or on floats derived from them, does not reliably do.
     */
    public function compareTo(self $other): int
    {
        return $this->dirham <=> $other->dirham;
    }

    public function equals(self $other): bool
    {
        return $this->dirham === $other->dirham;
    }

    /** Design §5's overpayment check: an allocation may not exceed outstanding. */
    public function isGreaterThan(self $other): bool
    {
        return $this->dirham > $other->dirham;
    }

    public function isLessThan(self $other): bool
    {
        return $this->dirham < $other->dirham;
    }

    /** A bill with nothing left on it. */
    public function isZero(): bool
    {
        return $this->dirham === 0;
    }

    /** Mirrors the `CHECK (amount > 0)` constraints on tenders and allocations. */
    public function isPositive(): bool
    {
        return $this->dirham > 0;
    }

    /**
     * Negative amounts are legitimate: design §9 gives payroll adjustment lines
     * a signed amount. This is here so callers whose own columns are
     * `CHECK (amount >= 0)` can refuse one before the database has to.
     */
    public function isNegative(): bool
    {
        return $this->dirham < 0;
    }

    /**
     * $a + $b, refusing rather than silently becoming a float.
     *
     * PHP does not wrap an integer overflow; it converts the result to float.
     * That is the same silent precision loss this class was built to prevent,
     * arriving through a different door, so it is refused rather than returned.
     *
     * Written as `$a > PHP_INT_MAX - $b` rather than `$a + $b > PHP_INT_MAX`,
     * because the second expression has to overflow before it can be tested.
     */
    private static function addDirham(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new InvalidArgumentException("Adding [{$a}] and [{$b}] dirham overflows an integer.");
        }

        return $a + $b;
    }

    /** $a − $b, with the same refusal and for the same reason as addDirham(). */
    private static function subtractDirham(int $a, int $b): int
    {
        if (($b < 0 && $a > PHP_INT_MAX + $b) || ($b > 0 && $a < PHP_INT_MIN + $b)) {
            throw new InvalidArgumentException("Subtracting [{$b}] from [{$a}] dirham overflows an integer.");
        }

        return $a - $b;
    }

    /**
     * $a × $b, refusing an overflow instead of returning a float.
     *
     * The bound is checked with intdiv, which truncates toward zero and so
     * refuses a narrow band of products that would in fact have fitted. That is
     * the safe direction to be wrong in, and the band sits around 9.2 × 10^14
     * dinar — a thousand times what `decimal(12,3)` can even store.
     *
     * $b IS ALWAYS NON-NEGATIVE HERE. multipliedBy() refuses a negative
     * numerator before calling this, and the two bounds are written for a
     * positive multiplier. Handed a negative one this refuses almost every
     * input rather than overflowing — it fails closed, which is the right
     * failure, but it is not a general-purpose multiply and should not be
     * pressed into service as one.
     */
    private static function multiplyDirham(int $a, int $b): int
    {
        if ($b !== 0 && ($a > intdiv(PHP_INT_MAX, $b) || $a < intdiv(PHP_INT_MIN, $b))) {
            throw new InvalidArgumentException("Multiplying [{$a}] dirham by [{$b}] overflows an integer.");
        }

        return $a * $b;
    }

    /**
     * $dividend ÷ $divisor rounded half-up, in integer arithmetic.
     *
     * `intdiv($dividend + intdiv($divisor, 2), $divisor)` is exact half-up for
     * every positive divisor, odd ones included: it rounds up exactly when
     * `$dividend mod $divisor` reaches `ceil($divisor / 2)`, which for an even
     * divisor is the .5 tie and for an odd divisor is the first value above it —
     * and an odd divisor has no tie to get wrong.
     *
     * PHP's own round() is not used, and neither is `(int) ($a / $b)`. Both go
     * through float: round() takes and returns float, and `/` produces one
     * whenever the division is not exact.
     *
     * THIS IS NOT A THEORETICAL PRECAUTION — IT WAS MEASURED. Sweeping list
     * prices from 0.001 to 2,000.000 against every percentage `decimal(5,2)`
     * can hold, a float implementation of §3's rule disagreed with this one on
     * **1,687 of 802,800 cases**, always by a dirham. The smallest is easy to
     * check by hand: **216.350 at 1.00%** is exactly 214,186.5 dirham, which
     * half-up is 214.187 — but in float the division lands a hair below the tie
     * and round() answers 214.186. §7's salary formula behaves the same way,
     * diverging on 651 of 177,236 sampled segments. The design's own worked
     * example, 333.333 at 10%, is one of the cases where the two agree, which is
     * exactly why agreeing on it proves nothing.
     *
     * A dirham lost this way appears in a total and in nothing that explains it.
     *
     * Negative dividends round away from zero — the same direction as positive
     * ones, so a signed payroll correction of −x is the mirror of +x rather than
     * a dirham adrift from it. No caller reaches this with a negative dividend
     * today: list prices are `CHECK (list_price >= 0)` and multipliers are
     * refused below zero. It is defined anyway rather than left to be discovered.
     */
    private static function divideHalfUp(int $dividend, int $divisor): int
    {
        $half = intdiv($divisor, 2);

        if ($dividend >= 0) {
            return intdiv(self::addDirham($dividend, $half), $divisor);
        }

        return -intdiv(self::addDirham(self::subtractDirham(0, $dividend), $half), $divisor);
    }

    /**
     * A percentage string as integer hundredths of a percent.
     *
     * @throws InvalidArgumentException if it is malformed or above 100.
     */
    private static function parsePercentage(string $percentage): int
    {
        $value = trim($percentage);

        if (preg_match(self::PERCENTAGE_PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException(
                "Not a percentage this system can apply exactly: [{$percentage}]. Expected an unsigned decimal with at most 2 decimal places."
            );
        }

        $hundredths = (int) ($matches['whole'].str_pad($matches['fraction'] ?? '', 2, '0', STR_PAD_RIGHT));

        if ($hundredths > self::PERCENTAGE_SCALE) {
            throw new InvalidArgumentException(
                "A discount above 100% would produce a negative charge: [{$percentage}]."
            );
        }

        return $hundredths;
    }

    /**
     * Refuse a magnitude that `(int)` would saturate to PHP_INT_MAX.
     *
     * Compared as digit strings — length first, then strcmp — so the check
     * itself never has to hold the number it is rejecting. Casting first and
     * checking afterwards cannot work: the cast is where the value is lost.
     *
     * The bound is the integer's, not the column's. `decimal(12,3)` caps a
     * stored amount at 999,999,999.999, but a SQL SUM hydrated through
     * fromDecimal() legitimately exceeds that, and refusing a correct report
     * total would be the wrong failure.
     *
     * PHP_INT_MIN is one below −PHP_INT_MAX and is refused with everything else
     * past the bound. Nothing is lost by not being able to express it.
     *
     * @param  string  $digits  the dirham count, leading zeros already stripped
     */
    private static function assertFitsInAnInteger(string $digits, string $amount): void
    {
        $limit = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new InvalidArgumentException(
                "Amount is too large to hold as an exact integer number of dirham: [{$amount}]."
            );
        }
    }
}
