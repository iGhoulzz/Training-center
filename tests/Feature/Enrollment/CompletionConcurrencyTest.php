<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Two guards a single connection cannot prove
|--------------------------------------------------------------------------
|
| 1. Two connections racing to complete the SAME active enrolment: one must
|    win and the other must see it already completed and refuse, typed.
|
| 2. The scoped-permission guard itself. Under InnoDB REPEATABLE READ, an
|    ordinary read keeps using the transaction snapshot established before a
|    competing write committed, even after the transaction waits for and then
|    acquires a lock on some OTHER row. So a worker that opens its transaction,
|    then blocks waiting for the batch lock while an assignment is revoked and
|    committed, and only THEN reads the pivot with an ordinary SELECT, can
|    still see the assignment that no longer exists — the exact shape that let
|    two tills double-collect on a charge balance (see AdjustChargeConcurrencyTest).
|    Only a genuine second connection can force that sequence; a single
|    connection's writes are always visible to its own later reads regardless
|    of isolation level, which would make the guard look proven when it is not.
|
| Modelled directly on AdjustChargeConcurrencyTest's parent/subprocess shape.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function completionWorkerEnvironment(): array
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
 * any of its real work. Probe 1 uses this to start two workers at effectively
 * the same instant, so their lock attempts genuinely contend rather than one
 * finishing before the other's process has even been scheduled.
 */
function completionWorker(
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
                     * exactly as AdjustChargeConcurrencyTest does. This is also
                     * what EnrollmentMutex::acquire() itself does as its own
                     * FIRST statement — an ordinary, unlocked read of the
                     * enrolment to choose which batch to lock — so this line
                     * mirrors the real transaction shape rather than adding an
                     * artificial precondition.
                     */
                    App\Domain\Enrollment\Models\Enrollment::query()->count();
                    file_put_contents($readyPath, 'ready');

                    if ($startPath !== null) {
                        // 60 seconds of headroom for slow CI nodes; the poll exits early on success.
        $deadline = hrtime(true) + 60_000_000_000;

                        while (! file_exists($startPath) && hrtime(true) < $deadline) {
                            usleep(10_000);
                        }
                    }

                    $enrollment = App\Domain\Enrollment\Models\Enrollment::query()->findOrFail((int) $enrollmentId);

                    app(App\Domain\Enrollment\Actions\CompleteEnrollmentAction::class)->execute(
                        App\Models\User::query()->findOrFail((int) $actorId),
                        $enrollment,
                    );

                    return ['outcome' => 'completed'];
                },
            );
        } catch (App\Domain\Enrollment\Exceptions\EnrollmentNotCompletableException) {
            $result = ['outcome' => 'refused_not_completable'];
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
        completionWorkerEnvironment(),
    );
}

/**
 * Wait for $path to exist, or fail loudly. Returns the elapsed check so
 * callers can assert on it rather than trusting a silent timeout.
 */
function waitForCompletionSignal(string $path, float $seconds = 60): bool
{
    $deadline = hrtime(true) + ($seconds * 1_000_000_000);

    while (! File::exists($path) && hrtime(true) < $deadline) {
        usleep(25_000);
    }

    return File::exists($path);
}

/*
|--------------------------------------------------------------------------
| Probe 1: two connections, one active enrolment, one winner
|--------------------------------------------------------------------------
*/

it('lets one of two simultaneous completions through and types the other\'s refusal', function () {
    /*
     * TWO REAL WORKERS, NOT A HAND-ROLLED LOCK-THEN-WRITE.
     *
     * An earlier version of this test held the enrolment locked in the test
     * process itself and then issued a raw UPDATE on that same row while a
     * worker sat blocked waiting for it. That reliably deadlocked InnoDB
     * (Error 1213): the worker's queued FOR UPDATE request on the row and the
     * lock holder's own UPDATE — which has to maintain the (batch_id, status)
     * secondary index — form a genuine wait-for cycle under REPEATABLE READ,
     * even though the "holder" already owns the row's exclusive lock. That is
     * a real MySQL hazard, not a defect in the guard being tested, and the
     * fix is to stop manufacturing it: run TWO genuine CompleteEnrollmentAction
     * invocations, each taking the real batch-then-enrolment lock order the
     * Action itself uses, and let InnoDB serialise them exactly as it would
     * for two real requests.
     *
     * $startPath is the synchronisation: each worker signals $readyPath (after
     * establishing its own snapshot, exactly as the Action's first statement
     * does) and then blocks IN PHP until $startPath exists, so both enter their
     * locking work at effectively the same instant instead of one finishing
     * before the other's process has even been scheduled.
     */
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->active()->create();
    $enrollment = Enrollment::factory()->for($batch)->create();

    $token = (string) Str::uuid();
    $startPath = storage_path("framework/testing/complete-race-{$token}-start");
    $readyPathA = storage_path("framework/testing/complete-race-{$token}-ready-a");
    $resultPathA = storage_path("framework/testing/complete-race-{$token}-result-a.json");
    $readyPathB = storage_path("framework/testing/complete-race-{$token}-ready-b");
    $resultPathB = storage_path("framework/testing/complete-race-{$token}-result-b.json");

    $workerA = completionWorker((int) $actor->getKey(), (int) $enrollment->getKey(), $readyPathA, $resultPathA, $startPath);
    $workerB = completionWorker((int) $actor->getKey(), (int) $enrollment->getKey(), $readyPathB, $resultPathB, $startPath);

    $allPaths = [$startPath, $readyPathA, $resultPathA, $readyPathB, $resultPathB];

    try {
        $workerA->start();
        $workerB->start();

        expect(waitForCompletionSignal($readyPathA))->toBeTrue('Worker A never established its old snapshot.')
            ->and(waitForCompletionSignal($readyPathB))->toBeTrue('Worker B never established its old snapshot.');

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

        $outcomeA = json_decode((string) File::get($resultPathA), true, flags: JSON_THROW_ON_ERROR)['outcome'];
        $outcomeB = json_decode((string) File::get($resultPathB), true, flags: JSON_THROW_ON_ERROR)['outcome'];

        $outcomes = collect([$outcomeA, $outcomeB])->sort()->values()->all();

        expect($outcomes)->toBe(
            ['completed', 'refused_not_completable'],
            "Worker A reported [{$outcomeA}] and worker B reported [{$outcomeB}]; expected exactly "
            .'one completed and one typed refusal for racing the same active enrolment.',
        );

        expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed);
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
| Probe 2: the scoped-permission guard itself
|--------------------------------------------------------------------------
*/

it('refuses a scoped completion when the assignment is revoked while the worker waits for the batch lock', function () {
    $this->seed(RolePermissionSeeder::class);

    $system = app(SystemRoleWriter::class);

    // A BESPOKE role holding ONLY the scoped ability — see
    // CompletionAuthorizationTest for why the seeded 'staff' role cannot
    // stand in for this: it happens to be correctly configured today, which
    // proves nothing about whether CompletionRule's own branching is correct.
    $system->syncRolePermissions(
        Role::findOrCreate('batch_marker', 'web'),
        [Permission::findByName('complete_assigned_batch_enrollment', 'web')],
    );

    $staff = User::factory()->create(['is_active' => true]);
    $system->assignRoles($staff, 'batch_marker');
    $staff->refresh();
    StaffProfile::factory()->for($staff)->instructor()->create();

    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->active()->create();
    $enrollment = Enrollment::factory()->for($batch)->create();

    $batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 20]);

    $token = (string) Str::uuid();
    $readyPath = storage_path("framework/testing/complete-scope-{$token}-ready");
    $resultPath = storage_path("framework/testing/complete-scope-{$token}-result.json");

    $worker = completionWorker((int) $staff->getKey(), (int) $enrollment->getKey(), $readyPath, $resultPath);

    $connection = DB::connection();
    $connection->beginTransaction();
    $batchLockReleased = false;

    try {
        // Hold the BATCH row. EnrollmentMutex takes this before the pivot is
        // ever read, so the worker is guaranteed to block here — BEFORE
        // CompletionRule's locking pivot read runs — while still having
        // already taken its own snapshot via the unlocked read inside the
        // worker script above.
        Batch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

        $worker->start();

        expect(waitForCompletionSignal($readyPath))->toBeTrue('The completion worker never established its old snapshot.');

        usleep(400_000);

        expect($worker->isRunning())->toBeTrue('The completion worker was not blocked by the batch lock.')
            ->and(File::exists($resultPath))->toBeFalse();

        // Revoke the assignment and commit it — a fact the worker's snapshot,
        // taken before this write, cannot see through an ORDINARY read.
        DB::table('batch_instructor')
            ->where('batch_id', $batch->getKey())
            ->where('user_id', $staff->getKey())
            ->delete();

        $connection->commit();
        $batchLockReleased = true;

        $worker->wait();

        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

        $result = json_decode((string) File::get($resultPath), true, flags: JSON_THROW_ON_ERROR);

        /*
         * THE ASSERTION THIS WHOLE FILE EXISTS FOR.
         *
         * If CompletionRule::isAssigned() ever loses its lockForUpdate(), this
         * fails here: the worker's pivot read is served from the snapshot
         * taken before the delete above, sees the (deleted) assignment as
         * still present, and the outcome becomes 'completed' instead —
         * silently authorizing a staff actor no longer assigned to the batch.
         *
         * THE CONTROL BELOW IS WHAT MAKES THIS ASSERTION MEAN ANYTHING.
         * 'refused_unauthorized' is also this fixture's DEFAULT failure mode —
         * a mistyped role, a missed attach(), an inactive user all produce it —
         * so on its own this test could stay green while proving nothing about
         * the lock. The next test runs the identical worker with the assignment
         * LEFT IN PLACE and requires 'completed', which separates "the lock
         * refused it" from "the actor was never authorized at all".
         */
        expect($result)->toBe(['outcome' => 'refused_unauthorized'])
            ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
    } finally {
        if (! $batchLockReleased && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if ($worker->isRunning()) {
            $worker->stop();
        }

        File::delete([$readyPath, $resultPath]);
    }
})->group('enrollment');

it('completes the same scoped enrolment when the assignment is left in place', function () {
    /*
     * THE POSITIVE CONTROL FOR THE TEST ABOVE, and not optional.
     *
     * That test's pass condition is a REFUSAL, and a refusal is exactly what
     * this fixture produces whenever anything is merely MISCONFIGURED: a
     * mistyped role name, an attach() that did not land, an inactive user, a
     * renamed permission. Without a control, a typo would leave it green
     * forever while the lock it exists to prove had been removed.
     *
     * Identical setup, identical worker, identical blocking shape. One
     * difference: the assignment is not revoked. The outcome must be
     * 'completed', which a misconfigured actor cannot reach.
     */
    $this->seed(RolePermissionSeeder::class);

    $system = app(SystemRoleWriter::class);

    $system->syncRolePermissions(
        Role::findOrCreate('batch_marker', 'web'),
        [Permission::findByName('complete_assigned_batch_enrollment', 'web')],
    );

    $staff = User::factory()->create(['is_active' => true]);
    $system->assignRoles($staff, 'batch_marker');
    $staff->refresh();
    StaffProfile::factory()->for($staff)->instructor()->create();

    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->active()->create();
    $enrollment = Enrollment::factory()->for($batch)->create();

    $batch->instructors()->attach($staff->getKey(), ['assigned_hours' => 20]);

    $token = (string) Str::uuid();
    $readyPath = storage_path("framework/testing/complete-control-{$token}-ready");
    $resultPath = storage_path("framework/testing/complete-control-{$token}-result.json");

    $worker = completionWorker((int) $staff->getKey(), (int) $enrollment->getKey(), $readyPath, $resultPath);

    $connection = DB::connection();
    $connection->beginTransaction();
    $batchLockReleased = false;

    try {
        Batch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

        $worker->start();

        expect(waitForCompletionSignal($readyPath))->toBeTrue('The completion worker never established its old snapshot.');

        usleep(400_000);

        expect($worker->isRunning())->toBeTrue('The completion worker was not blocked by the batch lock.');

        $connection->commit();
        $batchLockReleased = true;

        $worker->wait();

        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

        $result = json_decode((string) File::get($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($result)->toBe(['outcome' => 'completed'])
            ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed);
    } finally {
        if (! $batchLockReleased && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if ($worker->isRunning()) {
            $worker->stop();
        }

        File::delete([$readyPath, $resultPath]);
    }
})->group('enrollment');
