<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\EnrollStudentAction;
use App\Domain\Enrollment\Data\EnrollStudentData;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\Reference;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Reference — the three document series, and the placeholder (design §2)
|--------------------------------------------------------------------------
|
| Two of design §14's required tests live here and both depend on
| Reference::isPlaceholder() being EXACT rather than uuid-shaped: no row survives
| a transaction holding a placeholder, and no entry anywhere in the append-only
| activity log carries one. A test whose predicate is approximate is a test that
| can pass for the wrong reason, so the predicate is pinned first and the two
| properties are asserted with it afterwards.
*/
uses(RefreshDatabase::class);

/** The activity log's table. Named once; every scan below reads it raw. */
const ACTIVITY_LOG_TABLE = 'activity_log';

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');

    $this->admin = $admin->refresh();
    $this->batch = Batch::factory()->for(Course::factory()->create())->active()->create();
    $this->enroll = app(EnrollStudentAction::class);

    $this->enrolSomeone = fn () => $this->enroll->execute($this->admin, new EnrollStudentData(
        (int) Student::factory()->create()->getKey(),
        (int) $this->batch->getKey(),
    ));

    /**
     * Every value in the activity log, flattened to one searchable string.
     *
     * The WHOLE table, every column of every row — not the subject's own
     * entries. A placeholder that leaked through some other model's `properties`
     * would be just as permanent, and an append-only log has no delete path for
     * any role to clean it up with.
     */
    $this->wholeLog = fn (): string => DB::table(ACTIVITY_LOG_TABLE)
        ->get()
        ->map(fn (object $row): string => (string) json_encode(get_object_vars($row)))
        ->implode(' ');
});

/*
|--------------------------------------------------------------------------
| The format, for all three series
|--------------------------------------------------------------------------
*/

it('formats {PREFIX}-{year}-{id padded to 6} for every series', function (string $prefix, string $expected) {
    expect(Reference::format($prefix, 2026, 42))->toBe($expected);
})->with([
    'enrolment' => [Reference::ENROLLMENT_PREFIX, 'ENR-2026-000042'],
    'charge' => [Reference::CHARGE_PREFIX, 'CHG-2026-000042'],
    'receipt' => [Reference::PAYMENT_PREFIX, 'RCT-2026-000042'],
]);

it('pads to six digits and grows rather than truncating past a million', function () {
    /*
     * An id past 999,999 produces a seventh digit. The number stays unique and
     * stays correct; only its lexicographic sort order changes, and nothing
     * sorts on the string. Truncating would mint a duplicate on a UNIQUE column.
     */
    expect(Reference::format(Reference::CHARGE_PREFIX, 2026, 1))->toBe('CHG-2026-000001')
        ->and(Reference::format(Reference::CHARGE_PREFIX, 2026, 999999))->toBe('CHG-2026-999999')
        ->and(Reference::format(Reference::CHARGE_PREFIX, 2026, 1000000))->toBe('CHG-2026-1000000');
});

it('mints nothing for a series it does not know', function () {
    /*
     * A typo must not produce an `XXX-2026-000001` in a column whose entire value
     * is that it is recognisable when read down a phone.
     */
    Reference::format('XXX', 2026, 1);
})->throws(InvalidArgumentException::class);

it('refuses a year or an id a reference cannot be built from', function (int $year, int $id) {
    Reference::format(Reference::ENROLLMENT_PREFIX, $year, $id);
})->throws(InvalidArgumentException::class)->with([
    'three-digit year' => [999, 1],
    'five-digit year' => [10000, 1],
    'year zero' => [0, 1],
    // An id of 0 or below is a row that does not exist, and a reference names a
    // row that does.
    'id zero' => [2026, 0],
    'negative id' => [2026, -1],
]);

/*
|--------------------------------------------------------------------------
| The placeholder predicate, which two required tests rest on
|--------------------------------------------------------------------------
*/

it('recognises exactly what it minted', function () {
    $placeholder = Reference::placeholder();

    expect(Reference::isPlaceholder($placeholder))->toBeTrue()
        ->and($placeholder)->toStartWith(Reference::PLACEHOLDER_MARKER)
        // Case-insensitive on the hex, for a value something else upper-cased on
        // the way past. For a detector, catching that is the right direction to
        // be wrong in.
        ->and(Reference::isPlaceholder(Str::upper($placeholder)))->toBeTrue()
        // Trimmed before matching, deliberately: a value carrying stray
        // whitespace is still CAUGHT. Over-reporting fails a test loudly; missing
        // lets a placeholder ship into a log with no delete path.
        ->and(Reference::isPlaceholder("  {$placeholder}\n"))->toBeTrue();
});

it('does not call a bare UUID a placeholder', function () {
    /*
     * THE CASE A LOOSE "LOOKS LIKE A UUID" CHECK GETS WRONG, and the reason the
     * predicate is exact rather than shaped.
     *
     * The system stores UUIDs that are not placeholders — `payments.idempotency_key`
     * is one, minted fresh for every payment. A detector that answered "uuid?"
     * would report every one of them as a leaked placeholder, and the two required
     * scans below would fail for a reason that has nothing to do with the property
     * they exist to prove. Worse, the fix for such a false positive is to loosen
     * the scan, which is how a real leak eventually slips past it.
     */
    $uuid = Str::uuid()->toString();

    expect(Reference::isPlaceholder($uuid))->toBeFalse()
        ->and(Reference::isPlaceholder(Str::upper($uuid)))->toBeFalse()
        // The idempotency key by the name it actually has in the schema.
        ->and(Reference::isPlaceholder((string) Payment::factory()->create()->idempotency_key))->toBeFalse();
});

it('does not call anything else a placeholder either', function (string $value) {
    expect(Reference::isPlaceholder($value))->toBeFalse();
})->with([
    // A real reference from each series. None can ever satisfy the predicate.
    'an enrolment reference' => ['ENR-2026-000042'],
    'a charge reference' => ['CHG-2026-000042'],
    'a receipt reference' => ['RCT-2026-000042'],
    'empty' => [''],
    // The marker without a UUID behind it, and a UUID without the marker.
    'the marker alone' => [Reference::PLACEHOLDER_MARKER],
    'the marker with rubbish' => [Reference::PLACEHOLDER_MARKER.'not-a-uuid'],
    'the marker with a truncated uuid' => [Reference::PLACEHOLDER_MARKER.'0d3d0b8e-1f4a-4c1e-9a2b-000000000'],
    'a uuid with the wrong marker' => ['PLACEHOLDER'.Str::uuid()->toString()],
]);

it('answers "is this a placeholder", not "does this text contain one"', function () {
    /*
     * The pattern is anchored at both ends, and the `D` modifier makes `$` mean
     * end of string rather than PCRE's default "or just before a final newline".
     * Both halves matter: a value with a placeholder EMBEDDED in it is not a
     * placeholder, and the class docblock directs scanners at PLACEHOLDER_MARKER
     * as a substring instead — which is exactly what the log scan below does.
     */
    $placeholder = Reference::placeholder();

    expect(Reference::isPlaceholder("reference={$placeholder}"))->toBeFalse()
        ->and(Reference::isPlaceholder($placeholder.'-suffix'))->toBeFalse()
        // An embedded newline, not a trailing one — there is more text after
        // it, not just a line ending at the very end of the string. The
        // anchors refuse it regardless of the `D` modifier: PCRE's plain `$`
        // only tolerates a newline immediately before the absolute end of the
        // subject, and this one is not there.
        ->and(Reference::isPlaceholder($placeholder."\nENR-2026-000001"))->toBeFalse()
        // And the substring scan the docblock points at DOES see it.
        ->and(str_contains("reference={$placeholder}", Reference::PLACEHOLDER_MARKER))->toBeTrue();
});

it('sizes the column for the longest value it will ever hold', function () {
    /*
     * The longest value is a PLACEHOLDER — marker plus a 36-character UUID — not
     * a real reference, which is 15. Sizing the column from the real format would
     * make every insert fail on a value nobody thought to measure.
     */
    expect(strlen(Reference::placeholder()))->toBeLessThanOrEqual(Reference::COLUMN_LENGTH)
        ->and(strlen(Reference::placeholder()))
        ->toBeGreaterThan(strlen(Reference::format(Reference::CHARGE_PREFIX, 2026, 42)));
});

/*
|--------------------------------------------------------------------------
| Concurrency: the placeholder is a UUID *because* the column is UNIQUE
|--------------------------------------------------------------------------
*/

it('mints a distinct placeholder every call, so concurrent inserts cannot collide', function () {
    /*
     * `reference` is NOT NULL UNIQUE from the first byte, so two rows inserted
     * concurrently must not carry the same placeholder. A single-call test says
     * nothing about that; the count is large enough that a constant, a
     * per-request memo or a second-resolution seed would all show up.
     */
    $placeholders = array_map(
        static fn (): string => Reference::placeholder(),
        range(1, 500),
    );

    expect(array_unique($placeholders))->toHaveCount(500);

    foreach ($placeholders as $placeholder) {
        expect(Reference::isPlaceholder($placeholder))->toBeTrue();
    }
});

it('gives two enrolments inserted in one open transaction distinct references — a distinctness check, not a concurrency test', function () {
    /*
     * THIS IS A DISTINCTNESS CHECK, NOT A CONCURRENCY TEST, and it is worth
     * saying plainly rather than the way this test used to put it — "as close
     * as a single-process test can get" — which reads as satisfying the plan's
     * "concurrent inserts produce no collision" clause without actually doing
     * so. Both inserts here run sequentially, on the SAME connection, inside
     * the SAME transaction: nothing overlaps in time, and no second session is
     * ever involved. What this DOES prove, and it is real: two
     * placeholder-carrying rows can coexist uncommitted without colliding on
     * the UNIQUE index on `reference`, and both resolve to distinct real
     * references once committed.
     *
     * THE GENUINE TWO-CONNECTION VERSION NOW EXISTS, in
     * ReferenceConcurrencyTest.php — this docblock previously argued one could
     * not be written, on the grounds that there is no lock here for a second
     * connection to race and that the property protecting against a collision
     * is Reference::placeholder()'s own UUID randomness rather than anything
     * the database serialises. That argument explains why a second connection
     * cannot be made to collide on the placeholder; it does not follow from it
     * that a second connection has nothing to prove. Two independent
     * EnrollStudentAction calls, on two independent connections, with one
     * transaction genuinely open while the other runs to completion, is a
     * different and stronger claim than this test makes — it exercises the
     * batch lock, the student lock and the placeholder-then-update sequence
     * under real interleaving rather than under one session's own ordering.
     * That file lives apart from this one because it needs DatabaseMigrations
     * rather than RefreshDatabase — see its own docblock — not because the
     * property belongs elsewhere conceptually. This test is kept because the
     * uncommitted-coexistence property it proves is real and is not what the
     * other file proves.
     */
    [$first, $second] = DB::transaction(fn (): array => [
        ($this->enrolSomeone)(),
        ($this->enrolSomeone)(),
    ]);

    expect((string) $first->fresh()->reference)->not->toBe((string) $second->fresh()->reference)
        ->and((string) $first->fresh()->reference)->toBe(sprintf('ENR-%d-%06d', now()->timezone('Africa/Tripoli')->year, (int) $first->getKey()))
        ->and((string) $second->fresh()->reference)->toBe(sprintf('ENR-%d-%06d', now()->timezone('Africa/Tripoli')->year, (int) $second->getKey()));
});

it('depends on fresh UUIDs, which frozen ones are not', function () {
    /*
     * The docblock's own caveat, asserted rather than left as prose: a caller
     * that has frozen UUIDs gets the SAME placeholder twice, and two such rows in
     * one transaction collide on the unique index. That is the constraint
     * working, not a defect here — but it is a real trap for a test author, so it
     * is pinned where somebody will find it.
     */
    Str::freezeUuids(function (): void {
        expect(Reference::placeholder())->toBe(Reference::placeholder());
    });

    // And released again, so the freeze cannot leak into whatever runs next.
    expect(Reference::placeholder())->not->toBe(Reference::placeholder());
});

/*
|--------------------------------------------------------------------------
| Required test: no row survives a transaction holding a placeholder (§14)
|--------------------------------------------------------------------------
*/

it('leaves no placeholder in any reference column, through the real Action path', function () {
    /*
     * Driven through EnrollStudentAction rather than through the factory, because
     * the Action is where the two-write mechanism actually lives: insert carrying
     * a placeholder, UPDATE to the real value, both inside one transaction. A
     * factory that happened to do it correctly would prove nothing about the
     * production path.
     *
     * Three enrolments, then a charge and a payment, so all three series are
     * covered — the property is about the column, not about one table.
     */
    foreach (range(1, 3) as $ignored) {
        ($this->enrolSomeone)();
    }

    Charge::factory()->create();
    Payment::factory()->create();

    /*
     * A minimum rather than an exact count: ChargeFactory mints its own enrolment
     * — `charges.enrollment_id` is UNIQUE, so it cannot share one — and that row
     * comes through EnrollmentFactory instead of the Action. Both paths carry the
     * same obligation, so covering both is the point; pinning the total would
     * only pin today's factory graph.
     */
    $minimums = [
        'enrollments' => 3,
        'charges' => 1,
        'payments' => 1,
    ];

    foreach ($minimums as $table => $minimum) {
        $references = DB::table($table)->pluck('reference')
            ->map(fn (mixed $reference): string => (string) $reference)
            ->all();

        expect(count($references))->toBeGreaterThanOrEqual(
            $minimum,
            'Only '.count($references)." rows in {$table}, so this scan proves less than it looks like it does.",
        )->and(array_unique($references))->toHaveCount(count($references));

        foreach ($references as $reference) {
            expect(Reference::isPlaceholder($reference))->toBeFalse(
                "A placeholder survived into {$table}.reference: {$reference}",
            );
        }
    }
});

/*
|--------------------------------------------------------------------------
| Required test: no activity log entry anywhere carries a placeholder (§14)
|--------------------------------------------------------------------------
*/

it('never lets a placeholder reach the append-only activity log', function () {
    /*
     * THE HALF THAT SHARING A TRANSACTION DOES NOT SOLVE.
     *
     * RecordsActivity logs on model events. The insert fires `created` and would
     * record `reference = <uuid>`; the replacement fires `updated` and would
     * record a change from the uuid to the real value. Both commit with
     * everything else, into a table that has no delete path for any role — so a
     * leak here is permanent. `reference` is excluded from auditedAttributes() on
     * every model that carries one, and this is the assertion that the exclusion
     * is still there.
     *
     * THE SCAN IS THE WHOLE TABLE, BY MARKER SUBSTRING. Not the enrolment's own
     * entries, and not isPlaceholder() on a column: a placeholder in the log is
     * embedded inside a JSON `properties` blob, which the exact predicate
     * correctly refuses to call a placeholder. Reference's docblock names this
     * distinction and PLACEHOLDER_MARKER is public so the scanner can use it
     * rather than re-deriving a pattern that could drift.
     */
    $this->actingAs($this->admin);

    foreach (range(1, 3) as $ignored) {
        ($this->enrolSomeone)();
    }

    Charge::factory()->create();
    Payment::factory()->create();

    $log = $this->wholeLog;

    expect(DB::table(ACTIVITY_LOG_TABLE)->count())->toBeGreaterThan(0, 'Nothing was logged, so the scan below is vacuous.')
        ->and($log())->not->toContain(Reference::PLACEHOLDER_MARKER);
});

it('files no phantom "reference changed" entry when the placeholder is replaced', function () {
    /*
     * The third assertion the leak needs, and the one the marker scan alone
     * cannot make. An excluded column that still produced an empty `updated`
     * entry would pass the scan and still fill the log with a row per enrolment
     * saying nothing — dontLogEmptyChanges() is what prevents it, and this is
     * where that is pinned.
     */
    $this->actingAs($this->admin);

    $enrollment = ($this->enrolSomeone)();

    $entries = DB::table(ACTIVITY_LOG_TABLE)
        ->where('subject_type', $enrollment->getMorphClass())
        ->where('subject_id', $enrollment->getKey())
        ->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->event)->toBe('created')
        ->and((string) json_encode(get_object_vars($entries->first())))->not->toContain('reference');
});

it('can actually find a placeholder in the log, if one is ever there', function () {
    /*
     * THE CONTROL. The two scans above are only worth their runtime if they can
     * fail, and a scan over a log that happens to be clean agrees with itself.
     * So a placeholder is written into the log DELIBERATELY — through the raw
     * table, which is the only way in, since nothing in the application can
     * produce one — and the scan is required to see it.
     *
     * The row never leaves this test: RefreshDatabase rolls it back, and the
     * append-only rule is about what the application may delete, not about what a
     * test may insert into its own transaction.
     */
    $placeholder = Reference::placeholder();

    DB::table(ACTIVITY_LOG_TABLE)->insert([
        'log_name' => 'default',
        'description' => 'created',
        'properties' => (string) json_encode(['attributes' => ['reference' => $placeholder]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $log = $this->wholeLog;

    expect($log())->toContain(Reference::PLACEHOLDER_MARKER)
        ->and($log())->toContain($placeholder);
});
