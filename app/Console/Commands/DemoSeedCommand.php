<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoGuard;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fill a freshly migrated LOCAL database with a realistic, fictional training-centre
 * history, so the owner can click through every admin screen.
 *
 * Every row is written by the application's own Actions (see database/seeders/Demo), so
 * the activity log, references, receipts and reports are exactly what the app produces.
 * The base roles and the owner account are seeded by the demo itself, on its first
 * simulated day, when they are missing.
 *
 * Refuses, with no override flag, through DemoGuard (which DemoSeeder repeats before its
 * own first write):
 * - any environment other than local or testing, production above all;
 * - a database that already holds students, courses, charges, payments or payroll runs.
 *
 * Console messages are operator diagnostics, not the translated web surface.
 */
final class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Fill a freshly migrated local database with fictional demo data';

    public function handle(DemoSeeder $seeder): int
    {
        $refusal = DemoGuard::refusal();

        if ($refusal !== null) {
            foreach ($refusal as $line) {
                $this->components->error($line);
            }

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $seeder->run();
        } catch (Throwable $exception) {
            $this->components->error('The demo seed failed part-way: '.$exception->getMessage());
            $this->components->error('Run php artisan migrate:fresh --seed before trying again.');

            return self::FAILURE;
        }

        $summary = $seeder->summary();

        $this->components->info(sprintf('Demo data created in %.1f seconds.', microtime(true) - $started));
        $this->table(
            ['What', 'How many'],
            array_map(static fn (string $label, int|string $value): array => [$label, (string) $value], array_keys($summary), array_values($summary)),
        );

        $this->newLine();
        $this->components->info('Demo logins (admin panel at /admin, student portal at /portal)');
        $this->table(
            ['Name', 'Email', 'Password', 'Role', 'State'],
            array_map(static fn (array $login): array => [
                $login['name'], $login['email'], $login['password'], $login['role'], $login['status'],
            ], $seeder->logins()),
        );
        $this->line('Portal students must change their temporary password at first sign-in.');

        return self::SUCCESS;
    }
}
