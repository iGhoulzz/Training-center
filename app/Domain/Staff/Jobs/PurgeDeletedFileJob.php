<?php

declare(strict_types=1);

namespace App\Domain\Staff\Jobs;

use App\Domain\Staff\Actions\UpdateStaffPhotoAction;
use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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
 *
 * PROVISIONAL UPLOAD RECEIPTS
 * ---------------------------
 * New uploads get a write-ahead receipt so an outer transaction rollback cannot
 * orphan their bytes. Those jobs set $usesCompensationConnection and re-check
 * whether a committed profile/certificate owns the generated path. If it does,
 * only the stale receipt is removed; owned bytes are never touched.
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
        public readonly bool $usesCompensationConnection = false,
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
        $pending = $this->usesCompensationConnection
            ? PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
                ->find($this->pendingFileDeletionId)
            : PendingFileDeletion::query()->find($this->pendingFileDeletionId);

        // Already purged — a duplicate dispatch or a retry that raced the
        // successful attempt. Nothing to do, and nothing to report.
        if (! $pending instanceof PendingFileDeletion) {
            return;
        }

        /*
         * A provisional upload receipt may outlive a successful commit if its
         * after-commit cancellation failed or the database reported an
         * ambiguous commit result. Generated paths are never reassigned, so an
         * existing owner means this receipt is stale and the bytes must stay.
         */
        if ($this->usesCompensationConnection && $this->isOwned($pending)) {
            $pending->delete();

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

    private function isOwned(PendingFileDeletion $pending): bool
    {
        $connection = FileLifecycleService::compensationConnectionName();

        return DB::connection($connection)->transaction(function () use ($connection, $pending): bool {
            /*
             * These are current locking reads, not snapshot exists() checks.
             * If the upload's owner transaction is still deciding whether to
             * commit, the indexed lookup waits for that decision before this
             * job is allowed to unlink the generated path.
             */
            $certificate = StaffCertificate::on($connection)
                ->where('disk', $pending->disk)
                ->where('path', $pending->path)
                ->lockForUpdate()
                ->first(['id']);

            if ($certificate instanceof StaffCertificate) {
                return true;
            }

            if ($pending->disk !== UpdateStaffPhotoAction::DISK) {
                return false;
            }

            return StaffProfile::on($connection)
                ->where('profile_photo_path', $pending->path)
                ->lockForUpdate()
                ->first(['id']) instanceof StaffProfile;
        });
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
