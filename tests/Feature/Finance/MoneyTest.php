<?php

declare(strict_types=1);

use App\Domain\Finance\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Money — integer dirham, and the one rounding rule (design §3, §6)
|--------------------------------------------------------------------------
|
| This file opens no connection: Money is pure arithmetic over an int and never
| touches Eloquent. RefreshDatabase is declared anyway because
| DatabaseIsolationTest is deliberately fail-closed — every feature test either
| names an isolation trait or is listed as reviewed read-only, and a new file is
| an offender until somebody decides which. Declaring the trait is the cheaper
| half of that decision and keeps the choice inside this file.
*/
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Round-tripping decimal(12,3)
|--------------------------------------------------------------------------
*/

it('round-trips a decimal(12,3) string without losing a dirham', function (string $decimal) {
    /*
     * The whole reason the class exists. `decimal:3` hands PHP a STRING, and
     * `(float) "0.001"` is already wrong before any arithmetic happens — so
     * parsing reads the digits rather than casting them, and the assertion is on
     * the exact string, not on a tolerance.
     */
    expect(Money::fromDecimal($decimal)->toDecimal())->toBe($decimal);
})->with([
    '0.000',
    // The dirham itself, from both directions. This is the digit a float loses.
    '0.001',
    '-0.001',
    '0.999',
    '1.000',
    '1234.500',
    // The largest amount `decimal(12,3)` can store.
    '999999999.999',
    '-1234.500',
    /*
     * PHP_INT_MAX dirham, which is what assertFitsInAnInteger() accepts up to.
     * The bound is the integer's, not the column's: a SQL SUM hydrated through
     * fromDecimal() legitimately exceeds what any single row can hold.
     */
    '9223372036854775.807',
    '-9223372036854775.807',
]);

it('renders PHP_INT_MIN dirham without going through abs()', function () {
    /*
     * The docblock on toDecimal() claims it slices the integer's own digits
     * rather than dividing, specifically so there is no abs() — abs(PHP_INT_MIN)
     * returns a FLOAT, which would reintroduce the exact problem this class
     * removes. PHP_INT_MIN cannot be produced by fromDecimal() (it is one past
     * the accepted bound), so this is the only way to reach that path.
     */
    expect(Money::fromDirham(PHP_INT_MIN)->toDecimal())->toBe('-9223372036854775.808');
});

it('accepts the surface forms a form and a database actually produce', function (string $input, int $dirham) {
    expect(Money::fromDecimal($input)->dirham)->toBe($dirham);
})->with([
    // Whitespace carries no value, so it is trimmed.
    'padded' => ['  12.500  ', 12500],
    // Fewer than three places is a database and form reality, not an error.
    'one place' => ['12.5', 12500],
    'two places' => ['12.50', 12500],
    'no fraction' => ['12', 12000],
    // An explicit plus is accepted; it just is not what toDecimal() emits back.
    'explicit sign' => ['+12.500', 12500],
    // Negative zero is zero. There is no second representation of nothing.
    'negative zero' => ['-0.000', 0],
    'leading zeros' => ['00012.500', 12500],
]);

/*
|--------------------------------------------------------------------------
| Precision is validated, never rounded away (design §3)
|--------------------------------------------------------------------------
*/

it('refuses a fourth decimal place rather than silently rounding it', function (string $input) {
    /*
     * Design §3 requires precision to be VALIDATED, not absorbed. Rounding
     * 100.0004 to 100.000 and then reporting "unchanged" turns a real edit into
     * a silent no-op — the user typed a change, the system agreed there wasn't
     * one, and nothing anywhere says why.
     *
     * The user-facing message for this belongs to a validation rule upstream;
     * reaching this exception means that rule is missing, which is the bug.
     */
    Money::fromDecimal($input);
})->throws(InvalidArgumentException::class)->with([
    '100.0004',
    '0.0001',
    '-1.2345',
]);

it('refuses a value that is not an exact LYD amount at all', function (string $input) {
    Money::fromDecimal($input);
})->throws(InvalidArgumentException::class)->with([
    // A whole part is required. A refusal is a better answer to a half-typed
    // amount than a guess at what was meant.
    'no whole part' => ['.5'],
    'empty' => [''],
    'not a number' => ['abc'],
    'thousands separator' => ['1,234.500'],
    // Display is not this class's job, so a currency symbol is not an amount.
    'currency symbol' => ['1234.500 LYD'],
    'scientific' => ['1e3'],
    'two dots' => ['1.2.3'],
    'inner space' => ['1 234.500'],
    /*
     * An embedded newline, not a trailing one — there are digits after it,
     * not just a line ending at the very end of the string. The `^...$`
     * anchors refuse it either way: PCRE's plain `$` only tolerates a newline
     * immediately before the absolute end of the subject, and this one is
     * not there. This does not exercise the `D` modifier — see Money's own
     * docblock on DECIMAL_PATTERN for where `D` does, and measurably does
     * not, matter.
     */
    'a newline embedded in the digits, not a trailing one' => ["1.500\n5"],
]);

it('refuses a magnitude an integer cannot hold exactly', function (string $input) {
    /*
     * Checked as digit STRINGS — length first, then strcmp — so the check never
     * has to hold the number it is rejecting. Casting first and checking after
     * cannot work: the cast is where the value is lost, saturating silently to
     * PHP_INT_MAX.
     */
    Money::fromDecimal($input);
})->throws(InvalidArgumentException::class)->with([
    // One dirham past PHP_INT_MAX, and its mirror.
    '9223372036854775.808',
    '-9223372036854775.808',
    '99999999999999999999.999',
]);

/*
|--------------------------------------------------------------------------
| The rounding rule (design §3), on cases that actually round
|--------------------------------------------------------------------------
*/

/**
 * DO NOT "SIMPLIFY" THIS BACK TO THE DESIGN'S WORKED EXAMPLE.
 *
 * The design illustrates §3 with 333.333 at 10% — 299,999.7 dirham, which
 * becomes 300.000. That case is a fine illustration and a useless test: the
 * fraction is .7, so every rounding convention anyone might substitute here
 * agrees on it, and a float implementation of the rule agrees too. A test built
 * only on it passes against arithmetic that is wrong.
 *
 * Each case below is an exact .5 tie in integer dirham, and each is here because
 * it catches something different:
 *
 *   100.001 @ 50%  → 50,000.5 dirham. Half-up says 50.001. TRUNCATION says
 *                    50.000 and BANKER'S ROUNDING (half-to-even) says 50.000
 *                    too, because 50,000 is even. This is the case that pins the
 *                    convention as half-up rather than merely "rounds".
 *
 *   0.003  @ 50%   → 1.5 dirham. The smallest tie the currency can express, at
 *                    the magnitude where a lost dirham is the entire amount.
 *                    Truncation says 0.001.
 *
 *   216.350 @ 1%   → 214,186.5 dirham. **The float case**, and the one measured
 *                    in Money::divideHalfUp()'s own docblock. The product lands
 *                    a hair BELOW the tie in IEEE-754, so a float implementation
 *                    of §3's rule answers 214.186 while integer arithmetic
 *                    answers 214.187. The two cases above are exact ties that
 *                    happen to land just above the tie in float, so they do NOT
 *                    catch a float implementation — this one does, and it is the
 *                    reason it is in the list rather than a fourth flavour of
 *                    the same assertion.
 *
 * Verified by mutation: replacing afterDiscount() with the float form of the
 * rule leaves the first two green and turns this one red by exactly one dirham.
 */
it('applies the discount rule on a case that actually rounds', function (string $listPrice, string $percentage, string $expected) {
    expect(Money::fromDecimal($listPrice)->afterDiscount($percentage)->toDecimal())
        ->toBe($expected);
})->with([
    'a tie truncation and half-even both get wrong' => ['100.001', '50', '50.001'],
    'the smallest tie the currency can express' => ['0.003', '50', '0.002'],
    'the tie a float implementation gets wrong' => ['216.350', '1.00', '214.187'],
    // The design's worked example, kept so the documented figure is covered —
    // never as the only case. See the note above.
    'the design worked example' => ['333.333', '10', '300.000'],
]);

it('rounds a negative amount away from zero, mirroring the positive case', function () {
    /*
     * divideHalfUp()'s docblock makes a specific choice here — negative
     * dividends round AWAY from zero, so a signed correction of −x is the mirror
     * of +x rather than a dirham adrift from it. Nothing reaches it with a
     * negative dividend today (list prices are `CHECK (list_price >= 0)`), and it
     * is defined anyway rather than left to be discovered; this is the assertion
     * that the definition is the one claimed.
     *
     * Both magnitudes from the tie cases above, so the mirror is exact and not
     * merely "also negative".
     */
    expect(Money::fromDecimal('-100.001')->afterDiscount('50')->toDecimal())->toBe('-50.001')
        ->and(Money::fromDecimal('-0.003')->afterDiscount('50')->toDecimal())->toBe('-0.002')
        ->and(Money::fromDecimal('-216.350')->afterDiscount('1.00')->toDecimal())->toBe('-214.187');
});

it('treats a zero discount as the arithmetic identity and 100% as nothing owed', function () {
    /*
     * 0 is accepted because a caller that normalises "no discount" to zero should
     * get an answer, not an exception. 100% produces a 0.000 charge rather than
     * no charge — the enrolment still has the bill every later report joins
     * through. The narrower `> 0 AND <= 100` rule is the discounts table's CHECK
     * constraint, enforced where a definition is written.
     */
    $price = Money::fromDecimal('1234.567');

    expect($price->afterDiscount('0')->toDecimal())->toBe('1234.567')
        ->and($price->afterDiscount('0.00')->toDecimal())->toBe('1234.567')
        ->and($price->afterDiscount('100')->isZero())->toBeTrue()
        ->and($price->afterDiscount('100.00')->toDecimal())->toBe('0.000');
});

it('refuses a percentage it cannot apply exactly', function (string $percentage) {
    Money::fromDecimal('100.000')->afterDiscount($percentage);
})->throws(InvalidArgumentException::class)->with([
    // Above 100 would produce a negative charge, and §1 rules refunds out.
    'above 100' => ['100.01'],
    'far above 100' => ['150'],
    // Unsigned in the pattern itself, so this is refused before any range check.
    'negative' => ['-10'],
    // `discounts.percentage` is decimal(5,2); a third place cannot be stored.
    'three decimal places' => ['10.001'],
    'not a number' => ['ten'],
    'empty' => [''],
    'percent sign' => ['10%'],
]);

it('keeps a percentage in integer hundredths rather than in a float', function () {
    /*
     * 33.33 is the case a two-place column exists for, and the one that would
     * arrive as 33.329999999999998 through a float. 12,000.000 at 33.33% is
     * exactly 8,000,400 dirham with no tie to resolve, so a wrong answer here is
     * a parsing defect and not a rounding one.
     */
    expect(Money::fromDecimal('12000.000')->afterDiscount('33.33')->toDecimal())
        ->toBe('8000.400');
});

/*
|--------------------------------------------------------------------------
| The shared ratio, which payroll's pro-rating will also use (design §7)
|--------------------------------------------------------------------------
*/

it('rounds a ratio half-up, on an odd divisor as well as an even one', function () {
    /*
     * multipliedBy() is the single rounding implementation in the domain —
     * afterDiscount() is one caller and §7's `round(rate × days ÷ days_in_month)`
     * is the other. Two rounding sites would be two conventions waiting to
     * disagree by a dirham on a payslip.
     *
     * An odd divisor has no tie to get wrong, which is exactly why it is here:
     * `intdiv($a + intdiv($b, 2), $b)` has to be right for both, and a
     * half-up implementation written only against even divisors is not obviously
     * either.
     */
    $salary = Money::fromDecimal('1000.000');

    expect($salary->multipliedBy(1, 2)->toDecimal())->toBe('500.000')
        // 1,000,000 ÷ 3 is 333,333.33 — rounds down.
        ->and($salary->multipliedBy(1, 3)->toDecimal())->toBe('333.333')
        // 2,000,000 ÷ 3 is 666,666.67 — rounds up.
        ->and($salary->multipliedBy(2, 3)->toDecimal())->toBe('666.667')
        // 15 days of a 30-day month, exact.
        ->and($salary->multipliedBy(15, 30)->toDecimal())->toBe('500.000')
        // 1 day of a 31-day month: 32,258.06 dirham, rounds down.
        ->and($salary->multipliedBy(1, 31)->toDecimal())->toBe('32.258');
});

it('rounds a half exactly up, never down and never to even', function () {
    // 1 ÷ 2 dirham on every parity, so half-to-even would fail on one of them.
    expect(Money::fromDirham(1)->multipliedBy(1, 2)->dirham)->toBe(1)
        ->and(Money::fromDirham(3)->multipliedBy(1, 2)->dirham)->toBe(2)
        ->and(Money::fromDirham(5)->multipliedBy(1, 2)->dirham)->toBe(3);
});

it('refuses a ratio that is not one', function () {
    expect(fn () => Money::fromDecimal('1.000')->multipliedBy(1, 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromDecimal('1.000')->multipliedBy(1, -2))
        ->toThrow(InvalidArgumentException::class)
        /*
         * A negative numerator is refused rather than handled: multiplyDirham()'s
         * overflow bounds are written for a non-negative multiplier, and the
         * docblock says so. This is the guard that keeps that assumption true.
         */
        ->and(fn () => Money::fromDecimal('1.000')->multipliedBy(-1, 2))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Overflow refuses rather than silently becoming a float
|--------------------------------------------------------------------------
*/

it('refuses an overflow instead of returning a float', function () {
    /*
     * PHP does not wrap an integer overflow; it converts the result to float.
     * That is the same silent precision loss this class exists to prevent,
     * arriving through a different door. Each of the three arithmetic paths has
     * its own guard and each is exercised, in both directions where it has two.
     */
    $max = Money::fromDirham(PHP_INT_MAX);
    $min = Money::fromDirham(PHP_INT_MIN);
    $one = Money::fromDirham(1);

    expect(fn () => $max->add($one))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $min->add(Money::fromDirham(-1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $min->subtract($one))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $max->subtract(Money::fromDirham(-1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $max->multipliedBy(2, 1))->toThrow(InvalidArgumentException::class);
});

it('adds and subtracts in whole dirham', function () {
    // The sum of three amounts a float cannot hold. 0.1 + 0.2 is the canonical
    // float failure; here it is exact, and so is the dirham beside it.
    $sum = Money::fromDecimal('0.100')
        ->add(Money::fromDecimal('0.200'))
        ->add(Money::fromDecimal('0.001'));

    expect($sum->toDecimal())->toBe('0.301')
        ->and($sum->subtract(Money::fromDecimal('0.301'))->isZero())->toBeTrue()
        // Subtraction may legitimately go negative: §9 gives payroll adjustment
        // lines a signed amount.
        ->and(Money::zero()->subtract(Money::fromDecimal('0.001'))->toDecimal())->toBe('-0.001');
});

/*
|--------------------------------------------------------------------------
| Comparison is integer comparison, not string or float comparison
|--------------------------------------------------------------------------
*/

it('tells two amounts a single dirham apart from each other', function () {
    /*
     * `==` on the cast strings, or on floats derived from them, does not reliably
     * do this — which matters because §5's overpayment check is a comparison.
     */
    $a = Money::fromDecimal('1000.000');
    $b = Money::fromDecimal('1000.001');

    expect($a->equals($b))->toBeFalse()
        ->and($a->compareTo($b))->toBe(-1)
        ->and($b->compareTo($a))->toBe(1)
        ->and($a->compareTo(Money::fromDecimal('1000.000')))->toBe(0)
        ->and($b->isGreaterThan($a))->toBeTrue()
        ->and($a->isLessThan($b))->toBeTrue()
        ->and($a->equals(Money::fromDirham(1000000)))->toBeTrue();
});

it('reports sign and emptiness the way the CHECK constraints do', function () {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::zero()->isPositive())->toBeFalse()
        ->and(Money::zero()->isNegative())->toBeFalse()
        ->and(Money::fromDecimal('0.001')->isPositive())->toBeTrue()
        ->and(Money::fromDecimal('-0.001')->isNegative())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The class's structural claims, asserted rather than trusted
|--------------------------------------------------------------------------
*/

it('has no float anywhere in its signatures, not even as an intermediate', function () {
    /*
     * The class docblock's central claim: "every value here is an int, every
     * operation is integer arithmetic, and there is no float anywhere — not in a
     * parameter, not in a return, and not as an intermediate."
     *
     * A worked example cannot establish that; a signature sweep can establish
     * the half of it that is visible in types, and the arithmetic assertions
     * above cover the intermediates. Private methods included: divideHalfUp() is
     * where a `float` would actually appear.
     */
    $reflection = new ReflectionClass(Money::class);
    $offenders = [];

    $names = static function (?ReflectionType $type): array {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return array_map(
                static fn (ReflectionType $inner): string => $inner instanceof ReflectionNamedType
                    ? $inner->getName()
                    : (string) $inner,
                $type->getTypes(),
            );
        }

        return [];
    };

    foreach ($reflection->getMethods() as $method) {
        if (in_array('float', $names($method->getReturnType()), true)) {
            $offenders[] = $method->getName().'() returns float';
        }

        foreach ($method->getParameters() as $parameter) {
            if (in_array('float', $names($parameter->getType()), true)) {
                $offenders[] = $method->getName().'($'.$parameter->getName().') takes float';
            }
        }
    }

    foreach ($reflection->getProperties() as $property) {
        if (in_array('float', $names($property->getType()), true)) {
            $offenders[] = '$'.$property->getName().' is float';
        }
    }

    expect($offenders)->toBeEmpty(
        'Money grew a float, which is the one thing it exists to remove: '
        .implode(', ', $offenders),
    );
});

it('refuses a float argument rather than coercing it', function () {
    /*
     * fromDecimal() takes `string` on purpose. Under strict_types a caller
     * passing a float gets a TypeError, and that refusal IS the feature — the
     * float is already wrong by the time it reaches the call, so accepting it
     * with a union type would smooth over the loss rather than prevent it.
     */
    // @phpstan-ignore-next-line — passing the wrong type deliberately is the test.
    Money::fromDecimal(1.5);
})->throws(TypeError::class);

it('cannot be turned into a string or a number implicitly', function () {
    /*
     * There is deliberately no __toString(). An implicit string conversion is
     * exactly how a Money ends up on the wrong side of a `+` and back in float
     * arithmetic. Callers call toDecimal() and mean it.
     */
    expect(method_exists(Money::class, '__toString'))->toBeFalse()
        ->and(method_exists(Money::class, '__invoke'))->toBeFalse();
});

it('has no public constructor, so no Money holds something the currency cannot express', function () {
    $constructor = (new ReflectionClass(Money::class))->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and($constructor->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(Money::class))->isReadOnly())->toBeTrue();
});

it('emits bare digits, because formatting is localisable and belongs elsewhere', function () {
    /*
     * Design §12 puts the separator, the symbol and their ordering behind
     * translation keys — all three move in Arabic. A currency symbol here would
     * be a hardcoded user-facing string in a support class.
     */
    $rendered = Money::fromDecimal('1234567.890')->toDecimal();

    expect($rendered)->toBe('1234567.890')
        ->and($rendered)->not->toContain(',')
        ->and($rendered)->not->toContain('LYD');
});
