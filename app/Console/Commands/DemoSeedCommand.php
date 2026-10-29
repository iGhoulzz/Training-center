<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fill a freshly migrated LOCAL database with a realistic, fictional training-centre
 * history, so the owner can click through every admin screen.
 *
 * Every row is written by the application's own Actions (see database/seeders/Demo), so
 * the activity log, references, receipts and reports are exactly what the app produces.
 *
 * Refuses, with no override flag:
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
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('demo:seed only runs in the local or testing environment, never in production.');

            return self::FAILURE;
        }

        $occupied = $this->occupiedBy();

        if ($occupied !== null) {
            $this->components->error("The database already holds {$occupied}; demo:seed is for a fresh database.");
            $this->components->error('Run php artisan migrate:fresh --seed first.');

            return self::FAILURE;
        }

        if (! $this->baseSeeded()) {
            $this->components->info('Roles or the owner account are missing; running the base seeders first.');
            $this->call('db:seed', ['--class' => DatabaseSeeder::class]);
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

    /** What the database already holds that this command would collide with, if anything. */
    private function occupiedBy(): ?string
    {
        return match (true) {
            PayrollRun::query()->exists() => 'payroll runs',
            Payment::query()->exists() => 'payments',
            Charge::query()->exists() => 'charges',
            Student::withTrashed()->exists() => 'students',
            Course::query()->exists() => 'courses',
            default => null,
        };
    }

    private function baseSeeded(): bool
    {
        return Role::query()->whereIn('name', ['super_admin', 'admin', 'staff', 'student'])->count() === 4
            && User::query()->where('email', 'owner@example.test')->exists();
    }
}
