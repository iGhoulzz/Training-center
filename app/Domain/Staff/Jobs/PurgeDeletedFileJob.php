<?php

declare(strict_types=1);

namespace App\Domain\Staff\Jobs;

use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Models\PendingFileDeletion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Destroy the bytes recorded by one pending_file_deletions row, then remove the
 * row.
 *
 * STEP 3 OF THE DELETION PIPELINE
 * -------------------------------
 *   1. One transaction writes a PendingFileDeletion per file and removes the
 *      owning row(s).
 *   2. After that transaction commits, one of these jobs is dispatched per
 *      pending row.
 *   3. This job deletes the file and then deletes its own pending row.
 *
 * The pending row is the receipt: while it exists, the system still believes a
 * file needs destroying. Removing it only after a confirmed delete is what makes
 * a crashed worker recoverable rather than a silent leak.
 *
 * ON FAILURE IT THROWS, DELIBERATELY
 * ----------------------------------
 * Throwing is the retry mechanism. Catching the failure and returning would mark
 * the job complete, leaving a document the centre is no longer entitled to hold
 * sitting on disk with nothing recording that fact. The catch below records the
 * attempt for an operator and re-raises untouched; it does not convert, wrap, or
 * suppress.
 *
 * After $tries attempts the job lands in failed_jobs, where it is visible, and
 * its pending row is still present for a sweep to re-dispatch.
 */
class PurgeDeletedFileJob implements ShouldQueue
{
    use Queueable;

    /**
     * Five attempts spread over roughly twenty minutes by the backoff below.
     *
     * A disk that is unreachable for twenty minutes is an outage a human needs
     * to see, not something to keep retrying silently.
     */
    public int $tries = 5;

    public function __construct(
        public readonly int $pendingFileDeletionId,
    ) {}

    /**
     * Escalating waits between attempts, in seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(): void
    {
        $pending = PendingFileDeletion::query()->find($this->pendingFileDeletionId);

        // Already purged — a duplicate dispatch or a retry that raced the
        // successful attempt. Nothing to do, and nothing to report.
        if (! $pending instanceof PendingFileDeletion) {
            return;
        }

        try {
            $disk = Storage::disk($pending->disk);

            // The disk is configured with throw => false, so a failed unlink
            // comes back as `false` rather than an exception. Deleting a file
            // that is already absent still returns true, so a false here means
            // a real failure and not a double delete.
            if (! $disk->delete($pending->path)) {
                throw FileStorageException::deleteFailed($pending->disk, $pending->path);
            }
        } catch (Throwable $exception) {
            $this->recordFailure($pending, $exception);

            throw $exception;
        }

        // Only now: the bytes are confirmed gone, so the receipt can go too.
        $pending->delete();
    }

    /**
     * Persist why this attempt failed, so a stuck row explains itself without
     * anyone having to open the failed_jobs payload.
     */
    private function recordFailure(PendingFileDeletion $pending, Throwable $exception): void
    {
        $pending->update([
            'attempts' => $pending->attempts + 1,
            'last_error' => Str::limit($exception->getMessage(), 1000, ''),
        ]);
    }
}
