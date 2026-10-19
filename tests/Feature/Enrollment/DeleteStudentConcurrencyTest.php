<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Actions\AdjustChargeAction;
use App\Domain\Finance\Actions\ReversePaymentAction;
use App\Domain\Finance\Data\AdjustChargeData;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| A debt that commits between the delete's snapshot and its decision
|--------------------------------------------------------------------------
|
| A single connection cannot prove this guard. Under InnoDB REPEATABLE READ a
| plain read is served from the snapshot of the transaction's first consistent
| read, and the Finance Actions that can make a student owe again - an upward
| adjustment of a charge, the reversal of a payment - lock a charge or a payment
| and never the student row DeleteStudentAction holds. So the sequence is forced
| rather than left to timing: the worker opens a transaction and takes its
| snapshot, the parent (holding the student row) commits the change, and only
| then does the worker run the Action and reach its decision.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function deleteStudentWorkerEnvironment(): array
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

function deleteStudentWorker(int $actorId, int $studentId, string $readyPath, string $resultPath): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $studentId, $readyPath, $resultPath] = $_SERVER['argv'];

        try {
            $result = Illuminate\Support\Facades\DB::transaction(
                static function () use ($actorId, $studentId, $readyPath): array {
                    /* Establish the old consistent-read snapshot deliberately. */
                    App\Domain\Finance\Models\PaymentAllocation::query()->count();
                    file_put_contents($readyPath, 'ready');

                    app(App\Domain\Enrollment\Actions\DeleteStudentAction::class)->execute(
                        App\Models\User::query()->findOrFail((int) $actorId),
                        App\Domain\Enrollment\Models\Student::query()->findOrFail((int) $studentId),
                    );

                    return ['outcome' => 'deleted'];
                },
            );
        } catch (App\Domain\Enrollment\Exceptions\StudentNotDeletableException) {
            $result = ['outcome' => 'refused'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, (string) $studentId, $readyPath, $resultPath],
        base_path(),
        deleteStudentWorkerEnvironment(),
    );
}

/**
 * Run the delete in a worker that has already taken its snapshot, make
 * $changeWhileItWaits commit while the worker waits on the student row, and
 * return the worker's outcome.
 *
 * @param  callable(): void  $changeWhileItWaits  runs in the parent, inside the transaction holding the student row
 * @return array<string, mixed>
 */
function deleteStudentAfterConcurrentChange(User $remover, Student $student, callable $changeWhileItWaits): array
{
    $token = (string) Str::uuid();
    $readyPath = storage_path("framework/testing/delete-student-{$token}-ready");
    $resultPath = storage_path("framework/testing/delete-student-{$token}-result.json");
    $worker = deleteStudentWorker((int) $remover->getKey(), (int) $student->getKey(), $readyPath, $resultPath);

    $connection = DB::connection();
    $connection->beginTransaction();
    $released = false;

    try {
        Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

        $worker->start();

        // 60 seconds of headroom for slow CI nodes; the poll exits early on success.
        $deadline = hrtime(true) + 60_000_000_000;

        while (! File::exists($readyPath) && hrtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                Assert::fail('Worker died unexpectedly: '.$worker->getErrorOutput());
            }
            usleep(25_000);
        }

        expect(File::exists($readyPath))->toBeTrue('The delete worker never established its old snapshot.');

        usleep(400_000);

        expect($worker->isRunning())->toBeTrue('The delete worker was not blocked by the student lock.')
            ->and(File::exists($resultPath))->toBeFalse();

        $changeWhileItWaits();

        $connection->commit();
        $released = true;

        $worker->wait();

        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

        return json_decode((string) File::get($resultPath), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        if (! $released && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if ($worker->isRunning()) {
            $worker->stop();
        }

        File::delete([$readyPath, $resultPath]);
    }
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->remover = ($this->userWith)('delete_student');

    /**
     * A withdrawn student whose one 1000.000 bill is paid in full, so the delete
     * is allowed on every committed row it can see before the change.
     *
     * @return array{student: Student, charge: Charge, payment: Payment}
     */
    $this->settledStudent = function (): array {
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Withdrawn]);
        $charge = Charge::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'list_price' => '1000.000',
            'amount' => '1000.000',
        ]);
        $payment = Payment::factory()->create(['student_id' => $student->getKey()]);
        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => '1000.000',
        ]);

        return ['student' => $student, 'charge' => $charge, 'payment' => $payment];
    };
});

it('refuses a student whose paid charge was raised by a thousandth after the delete took its snapshot', function () {
    ['student' => $student, 'charge' => $charge] = ($this->settledStudent)();
    $adjuster = ($this->userWith)('adjust_charge');

    $result = deleteStudentAfterConcurrentChange(
        $this->remover,
        $student,
        fn () => app(AdjustChargeAction::class)->execute(
            $adjuster,
            new AdjustChargeData(chargeId: (int) $charge->getKey(), amount: '1000.001', reason: 'Entered a thousandth short.'),
        ),
    );

    expect($result)->toBe(['outcome' => 'refused'])
        ->and($charge->fresh()->amount)->toBe('1000.001')
        ->and($student->fresh()->trashed())->toBeFalse();
})->group('enrollment');

it('refuses a student whose payment was reversed after the delete took its snapshot', function () {
    ['student' => $student, 'payment' => $payment] = ($this->settledStudent)();
    $reverser = ($this->userWith)('reverse_payment');

    $result = deleteStudentAfterConcurrentChange(
        $this->remover,
        $student,
        fn () => app(ReversePaymentAction::class)->execute($reverser, (int) $payment->getKey(), 'Recorded against the wrong bill.'),
    );

    expect($result)->toBe(['outcome' => 'refused'])
        ->and($payment->fresh()->isReversed())->toBeTrue()
        ->and($student->fresh()->trashed())->toBeFalse();
})->group('enrollment');

it('still deletes a settled student when nothing changed while it waited', function () {
    // The control: the same forced sequence with no concurrent change, so the
    // two refusals above are the change's doing and not the harness's.
    ['student' => $student] = ($this->settledStudent)();

    $result = deleteStudentAfterConcurrentChange($this->remover, $student, static fn () => null);

    expect($result)->toBe(['outcome' => 'deleted'])
        ->and($student->fresh()->trashed())->toBeTrue();
})->group('enrollment');
