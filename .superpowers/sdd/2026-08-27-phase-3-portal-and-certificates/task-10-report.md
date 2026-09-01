# Task 10 — Export retention report

## Implementation

- Added nullable `delete_after` and a defaulted `path_kind` discriminator in
  separate migrations.
- Added `PathKind` and casts on `PendingFileDeletion`; the existing stale sweep
  now excludes receipts whose retention time is still in the future.
- Added `FileLifecycleService::scheduleDeletion()` without changing
  `record()` or its transaction contract. Directory deletion is restricted to
  the configured Filament export disk and an already-canonical
  `filament_exports/{child}` path; root, traversal, dot, empty-segment,
  outside-prefix, and wrong-disk inputs are rejected.
- `PrepareReportCsvExport` records one directory receipt for CSV/XLSX after
  its bytes are written. `GenerateReportPdfJob` records one file receipt only
  after its PDF write succeeds. `PurgeDeletedFileJob` dispatches to file or
  directory removal from the stored kind.

## Files

- `app/Domain/Finance/Exports/PrepareReportCsvExport.php`
- `app/Domain/Finance/Jobs/GenerateReportPdfJob.php`
- `app/Domain/Staff/Enums/PathKind.php`
- `app/Domain/Staff/Jobs/PurgeDeletedFileJob.php`
- `app/Domain/Staff/Models/PendingFileDeletion.php`
- `app/Domain/Staff/Services/FileLifecycleService.php`
- `database/migrations/2026_08_31_200224_add_delete_after_to_pending_file_deletions_table.php`
- `database/migrations/2026_08_31_200225_add_path_kind_to_pending_file_deletions_table.php`
- `tests/Feature/Finance/ExportRetentionTest.php`
- `tests/Feature/Staff/PendingFileDeletionSweepTest.php`

## RED / GREEN evidence

- Initial RED suite: 32 tests, 22 passed, 6 failed, 4 errors: receipts,
  schema columns, and `scheduleDeletion()` were absent.
- Path-kind RED: `Failed asserting that false is true.`; then GREEN: 1 test,
  1 passed.
- Directory-sweep RED left
  `filament_exports/expired-export/headers.csv` behind. The cause was the
  test's uncommitted `RefreshDatabase` receipt being intentionally invisible to
  the compensation connection; the new test correctly uses the existing
  `DatabaseTruncation` pattern. GREEN: 1 test, 1 passed, 4 assertions.
- Canonical-fence RED: 3 tests, 1 passed, 2 failed; both dot and empty
  segments did not throw. GREEN: 3 tests, 3 passed, 3 assertions.
- Final focused run:
  `{"tool":"pest","result":"passed","tests":43,"passed":43,"assertions":116,"duration_ms":38015}`.

Mutation evidence: temporarily removing the prefix fence made the outside-path
test fail with `Exception "InvalidArgumentException" not thrown.` The fence was
then restored. The dot/empty-segment tests cover the additional
`WhitespacePathNormalizer` prefix-collapse path found in review.

## Verification

Final `composer verify` completed with exit code 0:

```text
./composer.json is valid
{"tool":"pint","result":"passed"}
PHPStan: errors 0
{"tool":"pest","result":"passed","tests":2045,"passed":2044,"assertions":6507,"duration_ms":1237021,"skipped":1}
```

## Scope and deviation

- `routes/console.php` was not touched; retention uses the existing hourly
  sweep.
- `FileLifecycleService::record()` and existing caller behavior remain
  unchanged; `FileLifecycleTransactionTest` passed in the focused suite.
- Authorized T14 deviation: added
  `app/Domain/Staff/Enums/PathKind.php`, required for the persisted path-kind
  discriminator and explicit purge behavior.

## Commit

Implementation commit: `7391e82` (`feat: retain generated report exports for seven days`).
