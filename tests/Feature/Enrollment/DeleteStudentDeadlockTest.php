<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Deleting a student against writers that lock in the opposite order
|--------------------------------------------------------------------------
|
| DeleteStudentAction locks the student, then the student's enrolments and
| charges. Two kinds of writer take rows in the opposite order: the certificate
| Actions (batch, enrolment, then the student, shared) and RecordPaymentAction
| (the charge, then the student through the payment's foreign key). Each pair can
| meet in a cycle, InnoDB rolls one side back with 1213, and the answer is that
| each transaction is run again.
|
| DeleteStudentConcurrencyTest cannot show that, and neither can any test that
| nests the Action in its own transaction: Laravel retries a deadlock only at the
| OUTERMOST transaction, so a nested run just rethrows. The workers here call the
| Actions at the outermost level, count their transaction BEGINs, and report both
| the outcome and the count, so "the retry happened" is observed and not assumed.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function deadlockWorkerEnvironment(): array
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
 * A worker that runs one Action at the OUTERMOST transaction level.
 *
 * It signals $readyPath once booted, and (for the certificate) $lockedPath the
 * moment its enrolment lock is taken, then reports the outcome and how many
 * transactions it began: 1 means no retry, 2 means one deadlock retry.
 *
 * @param  'delete'|'issue'|'replace'  $kind
 */
function deadlockWorker(string $kind, int $actorId, int $subjectId, string $readyPath, string $lockedPath, string $resultPath): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $kind, $actorId, $subjectId, $readyPath, $lockedPath, $resultPath] = $_SERVER['argv'];

        $begins = 0;
        Illuminate\Support\Facades\Event::listen(
            Illuminate\Database\Events\TransactionBeginning::class,
            static function () use (&$begins): void {
                $begins++;
            },
        );
        Illuminate\Support\Facades\DB::listen(static function ($query) use ($lockedPath): void {
            if (str_contains($query->sql, 'from `enrollments`') && str_contains($query->sql, 'for update')) {
                file_put_contents($lockedPath, 'locked');
            }
        });

        file_put_contents($readyPath, 'ready');

        try {
            $actor = App\Models\User::query()->findOrFail((int) $actorId);

            if ($kind === 'delete') {
                app(App\Domain\Enrollment\Actions\DeleteStudentAction::class)->execute(
                    $actor,
                    App\Domain\Enrollment\Models\Student::query()->findOrFail((int) $subjectId),
                );
                $outcome = 'deleted';
            } elseif ($kind === 'replace') {
                app(App\Domain\Enrollment\Actions\ReplaceStudentCertificateAction::class)->execute(
                    $actor,
                    App\Domain\Enrollment\Models\Enrollment::query()->findOrFail((int) $subjectId),
                );
                $outcome = 'issued';
            } else {
                app(App\Domain\Enrollment\Actions\IssueStudentCertificateAction::class)->execute(
                    $actor,
                    App\Domain\Enrollment\Models\Enrollment::query()->findOrFail((int) $subjectId),
                );
                $outcome = 'issued';
            }

            $result = ['outcome' => $outcome];
        } catch (App\Domain\Enrollment\Exceptions\StudentNotDeletableException) {
            $result = ['outcome' => 'refused'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        $result['begins'] = $begins;
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, $kind, (string) $actorId, (string) $subjectId, $readyPath, $lockedPath, $resultPath],
        base_path(),
        deadlockWorkerEnvironment(),
    );
}

function deadlockWaitFor(string $path, Process $worker): void
{
    $deadline = hrtime(true) + 60_000_000_000;

    while (! File::exists($path) && hrtime(true) < $deadline) {
        if (! $worker->isRunning()) {
            Assert::fail('Worker died unexpectedly: '.$worker->getErrorOutput());
        }
        usleep(25_000);
    }

    expect(File::exists($path))->toBeTrue("A worker never reached {$path}.");
}

/**
 * Wait, without a sleep standing in for it, until $worker HOLDS the student row.
 *
 * The test database user cannot read performance_schema or innodb_trx (both need
 * PROCESS), so the lock is probed from a second connection with
 * `FOR SHARE NOWAIT`: it succeeds while nobody holds the row exclusively and
 * fails at once with error 3572 when someone does. The only exclusive locker the
 * parent allows is the worker, so the first failure means it has the student.
 * Each probe that succeeds releases its own lock as it commits (autocommit).
 * Bounded, and a clear failure rather than a hang.
 */
function deadlockAwaitStudentHeld(int $studentId, Process $worker, float $seconds = 10): void
{
    config(['database.connections.deadlock_probe' => config('database.connections.'.config('database.default'))]);
    $probe = DB::connection('deadlock_probe');
    $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);

    try {
        while (hrtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                Assert::fail('Worker died before it took the student: '.$worker->getErrorOutput());
            }

            try {
                $probe->select('select id from students where id = ? for share nowait', [$studentId]);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) === 3572) {
                    return;
                }

                throw $exception;
            }

            usleep(10_000);
        }

        Assert::fail("The worker never took a lock on student {$studentId} within {$seconds} seconds.");
    } finally {
        DB::purge('deadlock_probe');
    }
}

/**
 * @return array<string, mixed>
 */
function deadlockResultOf(Process $worker, string $resultPath): array
{
    $worker->wait();

    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

    return json_decode((string) File::get($resultPath), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->token = (string) Str::uuid();
    $this->path = fn (string $name): string => storage_path("framework/testing/deadlock-{$this->token}-{$name}");
});

afterEach(function () {
    File::delete(glob(storage_path("framework/testing/deadlock-{$this->token}-*")) ?: []);
});

it('writes the certificate and refuses the delete when they deadlock on the student and its enrolments', function (string $kind, string $permission) {
    $remover = ($this->userWith)('delete_student');
    $issuer = ($this->userWith)($permission);

    // Five enrolments, the one to certify LAST: the delete has locked the other
    // four by the time it needs it, so it is the heavier transaction and InnoDB
    // names the certificate side the victim - the case that was a raw 1213.
    $student = Student::factory()->create();
    $earlier = Enrollment::factory()->for($student)->count(4)->create(['status' => EnrollmentStatus::Withdrawn]);
    $target = Enrollment::factory()->for($student)->completed()->create();
    if ($kind === 'replace') {
        StudentCertificate::factory()->create(['enrollment_id' => $target->getKey()]);
    }
    // The student owes on the first one, so the delete's answer is a refusal and
    // the student row survives for the certificate to read.
    Charge::factory()->create(['enrollment_id' => $earlier->first()->getKey(), 'list_price' => '300.000', 'amount' => '300.000']);

    $deleteWorker = deadlockWorker('delete', (int) $remover->getKey(), (int) $student->getKey(), ($this->path)('d-ready'), ($this->path)('d-locked'), ($this->path)('d-result.json'));
    $issueWorker = deadlockWorker($kind, (int) $issuer->getKey(), (int) $target->getKey(), ($this->path)('i-ready'), ($this->path)('i-locked'), ($this->path)('i-result.json'));

    $connection = DB::connection();
    $connection->beginTransaction();
    $released = false;

    try {
        // The parent holds the first enrolment, not the student. The delete takes
        // the student and then waits for that enrolment; the certificate takes its
        // batch and its own enrolment and then wants the student. Committing the
        // parent lets the delete walk on to the certified enrolment: a cycle,
        // whichever of the two gets there first.
        Enrollment::query()->whereKey($earlier->first()->getKey())->lockForUpdate()->firstOrFail();

        $deleteWorker->start();
        deadlockAwaitStudentHeld((int) $student->getKey(), $deleteWorker);

        $issueWorker->start();
        deadlockWaitFor(($this->path)('i-ready'), $issueWorker);
        deadlockWaitFor(($this->path)('i-locked'), $issueWorker);

        expect($deleteWorker->isRunning())->toBeTrue()
            ->and($issueWorker->isRunning())->toBeTrue();

        $connection->commit();
        $released = true;

        $delete = deadlockResultOf($deleteWorker, ($this->path)('d-result.json'));
        $issue = deadlockResultOf($issueWorker, ($this->path)('i-result.json'));

        // begins: the cycle formed, InnoDB rolled ONE side back, and that side ran
        // its transaction again. Without a deadlock both would be 1 and the outcomes
        // alone would still pass. WHICH side is the victim is not asserted: InnoDB
        // weighs a transaction by its lock structures, which depend on how the rows
        // sit on their pages, and that differs between a fresh database and one a
        // worker of a parallel run has already used. Either victim must end the same.
        expect($issue['outcome'])->toBe('issued', json_encode($issue))
            ->and($delete['outcome'])->toBe('refused', json_encode($delete))
            ->and($issue['begins'] + $delete['begins'])->toBe(3, json_encode([$issue, $delete]))
            ->and(StudentCertificate::query()->where('enrollment_id', $target->getKey())->count())->toBe($kind === 'replace' ? 2 : 1)
            ->and($student->fresh()->trashed())->toBeFalse();
    } finally {
        if (! $released && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ([$deleteWorker, $issueWorker] as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
})->with([
    'issue' => ['issue', 'issue_student_certificate'],
    'replace' => ['replace', 'replace_student_certificate'],
])->group('enrollment');

it('retries the delete when a payment being recorded deadlocks with it, then decides', function () {
    $remover = ($this->userWith)('delete_student');

    // Settled, so once the deadlock is over the retry finds nothing owed.
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Withdrawn]);
    $charge = Charge::factory()->create(['enrollment_id' => $enrollment->getKey(), 'list_price' => '1000.000', 'amount' => '1000.000']);
    PaymentAllocation::factory()->create([
        'payment_id' => Payment::factory()->create(['student_id' => $student->getKey()])->getKey(),
        'charge_id' => $charge->getKey(),
        'amount' => '1000.000',
    ]);

    $worker = deadlockWorker('delete', (int) $remover->getKey(), (int) $student->getKey(), ($this->path)('ready'), ($this->path)('locked'), ($this->path)('result.json'));

    $connection = DB::connection();
    $connection->beginTransaction();
    $released = false;

    try {
        // A payment in flight holds the charge (RecordPaymentAction's first lock)...
        Charge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

        $worker->start();
        // ...the delete takes the student and goes on to wait for that charge...
        deadlockAwaitStudentHeld((int) $student->getKey(), $worker);

        // ...and the payment's insert then needs the student for its foreign key.
        Payment::factory()->create(['student_id' => $student->getKey()]);

        $connection->commit();
        $released = true;

        $result = deadlockResultOf($worker, ($this->path)('result.json'));

        // begins 2: the first attempt was the deadlock victim and the Action ran
        // again. 1 would mean the parent was the victim and nothing was retried.
        expect($result)->toBe(['outcome' => 'deleted', 'begins' => 2])
            ->and($student->fresh()->trashed())->toBeTrue();
    } finally {
        if (! $released && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
})->group('enrollment');
