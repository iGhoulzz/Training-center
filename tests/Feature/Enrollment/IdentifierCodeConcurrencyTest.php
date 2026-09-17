<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Exceptions\IdentifierCodeExhaustedException;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Domain\Enrollment\Support\IdentifierCode;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Generated codes under real contention
|--------------------------------------------------------------------------
|
| Probe 1 is two genuine processes drawing the SAME generated student code.
| Worker A inserts it and holds its transaction open; worker B's insert of the
| identical value then waits on A's uncommitted unique-index entry — a real
| InnoDB lock wait, measured rather than assumed. When A commits, B's insert
| fails on students_student_code_unique, and the Action must redraw and succeed
| with a different code. Two rows, two codes, no raw driver error.
|
| Remove the redraw — treat every code-index collision as fatal — and worker B
| reports a UniqueConstraintViolationException instead of a code.
|
| Probe 2 is the other end of the same loop: when EVERY draw collides, the
| bounded retry must throw its typed exhaustion rather than return anything,
| least of all a duplicate.
|
| DatabaseTruncation, not RefreshDatabase: the workers are separate connections
| and cannot see a row this process has not committed. The afterAll reset is
| docs/ENGINEERING.md's rule for truncation files — see CertificateConcurrencyTest.
*/
uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function identifierCodeWorkerEnvironment(): array
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
 * A worker that creates one student with a blank code.
 *
 * Its generator draws the alphabet's first character for the first six picks
 * — the same code in every worker — and $retryIndex for every pick after that,
 * so a redraw is visible in the stored suffix and in the pick count.
 *
 * With $holdPath set, the Action runs inside an outer transaction that stays
 * open until $holdPath appears: the Action's own transaction is then a
 * savepoint, and the inserted code stays uncommitted and locked.
 */
function identifierCodeWorker(
    int $actorId,
    int $retryIndex,
    string $readyPath,
    string $resultPath,
    string $holdPath = '',
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $retryIndex, $readyPath, $resultPath, $holdPath] = $_SERVER['argv'];

        $picks = 0;
        $insertStartedAt = null;
        app()->instance(
            App\Domain\Enrollment\Support\IdentifierCode::class,
            new App\Domain\Enrollment\Support\IdentifierCode(function (int $max) use (&$picks, $retryIndex): int {
                $picks++;

                return $picks <= App\Domain\Enrollment\Support\IdentifierCode::SUFFIX_LENGTH ? 0 : (int) $retryIndex;
            }),
        );

        $create = static fn (): App\Domain\Enrollment\Models\Student => app(App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction::class)
            ->createStudent(App\Models\User::query()->findOrFail((int) $actorId), [
                'first_name' => 'Worker',
                'last_name' => (string) $retryIndex,
                'status' => App\Domain\Enrollment\Enums\StudentStatus::Prospective,
            ]);

        try {
            if ($holdPath !== '') {
                $student = Illuminate\Support\Facades\DB::transaction(static function () use ($create, $readyPath, $holdPath) {
                    $student = $create();
                    file_put_contents($readyPath, 'inserted');

                    $deadline = hrtime(true) + 60_000_000_000;

                    while (! file_exists($holdPath) && hrtime(true) < $deadline) {
                        usleep(10_000);
                    }

                    return $student;
                });
            } else {
                /*
                 * `creating` fires immediately before the INSERT is sent, so
                 * this signal means "my first insert is about to reach the
                 * server". Its timestamp is when the wait begins.
                 */
                App\Domain\Enrollment\Models\Student::creating(static function () use (&$insertStartedAt, $readyPath): void {
                    if ($insertStartedAt === null) {
                        $insertStartedAt = hrtime(true);
                        file_put_contents($readyPath, 'inserting');
                    }
                });

                $student = $create();
            }

            $result = [
                'outcome' => 'created',
                'code' => $student->student_code,
                'picks' => $picks,
                'waited_ms' => $insertStartedAt === null ? null : (hrtime(true) - $insertStartedAt) / 1_000_000,
            ];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage(), 'picks' => $picks];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, (string) $retryIndex, $readyPath, $resultPath, $holdPath],
        base_path(),
        identifierCodeWorkerEnvironment(),
    );
}

/** Wait for $path, failing with the worker's stderr if it dies first. */
function waitForIdentifierCodeSignal(string $path, Process $worker, float $seconds = 60): bool
{
    $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);

    while (! File::exists($path) && hrtime(true) < $deadline) {
        if (! $worker->isRunning()) {
            Assert::fail('Worker died unexpectedly: '.$worker->getErrorOutput());
        }

        usleep(25_000);
    }

    return File::exists($path);
}

it('lets two workers that draw the same code both create a student, by redrawing the loser', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $last = strlen(CertificateReference::ALPHABET) - 1;
    $token = (string) Str::uuid();
    $paths = [
        'holdA' => storage_path("framework/testing/code-race-{$token}-hold-a"),
        'readyA' => storage_path("framework/testing/code-race-{$token}-ready-a"),
        'resultA' => storage_path("framework/testing/code-race-{$token}-result-a.json"),
        'readyB' => storage_path("framework/testing/code-race-{$token}-ready-b"),
        'resultB' => storage_path("framework/testing/code-race-{$token}-result-b.json"),
    ];

    $workerA = identifierCodeWorker((int) $actor->getKey(), $last, $paths['readyA'], $paths['resultA'], $paths['holdA']);
    $workerB = identifierCodeWorker((int) $actor->getKey(), $last, $paths['readyB'], $paths['resultB']);

    try {
        $workerA->start();
        expect(waitForIdentifierCodeSignal($paths['readyA'], $workerA))->toBeTrue('Worker A never inserted its code.');

        $workerB->start();
        expect(waitForIdentifierCodeSignal($paths['readyB'], $workerB))->toBeTrue('Worker B never reached its insert.');

        /*
         * THE CONTENTION IS MEASURED, NOT ASSUMED. B has signalled that its
         * insert of A's still-uncommitted code is being sent; A is held open a
         * further 750 ms before it commits. If B truly waited on A's lock, its
         * insert took at least that long — asserted below as a floor. A floor
         * cannot be failed by a slow machine, only by B NOT waiting, which
         * would make this a sequential collision wearing a concurrency test's
         * name.
         *
         * Measured this way, not through information_schema.innodb_trx, because
         * that needs the PROCESS privilege and the development database account
         * does not hold it — the first version of this probe failed on exactly
         * that.
         */
        usleep(750_000);

        expect(File::exists($paths['resultB']))->toBeFalse('Worker B finished while worker A still held its code.');

        File::put($paths['holdA'], 'commit');

        $workerA->wait();
        $workerB->wait();

        expect($workerA->isSuccessful())->toBeTrue($workerA->getErrorOutput())
            ->and($workerB->isSuccessful())->toBeTrue($workerB->getErrorOutput());

        $resultA = json_decode((string) File::get($paths['resultA']), true, flags: JSON_THROW_ON_ERROR);
        $resultB = json_decode((string) File::get($paths['resultB']), true, flags: JSON_THROW_ON_ERROR);

        expect($resultA['outcome'])->toBe('created', json_encode($resultA))
            ->and($resultB['outcome'])->toBe('created', json_encode($resultB))
            ->and($resultA['code'])->toEndWith('-222222')
            ->and($resultA['picks'])->toBe(6)
            // B drew A's code, lost the unique index, and redrew exactly once.
            ->and($resultB['code'])->toEndWith('-ZZZZZZ')
            ->and($resultB['picks'])->toBe(12)
            ->and($resultB['waited_ms'])->toBeGreaterThanOrEqual(500);

        expect(Student::query()->pluck('student_code')->sort()->values()->all())
            ->toBe(collect([$resultA['code'], $resultB['code']])->sort()->values()->all());
    } finally {
        foreach ([$workerA, $workerB] as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        File::delete(array_values($paths));
    }
})->group('enrollment');

it('throws its typed exhaustion when every draw collides, and writes nothing', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));
    Student::factory()->create(['student_code' => 'STU-2026-222222']);

    $picks = 0;
    app()->instance(IdentifierCode::class, new IdentifierCode(function (int $max) use (&$picks): int {
        $picks++;

        return 0;
    }));

    $thrown = null;

    try {
        app(CreateWithIdentifierCodeAction::class)->createStudent($actor, [
            'first_name' => 'Never',
            'last_name' => 'Written',
            'status' => StudentStatus::Prospective,
        ]);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(IdentifierCodeExhaustedException::class)
        ->and($thrown->attempts)->toBe(CreateWithIdentifierCodeAction::MAX_ATTEMPTS)
        ->and($picks)->toBe(CreateWithIdentifierCodeAction::MAX_ATTEMPTS * IdentifierCode::SUFFIX_LENGTH)
        ->and(Student::query()->pluck('student_code')->all())->toBe(['STU-2026-222222']);
})->group('enrollment');
