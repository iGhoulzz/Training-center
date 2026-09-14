# Phase 3.5 Task 5 — Outstanding Aged Query Plan Report

## Outcome

The Outstanding Aged report now evaluates `ChargeBalance::outstandingSql()` once
per charge, filters the selected `ChargeBalance::OUTSTANDING_ALIAS` with
`HAVING`, and joins only `enrollments.student_id` through the enrollment-domain
query boundary. The unused batch/course catalogue path is gone.

The task also adds the reusable destructive-tooling boundary and deterministic
payment-heavy fixture required by Task 11:

```text
php artisan seed:performance-dataset --profile=small|medium --confirm-database=<name>
```

The command runs `migrate:fresh` only after every safety check succeeds. It does
not selectively delete application, finance, or activity rows.

## Dataset and safety decisions

- `PerformanceDatabaseGuard::assertSafe(string): void` is the single boundary
  for the production refusal, exact connected-database confirmation, and literal
  allowlist check.
- The guard checks production first, before opening a database connection.
- The allowlist is fixed in code as `training_center_test` and
  `training_center_performance`; it cannot be expanded through an environment
  variable that merely repeats the command target.
- `PerformanceDatasetSeeder` requires the confirmed database and invokes the
  same guard before `DatabaseSeeder` or any fixture write. Direct invocation is
  therefore not a bypass.
- Profiles are fixed: `small` creates 1,000 charges and 2,000 allocations;
  `medium` creates 4,000 charges and 8,000 allocations.
- Every charge belongs to a deterministic student/enrollment and has two
  standing payments, two matching cash tenders, and two matching allocations.
  A charge is `1000.000`, each allocation is `200.000`, and the expected derived
  outstanding amount is `600.000`.
- No balance, paid amount, payment total, or tender total is stored.
- Bulk inserts deliberately bypass model events so the synthetic performance
  fixture does not generate thousands of append-only activity entries. This is
  guarded tooling for a freshly rebuilt disposable database, not an application
  write path.

## Strict TDD evidence

### Tooling red and green

The first tooling run preceded every production/tooling file:

```text
php artisan test --compact tests/Feature/Tooling/SeedPerformanceDatasetCommandTest.php
FAILED — 9 tests, 0 passed, 1 assertion, 103177 ms
8 errors: The command "seed:performance-dataset" does not exist.
1 failure: PerformanceDatasetSeeder could not be resolved.
```

After the guard, command, config and seeder were implemented, the complete
focused file passed:

```text
PASSED — 9 tests, 40 assertions, 182790 ms
```

The file independently proves:

1. production refusal before reset;
2. exact-name mismatch refusal before reset;
3. absent-from-allowlist refusal before reset;
4. missing and unknown profile refusal before reset;
5. direct seeder invocation still enforces the guard;
6. the success path erases a pre-existing sentinel by rebuilding the complete
   database;
7. exact small and medium row counts, matching tenders/allocations, and the
   seeded owner account;
8. a second small run rebuilds the same deterministic row values.

The direct-seeder assertion requires the guard's exact mismatch message. It
cannot pass on an unrelated database exception.

### Query-plan red and green

The plan test was written against the old report and failed with two distinct
dependent subquery IDs plus the dead catalogue tables:

```text
php artisan test --compact tests/Feature/Finance/OutstandingAgedPerformanceTest.php
FAILED — 1 test, 4 assertions
Expected one dependent balance lookup per charge; EXPLAIN contained IDs 2 and 3.
Primary tables included batches, courses, enrollments, charges.
```

After the query change:

```text
PASSED — 1 test, 10 assertions
```

The final combined semantics and query-boundary run was:

```text
php artisan test --compact \
  tests/Feature/Finance/OutstandingAgedPerformanceTest.php \
  tests/Feature/Finance/Reports/OutstandingAgedReportTest.php \
  tests/Feature/Finance/EnrollmentQueryServiceTest.php
PASSED — 31 tests, 121 assertions, 22139 ms
```

This preserves aging boundaries, write-off behavior, settlement/reversal
semantics, literal `600.000` derived balances, and the T03 query-service
additions.

## Mutation proof

Three deliberate regressions were applied one at a time, tested, and restored
with patches:

1. Replacing alias `HAVING` with a repeated
   `whereRaw(ChargeBalance::outstandingSql().' > 0')` failed the plan test: two
   distinct dependent subquery IDs were reported instead of one.
2. Replacing the narrow enrollment-student join with
   `joinCatalogueTo(... DIMENSION_COURSE)` failed after 10 assertions because
   the plan contained `batches` and `courses`.
3. Removing the seeder's own `assertSafe()` call failed the direct-invocation
   test because the observed SQL constraint exception did not contain the
   required guard mismatch reason.

The final source restores all three protections.

## Medium measurement

Both measurements used the fixed `medium` fixture: 4,000 relational charges,
8,000 standing payments, 8,000 tenders, and 8,000 allocations. They ran alone
under the repository's shared database lock. Elapsed time is recorded evidence,
not a CI assertion; the regression gate is the plan structure.

The tooling test first proves the public command's `--profile=medium` path
rebuilds and produces those exact counts. The timed plan test then invokes the
same guarded seeder directly inside `RefreshDatabase`, because nesting the
command's `migrate:fresh` inside that test transaction would invalidate the
isolation mechanism. The measurement command was:

```powershell
$env:PERFORMANCE_MEASUREMENT_PROFILE='medium'
php artisan test --compact tests/Feature/Finance/OutstandingAgedPerformanceTest.php
```

### Before

```text
Outstanding aged medium: 13617.895 ms
```

Plan summary:

- primary path began at `batches` (`index`, `Using temporary; Using filesort`),
  then `courses`, `enrollments`, and `charges`;
- dependent subquery IDs `3` and `2` each scanned
  `payment_allocations` as `ALL`, estimated 8,000 rows;
- each dependent lookup then joined `payments` by primary key.

### After

```text
Outstanding aged medium: 277.766 ms
```

Plan summary:

- primary path is `charges` (`ALL`, 4,000 rows) then `enrollments` (`eq_ref`);
- exactly one dependent subquery ID, `2`;
- `payment_allocations` uses `payment_allocations_charge_id_index` as `ref`,
  estimated one row per lookup;
- `payments` remains an `eq_ref` primary-key join;
- no `batches` or `courses` step exists.

The observed run was about 49 times faster. That ratio is not encoded as a
threshold because machine load and optimizer statistics vary; the one-subquery,
indexed, no-catalogue plan is the durable assertion.

## Verification

`vendor/bin/pint --dirty --format agent` formatted the scoped PHP files.

The final fresh focused run combined both new files with the existing report and
query-service suites:

```text
PASSED — 40 tests, 162 assertions, 217683 ms
```

The related localization, application-write-boundary, and database-isolation
guardrails also passed:

```text
PASSED — 224 tests, 281 assertions, 118746 ms
```

`composer verify:fast` completed with exit code 0: Pint passed and static
analysis reported 0 errors. Per the controller's instruction, the full
`composer verify` was not run in this worktree; the controller runs that shared
full-suite gate after review.

## Changed files

- `app/Console/Commands/SeedPerformanceDatasetCommand.php` (new)
- `app/Support/PerformanceDatabaseGuard.php` (new)
- `config/performance.php` (new)
- `database/seeders/PerformanceDatasetSeeder.php` (new)
- `app/Domain/Enrollment/Services/EnrollmentQueryService.php`
- `app/Domain/Finance/Reports/OutstandingAgedReport.php`
- `tests/Feature/Finance/OutstandingAgedPerformanceTest.php` (new)
- `tests/Feature/Tooling/SeedPerformanceDatasetCommandTest.php` (new)
- `.superpowers/sdd/2026-09-07-phase-3.5-hardening-and-feedback/task-5-report.md`
