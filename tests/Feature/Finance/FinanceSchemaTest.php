<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Finance\Support\Reference;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates typed one-to-one receipt snapshot storage', function () {
    expect(Schema::hasTable('payment_receipt_snapshots'))->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->select([
            'column_name as receipt_column',
            'column_type as receipt_column_type',
        ])
        ->where('table_schema', DB::connection()->getDatabaseName())
        ->where('table_name', 'payment_receipt_snapshots')
        ->whereIn('column_name', [
            'list_price',
            'discount_percentage',
            'final_charge',
            'amount_paid',
            'remaining_balance',
        ])
        ->pluck('receipt_column_type', 'receipt_column')
        ->all();

    expect($columns)->toMatchArray([
        'list_price' => 'decimal(12,3)',
        'discount_percentage' => 'decimal(5,2)',
        'final_charge' => 'decimal(12,3)',
        'amount_paid' => 'decimal(12,3)',
        'remaining_balance' => 'decimal(12,3)',
    ]);
    expect(financeIndexColumns('payment_receipt_snapshots', 'payment_receipt_snapshots_payment_id_unique'))
        ->toBe(['payment_id'])
        ->and(financeIndexIsUnique('payment_receipt_snapshots', 'payment_receipt_snapshots_payment_id_unique'))
        ->toBeTrue()
        ->and(financeIndexColumns('payments', 'payments_receipt_path_index'))
        ->toBe(['receipt_path']);
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| What this file proves, and what it deliberately does not
|--------------------------------------------------------------------------
|
| Design section 11 splits the phase 2 invariants into two columns: the ones
| held by a MySQL `CHECK` or a unique index, and the ones held by a lock inside
| an Action. This file tests the FIRST column only, and it tests it the one way
| that means anything — by handing MySQL a row it must refuse, through
| DB::table()->insert(), with no model, no Action and no validation anywhere in
| the path.
|
| A test that creates a row through a factory and asserts it looks right proves
| that the factory is correct. It proves nothing whatsoever about the
| constraint, because the constraint is never asked a question it could answer
| "no" to. Section 14 says this in the imperative: database-level guarantees are
| proven at the database, "not by asserting a button is hidden".
|
| EVERY REFUSAL IS MATCHED AGAINST THE CONSTRAINT'S NAME. `financeRefusedBy()`
| asserts that MySQL's error names the specific constraint under test, so a row
| refused for some unrelated reason — a missing NOT NULL column, a foreign key,
| a unique index the test did not mean to hit — fails rather than reads as
| success. Every `CHECK` in this schema is explicitly named in its migration for
| exactly this reason (design section 14); an autonumbered `charges_chk_1` could
| not be asserted against.
|
| The invariants NOT tested here, because no constraint holds them, are the ones
| section 11 lists against a lock: tender total versus allocation total, a
| payment never exceeding outstanding, overlapping salary segments, overlapping
| compensation periods, adjustments only while draft. Those belong to the tasks
| that build their Actions. Asserting them here would either fail or, worse,
| pass for a reason that has nothing to do with the guarantee.
*/

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Assert MySQL refuses $row, and that the refusal names $constraint.
 *
 * The name check is the whole point. Without it this helper would pass for any
 * insert that failed for any reason, which on a schema this heavily constrained
 * is easy to do by accident — a row built one column short is refused too, and
 * reads identically from the outside.
 *
 * The row count is asserted unchanged as well, so a constraint that somehow
 * refused *and* wrote would still fail.
 *
 * @param  array<string, mixed>  $row
 */
function financeRefusedBy(string $constraint, string $table, array $row): void
{
    $before = DB::table($table)->count();

    $message = null;

    try {
        DB::table($table)->insert($row);
    } catch (QueryException $exception) {
        $message = $exception->getMessage();
    }

    /*
     * `expect($message !== null)->toBeTrue()` rather than `->not->toBeNull()`.
     * Pest's negated expectations re-render the failure message as a VALUE and
     * shorten it — "MySQL accepted a row into `pa…means." — so the explanation
     * that makes the failure actionable never reaches the reader. A positive
     * assertion hands the message to PHPUnit intact.
     */
    expect($message !== null)->toBeTrue(
        "MySQL accepted a row into `{$table}` that `{$constraint}` exists to refuse. "
        .'The constraint is either missing from the schema or does not mean what its '
        .'migration says it means.',
    );

    /*
     * str_contains() rather than expect()->toContain(), which is VARIADIC and
     * would read the failure message below as a second string it must also find
     * — so the assertion would fail on every genuine refusal. tests/Pest.php
     * records the same trap for expect()->toContain() on arrays.
     */
    expect(str_contains((string) $message, $constraint))->toBeTrue(
        "The insert into `{$table}` was refused, but not by `{$constraint}` — so this test "
        ."is passing on an unrelated failure and proves nothing about {$constraint}. "
        ."MySQL said: {$message}",
    );

    expect(DB::table($table)->count())->toBe(
        $before,
        "`{$constraint}` refused the insert and a row appeared in `{$table}` anyway.",
    );
}

/**
 * Insert $row and return the id, failing loudly if MySQL will not take it.
 *
 * THE CONTROL FOR EVERY REFUSAL IN THIS FILE. Each negative test starts from a
 * builder below and breaks one column. If a builder produced a row MySQL would
 * reject anyway, every one of those tests would pass while testing nothing —
 * the name assertion in financeRefusedBy() narrows that risk to "refused by the
 * same constraint for a different reason", and this closes it.
 *
 * @param  array<string, mixed>  $row
 */
function financeAccepted(string $table, array $row): int
{
    $before = DB::table($table)->count();

    try {
        DB::table($table)->insert($row);
    } catch (QueryException $exception) {
        expect(false)->toBeTrue(
            "MySQL refused a row that this file treats as VALID for `{$table}`, so every "
            .'negative test built from it is passing for the wrong reason. MySQL said: '
            .$exception->getMessage(),
        );
    }

    expect(DB::table($table)->count())->toBe(
        $before + 1,
        "The insert into `{$table}` raised nothing and wrote nothing.",
    );

    return (int) DB::table($table)->max('id');
}

/**
 * A row already in the table, as raw column values ready to insert again.
 *
 * For the duplicate-key tests. Copying the whole row rather than hand-building
 * a second one is what makes the duplicate GENUINE: the two rows differ in
 * nothing except the id MySQL assigns and whatever $overrides names, so the
 * only thing that can refuse the second is the index under test.
 *
 * Generated columns are stripped because MySQL refuses a write to one outright,
 * and that refusal would be indistinguishable from the index doing its job.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeRowCopy(string $table, int $id, array $overrides = []): array
{
    $row = (array) DB::table($table)->where('id', $id)->first();

    unset(
        $row['id'],
        $row['finalized_batch_instructor_id'],
        $row['finalized_salary_segment'],
    );

    return array_merge($row, $overrides);
}

/**
 * The columns an index covers, in index order, or [] if there is no such index.
 *
 * SELECTED WITH AN ALIAS, deliberately. MySQL returns `information_schema`
 * column names UPPERCASED, so pluck('column_name') reads a property that is not
 * there and dies with "Undefined property: stdClass::$column_name". An alias
 * comes back exactly as written, which makes the key predictable rather than a
 * property of the server's identifier casing.
 *
 * @return array<int, string>
 */
function financeIndexColumns(string $table, string $index): array
{
    return DB::table('information_schema.statistics')
        ->select('column_name as indexed_column')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', $table)
        ->where('index_name', $index)
        ->orderBy('seq_in_index')
        ->pluck('indexed_column')
        ->map(fn (mixed $column): string => (string) $column)
        ->all();
}

/** Is $index a UNIQUE index rather than a plain one? */
function financeIndexIsUnique(string $table, string $index): bool
{
    return DB::table('information_schema.statistics')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', $table)
        ->where('index_name', $index)
        ->where('non_unique', 0)
        ->exists();
}

/**
 * The declared character length of a column, or null if it does not exist.
 *
 * Selected with an alias, matching every other information_schema query in this
 * file: MySQL returns those column names UPPERCASED, so reading
 * `$row->character_maximum_length` dies on a missing property.
 */
function financeColumnLength(string $table, string $column): ?int
{
    $length = DB::table('information_schema.columns')
        ->select('character_maximum_length as declared_length')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', $table)
        ->where('column_name', $column)
        ->value('declared_length');

    return $length === null ? null : (int) $length;
}

/**
 * A valid `discounts` row.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeDiscountRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'Discount '.Str::upper(Str::random(10)),
        'percentage' => '10.00',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * A valid `charges` row for an enrolment that has no bill yet.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeChargeRow(int $enrollmentId, array $overrides = []): array
{
    return array_merge([
        'enrollment_id' => $enrollmentId,
        'reference' => Reference::placeholder(),
        'list_price' => '1000.000',
        'discount_id' => null,
        'discount_percentage' => null,
        'amount' => '1000.000',
        'due_date' => '2026-08-10',
        'written_off_at' => null,
        'written_off_by' => null,
        'written_off_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * A valid `payments` row.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financePaymentRow(int $studentId, int $actorId, array $overrides = []): array
{
    return array_merge([
        'student_id' => $studentId,
        'reference' => Reference::placeholder(),
        'idempotency_key' => Str::uuid()->toString(),
        'request_fingerprint' => hash('sha256', Str::uuid()->toString()),
        'received_at' => now(),
        'recorded_by' => $actorId,
        'notes' => null,
        'reversed_at' => null,
        'reversed_by' => null,
        'reversal_reason' => null,
        'receipt_disk' => null,
        'receipt_path' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function financeReceiptSnapshotRow(int $paymentId, array $overrides = []): array
{
    $payment = DB::table('payments')->where('id', $paymentId)->first();

    return array_merge([
        'payment_id' => $paymentId,
        'locale' => 'en',
        'student_code' => 'STU-000001',
        'student_name' => 'Snapshot Student',
        'enrollment_reference' => 'ENR-2026-000001',
        'course_code' => 'CRS-001',
        'batch_code' => 'BAT-001',
        'charge_reference' => 'CHG-2026-000001',
        'list_price' => '1000.000',
        'discount_percentage' => null,
        'final_charge' => '1000.000',
        'amount_paid' => '300.000',
        'remaining_balance' => '700.000',
        'recorded_by_name' => 'Snapshot Operator',
        'payment_reference' => (string) $payment->reference,
        'received_at' => $payment->received_at,
        'last_reconciliation_attempt_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('holds one immutable receipt snapshot per payment at the database', function () {
    $payment = Payment::factory()->create();
    financeAccepted('payment_receipt_snapshots', financeReceiptSnapshotRow((int) $payment->getKey()));

    financeRefusedBy(
        'payment_receipt_snapshots_payment_id_unique',
        'payment_receipt_snapshots',
        financeReceiptSnapshotRow((int) $payment->getKey(), ['student_name' => 'Second Snapshot']),
    );
})->group('finance-schema');

it('refuses invalid receipt snapshot amounts and discounts at the database', function (
    string $constraint,
    array $override,
): void {
    $payment = Payment::factory()->create();

    financeRefusedBy(
        $constraint,
        'payment_receipt_snapshots',
        financeReceiptSnapshotRow((int) $payment->getKey(), $override),
    );
})->with([
    'negative list price' => [
        'payment_receipt_snapshots_amounts_non_negative',
        ['list_price' => '-0.001'],
    ],
    'negative final charge' => [
        'payment_receipt_snapshots_amounts_non_negative',
        ['final_charge' => '-0.001'],
    ],
    'negative paid amount' => [
        'payment_receipt_snapshots_amounts_non_negative',
        ['amount_paid' => '-0.001'],
    ],
    'negative remaining balance' => [
        'payment_receipt_snapshots_amounts_non_negative',
        ['remaining_balance' => '-0.001'],
    ],
    'zero discount' => [
        'payment_receipt_snapshots_discount_percentage_valid',
        ['discount_percentage' => '0.00'],
    ],
    'discount above one hundred' => [
        'payment_receipt_snapshots_discount_percentage_valid',
        ['discount_percentage' => '100.01'],
    ],
])->group('finance-schema');

/**
 * A valid `payment_tenders` row — cash, which needs no terminal reference.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeTenderRow(int $paymentId, array $overrides = []): array
{
    return array_merge([
        'payment_id' => $paymentId,
        'method' => 'cash',
        'amount' => '250.000',
        'external_reference' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * A valid `payment_allocations` row.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeAllocationRow(int $paymentId, int $chargeId, array $overrides = []): array
{
    return array_merge([
        'payment_id' => $paymentId,
        'charge_id' => $chargeId,
        'amount' => '250.000',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * A valid `staff_compensation` row — the open current rate.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeCompensationRow(int $userId, array $overrides = []): array
{
    return array_merge([
        'user_id' => $userId,
        'type' => 'salary',
        'amount' => '2500.000',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * A valid `payroll_runs` row — a draft monthly salary run.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financePayrollRunRow(int $actorId, array $overrides = []): array
{
    return array_merge([
        'type' => 'monthly_salary',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'created_by' => $actorId,
        'finalized_at' => null,
        'finalized_by' => null,
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * A valid `payroll_lines` row in one of design section 9's three shapes.
 *
 * The shape table is spelled out here ONCE, so the tests below can describe a
 * violation as "the salary shape, minus its denominator" rather than restating
 * eleven columns each time. $shapeValues supplies a real id or value per column
 * — foreign keys have to point at rows that exist, or the insert is refused by
 * a foreign key rather than by the shape constraint.
 *
 * @param  array<string, mixed>  $shapeValues
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financePayrollLineRow(int $runId, int $userId, string $shape, array $shapeValues, array $overrides = []): array
{
    $row = [
        'payroll_run_id' => $runId,
        'user_id' => $userId,
        'staff_compensation_id' => null,
        'batch_instructor_id' => null,
        'corrects_payroll_line_id' => null,
        'segment_start' => null,
        'segment_end' => null,
        'frozen_rate' => null,
        'frozen_hours' => null,
        'frozen_days' => null,
        'frozen_days_in_month' => null,
        'computed_amount' => '2500.000',
        'posting_period_start' => null,
        'reason' => null,
        'finalized_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $columns = match ($shape) {
        'salary' => [
            'staff_compensation_id', 'segment_start', 'segment_end',
            'frozen_rate', 'frozen_days', 'frozen_days_in_month',
        ],
        'instructor' => ['staff_compensation_id', 'batch_instructor_id', 'frozen_rate', 'frozen_hours'],
        'adjustment' => ['corrects_payroll_line_id', 'reason'],
    };

    foreach ($columns as $column) {
        $row[$column] = $shapeValues[$column];
    }

    return array_merge($row, $overrides);
}

/**
 * A real value for every column any of the three shapes can carry.
 *
 * The foreign keys point at rows that genuinely exist, so a row assembled from
 * these can only ever be refused by `payroll_lines_exactly_one_shape` — which
 * is what lets the tests below attribute a refusal to the shape rather than to
 * a dangling reference.
 *
 * @return array<string, mixed>
 */
function financePayrollShapeValues(int $userId): array
{
    // A real batch_instructor row, built exactly the way PayrollLineFactory
    // builds one rather than by hand.
    $assignment = (int) PayrollLine::factory()->instructor()->create()->batch_instructor_id;

    return [
        'staff_compensation_id' => (int) StaffCompensation::factory()->create(['user_id' => $userId])->getKey(),
        'batch_instructor_id' => $assignment,
        'corrects_payroll_line_id' => (int) PayrollLine::factory()->finalized()->create()->getKey(),
        'segment_start' => '2026-07-01',
        'segment_end' => '2026-07-31',
        'frozen_rate' => '2500.000',
        'frozen_hours' => 20,
        'frozen_days' => 31,
        'frozen_days_in_month' => 31,
        'reason' => 'Recorded against the wrong month at the desk.',
    ];
}

/**
 * A valid `payroll_line_adjustments` row.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeAdjustmentRow(int $lineId, int $actorId, array $overrides = []): array
{
    return array_merge([
        'payroll_line_id' => $lineId,
        'amount' => '150.000',
        'reason' => 'Agreed bonus for the March intake.',
        'created_by' => $actorId,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

beforeEach(function () {
    $this->actor = User::factory()->create();
});

/*
|--------------------------------------------------------------------------
| The control: every builder above produces a row MySQL accepts
|--------------------------------------------------------------------------
*/

it('accepts the valid row every refusal below is built from', function () {
    /*
     * Without this, a builder one column short would make every negative test in
     * this file pass while proving nothing — the insert would be refused, and
     * only the constraint-name assertion in financeRefusedBy() would stand
     * between that and a green suite. This is the other half of that guard.
     */
    $enrollment = Enrollment::factory()->create();
    $student = Student::factory()->create();
    $user = User::factory()->create();

    financeAccepted('discounts', financeDiscountRow());
    $chargeId = financeAccepted('charges', financeChargeRow((int) $enrollment->getKey()));
    $paymentId = financeAccepted('payments', financePaymentRow((int) $student->getKey(), (int) $this->actor->getKey()));
    financeAccepted('payment_tenders', financeTenderRow($paymentId));
    financeAccepted('payment_allocations', financeAllocationRow($paymentId, $chargeId));
    financeAccepted('staff_compensation', financeCompensationRow((int) $user->getKey()));
    $runId = financeAccepted('payroll_runs', financePayrollRunRow((int) $this->actor->getKey()));

    $values = financePayrollShapeValues((int) $user->getKey());

    foreach (['salary', 'instructor', 'adjustment'] as $shape) {
        financeAccepted(
            'payroll_lines',
            financePayrollLineRow($runId, (int) $user->getKey(), $shape, $values),
        );
    }

    $lineId = (int) DB::table('payroll_lines')->max('id');
    financeAccepted('payroll_line_adjustments', financeAdjustmentRow($lineId, (int) $this->actor->getKey()));
})->group('finance-schema');

it('carries every named constraint the migrations claim to add', function () {
    /*
     * A completeness check, not a substitute for the refusals below. It catches
     * a constraint renamed or dropped in a migration edit — which would
     * otherwise surface as a refusal test failing on its name assertion, in one
     * file, rather than as a list.
     */
    $present = DB::table('information_schema.check_constraints')
        ->select('constraint_name as name')
        ->where('constraint_schema', DB::getDatabaseName())
        ->pluck('name')
        ->map(fn (mixed $name): string => (string) $name)
        ->all();

    $expected = [
        'discounts_percentage_within_range',
        'charges_amount_not_negative',
        'charges_list_price_not_negative',
        'charges_discount_columns_paired',
        'charges_write_off_columns_paired',
        'payments_reversal_columns_paired',
        'payment_tenders_amount_positive',
        'payment_tenders_card_requires_external_reference',
        'payment_allocations_amount_positive',
        'staff_compensation_amount_positive',
        'staff_compensation_effective_to_not_before_from',
        'payroll_runs_period_matches_type',
        'payroll_runs_period_end_not_before_start',
        'payroll_runs_finalization_columns_paired',
        'payroll_lines_exactly_one_shape',
        'payroll_lines_posting_period_required_when_finalized',
        'payroll_line_adjustments_amount_not_zero',
    ];

    expect(array_values(array_diff($expected, $present)))->toBe(
        [],
        'These CHECK constraints are named in the phase 2 migrations but are not in the '
        .'database. A CHECK that MySQL autonumbered (charges_chk_1) cannot be asserted '
        .'against, so a rename is a real regression here.',
    );
})->group('finance-schema');

it('keeps every reference column wide enough for Reference::COLUMN_LENGTH', function (string $table) {
    /*
     * FIX for the drift `Reference::COLUMN_LENGTH` used to be imported into five
     * migrations to prevent. Those migrations are frozen snapshots now — see
     * their own docblocks — so the constant and the schema can no longer be kept
     * in sync by one of them reading the other. This is the other half of that
     * trade: a change to the constant must fail HERE, at the database, rather
     * than truncate a real reference the first time somebody widens the format.
     *
     * `>=` rather than `===`. The frozen migrations wrote the literal 64 that
     * was current when they ran; a later, larger constant is a legitimate reason
     * to add a new migration widening the column, and this assertion is
     * satisfied by that widening. A SMALLER constant than the column's declared
     * width is not a failure this test exists to catch — the failure mode is
     * truncation, not slack.
     */
    expect(financeColumnLength($table, 'reference'))->not->toBeNull(
        "`{$table}.reference` does not exist, so this test cannot prove anything about it.",
    )->and(financeColumnLength($table, 'reference'))->toBeGreaterThanOrEqual(
        Reference::COLUMN_LENGTH,
        "`{$table}.reference` is narrower than Reference::COLUMN_LENGTH (".Reference::COLUMN_LENGTH.'). '
        .'The migrations that created this column are frozen and will not grow with the '
        .'constant — a new migration is required to widen it before a placeholder or a '
        .'longer reference can be written safely.',
    );
})->with([
    'enrollments' => ['enrollments'],
    'charges' => ['charges'],
    'payments' => ['payments'],
])->group('finance-schema');

/*
|--------------------------------------------------------------------------
| discounts
|--------------------------------------------------------------------------
*/

it('refuses a discount of zero percent', function () {
    // "A zero discount is not a discount" — the migration's own words. The
    // boundary matters: the constraint is `> 0`, not `>= 0`.
    financeRefusedBy(
        'discounts_percentage_within_range',
        'discounts',
        financeDiscountRow(['percentage' => '0.00']),
    );
})->group('finance-schema');

it('refuses a negative discount, which would be a surcharge', function () {
    financeRefusedBy(
        'discounts_percentage_within_range',
        'discounts',
        financeDiscountRow(['percentage' => '-5.00']),
    );
})->group('finance-schema');

it('refuses a discount above one hundred percent', function () {
    financeRefusedBy(
        'discounts_percentage_within_range',
        'discounts',
        financeDiscountRow(['percentage' => '100.01']),
    );
})->group('finance-schema');

it('accepts a full waiver at exactly one hundred percent', function () {
    // The upper boundary is inclusive on purpose — a fully waived enrolment is
    // a real thing the centre does. A constraint that refused this would be
    // caught by no negative test in this file.
    financeAccepted('discounts', financeDiscountRow(['percentage' => '100.00']));
})->group('finance-schema');

it('holds one discount definition per name at a unique index', function () {
    // An operator picks a discount by name at the desk; two "Staff family"
    // rows would make that choice a guess. The row copied here is a genuine
    // duplicate — discounts carry no other unique column to collide on
    // instead — so only this index can be refusing it.
    $discount = Discount::factory()->create();

    expect(financeIndexColumns('discounts', 'discounts_name_unique'))->toBe(['name'])
        ->and(financeIndexIsUnique('discounts', 'discounts_name_unique'))->toBeTrue(
            'discounts_name_unique exists but is not UNIQUE, so two discount definitions '
            .'could share one name.',
        );

    financeRefusedBy(
        'discounts_name_unique',
        'discounts',
        financeRowCopy('discounts', (int) $discount->getKey()),
    );
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| charges
|--------------------------------------------------------------------------
*/

it('refuses a bill for a negative amount', function () {
    // Design section 1 rules refunds out of the whole system, and a negative
    // bill is a refund wearing a bill's name.
    financeRefusedBy(
        'charges_amount_not_negative',
        'charges',
        financeChargeRow((int) Enrollment::factory()->create()->getKey(), ['amount' => '-0.001']),
    );
})->group('finance-schema');

it('accepts a bill of exactly zero, which a full waiver produces', function () {
    financeAccepted(
        'charges',
        financeChargeRow((int) Enrollment::factory()->create()->getKey(), ['amount' => '0.000']),
    );
})->group('finance-schema');

it('refuses a bill whose list price is negative', function () {
    financeRefusedBy(
        'charges_list_price_not_negative',
        'charges',
        financeChargeRow((int) Enrollment::factory()->create()->getKey(), [
            'list_price' => '-1.000',
            'amount' => '0.000',
        ]),
    );
})->group('finance-schema');

it('refuses a discount id with no frozen percentage beside it', function () {
    $discount = Discount::factory()->create();

    financeRefusedBy(
        'charges_discount_columns_paired',
        'charges',
        financeChargeRow((int) Enrollment::factory()->create()->getKey(), [
            'discount_id' => $discount->getKey(),
            'discount_percentage' => null,
        ]),
    );
})->group('finance-schema');

it('refuses a frozen percentage with no discount id beside it', function () {
    // Both directions. A pairing constraint tested one way round is half tested,
    // and the half that is skipped is exactly the one a `NOT NULL` would also
    // have caught.
    financeRefusedBy(
        'charges_discount_columns_paired',
        'charges',
        financeChargeRow((int) Enrollment::factory()->create()->getKey(), [
            'discount_id' => null,
            'discount_percentage' => '10.00',
        ]),
    );
})->group('finance-schema');

it('refuses a write-off with no actor and no reason', function () {
    financeRefusedBy(
        'charges_write_off_columns_paired',
        'charges',
        financeChargeRow((int) Enrollment::factory()->create()->getKey(), [
            'written_off_at' => now(),
            'written_off_by' => null,
            'written_off_reason' => null,
        ]),
    );
})->group('finance-schema');

it('refuses a write-off with an actor and a time but no reason', function () {
    // The mandatory-reason rule, at the database. Two of three columns is the
    // case a naive pairing check written as `(a IS NULL) = (b IS NULL)` over the
    // first two columns would let through.
    financeRefusedBy(
        'charges_write_off_columns_paired',
        'charges',
        financeChargeRow((int) Enrollment::factory()->create()->getKey(), [
            'written_off_at' => now(),
            'written_off_by' => $this->actor->getKey(),
            'written_off_reason' => null,
        ]),
    );
})->group('finance-schema');

it('refuses a write-off reason with no write-off', function () {
    financeRefusedBy(
        'charges_write_off_columns_paired',
        'charges',
        financeChargeRow((int) Enrollment::factory()->create()->getKey(), [
            'written_off_at' => null,
            'written_off_by' => null,
            'written_off_reason' => 'Uncollectable.',
        ]),
    );
})->group('finance-schema');

it('holds one bill per enrolment at a unique index', function () {
    /*
     * Design section 11 lists "one charge per enrolment" against a unique index
     * rather than against an Action, so it must hold for a write that never
     * touches IssueChargeAction. The second row is a byte-for-byte copy of the
     * first apart from its reference, which is separately unique — so nothing
     * except the enrolment index can be refusing it.
     */
    $charge = Charge::factory()->create();

    expect(financeIndexColumns('charges', 'charges_enrollment_id_unique'))->toBe(['enrollment_id'])
        ->and(financeIndexIsUnique('charges', 'charges_enrollment_id_unique'))->toBeTrue(
            'charges_enrollment_id_unique exists but is not UNIQUE, so it enforces nothing '
            .'and a second bill for one enrolment would simply be written.',
        );

    financeRefusedBy(
        'charges_enrollment_id_unique',
        'charges',
        financeRowCopy('charges', (int) $charge->getKey(), ['reference' => Reference::placeholder()]),
    );
})->group('finance-schema');

it('holds one reference per charge at a unique index', function () {
    // enrollment_id is swapped on the copy, since it carries its own unique
    // index — so nothing except charges_reference_unique can be refusing this
    // insert.
    $charge = Charge::factory()->create();

    expect(financeIndexColumns('charges', 'charges_reference_unique'))->toBe(['reference'])
        ->and(financeIndexIsUnique('charges', 'charges_reference_unique'))->toBeTrue(
            'charges_reference_unique exists but is not UNIQUE, so two bills could carry the '
            .'same reference.',
        );

    financeRefusedBy(
        'charges_reference_unique',
        'charges',
        financeRowCopy('charges', (int) $charge->getKey(), [
            'enrollment_id' => (int) Enrollment::factory()->create()->getKey(),
        ]),
    );
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| payments
|--------------------------------------------------------------------------
*/

it('refuses a reversal with no actor and no reason', function () {
    financeRefusedBy(
        'payments_reversal_columns_paired',
        'payments',
        financePaymentRow(
            (int) Student::factory()->create()->getKey(),
            (int) $this->actor->getKey(),
            ['reversed_at' => now(), 'reversed_by' => null, 'reversal_reason' => null],
        ),
    );
})->group('finance-schema');

it('refuses a reversal with a time and an actor but no reason', function () {
    financeRefusedBy(
        'payments_reversal_columns_paired',
        'payments',
        financePaymentRow(
            (int) Student::factory()->create()->getKey(),
            (int) $this->actor->getKey(),
            [
                'reversed_at' => now(),
                'reversed_by' => $this->actor->getKey(),
                'reversal_reason' => null,
            ],
        ),
    );
})->group('finance-schema');

it('refuses a reversal actor with no reversal', function () {
    financeRefusedBy(
        'payments_reversal_columns_paired',
        'payments',
        financePaymentRow(
            (int) Student::factory()->create()->getKey(),
            (int) $this->actor->getKey(),
            [
                'reversed_at' => null,
                'reversed_by' => $this->actor->getKey(),
                'reversal_reason' => 'Taken in error.',
            ],
        ),
    );
})->group('finance-schema');

it('refuses a second payment sharing an idempotency key', function () {
    /*
     * The one mechanism in the design that stops a double-clicked button
     * becoming two payments for one handover of cash. Section 11 lists it
     * against a unique index, and RecordPaymentAction matches on THIS INDEX
     * NAME when it converts the violation into a replay — so the name is part
     * of the contract, not an implementation detail.
     */
    $payment = Payment::factory()->create();

    expect(financeIndexColumns('payments', 'payments_idempotency_key_unique'))->toBe(['idempotency_key'])
        ->and(financeIndexIsUnique('payments', 'payments_idempotency_key_unique'))->toBeTrue(
            'payments_idempotency_key_unique exists but is not UNIQUE. Retry protection is '
            .'this index and nothing else.',
        );

    financeRefusedBy(
        'payments_idempotency_key_unique',
        'payments',
        financeRowCopy('payments', (int) $payment->getKey(), ['reference' => Reference::placeholder()]),
    );
})->group('finance-schema');

it('refuses a second payment sharing a receipt number', function () {
    $payment = Payment::factory()->create();

    expect(financeIndexColumns('payments', 'payments_reference_unique'))->toBe(['reference'])
        ->and(financeIndexIsUnique('payments', 'payments_reference_unique'))->toBeTrue(
            'payments_reference_unique exists but is not UNIQUE, so two receipts could carry '
            .'the same number.',
        );

    financeRefusedBy(
        'payments_reference_unique',
        'payments',
        financeRowCopy('payments', (int) $payment->getKey(), [
            'idempotency_key' => Str::uuid()->toString(),
        ]),
    );
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| payment_tenders
|--------------------------------------------------------------------------
*/

it('refuses a tender of zero', function () {
    // Strictly positive, unlike charges.amount — "nobody hands over nothing".
    financeRefusedBy(
        'payment_tenders_amount_positive',
        'payment_tenders',
        financeTenderRow((int) Payment::factory()->create()->getKey(), ['amount' => '0.000']),
    );
})->group('finance-schema');

it('refuses a negative tender', function () {
    financeRefusedBy(
        'payment_tenders_amount_positive',
        'payment_tenders',
        financeTenderRow((int) Payment::factory()->create()->getKey(), ['amount' => '-0.001']),
    );
})->group('finance-schema');

it('refuses a card tender whose terminal reference is only whitespace', function (string $blank) {
    /*
     * THE CASE THE BLANKNESS TEST EXISTS FOR, and the one design section 14
     * names explicitly. A nullability check alone accepts a single space, and a
     * space is not a reference — it is an operator tabbing past a field, leaving
     * a card transaction with nothing to reconcile against the terminal's log.
     *
     * THE TAB AND NEWLINE CASES ARE ASSERTED, and they are why this constraint
     * is a REGEXP rather than a TRIM. Written as `TRIM(x) <> ''` first, it
     * passed the space case and silently accepted a tab: MySQL's one-argument
     * TRIM removes ASCII SPACES ONLY. The gap was found by running this test
     * against that constraint, not by reading it. `REGEXP '[^[:space:]]'` asks
     * the question the design actually asks — is there any non-whitespace
     * character here — so all three cases below are refused by the database.
     */
    financeRefusedBy(
        'payment_tenders_card_requires_external_reference',
        'payment_tenders',
        financeTenderRow((int) Payment::factory()->create()->getKey(), [
            'method' => 'card',
            'external_reference' => $blank,
        ]),
    );
})->with([
    'spaces' => ['   '],
    'a single tab' => ["\t"],
    'a newline' => ["\n"],
    'mixed whitespace' => [" \t\r\n "],
])->group('finance-schema');

it('refuses a card tender with an empty terminal reference', function () {
    financeRefusedBy(
        'payment_tenders_card_requires_external_reference',
        'payment_tenders',
        financeTenderRow((int) Payment::factory()->create()->getKey(), [
            'method' => 'card',
            'external_reference' => '',
        ]),
    );
})->group('finance-schema');

it('refuses a card tender with no terminal reference at all', function () {
    financeRefusedBy(
        'payment_tenders_card_requires_external_reference',
        'payment_tenders',
        financeTenderRow((int) Payment::factory()->create()->getKey(), [
            'method' => 'card',
            'external_reference' => null,
        ]),
    );
})->group('finance-schema');

it('leaves the terminal reference optional for a cash tender', function () {
    /*
     * The direction check. `method <> 'card' OR ...` must leave cash alone; a
     * constraint written as a plain NOT NULL would pass every refusal above and
     * fail here, and nothing else in this file would notice.
     */
    financeAccepted(
        'payment_tenders',
        financeTenderRow((int) Payment::factory()->create()->getKey(), [
            'method' => 'cash',
            'external_reference' => null,
        ]),
    );
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| payment_allocations
|--------------------------------------------------------------------------
*/

it('refuses an allocation of zero', function () {
    $payment = Payment::factory()->create();
    $charge = Charge::factory()->create();

    financeRefusedBy(
        'payment_allocations_amount_positive',
        'payment_allocations',
        financeAllocationRow((int) $payment->getKey(), (int) $charge->getKey(), ['amount' => '0.000']),
    );
})->group('finance-schema');

it('refuses a negative allocation', function () {
    $payment = Payment::factory()->create();
    $charge = Charge::factory()->create();

    financeRefusedBy(
        'payment_allocations_amount_positive',
        'payment_allocations',
        financeAllocationRow((int) $payment->getKey(), (int) $charge->getKey(), ['amount' => '-50.000']),
    );
})->group('finance-schema');

it('holds one allocation per payment per bill at a unique index', function () {
    /*
     * THE IMPORTANT ONE. The migration's own words: without this index a
     * payment could be recorded against the same charge twice, and both rows
     * would sum into the balance — "tender total equals allocation total"
     * would still hold on each individual write while the bill quietly
     * over-settled underneath it.
     */
    $payment = Payment::factory()->create();
    $charge = Charge::factory()->create();

    $allocationId = financeAccepted(
        'payment_allocations',
        financeAllocationRow((int) $payment->getKey(), (int) $charge->getKey()),
    );

    expect(financeIndexColumns('payment_allocations', 'payment_allocations_payment_id_charge_id_unique'))
        ->toBe(['payment_id', 'charge_id'])
        ->and(financeIndexIsUnique('payment_allocations', 'payment_allocations_payment_id_charge_id_unique'))
        ->toBeTrue(
            'payment_allocations_payment_id_charge_id_unique exists but is not UNIQUE, so a '
            .'payment could be allocated against the same charge twice and both rows would sum '
            .'into the balance — over-settling a bill while every per-write invariant still '
            .'held.',
        );

    financeRefusedBy(
        'payment_allocations_payment_id_charge_id_unique',
        'payment_allocations',
        financeRowCopy('payment_allocations', $allocationId),
    );
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| staff_compensation
|--------------------------------------------------------------------------
*/

it('refuses a compensation rate of zero', function () {
    financeRefusedBy(
        'staff_compensation_amount_positive',
        'staff_compensation',
        financeCompensationRow((int) $this->actor->getKey(), ['amount' => '0.000']),
    );
})->group('finance-schema');

it('refuses a negative compensation rate', function () {
    // A negative rate is a deduction, which is a payroll_line_adjustments row.
    financeRefusedBy(
        'staff_compensation_amount_positive',
        'staff_compensation',
        financeCompensationRow((int) $this->actor->getKey(), ['amount' => '-2500.000']),
    );
})->group('finance-schema');

it('refuses a rate whose validity ends before it begins', function () {
    financeRefusedBy(
        'staff_compensation_effective_to_not_before_from',
        'staff_compensation',
        financeCompensationRow((int) $this->actor->getKey(), [
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-05-31',
        ]),
    );
})->group('finance-schema');

it('accepts a rate that ends on the day it begins', function () {
    // `>=`, not `>`. A one-day rate is legitimate and the constraint says so.
    financeAccepted(
        'staff_compensation',
        financeCompensationRow((int) $this->actor->getKey(), [
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-06-01',
        ]),
    );
})->group('finance-schema');

it('accepts the open current rate, whose validity never ends', function () {
    financeAccepted(
        'staff_compensation',
        financeCompensationRow((int) $this->actor->getKey(), ['effective_to' => null]),
    );
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| payroll_runs
|--------------------------------------------------------------------------
*/

it('refuses a monthly salary run with no period', function () {
    financeRefusedBy(
        'payroll_runs_period_matches_type',
        'payroll_runs',
        financePayrollRunRow((int) $this->actor->getKey(), [
            'type' => 'monthly_salary',
            'period_start' => null,
            'period_end' => null,
        ]),
    );
})->group('finance-schema');

it('refuses a monthly salary run with only half a period', function () {
    financeRefusedBy(
        'payroll_runs_period_matches_type',
        'payroll_runs',
        financePayrollRunRow((int) $this->actor->getKey(), [
            'type' => 'monthly_salary',
            'period_end' => null,
        ]),
    );
})->group('finance-schema');

it('refuses an instructor batch run carrying a period', function () {
    financeRefusedBy(
        'payroll_runs_period_matches_type',
        'payroll_runs',
        financePayrollRunRow((int) $this->actor->getKey(), [
            'type' => 'instructor_batch',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]),
    );
})->group('finance-schema');

it('refuses a run type nobody decided a period rule for', function () {
    /*
     * The intended side effect the migration records: enumerating the three
     * known types pins the column to them, so a typo or an invented fourth type
     * satisfies neither branch. A fourth run type needs its period rule decided
     * in that constraint rather than defaulted into.
     */
    financeRefusedBy(
        'payroll_runs_period_matches_type',
        'payroll_runs',
        financePayrollRunRow((int) $this->actor->getKey(), [
            'type' => 'weekly_bonus',
            'period_start' => null,
            'period_end' => null,
        ]),
    );
})->group('finance-schema');

it('accepts an adjustment run with no period', function () {
    financeAccepted(
        'payroll_runs',
        financePayrollRunRow((int) $this->actor->getKey(), [
            'type' => 'adjustment',
            'period_start' => null,
            'period_end' => null,
        ]),
    );
})->group('finance-schema');

it('refuses a run period that ends before it begins', function () {
    financeRefusedBy(
        'payroll_runs_period_end_not_before_start',
        'payroll_runs',
        financePayrollRunRow((int) $this->actor->getKey(), [
            'period_start' => '2026-07-31',
            'period_end' => '2026-07-01',
        ]),
    );
})->group('finance-schema');

it('refuses a finalized time with no finalizing actor', function () {
    // Design section 14 names this one specifically: prove it by inserting a run
    // with a finalized time and no actor, not by asserting a button is hidden.
    financeRefusedBy(
        'payroll_runs_finalization_columns_paired',
        'payroll_runs',
        financePayrollRunRow((int) $this->actor->getKey(), [
            'finalized_at' => now(),
            'finalized_by' => null,
        ]),
    );
})->group('finance-schema');

it('refuses a finalizing actor with no finalized time', function () {
    // The other direction. An approver with no approval time is as incoherent as
    // an approval with no approver, and only one of the two is the obvious case.
    financeRefusedBy(
        'payroll_runs_finalization_columns_paired',
        'payroll_runs',
        financePayrollRunRow((int) $this->actor->getKey(), [
            'finalized_at' => null,
            'finalized_by' => $this->actor->getKey(),
        ]),
    );
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| payroll_lines — the three shapes
|--------------------------------------------------------------------------
*/

it('refuses a payroll line that is not exactly one of the three shapes', function (string $shape, array $add, array $blank) {
    $run = PayrollRun::factory()->create();
    $user = User::factory()->create();
    $values = financePayrollShapeValues((int) $user->getKey());

    $row = financePayrollLineRow((int) $run->getKey(), (int) $user->getKey(), $shape, $values);

    foreach ($add as $column) {
        $row[$column] = $values[$column];
    }

    foreach ($blank as $column) {
        $row[$column] = null;
    }

    financeRefusedBy('payroll_lines_exactly_one_shape', 'payroll_lines', $row);
})->with([
    // Each name says what was done to design section 9's shape table.
    'a salary line with no frozen denominator' => ['salary', [], ['frozen_days_in_month']],
    'a salary line with no segment end' => ['salary', [], ['segment_end']],
    'a salary line with no rate row' => ['salary', [], ['staff_compensation_id']],
    'a salary line that also names an instructor assignment' => ['salary', ['batch_instructor_id'], []],
    'a salary line that also carries frozen hours' => ['salary', ['frozen_hours'], []],
    'a salary line that also corrects another line' => ['salary', ['corrects_payroll_line_id'], []],
    'an instructor line with no frozen hours' => ['instructor', [], ['frozen_hours']],
    'an instructor line with no assignment' => ['instructor', [], ['batch_instructor_id']],
    'an instructor line that also carries a salary segment' => ['instructor', ['segment_start', 'segment_end'], []],
    'an adjustment line with no reason' => ['adjustment', [], ['reason']],
    'an adjustment line that also names a rate row' => ['adjustment', ['staff_compensation_id'], []],
    'an adjustment line that also names an assignment' => ['adjustment', ['batch_instructor_id'], []],
    'a line carrying no shape at all' => ['adjustment', [], ['corrects_payroll_line_id', 'reason']],
])->group('finance-schema');

it('refuses a finalized line with no posting period', function () {
    $run = PayrollRun::factory()->create();
    $user = User::factory()->create();
    $values = financePayrollShapeValues((int) $user->getKey());

    financeRefusedBy(
        'payroll_lines_posting_period_required_when_finalized',
        'payroll_lines',
        financePayrollLineRow((int) $run->getKey(), (int) $user->getKey(), 'salary', $values, [
            'finalized_at' => now(),
            'posting_period_start' => null,
        ]),
    );
})->group('finance-schema');

it('persists a draft line with no posting period', function () {
    /*
     * THE POSITIVE CASE, and the one that makes the constraint's DIRECTION
     * right rather than merely strict. Design section 14 requires it by name.
     *
     * An instructor draft's posting month is the month it will eventually be
     * finalized in, which is unknown while it is still a draft — this is the
     * shape a biconditional constraint requires every draft to take, both
     * because it is knowable no other way for this shape and because design
     * section 9 says a draft line cannot carry the column at all. Only the null
     * side is exercised here; the refusal above proves the finalized side.
     */
    $run = PayrollRun::factory()->create();
    $user = User::factory()->create();
    $values = financePayrollShapeValues((int) $user->getKey());

    $id = financeAccepted(
        'payroll_lines',
        financePayrollLineRow((int) $run->getKey(), (int) $user->getKey(), 'instructor', $values, [
            'finalized_at' => null,
            'posting_period_start' => null,
        ]),
    );

    expect(DB::table('payroll_lines')->where('id', $id)->value('posting_period_start'))->toBeNull(
        'The draft was accepted but did not persist with a null posting period.',
    );
})->group('finance-schema');

it('refuses a draft line that carries a posting period', function () {
    /*
     * THE OTHER HALF OF THE BICONDITIONAL, and the one this file used to get
     * backwards: an earlier revision of this test asserted the opposite —
     * that a draft carrying a posting period was ACCEPTED — on the theory
     * that a salary draft's posting month is knowable the moment its segment
     * is computed. Design section 9 reads as a pairing rather than a
     * one-way implication ("a draft line cannot carry it"), and the reason it
     * matters operationally is that `posting_period_start` is the column every
     * period report groups and filters on (see the migration's own comment on
     * the column) — a draft carrying one would leak unposted wage cost into a
     * report that has no other way to exclude it.
     */
    $run = PayrollRun::factory()->create();
    $user = User::factory()->create();
    $values = financePayrollShapeValues((int) $user->getKey());

    financeRefusedBy(
        'payroll_lines_posting_period_required_when_finalized',
        'payroll_lines',
        financePayrollLineRow((int) $run->getKey(), (int) $user->getKey(), 'salary', $values, [
            'finalized_at' => null,
            'posting_period_start' => '2026-07-01',
        ]),
    );
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| payroll_lines — the two stored generated columns
|--------------------------------------------------------------------------
|
| Both indexes carry their value ONLY while finalized_at is set, and MySQL's
| unique index ignores NULLs. Half of that behaviour is the refusal; the other
| half is that drafts and other shapes collide with nothing. A test that proves
| only the refusal has tested the technique's cost and not its benefit — and the
| NULL side is the entire reason a generated column was chosen over a plain
| composite unique index, which would have made every draft rebuild a conflict.
*/

it('refuses a second finalized line for an instructor assignment already paid', function () {
    $first = PayrollLine::factory()->instructor()->finalized()->create();

    expect(financeIndexColumns('payroll_lines', 'payroll_lines_finalized_batch_instructor_unique'))
        ->toBe(['finalized_batch_instructor_id'])
        ->and(financeIndexIsUnique('payroll_lines', 'payroll_lines_finalized_batch_instructor_unique'))
        ->toBeTrue(
            'payroll_lines_finalized_batch_instructor_unique exists but is not UNIQUE, so the '
            .'generated column is being maintained at every write while preventing nothing.',
        );

    // A genuine duplicate: every column copied, so only the index can refuse it.
    financeRefusedBy(
        'payroll_lines_finalized_batch_instructor_unique',
        'payroll_lines',
        financeRowCopy('payroll_lines', (int) $first->getKey()),
    );
})->group('finance-schema');

it('lets any number of draft lines share one instructor assignment', function () {
    /*
     * The NULL side. A draft can be rebuilt and re-ticked as often as the
     * operator likes, because the generated column is NULL until finalization.
     */
    $draft = PayrollLine::factory()->instructor()->create();
    $row = financeRowCopy('payroll_lines', (int) $draft->getKey());

    financeAccepted('payroll_lines', $row);
    financeAccepted('payroll_lines', $row);

    $assignment = (int) $draft->batch_instructor_id;

    expect(DB::table('payroll_lines')->where('batch_instructor_id', $assignment)->count())->toBe(3)
        ->and(DB::table('payroll_lines')->whereNotNull('finalized_batch_instructor_id')->count())->toBe(
            0,
            'A draft line materialised a value into the generated column, which would make '
            .'every draft rebuild collide with the last one.',
        );
})->group('finance-schema');

it('lets drafts sit alongside the finalized line that paid their assignment', function () {
    // The mixed case: finalization does not lock the assignment out of drafts,
    // it locks it out of a second FINALIZED line.
    $finalized = PayrollLine::factory()->instructor()->finalized()->create();

    $draft = financeRowCopy('payroll_lines', (int) $finalized->getKey(), [
        'finalized_at' => null,
        'posting_period_start' => null,
    ]);

    financeAccepted('payroll_lines', $draft);
    financeAccepted('payroll_lines', $draft);

    expect(DB::table('payroll_lines')
        ->where('batch_instructor_id', (int) $finalized->batch_instructor_id)
        ->count())->toBe(3);
})->group('finance-schema');

it('refuses a second finalized line for a salary segment already paid', function () {
    $first = PayrollLine::factory()->finalized()->create();

    expect(financeIndexColumns('payroll_lines', 'payroll_lines_finalized_salary_segment_unique'))
        ->toBe(['finalized_salary_segment'])
        ->and(financeIndexIsUnique('payroll_lines', 'payroll_lines_finalized_salary_segment_unique'))
        ->toBeTrue(
            'payroll_lines_finalized_salary_segment_unique exists but is not UNIQUE, so the '
            .'same salary segment can be finalized twice.',
        );

    financeRefusedBy(
        'payroll_lines_finalized_salary_segment_unique',
        'payroll_lines',
        financeRowCopy('payroll_lines', (int) $first->getKey()),
    );
})->group('finance-schema');

it('lets any number of draft lines share one salary segment', function () {
    $draft = PayrollLine::factory()->create();
    $row = financeRowCopy('payroll_lines', (int) $draft->getKey());

    financeAccepted('payroll_lines', $row);
    financeAccepted('payroll_lines', $row);

    expect(DB::table('payroll_lines')->whereNotNull('finalized_salary_segment')->count())->toBe(
        0,
        'A draft salary line materialised a value into the generated column, so a draft run '
        .'could not be rebuilt without colliding with itself.',
    );
})->group('finance-schema');

it('does not collide two finalized salary segments that start on different days', function () {
    /*
     * The honest limit, asserted rather than assumed. The index catches EXACT
     * duplicates; two runs covering 1–15 January and 10–31 January produce
     * segments with different start dates and no index refuses them. Design
     * section 7 says so, and FinalizePayrollRunAction's lock is what catches the
     * overlap — this test records that the database does not, so a later reader
     * does not mistake the index for overlap protection.
     */
    $first = PayrollLine::factory()->finalized()->create();

    financeAccepted('payroll_lines', financeRowCopy('payroll_lines', (int) $first->getKey(), [
        'segment_start' => '2026-05-10',
        'segment_end' => '2026-05-31',
    ]));

    expect(DB::table('payroll_lines')->whereNotNull('finalized_salary_segment')->count())->toBe(2);
})->group('finance-schema');

it('does not collide finalized lines of the other two shapes', function () {
    /*
     * Both generated columns are NULL on a shape that has no assignment and no
     * segment, so any number of finalized adjustment lines coexist — which they
     * must: design section 7 allows multiple corrections against one original,
     * and they sum.
     */
    $corrected = PayrollLine::factory()->finalized()->create();
    $user = (int) $corrected->user_id;

    $adjustment = PayrollLine::factory()->adjustment()->finalized()->create([
        'user_id' => $user,
        'corrects_payroll_line_id' => $corrected->getKey(),
    ]);

    financeAccepted('payroll_lines', financeRowCopy('payroll_lines', (int) $adjustment->getKey()));
    financeAccepted('payroll_lines', financeRowCopy('payroll_lines', (int) $adjustment->getKey()));

    expect(DB::table('payroll_lines')
        ->where('corrects_payroll_line_id', $corrected->getKey())
        ->count())->toBe(3);

    // And a finalized instructor line for the same person does not collide with
    // that person's finalized salary line either — different columns, both
    // indexes satisfied.
    $instructor = PayrollLine::factory()->instructor()->finalized()->create(['user_id' => $user]);

    expect(DB::table('payroll_lines')->where('id', $instructor->getKey())->exists())->toBeTrue();
})->group('finance-schema');

/*
|--------------------------------------------------------------------------
| payroll_line_adjustments
|--------------------------------------------------------------------------
*/

it('refuses an adjustment of zero', function () {
    // A reason with no effect, which would still read in the audit trail as
    // though something had happened.
    $line = PayrollLine::factory()->create();

    financeRefusedBy(
        'payroll_line_adjustments_amount_not_zero',
        'payroll_line_adjustments',
        financeAdjustmentRow((int) $line->getKey(), (int) $this->actor->getKey(), ['amount' => '0.000']),
    );
})->group('finance-schema');

it('accepts a negative adjustment, because a deduction is half of what the column is for', function () {
    // `<>` rather than `> 0`. A positivity check here would pass the refusal
    // above and silently make deductions impossible.
    $line = PayrollLine::factory()->create();

    financeAccepted(
        'payroll_line_adjustments',
        financeAdjustmentRow((int) $line->getKey(), (int) $this->actor->getKey(), ['amount' => '-150.000']),
    );
})->group('finance-schema');
