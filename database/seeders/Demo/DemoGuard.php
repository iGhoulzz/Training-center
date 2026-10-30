<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PayrollRun;
use RuntimeException;

/**
 * The two rules that make the demo data safe to write, in one place.
 *
 * Both DemoSeedCommand and DemoSeeder::run() ask this before their first write. The
 * command is the intended door, but `php artisan db:seed --class=...DemoSeeder` or a
 * direct container call reaches the seeder without it, and the seeder gives the owner
 * account a public password, so the seeder must refuse on its own account
 * (PerformanceDatasetSeeder repeats its guard for the same reason).
 *
 * - Only a local or testing environment. Production is the case that matters; the
 *   allowlist also refuses staging and anything unnamed.
 * - Only a database holding none of the things the demo creates. There is deliberately
 *   no override: a half-demo database is rebuilt, not topped up.
 */
final class DemoGuard
{
    /**
     * Why the demo must not run here, as one or two sentences, or null when it may.
     *
     * @return list<string>|null
     */
    public static function refusal(): ?array
    {
        if (! app()->environment(['local', 'testing'])) {
            return ['The demo data only loads in the local or testing environment, never in production.'];
        }

        $occupied = match (true) {
            PayrollRun::query()->exists() => 'payroll runs',
            Payment::query()->exists() => 'payments',
            Charge::query()->exists() => 'charges',
            Student::withTrashed()->exists() => 'students',
            Course::query()->exists() => 'courses',
            default => null,
        };

        return $occupied === null
            ? null
            : ["The database already holds {$occupied}; the demo data is for a fresh database.", 'Run php artisan migrate:fresh first (it empties this database).'];
    }

    /** @throws RuntimeException when the demo data must not be written here. */
    public static function assertSafe(): void
    {
        $refusal = self::refusal();

        if ($refusal !== null) {
            throw new RuntimeException(implode(' ', $refusal));
        }
    }
}
