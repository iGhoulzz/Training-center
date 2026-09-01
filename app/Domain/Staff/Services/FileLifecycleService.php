<?php

declare(strict_types=1);

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Enums\PathKind;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Support\SafeReporting;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The two halves of a durable file deletion, kept in one place so the four
 * Actions that delete files cannot drift apart on the ordering.
 *
 * THE ORDERING IS THE WHOLE POINT
 * -------------------------------
 * Filesystem operations do not participate in database transactions. Deleting
 * bytes inside DB::transaction() destroys them immediately, and a rollback then
 * leaves a surviving row pointing at a file that no longer exists — worse than
 * an orphan, because an orphan is recoverable.
 *
 * For deletion, record() runs INSIDE the transaction that removes the owning
 * row, and dispatchPurges() runs AFTER it commits.
 *
 * Uploads need the mirror image: a provisional receipt is written through an
 * independent connection before bytes exist, then cancelled only after the
 * outermost owning transaction commits. Rollback dispatches that receipt for
 * purge. The job checks ownership before unlinking, so even an ambiguous commit
 * result cannot turn cleanup into a broken row.
 *
 * Callers pass the ordinary-deletion ids from one method to the other rather
 * than this class holding state, because an Action may be invoked twice in one
 * request and a service holding a pending list between calls would purge the
 * wrong files.
 */
final class FileLifecycleService
{
    private const COMPENSATION_CONNECTION = 'file_lifecycle_compensation';

    private const COMPENSATION_QUEUE_CONNECTION = 'file_lifecycle_compensation_queue';

    private const EXPORT_DIRECTORY = 'filament_exports';

    /**
     * Persist a new file and the database state that owns it.
     *
     * A provisional deletion receipt is committed through an independent
     * connection before bytes are written or the owning row is changed. It is
     * cancelled only after the outermost application transaction commits. If
     * storage fails or any surrounding transaction rolls back, the receipt
     * survives and a purge job consumes it.
     *
     * This is deliberately write-ahead. A callback that first writes a receipt
     * after rollback is too late and unsafe: Laravel is still unwinding its
     * transaction manager at that point, so starting another transaction on the
     * same connection can execute unrelated after-commit callbacks from the
     * transaction that just rolled back.
     *
     * @template TResult
     *
     * @param  Closure(): void  $store
     * @param  Closure(): TResult  $persist
     * @return TResult
     *
     * @throws Throwable
     */
    public function persistNewFile(string $disk, string $path, Closure $store, Closure $persist): mixed
    {
        return $this->withNewFileCompensation(
            $disk,
            $path,
            function (Closure $dispatchCompensation, array $surroundingTransactions) use ($store, $persist): mixed {
                $store();

                return DB::transaction(function () use ($dispatchCompensation, $persist, $surroundingTransactions): mixed {
                    if ($surroundingTransactions === []) {
                        DB::afterRollBack($dispatchCompensation);
                    }

                    return $persist();
                });
            },
        );
    }

    /**
     * Persist a file only after its owner has been locked and re-checked.
     *
     * The lock, byte write and pointer write share one transaction. This is the
     * receipt-generation variant of persistNewFile(): two retrying workers
     * cannot both write the same deterministic path, while the same provisional
     * compensation still covers rollback and ambiguous root commits.
     *
     * @template TOwner
     * @template TResult
     *
     * @param  Closure(): (TOwner|false)  $lockOwner  false when another worker already won
     * @param  Closure(): void  $store
     * @param  Closure(TOwner): TResult  $persist
     * @return TResult|false
     *
     * @throws Throwable
     */
    public function persistNewFileWithOwnerLock(
        string $disk,
        string $path,
        Closure $lockOwner,
        Closure $store,
        Closure $persist,
    ): mixed {
        return $this->withNewFileCompensation(
            $disk,
            $path,
            function (Closure $dispatchCompensation, array $surroundingTransactions) use (
                $lockOwner,
                $store,
                $persist,
            ): mixed {
                return DB::transaction(function () use (
                    $dispatchCompensation,
                    $surroundingTransactions,
                    $lockOwner,
                    $store,
                    $persist,
                ): mixed {
                    if ($surroundingTransactions === []) {
                        DB::afterRollBack($dispatchCompensation);
                    }

                    $owner = $lockOwner();

                    if ($owner === false) {
                        return false;
                    }

                    $store();

                    return $persist($owner);
                });
            },
        );
    }

    /**
     * @template TResult
     *
     * @param  Closure(Closure(): void, array<int, DatabaseTransactionRecord>): TResult  $operation
     * @return TResult
     *
     * @throws Throwable
     */
    private function withNewFileCompensation(
        string $disk,
        string $path,
        Closure $operation,
    ): mixed {
        $compensationId = $this->recordCompensation($disk, $path);

        $dispatchCompensation = function () use ($compensationId): void {
            try {
                /*
                 * beforeCommit() is intentional. A rollback callback runs while
                 * Laravel's transaction manager still contains the transaction
                 * it is unwinding; afterCommit() would attach this job to that
                 * stale record. The receipt already committed independently, so
                 * the purge is safe to enqueue immediately.
                 */
                PurgeDeletedFileJob::dispatch($compensationId, true)
                    ->onConnection(self::compensationQueueConnectionName())
                    ->beforeCommit();
            } catch (Throwable $exception) {
                /*
                 * The receipt already exists. Report a queue or sync-worker
                 * failure without replacing the database exception; the stale
                 * receipt remains visible to the reconciliation sweep.
                 */
                $this->reportWithoutThrowing($exception);
            }
        };

        /*
         * Attach cleanup to every surrounding application transaction. The owner
         * can disappear if any one of those savepoints rolls back while an older
         * transaction later commits. Duplicate jobs are intentionally allowed:
         * the job is idempotent, whereas suppressing a later callback could lose
         * cleanup if an earlier database-queue insert rolled back with its
         * savepoint. RefreshDatabase's wrapper is excluded by
         * callbackApplicableTransactions(), as it should be.
         */
        $surroundingTransactions = $this->applicationTransactions();

        foreach ($surroundingTransactions as $transaction) {
            $transaction->addCallbackForRollback($dispatchCompensation);
        }

        try {
            $result = $operation($dispatchCompensation, $surroundingTransactions);
        } catch (Throwable $exception) {
            /*
             * Covers a savepoint failure whose parent catches and commits, and
             * a root commit exception (Laravel does not run rollback callbacks
             * for that path). File ownership is checked again by the purge job,
             * so an ambiguous commit cannot delete bytes a committed row owns.
             */
            $dispatchCompensation();

            throw $exception;
        }

        $cancelCompensation = function () use ($compensationId): void {
            try {
                PendingFileDeletion::on(self::compensationConnectionName())
                    ->whereKey($compensationId)
                    ->delete();
            } catch (Throwable $exception) {
                /*
                 * The owner committed, so this receipt is no longer actionable.
                 * Report a failed cancellation but do not turn a committed save
                 * into a misleading request failure. The purge job independently
                 * checks ownership before deleting, making a later sweep safe.
                 */
                $this->reportWithoutThrowing($exception);
            }
        };

        /*
         * If an outer Filament transaction exists, Laravel retains this callback
         * through nested commits and executes it only after the root commit. With
         * no outer transaction, DB::afterCommit() runs it immediately because
         * the Action's transaction has already committed.
         */
        DB::afterCommit($cancelCompensation);

        return $result;
    }

    /**
     * Commit the intent to destroy each file, returning the receipt ids.
     *
     * MUST be called inside the transaction that removes the owning row(s), so
     * that "the record is gone" and "these bytes must go" become true together
     * or not at all.
     *
     * @param  array<int, array{disk: string, path: string}>  $files
     * @return array<int, int>
     */
    public function record(array $files): array
    {
        $ids = [];

        foreach ($files as $file) {
            if ($file['path'] === '') {
                continue;
            }

            $pending = PendingFileDeletion::query()->create([
                'disk' => $file['disk'],
                'path' => $file['path'],
                'attempts' => 0,
                'last_error' => null,
            ]);

            $ids[] = (int) $pending->getKey();
        }

        return $ids;
    }

    /**
     * Commit the intent to destroy a generated artefact at a future time.
     *
     * Unlike record(), this does NOT belong inside an owning-row transaction —
     * an export owns no row. The artefact already exists on disk when this is
     * called, and the receipt is the only thing that will ever remove it.
     *
     * @throws InvalidArgumentException when a directory is not an export directory.
     */
    public function scheduleDeletion(
        string $disk,
        string $path,
        PathKind $kind,
        CarbonImmutable $deleteAfter,
    ): int {
        if ($kind === PathKind::Directory) {
            $this->guardExportDirectory($disk, $path);
        }

        $pending = PendingFileDeletion::query()->create([
            'disk' => $disk,
            'path' => $path,
            'path_kind' => $kind,
            'delete_after' => $deleteAfter,
            'attempts' => 0,
            'last_error' => null,
        ]);

        return (int) $pending->getKey();
    }

    /**
     * Queue one purge job per receipt.
     *
     * Call this AFTER the transaction returns. afterCommit() is belt and braces
     * on top of that: Filament pages set $hasDatabaseTransactions = true, so an
     * Action invoked from a save hook is already inside an OUTER transaction
     * that has not committed when DB::transaction() inside the Action returns.
     * Without afterCommit() the job would run against rows that a later Halt
     * could still roll back, and it would delete files belonging to a save that
     * never happened.
     *
     * The same mechanism discards the job entirely if that outer transaction
     * rolls back, which is exactly right: the owning row survived, so its file
     * must survive too.
     *
     * @param  array<int, int>  $pendingFileDeletionIds
     */
    public function dispatchPurges(array $pendingFileDeletionIds): void
    {
        foreach ($pendingFileDeletionIds as $id) {
            PurgeDeletedFileJob::dispatch($id)->afterCommit();
        }
    }

    public static function compensationConnectionName(): string
    {
        if (! is_array(config('database.connections.'.self::COMPENSATION_CONNECTION))) {
            $default = (string) config('database.default');
            $configuration = config('database.connections.'.$default);

            if (! is_array($configuration)) {
                throw new \RuntimeException('The default database connection is not configured.');
            }

            /*
             * A distinct connection object (and therefore a distinct PDO) is
             * required. Reusing the primary connection from a rollback callback
             * can re-enter Laravel's transaction manager before it has removed
             * the rolled-back record.
             */
            config([
                'database.connections.'.self::COMPENSATION_CONNECTION => $configuration,
            ]);
        }

        return self::COMPENSATION_CONNECTION;
    }

    /**
     * Use an independent database connection when the queue itself is database
     * backed, so a surrounding rollback cannot erase the compensation job.
     */
    public static function compensationQueueConnectionName(): string
    {
        $default = (string) config('queue.default');
        $configuration = config('queue.connections.'.$default);

        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'database') {
            return $default;
        }

        $ownerConnection = DB::connection()->getName();
        $queueDatabaseConnection = $configuration['connection'] ?? null;

        /*
         * An explicitly different database connection is already independent
         * from the owner's PDO, and workers poll that configured database. Keep
         * it unchanged: redirecting the insert to the application database
         * would strand the job when DB_QUEUE_CONNECTION uses a dedicated queue
         * schema.
         */
        if (
            is_string($queueDatabaseConnection)
            && $queueDatabaseConnection !== ''
            && $queueDatabaseConnection !== $ownerConnection
        ) {
            return $default;
        }

        config([
            'queue.connections.'.self::COMPENSATION_QUEUE_CONNECTION => [
                ...$configuration,
                'connection' => self::compensationConnectionName(),
                'after_commit' => false,
            ],
        ]);

        return self::COMPENSATION_QUEUE_CONNECTION;
    }

    /**
     * Write the provisional cleanup receipt outside the owning transaction.
     */
    private function recordCompensation(string $disk, string $path): int
    {
        $pending = PendingFileDeletion::on(self::compensationConnectionName())->create([
            'disk' => $disk,
            'path' => $path,
            'attempts' => 0,
            'last_error' => null,
        ]);

        return (int) $pending->getKey();
    }

    /**
     * Directory removal is limited to one generated Filament export directory.
     *
     * The stored path may use Windows separators because Filament builds it
     * with DIRECTORY_SEPARATOR, so validation normalizes only for comparison.
     */
    public function guardExportDirectory(string $disk, string $path): void
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $segments = explode('/', $normalizedPath);

        if (
            $disk !== $this->configuredExportDisk()
            || count($segments) !== 2
            || $segments[0] !== self::EXPORT_DIRECTORY
            || $segments[1] === ''
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new InvalidArgumentException('Directory deletion is limited to generated Filament exports.');
        }
    }

    /**
     * Mirror Filament Exporter's private-disk fallback exactly.
     *
     * Filament refuses to generate private exports on the public disk when a
     * local disk exists, and persists `local` on the export row instead. The
     * deletion fence must authorize the same disk Filament actually selected.
     */
    private function configuredExportDisk(): string
    {
        $disk = (string) config('filament.default_filesystem_disk');
        $disks = config('filesystems.disks');

        return $disk === 'public' && is_array($disks) && array_key_exists('local', $disks)
            ? 'local'
            : $disk;
    }

    /**
     * Observability must never replace the business/storage exception.
     *
     * Laravel's exception handler is allowed to throw while reporting (for
     * example, when its logging transport is unavailable). Cleanup already has
     * a durable receipt, so there is nothing safer to do synchronously here —
     * it remains available to a later reconciliation sweep even when both
     * cleanup and its reporting channel are down.
     *
     * The body moved to SafeReporting in P1-T17, on the third caller.
     */
    private function reportWithoutThrowing(Throwable $exception): void
    {
        SafeReporting::report($exception);
    }

    /**
     * Find every active application transaction for the default connection.
     *
     * @return array<int, DatabaseTransactionRecord>
     */
    private function applicationTransactions(): array
    {
        $manager = app('db.transactions');

        $connectionName = DB::connection()->getName();
        $transactions = [];

        foreach ($manager->callbackApplicableTransactions() as $transaction) {
            if ($transaction->connection === $connectionName) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }
}
