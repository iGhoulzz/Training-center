<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
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
 * AN EXISTING CLASH STOPS THE MIGRATION
 * -------------------------------------
 * Runs made before this rule could start mid-month or cross a month boundary, so
 * two of them can cover the same calendar month with different `period_start`
 * values (1–15 and 16–31 October; 1 Oct – 1 Nov and 1–30 Nov). The index would
 * accept those, and the rule would be false from the first day. So before
 * altering the table this migration lists every calendar month that two or more
 * salary runs touch, and stops with their ids. It never deletes or rewrites a
 * run: which one is the mistake is a decision for a person, and a finalized run
 * is a posted fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = $this->duplicateSalaryRuns();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot allow one salary run per month: these salary runs cover the same calendar month. '
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
     * Every calendar month that two or more salary runs cover, each naming the
     * run ids. A run covers each month from its `period_start` to its
     * `period_end`, inclusive, however few days of it.
     *
     * Public, and given the table, so the check can be exercised against a
     * scratch table that holds clashes (the real one can no longer).
     *
     * @return list<string> e.g. ["2026-10: runs #4, #9"]
     */
    public function duplicateSalaryRuns(string $table = 'payroll_runs'): array
    {
        /** @var array<string, list<int>> $runsByMonth */
        $runsByMonth = [];

        $runs = DB::table($table)
            ->where('type', 'monthly_salary')
            ->orderBy('id')
            ->get(['id', 'period_start', 'period_end']);

        foreach ($runs as $run) {
            $month = CarbonImmutable::parse((string) $run->period_start)->startOfMonth();
            $last = CarbonImmutable::parse((string) ($run->period_end ?? $run->period_start))->startOfMonth();

            for (; $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
                $runsByMonth[$month->format('Y-m')][] = (int) $run->id;
            }
        }

        ksort($runsByMonth);

        return collect($runsByMonth)
            ->filter(fn (array $ids): bool => count($ids) > 1)
            ->map(fn (array $ids, string $month): string => $month.': runs #'.implode(', #', $ids))
            ->values()
            ->all();
    }
};
