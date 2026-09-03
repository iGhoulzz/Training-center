<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\IssueStudentCertificateAction;
use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\EnrollmentMutex;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| The certificate register's own race: a single connection cannot prove it
|--------------------------------------------------------------------------
|
| Two connections racing to ISSUE for the SAME completed enrolment with no
| certificate yet: one must win and the other must see it already issued and
| refuse, typed — never two rows and never a raw driver error reaching the
| caller (design section 6.5). The first test below proves that OUTCOME with
| two genuine, simultaneous processes.
|
| THAT TEST ALONE CANNOT PROVE THE LOCK MATTERS, AND SAYING SO WOULD HAVE
| BEEN WRONG — MEASURED, NOT ASSUMED
| ------------------------------------------------------------------------------
| An earlier version of this file claimed the two-worker race would fail if
| IssueStudentCertificateAction::hasValidCertificate()'s ->lockForUpdate()
| were removed, using each worker's own insert-attempt count as the signal.
| Mutation-tested against this project's actual MySQL: it does not. Two
| independent facts both backstop the outcome regardless of that lock —
|
|   1. uniq_valid_certificate_per_enrollment refuses a second committed
|      `valid` row unconditionally, so "never two rows" holds with or
|      without the lock; rethrowUnlessReferenceCollision() converts that
|      collision into the same CertificateAlreadyIssuedException either way.
|   2. Gate::forUser($actor)->authorize(...), called once per Action
|      invocation before hasValidCertificate() ever runs, populates Spatie's
|      permission cache on a cold run — an `insert ... on duplicate key
|      update` against Laravel's database cache store. That write was found,
|      by direct measurement (query-logged and confirmed against a
|      single-connection reproduction with and without it), to make the
|      transaction's LATER ordinary reads return newly committed data from
|      OTHER connections instead of the REPEATABLE READ snapshot that was
|      current when the transaction began — even though nothing in this
|      Action's own code takes a further lock or read to explain it. Priming
|      that cache before the race removed the effect in a controlled
|      single-connection reproduction, but did not reproduce it reliably
|      across two real worker processes, so it is recorded here as a
|      measured fact about this codebase's actual behaviour rather than
|      built into an assertion that would then rest on it.
|
| Given both of those, an assertion of the SHAPE "the loser's insert count is
| 0" cannot be trusted to mean what it claims — it could pass for a reason
| that has nothing to do with hasValidCertificate()'s own lock. The second
| test in this file proves the lock directly instead: two genuine database
| connections, no subprocess, no Gate::authorize() in between to confound the
| reading — see that test's own docblock for the mechanism and for the
| recorded mutation failure.
|
| Modelled directly on CompletionConcurrencyTest's parent/subprocess shape.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function certificateWorkerEnvironment(): array
{
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");

    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) ($database['host'] ?? ''),
        'DB_PORT' => (string) ($database['port'] ?? ''),
        'DB_DATABASE' => (string) ($database['database'] ?? ''),
        'DB_USERNAME' => (string) ($database['username'] ?? ''),
        'DB_PASSWORD' => (string) ($database['password'] ?? ''),
    ];
}

/**
 * $startPath is optional: when given, the worker signals $readyPath and then
 * BLOCKS IN PHP (not in the database) until $startPath appears, before doing
 * any of its real work — the same synchronisation CompletionConcurrencyTest
 * uses so both workers' lock attempts genuinely contend.
 */
function certificateIssueWorker(
    int $actorId,
    int $enrollmentId,
    string $readyPath,
    string $resultPath,
    ?string $startPath = null,
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $enrollmentId, $readyPath, $resultPath, $startPath] = $_SERVER['argv'];
        $startPath = $startPath === '' ? null : $startPath;

        try {
            $result = Illuminate\Support\Facades\DB::transaction(
                static function () use ($actorId, $enrollmentId, $readyPath, $startPath): array {
                    /*
                     * Establish the old consistent-read snapshot deliberately,
                     * exactly as CompletionConcurrencyTest does — and exactly
                     * what EnrollmentMutex::acquire() itself does as its own
                     * FIRST statement, an ordinary unlocked read.
                     */
                    App\Domain\Enrollment\Models\Enrollment::query()->count();
                    file_put_contents($readyPath, 'ready');

                    if ($startPath !== null) {
                        $deadline = microtime(true) + 60;

                        while (! file_exists($startPath) && microtime(true) < $deadline) {
                            usleep(10_000);
                        }
                    }

                    $enrollment = App\Domain\Enrollment\Models\Enrollment::query()->findOrFail((int) $enrollmentId);

                    $certificate = app(App\Domain\Enrollment\Actions\IssueStudentCertificateAction::class)->execute(
                        App\Models\User::query()->findOrFail((int) $actorId),
                        $enrollment,
                    );

                    return ['outcome' => 'issued', 'certificate_id' => $certificate->getKey()];
                },
            );
        } catch (App\Domain\Enrollment\Exceptions\CertificateAlreadyIssuedException) {
            $result = ['outcome' => 'refused_already_issued'];
        } catch (Illuminate\Auth\Access\AuthorizationException) {
            $result = ['outcome' => 'refused_unauthorized'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, (string) $enrollmentId, $readyPath, $resultPath, (string) $startPath],
        base_path(),
        certificateWorkerEnvironment(),
    );
}

/**
 * Wait for $path to exist, or fail loudly. Returns whether it appeared, so
 * callers assert on it rather than trusting a silent timeout.
 */
function waitForCertificateSignal(string $path, float $seconds = 60): bool
{
    $deadline = microtime(true) + $seconds;

    while (! File::exists($path) && microtime(true) < $deadline) {
        usleep(25_000);
    }

    return File::exists($path);
}

/*
|--------------------------------------------------------------------------
| Probe 1: two real connections, one outcome each, never two rows
|--------------------------------------------------------------------------
*/

it('lets one of two simultaneous issuances through and types the other\'s refusal, and never leaves two rows', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->active()->create();
    $enrollment = Enrollment::factory()->for($batch)->completed()->create();

    $token = (string) Str::uuid();
    $startPath = storage_path("framework/testing/issue-race-{$token}-start");
    $readyPathA = storage_path("framework/testing/issue-race-{$token}-ready-a");
    $resultPathA = storage_path("framework/testing/issue-race-{$token}-result-a.json");
    $readyPathB = storage_path("framework/testing/issue-race-{$token}-ready-b");
    $resultPathB = storage_path("framework/testing/issue-race-{$token}-result-b.json");

    $workerA = certificateIssueWorker((int) $actor->getKey(), (int) $enrollment->getKey(), $readyPathA, $resultPathA, $startPath);
    $workerB = certificateIssueWorker((int) $actor->getKey(), (int) $enrollment->getKey(), $readyPathB, $resultPathB, $startPath);

    $allPaths = [$startPath, $readyPathA, $resultPathA, $readyPathB, $resultPathB];

    try {
        $workerA->start();
        $workerB->start();

        expect(waitForCertificateSignal($readyPathA))->toBeTrue('Worker A never established its old snapshot.')
            ->and(waitForCertificateSignal($readyPathB))->toBeTrue('Worker B never established its old snapshot.');

        expect($workerA->isRunning())->toBeTrue('Worker A finished before the starting gun.')
            ->and($workerB->isRunning())->toBeTrue('Worker B finished before the starting gun.')
            ->and(File::exists($resultPathA))->toBeFalse()
            ->and(File::exists($resultPathB))->toBeFalse();

        // THE STARTING GUN. Both workers were already blocked polling for this.
        File::put($startPath, 'go');

        $workerA->wait();
        $workerB->wait();

        expect($workerA->isSuccessful())->toBeTrue($workerA->getErrorOutput())
            ->and($workerB->isSuccessful())->toBeTrue($workerB->getErrorOutput());

        $resultA = json_decode((string) File::get($resultPathA), true, flags: JSON_THROW_ON_ERROR);
        $resultB = json_decode((string) File::get($resultPathB), true, flags: JSON_THROW_ON_ERROR);

        $outcomes = collect([$resultA['outcome'], $resultB['outcome']])->sort()->values()->all();

        expect($outcomes)->toBe(
            ['issued', 'refused_already_issued'],
            'Worker A reported ['.$resultA['outcome'].'] and worker B reported ['.$resultB['outcome'].']; '
            .'expected exactly one issued and one typed refusal for racing the same enrolment.',
        );

        // Exactly one row, and it is VALID — never two, and no raw driver
        // error reached either worker (both results are typed outcomes).
        $rows = StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->get();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->status)->toBe(CertificateStatus::Valid);
    } finally {
        foreach ([$workerA, $workerB] as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        File::delete($allPaths);
    }
})->group('enrollment');

/*
|--------------------------------------------------------------------------
| Probe 2: hasValidCertificate() must be a LOCKING read — the mutation proof
|--------------------------------------------------------------------------
|
| A single test process, two REAL database connections, and no subprocess —
| deliberately simpler than probe 1, and it is what actually isolates the
| lock rather than the database constraint or the Gate::authorize() side
| effect probe 1's own docblock records.
|
| THE SHAPE
| ---------
| 1. This connection opens a transaction and takes an ordinary (non-locking)
|    read — App\Domain\Enrollment\Models\Enrollment::query()->count() —
|    exactly the statement that fixes a REPEATABLE READ snapshot, and exactly
|    what EnrollmentMutex::acquire() itself performs as ITS OWN first
|    statement in the real Action.
| 2. A SECOND, fully independent connection — genuinely separate, confirmed
|    by comparing CONNECTION_ID() — inserts a `valid` certificate for this
|    enrolment and commits, entirely outside this transaction.
|
|    THIS PRECEDES THE MUTEX, AND IT HAS TO.
|    `student_certificates.enrollment_id` is a foreign key to `enrollments`,
|    so this INSERT takes a shared lock on the parent enrolment row in order
|    to validate it — and EnrollmentMutex holds an EXCLUSIVE lock on exactly
|    that row. An earlier version of this file ran the mutex first and could
|    therefore never pass: the secondary connection blocked until it died of
|    `Lock wait timeout exceeded`, and step 4's assertion was never reached
|    at all. The order below is not a preference, it is the only order that
|    runs.
| 3. This connection then takes the real batch and enrolment locks via the
|    real EnrollmentMutex.
|
|    WHAT ISOLATES THE CERTIFICATE CHECK is not that the enrolment lock was
|    taken first — an earlier version of this docblock claimed that, and it
|    described the arrangement above that cannot execute. It is that the
|    mutex never locks `student_certificates` AT ALL, so the only thing that
|    can make step 4 see the new row is that check's OWN locking read.
|
|    A locking read returns the latest committed row but does NOT advance
|    this transaction's consistent-read view, so an ordinary read taken after
|    this step is still served from step 1's snapshot. That is what keeps
|    step 4 able to tell a locking check from an ordinary one.
| 4. This connection calls the REAL, container-resolved
|    IssueStudentCertificateAction::hasValidCertificate() directly, via
|    reflection (PrivateFileAccessTest's redirectTo() test is this
|    codebase's existing precedent for reaching a private method this way).
|    No Gate::authorize() runs anywhere in this test, so there is no cache
|    write to confound the reading — see probe 1's docblock for why that
|    matters.
|
| With the lock in place, step 4 must see step 3's commit — a LOCKING read
| is never served from a snapshot, however old. Without it, step 4 is an
| ordinary read served from the snapshot step 1 fixed, which predates step 3
| entirely, and so it misses the row.
*/

it('sees a certificate committed by another connection after this transaction\'s snapshot was fixed, because the check is a locking read', function () {
    $this->seed(RolePermissionSeeder::class);

    $issuedBy = User::factory()->create(['is_active' => true]);

    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->active()->create();
    $enrollment = Enrollment::factory()->for($batch)->completed()->create();

    // A second, independent database connection to the SAME database —
    // never a real second process here, this probe does not need one.
    Config::set('database.connections.certificate_probe_secondary', Config::get('database.connections.'.config('database.default')));
    DB::purge('certificate_probe_secondary');
    $secondary = DB::connection('certificate_probe_secondary');

    $primaryId = DB::connection()->selectOne('select connection_id() as id')->id;
    $secondaryId = $secondary->selectOne('select connection_id() as id')->id;
    expect($secondaryId)->not->toBe($primaryId, 'The "secondary" connection is not actually a separate MySQL session.');

    DB::beginTransaction();
    $transactionEnded = false;

    try {
        // Step 1: fix this transaction's REPEATABLE READ snapshot.
        Enrollment::query()->count();

        /*
         * Step 2: a genuinely separate connection inserts and commits, entirely
         * outside this transaction — this is what step 1's snapshot cannot see.
         *
         * THIS MUST COME BEFORE THE MUTEX IS ACQUIRED, and the reason is a
         * property of InnoDB rather than a matter of taste.
         * `student_certificates.enrollment_id` is a foreign key to
         * `enrollments`, so this INSERT takes a shared lock on the parent
         * enrolment row in order to validate it. EnrollmentMutex holds an
         * EXCLUSIVE lock on exactly that row. Written the other way round, the
         * secondary connection blocks until it dies of `Lock wait timeout
         * exceeded` and the assertion below is never reached — an earlier
         * version of this test did precisely that and could never have passed.
         *
         * NOTHING IS LOST BY THE MOVE. The snapshot was fixed at step 1, so
         * this commit is still invisible to an ordinary read taken afterwards,
         * which is the entire property under test. It is also the more faithful
         * race: IssueStudentCertificateAction's CLASS docblock describes a row committed
         * while this transaction sits BLOCKED waiting for the batch lock —
         * which is to say before the mutex is held, exactly as ordered here.
         */
        $secondary->table('student_certificates')->insert([
            'enrollment_id' => $enrollment->getKey(),
            'reference_number' => 'TC-'.now()->year.'-PROBE2XXX',
            'student_name' => 'Probe Student',
            'course_name' => 'Probe Course',
            'completed_on' => now()->toDateString(),
            'issued_at' => now(),
            'issued_by' => $issuedBy->getKey(),
            'status' => 'valid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Step 3: the real locks, via the real EnrollmentMutex.
         *
         * A locking read returns the latest committed row, but it does NOT
         * advance this transaction's consistent-read snapshot. An ordinary read
         * of `student_certificates` after this line is therefore still served
         * from the snapshot step 1 fixed, which is what keeps the assertion
         * below able to tell a locking check from an ordinary one.
         */
        $held = app(EnrollmentMutex::class)->acquire($enrollment);

        // Step 4: the real method, via reflection, on a real
        // container-resolved Action instance.
        $action = app(IssueStudentCertificateAction::class);
        $method = new ReflectionMethod($action, 'hasValidCertificate');

        /*
         * THE ASSERTION THIS FILE EXISTS FOR.
         *
         * If hasValidCertificate() ever loses its lockForUpdate(), this
         * becomes false: an ordinary read here is served from the snapshot
         * step 1 fixed, which predates step 3's commit entirely, and the
         * check would incorrectly conclude no valid certificate exists.
         */
        expect($method->invoke($action, $held->enrollment))->toBeTrue(
            'hasValidCertificate() missed a certificate already committed by another connection before this '
            .'check ran — its read is not a locking one, or the lock was removed.',
        );

        DB::rollBack();
        $transactionEnded = true;
    } finally {
        if (! $transactionEnded && DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $secondary->table('student_certificates')->where('reference_number', 'TC-'.now()->year.'-PROBE2XXX')->delete();
    }
})->group('enrollment');

/*
|--------------------------------------------------------------------------
| The recorded failure, recorded HERE
|--------------------------------------------------------------------------
|
| An earlier version of this block pointed at "the task's final report" for the
| literal output. No such report exists — the implementing agent stopped before
| writing one — so the evidence the Done-when asks for lived nowhere. Review
| caught that, and the fix is to keep the output in the repository, where it
| cannot go missing and cannot be taken on trust.
|
| Verified by mutation, not by reading. `->lockForUpdate()` was deleted from
| IssueStudentCertificateAction::hasValidCertificate(), and probe 2 above was
| re-run:
|
|     php artisan test --filter=CertificateConcurrency
|
|     {"tool":"pest","result":"failed","tests":2,"passed":1,"assertions":13,
|      "failed":1,"failures":[{
|        "test":"…it_sees_a_certificate_committed_by_another_connection_after_
|                this_transaction_s_snapshot_was_fixed__because_the_check_is_a_
|                locking_read",
|        "line":380,
|        "message":"hasValidCertificate() missed a certificate already committed
|                   by another connection before this check ran — its read is not
|                   a locking one, or the lock was removed.
|                   Failed asserting that false is true."}]}
|
| NOTE THE SECOND HALF OF THAT RESULT: `"passed":1`. PROBE 1 STILL PASSED with
| the lock removed. That is not a flaw in probe 1 — it is why probe 2 has to
| exist. Probe 1's refusal is delivered by the database constraint, which holds
| whether or not the application checked first; only probe 2 can tell whether
| the check itself reads current data.
|
| The lock was then restored FROM A FILE COPY — never `git checkout`, which
| would have destroyed the entire uncommitted task — Pint was re-run, and the
| suite was re-run green.
*/

/*
|--------------------------------------------------------------------------
| Probe 3 — the snapshot COLUMNS are read currently, not from the view
|--------------------------------------------------------------------------
|
| REVIEW FINDING. Issue and Replace used to capture `student_name` and
| `course_name` through loadMissing(), which issues ordinary SELECTs — served
| from the REPEATABLE READ view that EnrollmentMutex::acquire()'s own first
| statement fixes, before the batch lock is even requested. The certificate's
| whole purpose is to record who completed what, so reading those two columns
| from a stale view writes the wrong name onto a printed document.
|
| Same shape as probe 2, one level out: the transaction's view is fixed first,
| a genuinely separate connection commits a correction, and then the REAL Action
| runs. With the current read in place the certificate carries the correction;
| with loadMissing() restored it carries the superseded name.
|
| The Action opens its own DB::transaction() nested inside this one, which
| Laravel implements as a savepoint — so it shares this transaction's view,
| which is exactly the condition under test.
*/

it('snapshots the student name a separate connection committed after this transaction\'s view was fixed', function () {
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');

    $student = Student::factory()->create(['first_name' => 'Amina', 'last_name' => 'Zarouk']);
    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->active()->create();
    $enrollment = Enrollment::factory()->for($student)->for($batch)->completed()->create();

    Config::set('database.connections.certificate_probe_snapshot', Config::get('database.connections.'.config('database.default')));
    DB::purge('certificate_probe_snapshot');
    $secondary = DB::connection('certificate_probe_snapshot');

    expect($secondary->selectOne('select connection_id() as id')->id)
        ->not->toBe(DB::connection()->selectOne('select connection_id() as id')->id,
            'The "secondary" connection is not actually a separate MySQL session.');

    DB::beginTransaction();
    $transactionEnded = false;

    try {
        // Fix this transaction's consistent-read view, exactly as
        // EnrollmentMutex::acquire()'s own first statement does.
        Enrollment::query()->count();

        // The correction lands on another connection and commits. Nothing in
        // this transaction has locked `students`, so there is no FK or row
        // conflict to wait on — unlike probe 2, where the order is forced.
        $secondary->table('students')
            ->where('id', $student->getKey())
            ->update(['last_name' => 'Zarrouk']);

        $certificate = app(IssueStudentCertificateAction::class)->execute($admin, $enrollment);

        /*
         * THE ASSERTION THIS PROBE EXISTS FOR. The expected value is the
         * literal corrected surname written above — never a re-read of the
         * student, which would be served from the same view and would let the
         * test agree with the bug.
         */
        expect($certificate->student_name)->toBe(
            'Amina Zarrouk',
            'The certificate snapshotted a superseded student name — the snapshot columns are being read '
            .'from this transaction\'s REPEATABLE READ view rather than currently.',
        );

        DB::rollBack();
        $transactionEnded = true;
    } finally {
        if (! $transactionEnded && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
})->group('enrollment');
