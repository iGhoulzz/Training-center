<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use Illuminate\Console\Command;

/**
 * Re-dispatch purge jobs for deletion receipts nothing has consumed.
 *
 * WHY THIS EXISTS
 * ---------------
 * pending_file_deletions is the durability guarantee of the whole file
 * lifecycle: while a row is there, the system still believes a file needs
 * destroying. PurgeDeletedFileJob retries five times and then lands in
 * failed_jobs, leaving its receipt behind for "a sweep to re-dispatch".
 *
 * Until P1-T15 no sweep existed. scopeStale() had no caller and nothing was
 * scheduled, so an exhausted job meant the file stayed on disk permanently —
 * and in every nightly backup from then on — after the centre had decided to
 * destroy it. Every comment in the lifecycle that promises "the receipt remains
 * visible to the reconciliation sweep" was promising this command.
 *
 * THE OWNERSHIP CHECK IS ALWAYS ON
 * --------------------------------
 * The table holds two kinds of row and NOTHING ON THE ROW SAYS WHICH:
 *
 *   - an ordinary deletion receipt, written inside the transaction that removed
 *     the owning record, whose bytes must be destroyed; and
 *   - a provisional upload receipt, whose owning row may have gone on to commit
 *     while the after-commit cancellation failed, whose bytes must be KEPT.
 *
 * Dispatching the unchecked form would unlink a file a live row still points
 * at — a staff certificate that silently 404s from then on. So this command
 * never guesses: it always dispatches the checked form, which is correct for
 * both kinds. An ordinary receipt has no surviving owner for the check to find,
 * so its bytes are destroyed exactly as intended.
 *
 * That check is a locking read, and P1-T15's G2-U2 experiment confirmed it
 * BLOCKS on an in-flight upload rather than deadlocking against it. This command
 * changes nothing about that ordering; it only decides which receipts reach it.
 *
 * THE RUN IS BOUNDED
 * ------------------
 * A mass profile deletion, or a queue that was down overnight, leaves a large
 * backlog. Each dispatched job opens a transaction and takes locking reads
 * against staff_certificates and staff_profiles, so an unbounded run would turn
 * a backlog into a lock storm on the tables the panel is serving from. Oldest
 * first, so successive runs drain the backlog as a queue rather than re-reading
 * whichever arbitrary page MySQL happens to return.
 *
 * THERE IS NO ATTEMPT CEILING
 * ---------------------------
 * A receipt that has failed five hundred times is still re-dispatched. Giving up
 * would leave a document the centre is no longer entitled to hold sitting on
 * disk forever, which is the outcome this feature exists to prevent. A
 * permanently failing receipt is meant to stay noisy; the backlog line below is
 * what makes it visible.
 *
 * CONSOLE OUTPUT IS NOT PART OF THE TRANSLATED SURFACE. lang/ carries the panel
 * and public-site copy that phase 4 translates into Arabic. This is operator
 * output on a server console, read by whoever administers the deployment, and
 * routing it through __() would put maintenance diagnostics into the catalogue a
 * translator works through.
 */
final class SweepPendingFileDeletionsCommand extends Command
{
    /**
     * How long a receipt must sit before a run treats it as abandoned.
     *
     * Must exceed PurgeDeletedFileJob's whole backoff ladder. Sweeping sooner
     * would put a second job against a path the first is still retrying, rather
     * than picking up after those retries are exhausted. PendingFileDeletionSweepTest
     * asserts the relationship against backoff() rather than against this
     * number, so lengthening the ladder fails the build.
     */
    public const DEFAULT_STALE_MINUTES = 60;

    /**
     * How many receipts one run may dispatch.
     *
     * Sized to drain a realistic overnight backlog within a few hourly runs
     * while staying far below anything that would contend with panel traffic.
     */
    public const DEFAULT_LIMIT = 100;

    protected $signature = 'files:sweep-pending-deletions
        {--minutes= : How many minutes old a receipt must be to count as stale}
        {--limit= : The most receipts this run may dispatch}';

    protected $description = 'Re-dispatch purge jobs for file deletion receipts that no job has consumed';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? self::DEFAULT_STALE_MINUTES);
        $limit = (int) ($this->option('limit') ?? self::DEFAULT_LIMIT);

        /*
         * Refused rather than clamped. A --limit of 0 sweeps nothing while still
         * reporting success, which is a silent night; a negative one reaches
         * MySQL as a syntax error. Substituting the default for either would
         * hide an operator's mistake behind a run that looks normal.
         */
        if ($minutes < 1 || $limit < 1) {
            $this->components->error('--minutes and --limit must both be at least 1.');

            return self::FAILURE;
        }

        /*
         * Read on the ordinary application connection. The independent
         * compensation connection exists to escape a transaction manager that is
         * mid-rollback, and a scheduled command is never in that position; both
         * names resolve to the same database, so this sees every receipt
         * whichever half of the lifecycle wrote it.
         */
        $stale = PendingFileDeletion::query()->stale($minutes);

        $backlog = (int) $stale->clone()->count();

        $receipts = $stale->clone()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        foreach ($receipts as $receipt) {
            // true: run the ownership check. Never conditional — see the class
            // docblock. An unchecked dispatch here deletes owned bytes.
            PurgeDeletedFileJob::dispatch((int) $receipt->getKey(), true);
        }

        $dispatched = $receipts->count();

        $this->components->info("Swept {$dispatched} of {$backlog} stale deletion receipt(s).");

        /*
         * Says so out loud when the bound bites, on its own line and short
         * enough not to wrap. Without it, a run reporting "swept 100" is
         * indistinguishable between a healthy trickle and a backlog growing
         * faster than the sweep drains it. Successive runs do reach the
         * remainder — they are ordered oldest first — so this is a signal to
         * look at why the purge jobs are failing, not an error in itself.
         */
        if ($backlog > $dispatched) {
            $this->components->warn(($backlog - $dispatched).' receipt(s) left for later runs.');
        }

        return self::SUCCESS;
    }
}
