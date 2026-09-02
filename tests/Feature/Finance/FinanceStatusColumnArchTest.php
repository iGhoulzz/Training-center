<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Architecture Guard: Finance Domain Status Columns
 *
 * This test enforces phase-2 design §4: no status string column exists on any
 * table in the Finance domain.
 *
 * SCOPE:
 * This proves no column literally named `status` exists on the tables
 * it enumerates. It will pass if a column is named `charge_status` or `state`.
 * The enumeration is DERIVED, not hand-maintained — it scans
 * `app/Domain/Finance/Models` and resolves the table from each class.
 *
 * This specifically exempts `student_certificates.status` because that table
 * belongs to the Enrollment domain, not Finance.
 *
 * @return array<int, string>
 */
function getFinanceDomainTables(): array
{
    return collect(File::allFiles(app_path('Domain/Finance/Models')))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->map(function ($file) {
            // Support recursive model directories
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $file->getRelativePathname());
            $classNameWithoutExtension = str_replace('.php', '', $relativePath);
            $class = 'App\\Domain\\Finance\\Models\\'.$classNameWithoutExtension;

            return (new $class)->getTable();
        })
        ->values()
        ->all();
}

/**
 * The core checker logic.
 * Returns an array of tables that violate the rule.
 *
 * @param  array<int, string>  $tables
 * @return array<int, string>
 */
function findTablesWithStatusColumn(array $tables, ?string $connection = null): array
{
    $violations = [];

    foreach ($tables as $table) {
        // High 1: The guard cannot tell an absent table from a clean one.
        // We must assert the table exists before checking its columns.
        expect(Schema::connection($connection)->hasTable($table))
            ->toBeTrue("The table '{$table}' does not exist on connection '{$connection}'. Migrations may not have run.");

        if (Schema::connection($connection)->hasColumn($table, 'status')) {
            $violations[] = $table;
        }
    }

    return $violations;
}

it('scans a meaningful number of Finance models', function () {
    // Tightened from 5 to 9, since we know there are 10 on disk today.
    expect(count(getFinanceDomainTables()))->toBeGreaterThanOrEqual(9);
});

it('forbids status columns on Finance tables', function () {
    $tables = getFinanceDomainTables();
    $violations = findTablesWithStatusColumn($tables);

    expect($violations)->toBeEmpty(
        'Finance tables must not use status string columns. Found on: '.implode(', ', $violations)
    );
});

it('specifically does not assert against student_certificates', function () {
    $tables = getFinanceDomainTables();

    // Explicitly document that this enrollment table is exempt, as requested by the plan.
    expect($tables)->not->toContain('student_certificates');
});

it('proves the guard fires when a status column exists (on a throwaway connection)', function () {
    config()->set('database.connections.throwaway', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Schema::connection('throwaway')->create('fake_finance_table', function ($table) {
        $table->string('status');
    });

    // Medium 3: We now test the *actual guard logic* (findTablesWithStatusColumn),
    // rather than testing if Laravel's Schema builder works.
    $violations = findTablesWithStatusColumn(['fake_finance_table'], 'throwaway');

    expect($violations)->toContain('fake_finance_table');
});
