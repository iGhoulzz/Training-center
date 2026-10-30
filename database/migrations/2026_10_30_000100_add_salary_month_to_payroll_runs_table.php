<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ONE MONTHLY SALARY RUN PER CALENDAR MONTH, held at the database (P4-T40).
 *
 * ```sql
 * salary_month DATE
 *     GENERATED ALWAYS AS (CASE WHEN type = 'monthly_salary' THEN period_start END) STORED,
 * UNIQUE KEY uniq_salary_run_per_month (salary_month)
 * ```
 *
 * The same technique `student_certificates.valid_enrollment_id` and the two
 * paid-once columns of `payroll_lines` use. MySQL's unique index does not
 * collide on NULL, and the expression carries a value ONLY for a salary run, so
 * any number of instructor-batch and adjustment runs coexist, while a second
 * salary run starting on the same day collides and is refused. `CreatePayrollRunAction`
 * only ever opens a run whose period is exactly one calendar month, so "the
 * same `period_start`" is "the same month"; the Action also refuses an overlap
 * with an older run of an odd period, which this index alone cannot see.
 *
 * THE COLUMN IS NOT A STORED DERIVED FIGURE
 * -----------------------------------------
 * It is a database-computed copy of a fact column, written by no one, that
 * exists only so a unique index can be built on a conditional value.
 *
 * AN EXISTING DUPLICATE STOPS THE MIGRATION
 * -----------------------------------------
 * If two salary runs already share a `period_start`, adding the index would
 * fail half-way with a bare "duplicate entry". This migration checks first and
 * stops with the run ids instead. It never deletes or rewrites a run: which of
 * the two is the mistaken one is a decision for a person, and a finalized run is
 * a posted fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = $this->duplicateSalaryRuns();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot allow one salary run per month: these salary runs share a period start. '
                .'Delete the mistaken draft (a finalized run cannot be deleted) and migrate again. '
                .implode('; ', $duplicates),
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payroll_runs
            ADD COLUMN salary_month DATE
                GENERATED ALWAYS AS (
                    CASE WHEN type = 'monthly_salary' THEN period_start END
                ) STORED,
            ADD UNIQUE INDEX uniq_salary_run_per_month (salary_month)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_runs
            DROP INDEX uniq_salary_run_per_month,
            DROP COLUMN salary_month
        SQL);
    }

    /**
     * Salary runs that share a `period_start`, one entry per shared date, each
     * naming the run ids.
     *
     * Public, and given the table, so the check can be exercised against a
     * scratch table that holds duplicates (the real one can no longer).
     *
     * @return list<string> e.g. ["2026-10-01: runs #4, #9"]
     */
    public function duplicateSalaryRuns(string $table = 'payroll_runs'): array
    {
        return DB::table($table)
            ->where('type', 'monthly_salary')
            ->orderBy('id')
            ->get(['id', 'period_start'])
            ->groupBy('period_start')
            ->filter(fn (Collection $runs): bool => $runs->count() > 1)
            ->map(fn (Collection $runs, string $date): string => $date.': runs #'.$runs->pluck('id')->implode(', #'))
            ->values()
            ->all();
    }
};
