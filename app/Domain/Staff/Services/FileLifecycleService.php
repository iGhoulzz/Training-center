<?php

declare(strict_types=1);

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Support\SafeReporting;
use Closure;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
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
            /*
             * Only now do bytes touch storage. If recording the independent
             * receipt failed above, the store closure never runs and there are
             * no untracked bytes to compensate.
             */
            $store();

            $result = DB::transaction(function () use ($dispatchCompensation, $persist, $surroundingTransactions): mixed {
                if ($surroundingTransactions === []) {
                    // No application transaction surrounded us, so this Action's
                    // own transaction is the rollback boundary.
                    DB::afterRollBack($dispatchCompensation);
                }

                return $persist();
            });
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
