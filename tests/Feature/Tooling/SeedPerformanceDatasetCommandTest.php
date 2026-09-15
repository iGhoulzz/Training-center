<?php

declare(strict_types=1);

use Database\Seeders\PerformanceDatasetSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tooling\SerialLock;

/*
|--------------------------------------------------------------------------
| Performance dataset tooling
|--------------------------------------------------------------------------
|
| DatabaseMigrations is intentional. The successful command destroys and
| rebuilds the complete connected schema, which cannot run inside the
| transaction RefreshDatabase opens. The repository-wide database lock in
| tests/bootstrap.php serialises this file with every other worktree.
*/
uses(DatabaseMigrations::class);

/** The exact database this test process is connected to. */
function performanceTestDatabase(): string
{
    return DB::connection()->getDatabaseName();
}

/** Put one unmistakable row in the schema so a refused reset is observable. */
function insertPerformanceResetSentinel(): void
{
    DB::table('courses')->insert([
        'code' => 'RESET-SENTINEL',
        'name_en' => 'Reset sentinel',
        'total_hours' => 1,
        'default_price' => '0.000',
        'is_active' => true,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/** Assert the refusal happened before migrate:fresh could erase the sentinel. */
function expectPerformanceResetWasNotAttempted(): void
{
    expect(DB::table('courses')->where('code', 'RESET-SENTINEL')->exists())->toBeTrue();
}

it('allowlists exactly the approved disposable databases', function () {
    expect(config('performance.allowed_databases'))->toBe([
        'training_center_test',
        'training_center_ci',
        'training_center_performance',
    ]);
});

it('refuses production before attempting to reset the database', function () {
    insertPerformanceResetSentinel();

    $database = performanceTestDatabase();
    $this->app->instance('env', 'production');

    try {
        expect($this->app->isProduction())->toBeTrue();

        $this->artisan('seed:performance-dataset', [
            '--profile' => 'small',
            '--confirm-database' => $database,
        ])->assertFailed();
    } finally {
        // DatabaseMigrations rolls back during teardown and must not inherit
        // the production probe, or Laravel correctly asks for confirmation.
        $this->app->instance('env', 'testing');
    }

    expectPerformanceResetWasNotAttempted();
});

it('refuses a confirmation that does not exactly name the connected database before reset', function () {
    insertPerformanceResetSentinel();

    $this->artisan('seed:performance-dataset', [
        '--profile' => 'small',
        '--confirm-database' => performanceTestDatabase().'-wrong',
    ])->assertFailed();

    expectPerformanceResetWasNotAttempted();
});

it('refuses a connected database absent from the allowlist before reset', function () {
    insertPerformanceResetSentinel();

    config(['performance.allowed_databases' => ['some-other-disposable-database']]);

    $this->artisan('seed:performance-dataset', [
        '--profile' => 'small',
        '--confirm-database' => performanceTestDatabase(),
    ])->assertFailed();

    expectPerformanceResetWasNotAttempted();
});

it('refuses a missing or unknown profile before reset', function (?string $profile) {
    insertPerformanceResetSentinel();

    $database = performanceTestDatabase();

    $arguments = ['--confirm-database' => $database];

    if ($profile !== null) {
        $arguments['--profile'] = $profile;
    }

    $this->artisan('seed:performance-dataset', $arguments)->assertFailed();

    expectPerformanceResetWasNotAttempted();
})->with([
    'missing' => [null],
    'unknown' => ['large'],
]);

it('enforces the same guard when the seeder is invoked directly', function () {
    insertPerformanceResetSentinel();

    expect(fn () => app(PerformanceDatasetSeeder::class)->run(
        profile: 'small',
        confirmedDatabase: performanceTestDatabase().'-wrong',
    ))->toThrow(RuntimeException::class, 'does not exactly match connected database');

    expectPerformanceResetWasNotAttempted();
    expect(DB::table('charges')->count())->toBe(0)
        ->and(DB::table('payment_allocations')->count())->toBe(0);
});

it('makes the public command wait on the repository lock before resetting', function () {
    expect(SerialLock::isHeld())->toBeTrue();

    $database = performanceTestDatabase();
    $process = null;
    $probeSurvived = true;

    try {
        Schema::dropIfExists('performance_lock_probe');
        Schema::create('performance_lock_probe', function (Blueprint $table): void {
            $table->id();
        });

        /** @var array<string, mixed> $mysql */
        $mysql = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => (string) ($mysql['host'] ?? ''),
            'DB_PORT' => (string) ($mysql['port'] ?? ''),
            'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) ($mysql['username'] ?? ''),
            'DB_PASSWORD' => (string) ($mysql['password'] ?? ''),
        ];

        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'seed:performance-dataset',
            '--profile=small',
            '--confirm-database='.$database,
            '--no-ansi',
        ], base_path(), $environment);
        $process->setTimeout(null);
        $process->start();

        $stderr = '';
        $deadline = hrtime(true) + 20_000_000_000;

        do {
            $stderr .= $process->getIncrementalErrorOutput();
            $process->getIncrementalOutput();

            if (str_contains($stderr, 'Waiting for the shared test database')) {
                break;
            }

            if (! $process->isRunning()) {
                $this->fail(sprintf(
                    "Performance dataset subprocess exited before waiting for the shared database lock (exit code %s).\nComplete stderr:\n%s",
                    $process->getExitCode() === null ? 'unknown' : (string) $process->getExitCode(),
                    $process->getErrorOutput(),
                ));
            }

            if (! Schema::hasTable('performance_lock_probe')) {
                break;
            }

            if (hrtime(true) >= $deadline) {
                $this->fail(sprintf(
                    "Timed out waiting for the performance dataset subprocess to report lock contention.\nComplete stderr:\n%s",
                    $process->getErrorOutput(),
                ));
            }

            usleep(50_000);
        } while (true);

        $probeSurvived = Schema::hasTable('performance_lock_probe');

        expect($stderr)->toContain('Waiting for the shared test database')
            ->and($process->isRunning())->toBeTrue()
            ->and($probeSurvived)->toBeTrue();
    } finally {
        if ($process instanceof Process && $process->isRunning()) {
            $process->stop(1);
        }

        if ($probeSurvived) {
            Schema::dropIfExists('performance_lock_probe');
        } else {
            /*
             * The deliberate red case proves the old command reached
             * migrate:fresh. Restore the shared schema before the assertion
             * leaves this test so no later case observes the controlled probe.
             */
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
});

it('rebuilds the complete database and seeds the fixed profile cardinalities', function (
    string $profile,
    int $charges,
    int $allocations,
) {
    insertPerformanceResetSentinel();

    $database = performanceTestDatabase();

    $this->artisan('seed:performance-dataset', [
        '--profile' => $profile,
        '--confirm-database' => $database,
    ])->assertSuccessful();

    expect(DB::table('courses')->where('code', 'RESET-SENTINEL')->exists())->toBeFalse()
        ->and(DB::table('students')->count())->toBe($charges)
        ->and(DB::table('enrollments')->count())->toBe($charges)
        ->and(DB::table('charges')->count())->toBe($charges)
        ->and(DB::table('payments')->count())->toBe($allocations)
        ->and(DB::table('payment_tenders')->count())->toBe($allocations)
        ->and(DB::table('payment_allocations')->count())->toBe($allocations)
        ->and(DB::table('users')->where('email', 'owner@example.test')->exists())->toBeTrue()
        ->and(DB::table('charges')->where('amount', '1000.000')->count())->toBe($charges)
        ->and(DB::table('payment_allocations')->where('amount', '200.000')->count())->toBe($allocations);
})->with([
    'small' => ['small', 1_000, 2_000],
    'medium' => ['medium', 4_000, 8_000],
]);

it('rebuilds to the same deterministic small dataset on every run', function () {
    $database = performanceTestDatabase();

    $arguments = [
        '--profile' => 'small',
        '--confirm-database' => $database,
    ];

    $this->artisan('seed:performance-dataset', $arguments)->assertSuccessful();

    $first = DB::table('charges')
        ->join('enrollments', 'enrollments.id', '=', 'charges.enrollment_id')
        ->join('students', 'students.id', '=', 'enrollments.student_id')
        ->where('charges.id', 731)
        ->first([
            'students.student_code',
            'enrollments.reference as enrollment_reference',
            'charges.reference as charge_reference',
            'charges.due_date',
        ]);

    $this->artisan('seed:performance-dataset', $arguments)->assertSuccessful();

    $second = DB::table('charges')
        ->join('enrollments', 'enrollments.id', '=', 'charges.enrollment_id')
        ->join('students', 'students.id', '=', 'enrollments.student_id')
        ->where('charges.id', 731)
        ->first([
            'students.student_code',
            'enrollments.reference as enrollment_reference',
            'charges.reference as charge_reference',
            'charges.due_date',
        ]);

    expect((array) $second)->toBe((array) $first);
});
