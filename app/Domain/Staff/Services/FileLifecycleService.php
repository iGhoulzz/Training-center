<?php

declare(strict_types=1);

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;

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
 * So: record() runs INSIDE the transaction that removes the owning row, and
 * dispatchPurges() runs AFTER it commits. Nothing here touches a disk.
 *
 * Callers pass the pending ids from one to the other rather than this class
 * holding state, because an Action may be invoked twice in one request and a
 * service holding a pending list between calls would purge the wrong files.
 */
final class FileLifecycleService
{
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
}
