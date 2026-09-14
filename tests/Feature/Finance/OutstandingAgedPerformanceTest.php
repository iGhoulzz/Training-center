<?php

declare(strict_types=1);

use App\Domain\Finance\Reports\OutstandingAgedReport;
use Database\Seeders\PerformanceDatasetSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Outstanding-aged query plan
|--------------------------------------------------------------------------
|
| This is a structural regression test, not a wall-clock gate. CI hosts differ,
| but MySQL's EXPLAIN must keep showing one correlated allocation lookup per
| charge and no catalogue tables the report does not consume. Set
| PERFORMANCE_MEASUREMENT_PROFILE=medium to print the audited medium timing and
| plan without changing the default suite's small fixture.
*/
uses(RefreshDatabase::class);

it('uses one dependent balance subquery and only the enrollment identity join', function () {
    $profile = getenv('PERFORMANCE_MEASUREMENT_PROFILE') === 'medium' ? 'medium' : 'small';
    $chargeCount = $profile === 'medium' ? 4_000 : 1_000;
    $database = DB::connection()->getDatabaseName();

    config(['performance.allowed_databases' => [$database]]);

    app(PerformanceDatasetSeeder::class)->run(
        profile: $profile,
        confirmedDatabase: $database,
    );

    $captured = null;
    $capturing = true;

    DB::listen(function (QueryExecuted $query) use (&$captured, &$capturing): void {
        $sql = strtolower($query->sql);

        if ($capturing
            && str_contains($sql, 'from `charges`')
            && str_contains($sql, 'outstanding')) {
            $captured = $query;
        }
    });

    $startedAt = hrtime(true);
    $rows = app(OutstandingAgedReport::class)->asOf('2026-06-30');
    $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
    $capturing = false;

    expect($captured)->toBeInstanceOf(QueryExecuted::class);

    /** @var QueryExecuted $captured */
    $plan = DB::select('EXPLAIN '.$captured->sql, $captured->bindings);
    $dependentSubqueries = collect($plan)
        ->filter(fn (object $step): bool => $step->select_type === 'DEPENDENT SUBQUERY')
        ->pluck('id')
        ->unique()
        ->count();
    $tables = collect($plan)
        ->pluck('table')
        ->filter(fn (mixed $table): bool => is_string($table))
        ->values()
        ->all();
    $planJson = json_encode($plan, JSON_THROW_ON_ERROR);

    if ($profile === 'medium') {
        fwrite(
            STDOUT,
            sprintf(
                "\nOutstanding aged medium: %.3f ms\nEXPLAIN: %s\n",
                $elapsedMilliseconds,
                $planJson,
            ),
        );
    }

    expect($rows)->toHaveCount($chargeCount)
        ->and($rows->first()['outstanding']->toDecimal())->toBe('600.000')
        ->and($dependentSubqueries)->toBe(
            1,
            "Expected one dependent balance lookup per charge. EXPLAIN: {$planJson}",
        )
        ->and($tables)->toContain('charges', 'enrollments', 'payment_allocations', 'payments')
        ->and($tables)->not->toContain('batches', 'courses')
        ->and(strtolower($captured->sql))->not->toContain('join `batches`', 'join `courses`');
});
