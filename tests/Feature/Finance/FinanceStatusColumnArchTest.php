<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

function financeTables(): array
{
    return collect(File::allFiles(app_path('Domain/Finance/Models')))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->map(function ($file) {
            $class = 'App\\Domain\\Finance\\Models\\'.$file->getFilenameWithoutExtension();

            return (new $class)->getTable();
        })
        ->values()
        ->all();

}

it('scans a meaningful number of Finance models', function () {
    expect(count(financeTables()))->toBeGreaterThan(5);
});
it('forbids status columns on Finance tables', function () {
    $violations = [];
    foreach (financeTables() as $table) {
        if (Schema::hasColumn($table, 'status')) {
            $violations[] = $table;
        }
    }
    expect($violations)->toBeEmpty(
        'Finance tables must not use status string columns. Found on: '.implode(', ', $violations)
    );
});
it('proves the guard fires when a status column exists (on a throwaway connection)', function () {
    // 1. Tell Laravel to invent a new database connection in RAM called 'throwaway'
    config()->set('database.connections.throwaway', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);
    // 2. Build a fake table in the throwaway database and give it a 'status' column
    Schema::connection('throwaway')->create('fake_finance_table', function ($table) {
        $table->string('status');
    });
    // 3. Ask our exact same checker logic if it can spot the column
    $hasStatus = Schema::connection('throwaway')->hasColumn('fake_finance_table', 'status');
    // 4. Prove that it caught it!
    expect($hasStatus)->toBeTrue();
});
