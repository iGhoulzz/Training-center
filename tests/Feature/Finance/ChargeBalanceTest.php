<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ChargeBalance — one definition of outstanding, for SQL and for PHP
|--------------------------------------------------------------------------
|
|     outstanding = charges.amount − Σ allocations from payments not reversed
|
| Design §4 forbids a status column and §11 forbids a cached `paid_amount`, so
| this is derived from source rows every time. Two surfaces need it in two
| shapes — a Filament table sorting thousands of rows wants SQL, an Action
| checking an overpayment under lock wants a Money — and the whole point of the
| class is that those two cannot answer differently.
|
| So the agreement is ASSERTED here rather than trusted. Every balance case below
| is checked through all three of: the raw SQL a Filament query would select, the
| PHP entry point an Action would call, and an independent Money computation
| written in this file from the source rows. If the reversal filter ever forks
| into two places, the two production surfaces would each stay internally
| consistent and quietly disagree with each other — which is the failure mode
| this triple check exists to make loud.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    /**
     * The outstanding balance as a Filament query would select it: raw SQL, into
     * the class's own alias.
     */
    $this->outstandingViaSql = fn (Charge $charge): string => (string) DB::table('charges')
        ->where('charges.id', $charge->getKey())
        ->selectRaw(ChargeBalance::outstandingSql().' as '.ChargeBalance::OUTSTANDING_ALIAS)
        ->value(ChargeBalance::OUTSTANDING_ALIAS);

    /** What has been paid, same route. */
    $this->allocatedViaSql = fn (Charge $charge): string => (string) DB::table('charges')
        ->where('charges.id', $charge->getKey())
        ->selectRaw(ChargeBalance::allocatedSql().' as '.ChargeBalance::ALLOCATED_ALIAS)
        ->value(ChargeBalance::ALLOCATED_ALIAS);

    /**
     * An independent definition, written here in PHP over the source rows.
     *
     * Deliberately NOT a second call into ChargeBalance. Both production paths
     * execute the same SQL string, so comparing them to each other proves they
     * are one definition but not that the definition is right. This arbiter
     * subtracts, in Money, exactly what design §4 says outstanding means — and it
     * is the assertion that would survive somebody rewriting the SQL.
     */
    $this->outstandingIndependently = function (Charge $charge): Money {
        $balance = Money::fromDecimal((string) $charge->fresh()->amount);

        // with('payment') because the loop below reads it per row, and P35-T04
        // turned Model::preventLazyLoading() on outside production. The rows and
        // the arithmetic are unchanged; only the number of statements is.
        $allocations = PaymentAllocation::query()
            ->where('charge_id', $charge->getKey())
            ->with('payment')
            ->get();

        foreach ($allocations as $allocation) {
            if ($allocation->payment->reversed_at !== null) {
                continue;
            }

            $balance = $balance->subtract(Money::fromDecimal((string) $allocation->amount));
        }

        return $balance;
    };

    /** Assert all three routes agree, and that the answer is the expected one. */
    $this->expectOutstanding = function (Charge $charge, string $expected): void {
        expect(ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal())->toBe(
            $expected,
            'ChargeBalance::outstandingFor() — the PHP entry point an Action calls under lock.',
        )->and(($this->outstandingViaSql)($charge))->toBe(
            $expected,
            'ChargeBalance::outstandingSql() — the fragment a Filament table selects and sorts on.',
        )->and(($this->outstandingIndependently)($charge)->toDecimal())->toBe(
            $expected,
            'The independent Money computation over the source rows.',
        );
    };

    /** A payment that still stands, allocating $amount to $charge. */
    $this->payTowards = function (Charge $charge, string $amount, bool $reversed = false): Payment {
        $payment = $reversed
            ? Payment::factory()->reversed()->create()
            : Payment::factory()->create();

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => $amount,
        ]);

        return $payment;
    };

    /** A bill for exactly $amount, with no payments against it yet. */
    $this->billFor = fn (string $amount): Charge => Charge::factory()->create([
        'list_price' => $amount,
        'amount' => $amount,
    ]);
});

/*
|--------------------------------------------------------------------------
| Outstanding = amount − allocations
|--------------------------------------------------------------------------
*/

it('reports the full amount for a charge nobody has paid', function () {
    /*
     * THE SUM-RETURNS-NULL CASE, and the one a naive implementation gets wrong.
     *
     * SUM over no rows is NULL, and `amount - NULL` is NULL — which sorts a brand
     * new unpaid bill to whichever end of the table MySQL felt like and reads as
     * "unknown" on a receipt. COALESCE is what makes an unpaid bill owe all of
     * it, and this is the assertion that COALESCE is still there.
     */
    $charge = ($this->billFor)('1000.000');

    ($this->expectOutstanding)($charge, '1000.000');

    expect(ChargeBalance::allocatedFor((int) $charge->getKey())->isZero())->toBeTrue()
        ->and(($this->allocatedViaSql)($charge))->toBe('0.000')
        // Not merely "falsy" — the raw column must be a value, never a NULL that
        // PHP would helpfully render as an empty string.
        ->and(($this->outstandingViaSql)($charge))->not->toBeNull();
});

it('subtracts a partial payment', function () {
    $charge = ($this->billFor)('1000.000');
    ($this->payTowards)($charge, '300.000');

    ($this->expectOutstanding)($charge, '700.000');

    expect(ChargeBalance::allocatedFor((int) $charge->getKey())->toDecimal())->toBe('300.000');
});

it('settles a bill exactly, without going through zero', function () {
    $charge = ($this->billFor)('1000.000');
    ($this->payTowards)($charge, '1000.000');

    ($this->expectOutstanding)($charge, '0.000');

    expect(ChargeBalance::outstandingFor((int) $charge->getKey())->isZero())->toBeTrue()
        ->and(ChargeBalance::allocatedFor((int) $charge->getKey())->toDecimal())->toBe('1000.000');
});

it('sums several payments against one bill', function () {
    /*
     * One bill, many receipts (design §2). Three installments and a remainder,
     * because two payments can be summed correctly by an implementation that
     * accidentally takes only the last row.
     */
    $charge = ($this->billFor)('1000.000');

    ($this->payTowards)($charge, '250.000');
    ($this->payTowards)($charge, '250.000');
    ($this->payTowards)($charge, '100.000');

    ($this->expectOutstanding)($charge, '400.000');

    expect(ChargeBalance::allocatedFor((int) $charge->getKey())->toDecimal())->toBe('600.000');
});

it('counts only allocations against this charge', function () {
    /*
     * The subquery is correlated on `charges.id`. Without that correlation every
     * charge in the table would carry every payment in the system, which is a
     * balance that reads plausibly and is wrong for everyone.
     */
    $charge = ($this->billFor)('1000.000');
    $other = ($this->billFor)('500.000');

    ($this->payTowards)($charge, '300.000');
    ($this->payTowards)($other, '400.000');

    ($this->expectOutstanding)($charge, '700.000');
    ($this->expectOutstanding)($other, '100.000');
});

/*
|--------------------------------------------------------------------------
| A reversed payment drops out — the most-tested property in the phase
|--------------------------------------------------------------------------
*/

it('drops a reversed payment out of the balance', function () {
    /*
     * A reversal is a set-once lifecycle transition on an immutable row (design
     * §5). The allocations of a reversed payment are never deleted and never
     * rewritten, so `payments.reversed_at IS NULL` is the ONLY thing that takes
     * them out of the balance. Remove that filter and every reversed payment
     * silently starts counting as collected money again — a bill that reads as
     * settled while the money was handed back.
     */
    $charge = ($this->billFor)('1000.000');

    ($this->payTowards)($charge, '300.000');
    $reversed = ($this->payTowards)($charge, '200.000', reversed: true);

    ($this->expectOutstanding)($charge, '700.000');

    expect(ChargeBalance::allocatedFor((int) $charge->getKey())->toDecimal())->toBe('300.000')
        // Nothing was deleted. The row is still there, and still allocated.
        ->and(PaymentAllocation::query()->where('payment_id', $reversed->getKey())->count())->toBe(1)
        ->and((string) PaymentAllocation::query()->where('payment_id', $reversed->getKey())->value('amount'))
        ->toBe('200.000');
});

it('reopens a settled bill when the payment that settled it is reversed', function () {
    /*
     * The transition, not just the end state. A bill can read 0.000 outstanding
     * one moment and its full amount the next, with no row deleted in between —
     * which is exactly what reversal is for, and what an implementation caching a
     * balance could not express.
     */
    $charge = ($this->billFor)('1000.000');
    $payment = ($this->payTowards)($charge, '1000.000');

    ($this->expectOutstanding)($charge, '0.000');

    $payment->forceFill([
        'reversed_at' => now(),
        'reversed_by' => $payment->recorded_by,
        'reversal_reason' => 'Cheque returned.',
    ])->saveQuietly();

    ($this->expectOutstanding)($charge, '1000.000');
});

it('keeps a reversed payment out even when every payment on the bill is reversed', function () {
    $charge = ($this->billFor)('1000.000');

    ($this->payTowards)($charge, '600.000', reversed: true);
    ($this->payTowards)($charge, '400.000', reversed: true);

    // Back to the COALESCE case: the inner SUM matches no surviving row at all.
    ($this->expectOutstanding)($charge, '1000.000');

    expect(ChargeBalance::allocatedFor((int) $charge->getKey())->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Writing a debt off does not pay it (design §4)
|--------------------------------------------------------------------------
*/

it('does not subtract a write-off from the balance', function () {
    /*
     * Folding a write-off in here would make the balance printed on a receipt
     * disagree with the balance in the payment history, for the same charge on
     * the same day. The debt stays in the student's history and the AGED REPORT
     * filters on `written_off_at` instead — which is that report's decision, not
     * this definition's.
     */
    $charge = Charge::factory()->writtenOff()->create([
        'list_price' => '1000.000',
        'amount' => '1000.000',
    ]);

    ($this->payTowards)($charge, '300.000');

    ($this->expectOutstanding)($charge, '700.000');

    expect($charge->fresh()->written_off_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The dirham survives the round trip out of MySQL
|--------------------------------------------------------------------------
*/

it('keeps a single dirham through the SQL sum and back into a Money', function () {
    /*
     * Aggregation happens in SQL because MySQL's DECIMAL sums are exact (design
     * §6), and the result is hydrated through Money::fromDecimal() rather than
     * cast. At this magnitude the whole balance IS the dirham, so a float
     * anywhere in the path shows up as a zero.
     */
    $charge = ($this->billFor)('0.003');
    ($this->payTowards)($charge, '0.001');

    ($this->expectOutstanding)($charge, '0.002');

    expect(ChargeBalance::allocatedFor((int) $charge->getKey())->toDecimal())->toBe('0.001');
});

it('sums many small allocations without drift', function () {
    /*
     * Ten dirham, one at a time. Adding `decimal:3` strings in PHP converts them
     * to float and this is where that would surface — 0.001 ten times is not
     * 0.010 in binary floating point.
     */
    $charge = ($this->billFor)('1.000');

    foreach (range(1, 10) as $ignored) {
        ($this->payTowards)($charge, '0.001');
    }

    ($this->expectOutstanding)($charge, '0.990');

    expect(ChargeBalance::allocatedFor((int) $charge->getKey())->toDecimal())->toBe('0.010');
});

/*
|--------------------------------------------------------------------------
| The shapes Filament will actually use it in
|--------------------------------------------------------------------------
*/

it('selects into its own alias, so a column and its query cannot drift apart', function () {
    /*
     * OUTSTANDING_ALIAS and ALLOCATED_ALIAS exist because a typo in a Filament
     * column's name against the alias its query selected produces an EMPTY
     * COLUMN, not an error — the same reasoning as Batch::ASSIGNED_HOURS_SUM.
     */
    $charge = ($this->billFor)('1000.000');
    ($this->payTowards)($charge, '250.000');

    $row = DB::table('charges')
        ->where('charges.id', $charge->getKey())
        ->selectRaw(ChargeBalance::outstandingSql().' as '.ChargeBalance::OUTSTANDING_ALIAS)
        ->selectRaw(ChargeBalance::allocatedSql().' as '.ChargeBalance::ALLOCATED_ALIAS)
        ->first();

    expect($row)->not->toBeNull()
        ->and(property_exists($row, ChargeBalance::OUTSTANDING_ALIAS))->toBeTrue()
        ->and(property_exists($row, ChargeBalance::ALLOCATED_ALIAS))->toBeTrue()
        ->and((string) $row->{ChargeBalance::OUTSTANDING_ALIAS})->toBe('750.000')
        ->and((string) $row->{ChargeBalance::ALLOCATED_ALIAS})->toBe('250.000');
});

it('sorts a table by outstanding balance, in both directions', function () {
    /*
     * The reason the fragment is SQL at all: an Eloquent relationship cannot be
     * handed to orderBy(), and a Filament table sorting thousands of rows cannot
     * hydrate them to sort in PHP. Sorting is also where the COALESCE matters
     * most — a NULL balance lands at an end of the list rather than in its place.
     */
    $small = ($this->billFor)('1000.000');
    ($this->payTowards)($small, '900.000');       // 100.000 outstanding

    $large = ($this->billFor)('1000.000');        // 1000.000 outstanding

    $middle = ($this->billFor)('1000.000');
    ($this->payTowards)($middle, '500.000');      // 500.000 outstanding

    // A reversed payment must not move a row in the sort either.
    ($this->payTowards)($large, '999.000', reversed: true);

    $ascending = DB::table('charges')
        ->orderBy(ChargeBalance::outstandingExpression())
        ->pluck('id')
        ->all();

    $descending = DB::table('charges')
        ->orderByDesc(ChargeBalance::outstandingExpression())
        ->pluck('id')
        ->all();

    expect($ascending)->toBe([(int) $small->getKey(), (int) $middle->getKey(), (int) $large->getKey()])
        ->and($descending)->toBe(array_reverse($ascending));
});

it('filters a table on the outstanding balance with having', function () {
    /*
     * "Only bills with something still on them" is a HAVING over a derived value,
     * the third shape the expression has to compose into.
     *
     * MYSQL RESOLVES A HAVING AGAINST THE SELECT LIST, NOT AGAINST THE TABLE.
     * The fragment names `charges.amount`, so a query that groups and selects
     * only `charges.id` fails with "Unknown column 'charges.amount' in 'having
     * clause'" — not a defect in the fragment, but a constraint on where it can
     * go, and one worth pinning so nobody discovers it from a Filament filter in
     * production. Both working shapes are asserted: the alias a table would
     * already be selecting, and the Expression alongside the columns it reads.
     */
    $unpaid = ($this->billFor)('1000.000');

    $settled = ($this->billFor)('1000.000');
    ($this->payTowards)($settled, '1000.000');

    $reopened = ($this->billFor)('1000.000');
    ($this->payTowards)($reopened, '1000.000', reversed: true);

    $expected = [(int) $unpaid->getKey(), (int) $reopened->getKey()];
    sort($expected);

    // The alias route: select it once, filter on the name.
    $viaAlias = DB::table('charges')
        ->select('charges.id')
        ->selectRaw(ChargeBalance::outstandingSql().' as '.ChargeBalance::OUTSTANDING_ALIAS)
        ->having(ChargeBalance::OUTSTANDING_ALIAS, '>', 0)
        ->pluck('id')
        ->all();

    // The Expression route, with the columns it reads in scope.
    $viaExpression = DB::table('charges')
        ->select('charges.*')
        ->groupBy('charges.id')
        ->having(ChargeBalance::outstandingExpression(), '>', 0)
        ->pluck('id')
        ->all();

    sort($viaAlias);
    sort($viaExpression);

    expect($viaAlias)->toBe($expected)
        ->and($viaExpression)->toBe($expected)
        ->and($viaAlias)->not->toContain((int) $settled->getKey());
});

/*
|--------------------------------------------------------------------------
| A charge that is not there
|--------------------------------------------------------------------------
*/

it('refuses to take a balance from a charge that does not exist', function () {
    /*
     * A missing row is a RuntimeException rather than a zero. A charge id that
     * matches nothing means the caller is holding something that no longer exists
     * — and answering "0.000 outstanding" to that reports a SETTLED BILL, which
     * is the most dangerous wrong answer available here.
     */
    ChargeBalance::outstandingFor(999999);
})->throws(RuntimeException::class);

it('refuses the same way when asked what was allocated', function () {
    ChargeBalance::allocatedFor(999999);
})->throws(RuntimeException::class);

/*
|--------------------------------------------------------------------------
| The locking twins, which an Action under lock is the only caller of
|--------------------------------------------------------------------------
|
| outstandingForUpdate() and allocatedForUpdate() exist because an ordinary
| read answers from the transaction's REPEATABLE READ snapshot, taken at its
| first ordinary read — before the charge lock is acquired. They are the ONLY
| readers RecordPaymentAction's overpayment guard uses.
|
| They were shipped without these tests, and the independent pre-PR review
| caught it by replacing `payments.reversed_at IS NULL` inside
| ALLOCATED_LOCKING_SQL with `1 = 1` and watching 68 tests stay green. The
| non-locking path has three dedicated reversal tests; this path had none, on a
| hand-assembled SQL constant that is exactly the sort of thing a later cleanup
| edits.
|
| The failure that gap allowed through is worth naming, because it is quiet:
| lose the filter here and a reversed payment still counts as collected on the
| locking path alone. outstandingFor() would report a bill fully owed in every
| report and Filament table, while outstandingForUpdate() reported it settled —
| so the bill reads unpaid everywhere and can never be paid, refused as an
| overpayment. Two internally consistent figures disagreeing quietly, on rows
| nobody has a reason to look at twice, is the precise failure this class's
| single-definition constants exist to prevent.
|
| These run outside an explicit transaction. `FOR UPDATE` under autocommit takes
| its locks and releases them immediately, so the SQL is exercised without the
| test having to hold anything; the concurrent behaviour is PaymentConcurrencyTest's
| job, in subprocesses.
*/

it('answers the same as the ordinary reader on a bill nobody has paid', function () {
    $charge = ($this->billFor)('1000.000');

    expect(ChargeBalance::outstandingForUpdate((int) $charge->getKey())->toDecimal())->toBe('1000.000')
        ->and(ChargeBalance::allocatedForUpdate((int) $charge->getKey())->toDecimal())->toBe('0.000');
});

it('subtracts a partial payment on the locking path too', function () {
    $charge = ($this->billFor)('1000.000');
    ($this->payTowards)($charge, '300.000');

    expect(ChargeBalance::outstandingForUpdate((int) $charge->getKey())->toDecimal())->toBe('700.000')
        ->and(ChargeBalance::allocatedForUpdate((int) $charge->getKey())->toDecimal())->toBe('300.000');
});

it('sums several standing payments on the locking path', function () {
    $charge = ($this->billFor)('1000.000');
    ($this->payTowards)($charge, '100.500');
    ($this->payTowards)($charge, '200.250');

    expect(ChargeBalance::allocatedForUpdate((int) $charge->getKey())->toDecimal())->toBe('300.750')
        ->and(ChargeBalance::outstandingForUpdate((int) $charge->getKey())->toDecimal())->toBe('699.250');
});

it('drops a reversed payment out of the LOCKING balance as well', function () {
    /*
     * THE TEST THE REVIEW FOUND MISSING. Replacing NOT_REVERSED_SQL inside
     * ALLOCATED_LOCKING_SQL with `1 = 1` must turn this red; nothing else in the
     * suite reaches that constant.
     */
    $charge = ($this->billFor)('1000.000');

    ($this->payTowards)($charge, '300.000');
    $reversed = ($this->payTowards)($charge, '200.000', reversed: true);

    expect(ChargeBalance::allocatedForUpdate((int) $charge->getKey())->toDecimal())->toBe(
        '300.000',
        'The locking reader counted a reversed payment as collected money.',
    )->and(ChargeBalance::outstandingForUpdate((int) $charge->getKey())->toDecimal())->toBe('700.000');

    // Nothing was deleted to achieve that — reversed_at is the whole mechanism.
    expect(PaymentAllocation::query()->where('payment_id', $reversed->getKey())->count())->toBe(1);
});

it('agrees with the ordinary reader when every payment on the bill is reversed', function () {
    $charge = ($this->billFor)('500.000');

    ($this->payTowards)($charge, '500.000', reversed: true);

    // Both routes, same answer: a bill whose only payment was voided is
    // owed in full. The two disagreeing is the quiet failure described above.
    expect(ChargeBalance::outstandingForUpdate((int) $charge->getKey())->toDecimal())->toBe('500.000')
        ->and(ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal())->toBe('500.000');
});

it('keeps a single dirham through the locking sum', function () {
    $charge = ($this->billFor)('1.000');
    ($this->payTowards)($charge, '0.001');

    expect(ChargeBalance::outstandingForUpdate((int) $charge->getKey())->toDecimal())->toBe('0.999')
        ->and(ChargeBalance::allocatedForUpdate((int) $charge->getKey())->toDecimal())->toBe('0.001');
});

it('refuses to take a locked balance from a charge that does not exist', function () {
    // Same reasoning as the ordinary reader's twin above: answering "0.000
    // outstanding" for a charge that is not there reports a settled bill.
    ChargeBalance::outstandingForUpdate(999999);
})->throws(RuntimeException::class);
