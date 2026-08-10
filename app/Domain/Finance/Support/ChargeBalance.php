<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * What a bill still owes: the one definition, for SQL and for PHP alike.
 *
 *     outstanding = charges.amount − Σ allocations from payments not reversed
 *
 * Design §4 forbids a status column and design §11 forbids a `paid_amount`-style
 * cached one, so this is derived from source rows every time it is asked for.
 * Two surfaces need it in two different shapes — a Filament table sorting
 * thousands of rows wants it as SQL, an Action checking an overpayment under
 * lock wants it as a Money — and both shapes are produced from the constants
 * below.
 *
 * ONE DEFINITION, FOR THE REASON `Tooling\Gate::fastChecks()` IS ONE DEFINITION
 * ----------------------------------------------------------------------------
 * outstandingFor() is not a second implementation. It executes the identical
 * SQL fragment that outstandingExpression() hands to the query builder, and
 * differs from it only by hydrating the result into a Money. Nothing here sums
 * allocations in PHP.
 *
 * That matters because of the property this phase tests hardest: **a reversed
 * payment drops out**. If the reversal filter lived in two places, the day
 * someone changes what reversal means is the day the aged report and the
 * overpayment guard start disagreeing — and they would disagree quietly, each
 * internally consistent, on rows nobody has a reason to look at twice. There is
 * one `reversed_at IS NULL`, in one string, and both paths run it.
 *
 * TABLE AND COLUMN NAMES, NOT MODELS
 * ----------------------------------
 * Deliberately. This is a correlated subquery that has to compose into somebody
 * else's query builder, and an Eloquent relationship cannot be handed to
 * `orderBy()`. Writing it against the schema also means this file has no
 * dependency on the Finance models, so the balance definition and the models
 * can be built independently.
 *
 * IT DOES NOT TAKE THE LOCK, AND DOES NOT PRETEND TO
 * --------------------------------------------------
 * Design §5 requires that outstanding be derived **under the charge row's
 * lock**, so that a bill settled between form load and submit produces a clean
 * refusal rather than an overpayment. The lock is the caller's: it belongs to
 * the transaction `RecordPaymentAction` opened, and taking one here would be
 * worthless outside that transaction. Read this inside the transaction that
 * holds the charge row, exactly as EnrollmentMutex requires of its callers.
 *
 * WRITE-OFFS ARE NOT SUBTRACTED HERE
 * ----------------------------------
 * Writing a debt off does not pay it. Design §4 keeps the debt in history and
 * excludes the charge from the **aged outstanding report**, which is a filter on
 * `charges.written_off_at` applied by that report — not a change to what the
 * student owes. Folding it in here would make the balance printed on a receipt
 * disagree with the balance in the payment history, for the same charge, on the
 * same day.
 */
final class ChargeBalance
{
    /**
     * The alias a query selects the outstanding balance into.
     *
     * Named here rather than spelled out at both ends, following
     * Batch::ASSIGNED_HOURS_SUM: a typo in a Filament column's name against the
     * alias its query selected produces an empty column, not an error.
     */
    public const OUTSTANDING_ALIAS = 'outstanding_amount';

    /** The alias for what has been paid. Same reasoning as OUTSTANDING_ALIAS. */
    public const ALLOCATED_ALIAS = 'allocated_amount';

    /** The bills. */
    private const CHARGES_TABLE = 'charges';

    /**
     * Everything allocated to a charge from payments that still stand.
     *
     * COALESCE, because SUM over no rows is NULL and a charge nobody has paid
     * yet owes all of it — `amount - NULL` is NULL, which would sort a brand new
     * unpaid bill to whichever end of the table MySQL felt like and read as
     * "unknown" on a receipt.
     *
     * The join to `payments` exists only for `reversed_at`. A reversal is
     * set-once on an immutable row (design §5): the allocations of a reversed
     * payment are never deleted and never rewritten, so this filter is the only
     * thing that takes them out of the balance. Remove it and every reversed
     * payment silently starts counting as collected money again.
     *
     * Correlated on `charges.id`, so it composes into any query over `charges`
     * and needs no GROUP BY from the caller.
     */
    private const ALLOCATED_SQL = <<<'SQL'
        COALESCE((
            SELECT SUM(payment_allocations.amount)
            FROM payment_allocations
            INNER JOIN payments ON payments.id = payment_allocations.payment_id
            WHERE payment_allocations.charge_id = charges.id
              AND payments.reversed_at IS NULL
        ), 0)
        SQL;

    /**
     * The bill less what has been paid on it, parenthesised so it composes as a
     * scalar wherever the columns it names are in scope — a select, an ORDER BY,
     * or one side of a comparison.
     *
     * IT DOES NOT COMPOSE INTO A BARE HAVING, and an earlier version of this
     * docblock claimed it did. MySQL resolves HAVING against the select list
     * rather than the table, so `having(OUTSTANDING_SQL, '>', 0)` on a grouped
     * query raises "Unknown column 'charges.amount' in 'having clause'". Filter
     * either by selecting the alias first and having on OUTSTANDING_ALIAS, or by
     * keeping the columns in scope with select('charges.*'). Both shapes are
     * covered in ChargeBalanceTest; the sentence was corrected because a filter
     * written from the old one fails in production rather than in review.
     *
     * Built by concatenating ALLOCATED_SQL rather than repeating it, so there is
     * exactly one place the reversal rule is written.
     */
    private const OUTSTANDING_SQL = '('.self::CHARGES_TABLE.'.amount - '.self::ALLOCATED_SQL.')';

    /**
     * The outstanding balance as raw SQL, for `selectRaw`, `orderByRaw` and
     * `havingRaw`.
     *
     * Both this and outstandingExpression() return the same fragment; which one
     * a caller wants depends only on whether the builder method it is feeding
     * takes a string or an Expression.
     *
     * The fragment names `charges` unqualified, so a query that aliases the
     * table away from `charges` will not correlate. Nothing in this phase
     * aliases it, and the alternative — interpolating a caller-supplied table
     * name into raw SQL — is an injection surface bought for a case that does
     * not exist.
     */
    public static function outstandingSql(): string
    {
        return self::OUTSTANDING_SQL;
    }

    /**
     * The outstanding balance for `select()`, `orderBy()` and `having()`.
     *
     * `Expression` is generic over `literal-string|int|float`, and the fragment
     * genuinely is a literal — assembled from class constants, never from
     * anything a caller supplied. That is what makes handing it to the query
     * builder raw a safe thing to do, and the type says so.
     *
     * @return Expression<self::OUTSTANDING_SQL>
     */
    public static function outstandingExpression(): Expression
    {
        return new Expression(self::OUTSTANDING_SQL);
    }

    /** What has been paid, as raw SQL. See outstandingSql(). */
    public static function allocatedSql(): string
    {
        return self::ALLOCATED_SQL;
    }

    /**
     * What has been paid, as an Expression. See outstandingExpression().
     *
     * @return Expression<self::ALLOCATED_SQL>
     */
    public static function allocatedExpression(): Expression
    {
        return new Expression(self::ALLOCATED_SQL);
    }

    /**
     * The outstanding balance of one charge.
     *
     * Runs OUTSTANDING_SQL — the same string the query surfaces use — so the
     * two cannot answer differently. Design §6 puts aggregation in SQL, where
     * MySQL's DECIMAL sums are exact, and hydrates the result into a Money;
     * summing decimal strings in PHP is the float bug this whole domain is built
     * to avoid.
     *
     * Call this inside the transaction holding the charge row's lock when the
     * answer is about to be acted on. See the class docblock.
     *
     * @throws RuntimeException if there is no such charge.
     */
    public static function outstandingFor(int $chargeId): Money
    {
        return self::valueOf($chargeId, self::OUTSTANDING_SQL, self::OUTSTANDING_ALIAS);
    }

    /**
     * Everything allocated to one charge from payments that still stand.
     *
     * The receipt's "amount paid" line and the per-student payment history read
     * this. Same construction, and same reversal rule, as outstandingFor().
     *
     * @throws RuntimeException if there is no such charge.
     */
    public static function allocatedFor(int $chargeId): Money
    {
        return self::valueOf($chargeId, self::ALLOCATED_SQL, self::ALLOCATED_ALIAS);
    }

    /**
     * Select one derived amount for one charge and hydrate it.
     *
     * A missing row is a RuntimeException rather than a zero. A charge id that
     * matches nothing means the caller is holding something that no longer
     * exists — or never did — and answering "0.000 outstanding" to that would
     * report a settled bill, which is the most dangerous wrong answer available
     * here.
     *
     * That is also why this is `->first()` and an explicit check rather than the
     * tidier `->rawValue()`: rawValue() returns null both for "no such charge"
     * and for "the expression was null", and those two have to be told apart.
     *
     * @param  string  $sql  one of the two constants above, never caller input
     * @param  string  $alias  one of the two alias constants
     */
    private static function valueOf(int $chargeId, string $sql, string $alias): Money
    {
        $row = DB::table(self::CHARGES_TABLE)
            ->where(self::CHARGES_TABLE.'.id', $chargeId)
            ->selectRaw($sql.' as '.$alias)
            ->first();

        if ($row === null) {
            throw new RuntimeException("There is no charge [{$chargeId}] to take a balance from.");
        }

        $value = $row->{$alias};

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(
                "Charge [{$chargeId}] returned a [{$alias}] the money parser cannot read exactly."
            );
        }

        return Money::fromDecimal((string) $value);
    }
}
