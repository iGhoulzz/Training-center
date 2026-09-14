<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PerformanceDatabaseGuard;
use Database\Seeders\PerformanceDatasetSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Rebuild an explicitly disposable database with a fixed performance fixture.
 *
 * The whole database is rebuilt after the shared guard passes. There is no
 * selective cleanup: finance and activity rows carry no fixture ownership
 * marker, and inventing a delete heuristic for append-only facts would be less
 * safe than rebuilding the independently allowlisted database.
 *
 * Console messages are operator diagnostics, not the translated web surface.
 */
final class SeedPerformanceDatasetCommand extends Command
{
    protected $signature = 'seed:performance-dataset
        {--profile= : Fixed dataset profile: small or medium}
        {--confirm-database= : Exact connected database name to rebuild}';

    protected $description = 'Rebuild an allowlisted disposable database with a fixed performance dataset';

    public function handle(
        PerformanceDatabaseGuard $guard,
        PerformanceDatasetSeeder $seeder,
    ): int {
        $confirmedDatabase = $this->option('confirm-database');

        try {
            $guard->assertSafe(is_string($confirmedDatabase) ? $confirmedDatabase : '');
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $profile = $this->option('profile');

        if (! is_string($profile) || ! PerformanceDatasetSeeder::supportsProfile($profile)) {
            $this->components->error('--profile must be exactly small or medium.');

            return self::FAILURE;
        }

        $migrationExitCode = $this->call('migrate:fresh', ['--force' => true]);

        if ($migrationExitCode !== self::SUCCESS) {
            $this->components->error('The database rebuild failed; the performance dataset was not seeded.');

            return self::FAILURE;
        }

        /*
         * The seeder repeats the guard before its first write. This command is
         * the intended entry point, but db:seed or a direct container call must
         * not become an unguarded route around the destructive boundary.
         */
        $seeder->run($profile, $confirmedDatabase);

        $counts = PerformanceDatasetSeeder::countsFor($profile);

        $this->components->info(
            "Seeded {$counts['charges']} charges and {$counts['allocations']} allocations for profile [{$profile}].",
        );

        return self::SUCCESS;
    }
}
