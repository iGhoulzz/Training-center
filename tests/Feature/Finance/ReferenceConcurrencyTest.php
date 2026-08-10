<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\EnrollStudentAction;
use App\Domain\Enrollment\Data\EnrollStudentData;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Support\Reference;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| A genuine two-connection concurrency proof for Reference (design section 14)
|--------------------------------------------------------------------------
|
| ReferenceTest's "distinctness, not concurrency" test says plainly that its two
| inserts run sequentially, on one connection, inside one transaction — a real
| property, but not the one Task 1's definition of done names: "concurrent
| inserts produce no collision". This file is that proof.
|
| WHY THIS IS A SEPARATE FILE, NOT ADDED TO ReferenceTest.php
| -------------------------------------------------------------
| RefreshDatabase wraps every test in a transaction on the default connection
| and rolls it back at the end. A second, independent connection cannot see
| anything written inside that wrapper until it commits, and it never commits
| under RefreshDatabase — so a second-connection assertion in that file would
| see nothing, no matter how real the second connection is.
| FileLifecycleTransactionTest hits the identical wall and solves it the same
| way this file does: DatabaseMigrations instead of RefreshDatabase, which
| commits for real, kept in its own file separate from its RefreshDatabase
| sibling rather than mixed into it.
|
| HOW THE SECOND CONNECTION IS BUILT
| -------------------------------------
| The same mechanism FileLifecycleService::compensationConnectionName() uses in
| production: clone the default connection's configuration under a new name, so
| Laravel opens a genuinely independent PDO handle to the same physical database
| rather than reusing the one already open. See config/database.php — there is
| no second connection defined there for the same reason there was never one
| for the file-lifecycle compensation path: it is registered at runtime,
| pointing at whatever the default connection already is, so it works
| identically against SQLite in a quick local run and MySQL in CI without a
| second block of credentials to keep in sync.
|
| HOW "CONCURRENT" IS MADE REAL FROM ONE PHP PROCESS
| ------------------------------------------------------
| Two literal database sessions with OVERLAPPING open transactions is what
| "concurrent" means to the database — not two OS threads. This test drives
| that directly: a DB::listen() callback fires the instant the FIRST
| enrolment's placeholder INSERT lands on the primary connection, while that
| transaction is still open and its row locks still held, and — from inside
| that callback — runs the SECOND enrolment to completion on the independent
| connection, committing it before the first transaction is allowed to
| continue and commit its own reference UPDATE. MySQL genuinely holds two open
| transactions at once, on two different connections, for the middle of this
| test. The claim is not left as an assertion about the test's own control
| flow: the callback also reads connection B for connection A's exact
| still-uncommitted placeholder and requires it to be invisible there, which
| only holds if the two really are independent sessions.
|
| Independent students and independent batches, so the two enrolments cannot
| contend on `enrollments_student_id_batch_id_unique` or on either batch's own
| lockForUpdate() — a lock collision there would turn this into a serialization
| test rather than a distinctness one, and could deadlock the two connections
| against each other instead of proving anything.
*/

uses(DatabaseMigrations::class);

const REFERENCE_CONCURRENCY_SECOND_CONNECTION = 'reference_concurrency_second_connection';

afterEach(function () {
    DB::disconnect(REFERENCE_CONCURRENCY_SECOND_CONNECTION);
});

/**
 * Register the second connection, cloned from whatever the default already is.
 *
 * A fresh config entry under a new name rather than a reused one — matching
 * FileLifecycleService::compensationConnectionName() — because a distinct
 * connection object, and therefore a distinct PDO, is the entire point: reusing
 * the primary connection's object would make every "independent session"
 * assertion below vacuous.
 */
function referenceConcurrencySecondConnection(): string
{
    if (! is_array(config('database.connections.'.REFERENCE_CONCURRENCY_SECOND_CONNECTION))) {
        $default = (string) config('database.default');
        $configuration = config('database.connections.'.$default);

        config(['database.connections.'.REFERENCE_CONCURRENCY_SECOND_CONNECTION => $configuration]);
    }

    return REFERENCE_CONCURRENCY_SECOND_CONNECTION;
}

it('lets two concurrent enrolments on independent connections commit with distinct references and no surviving placeholder', function () {
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');
    $admin = $admin->fresh();

    // Independent everything: two students, two courses, two active batches.
    $studentA = Student::factory()->create();
    $studentB = Student::factory()->create();
    $batchA = Batch::factory()->for(Course::factory()->create())->active()->create();
    $batchB = Batch::factory()->for(Course::factory()->create())->active()->create();

    $primaryConnection = (string) config('database.default');
    $secondConnection = referenceConcurrencySecondConnection();

    $enroll = app(EnrollStudentAction::class);

    $interleaved = false;
    $secondEnrollment = null;
    $overlapObserved = null;

    /*
     * THE INTERLEAVING POINT. QueryExecuted fires synchronously, inside
     * connection A's still-open transaction, immediately after the placeholder
     * INSERT and before EnrollStudentAction's next statement (the reference
     * UPDATE). Everything the callback does below therefore genuinely
     * interleaves with connection A's open transaction rather than merely
     * running before or after it.
     */
    DB::listen(function (QueryExecuted $query) use (
        &$interleaved,
        &$secondEnrollment,
        &$overlapObserved,
        $enroll,
        $admin,
        $studentB,
        $batchB,
        $primaryConnection,
        $secondConnection,
    ): void {
        if ($interleaved
            || $query->connectionName !== $primaryConnection
            || ! str_contains(strtolower($query->sql), 'insert into `enrollments`')
        ) {
            return;
        }

        $interleaved = true;

        /*
         * The placeholder connection A's own transaction just wrote, still
         * uncommitted. Read from the query's own bindings rather than
         * re-derived from Reference, so this proves what was ACTUALLY sent
         * over the wire, not what the Action was merely expected to send.
         */
        $placeholderBinding = collect($query->bindings)
            ->first(fn (mixed $binding): bool => is_string($binding) && Reference::isPlaceholder($binding));

        /*
         * THE OVERLAP, PROVEN RATHER THAN ASSUMED. Connection A's transaction
         * is still open here — its own transaction level is greater than zero
         * — and a plain read on the INDEPENDENT connection B for that exact
         * placeholder must see nothing: MySQL does not let an uncommitted row
         * from one session leak into another. If this ever found a row, the
         * two connections would not actually be independent sessions and
         * everything that follows would be theatre rather than proof.
         */
        $overlapObserved = DB::connection($primaryConnection)->transactionLevel() > 0
            && $placeholderBinding !== null
            && DB::connection($secondConnection)->table('enrollments')
                ->where('reference', $placeholderBinding)
                ->doesntExist();

        $originalDefault = (string) config('database.default');
        config(['database.default' => $secondConnection]);

        try {
            // The SECOND enrolment, through the real Action, running to
            // completion — its own transaction opens, locks its own batch and
            // student, inserts its own placeholder, replaces it, and commits —
            // entirely on connection B, entirely before connection A's
            // transaction is allowed to proceed past this callback.
            $secondEnrollment = $enroll->execute($admin, new EnrollStudentData(
                (int) $studentB->getKey(),
                (int) $batchB->getKey(),
            ));
        } finally {
            config(['database.default' => $originalDefault]);
        }
    });

    $firstEnrollment = $enroll->execute($admin, new EnrollStudentData(
        (int) $studentA->getKey(),
        (int) $batchA->getKey(),
    ));

    expect($interleaved)->toBeTrue(
        'The listener never observed the first INSERT INTO `enrollments`, so the second '
        .'enrolment never ran while the first transaction was open and this test proved '
        .'nothing about concurrency.',
    );

    expect($overlapObserved)->toBeTrue(
        'Connection B could see connection A\'s uncommitted placeholder (or the overlap check '
        .'could not be performed at all), so the two connections were not genuinely independent '
        .'open sessions at the moment the second enrolment ran.',
    );

    expect($secondEnrollment)->toBeInstanceOf(Enrollment::class);
    assert($secondEnrollment instanceof Enrollment);

    $firstReference = (string) $firstEnrollment->fresh()->reference;
    $secondReference = (string) $secondEnrollment->fresh()->reference;

    $pattern = '/^ENR-\d{4}-\d{6,}$/';

    expect($firstReference)->toMatch($pattern)
        ->and($secondReference)->toMatch($pattern)
        ->and($firstReference)->not->toBe($secondReference)
        ->and(Reference::isPlaceholder($firstReference))->toBeFalse()
        ->and(Reference::isPlaceholder($secondReference))->toBeFalse();

    // Both rows exist, committed, readable from the ordinary default connection
    // now that everything above has restored it.
    expect(Enrollment::query()->whereKey($firstEnrollment->getKey())->exists())->toBeTrue()
        ->and(Enrollment::query()->whereKey($secondEnrollment->getKey())->exists())->toBeTrue();

    // No placeholder anywhere on either row, seen from either connection.
    foreach ([$primaryConnection, $secondConnection] as $connection) {
        $references = DB::connection($connection)->table('enrollments')
            ->whereIn('id', [$firstEnrollment->getKey(), $secondEnrollment->getKey()])
            ->pluck('reference')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

        expect($references)->toHaveCount(2);

        foreach ($references as $reference) {
            expect(Reference::isPlaceholder($reference))->toBeFalse(
                "A placeholder survived into enrollments.reference, seen from connection [{$connection}]: {$reference}",
            );
        }
    }
});
