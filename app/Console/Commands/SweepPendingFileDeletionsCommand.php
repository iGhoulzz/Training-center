<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Throwable;

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
 * THE RUN IS BOUNDED, AND THE BOUND ROTATES
 * -----------------------------------------
 * A mass profile deletion, or a queue that was down overnight, leaves a large
 * backlog. Each dispatched job opens a transaction and takes locking reads
 * against staff_certificates and staff_profiles, so an unbounded run would turn
 * a backlog into a lock storm on the tables the panel is serving from.
 *
 * THE FIRST VERSION OF THIS COMMAND BOUNDED THE RUN AND THEN STARVED THE TABLE.
 * It ordered by created_at, which never changes, so a page that never drained —
 * a disk that is gone, an ownership check that keeps finding an owner — was
 * selected again by every subsequent run and the receipts behind it were never
 * dispatched at all. The bound stopped being a rate limit and became a permanent
 * ceiling. This docblock claimed the opposite, which is the more useful half of
 * the lesson: the claim was written from the intent rather than from the
 * behaviour, and only a two-run test could tell them apart.
 *
 * last_swept_at is stamped after each successful dispatch, and the sweep order
 * puts never-swept receipts ahead of every swept one. So one pass reaches the
 * whole backlog before any receipt takes a second turn, and rotation continues
 * from there. See PendingFileDeletion::scopeStale() and scopeInSweepOrder() —
 * the two halves of that rule, neither of which works without the other.
 *
 * THERE IS NO ATTEMPT CEILING
 * ---------------------------
 * A receipt that has failed five hundred times is still re-dispatched. Giving up
 * would leave a document the centre is no longer entitled to hold sitting on
 * disk forever, which is the outcome this feature exists to prevent. A
 * permanently failing receipt is meant to stay noisy; the backlog line below is
 * what makes it visible. Rotation is what stops that noise drowning out the rest
 * of the table.
 *
 * THE HANDOFF, IN ORDER
 * ---------------------
 * Dispatch, then stamp — never the reverse. A stamp written first would mark a
 * receipt as handed off when it was not, so a queue outage would push the entire
 * backlog a full threshold into the future for work that never happened. A
 * dispatch failure leaves last_swept_at untouched and the receipt immediately
 * eligible, and the rest of the page is still attempted.
 *
 * The reverse risk — dispatched but not stamped — is a duplicate purge on the
 * next run, and a duplicate purge is a non-event: the job returns early when the
 * receipt is gone, and Storage::delete() reports success for bytes that are
 * already absent.
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
            ->inSweepOrder()
            ->limit($limit)
            ->get(['id', 'last_swept_at']);

        $dispatched = 0;
        $failed = 0;

        foreach ($receipts as $receipt) {
            try {
                /*
                 * Bus::dispatch rather than PurgeDeletedFileJob::dispatch, so
                 * the queue write happens HERE and its failure is catchable
                 * here. The static helper returns a PendingDispatch that only
                 * reaches the queue when the temporary is destructed, which
                 * makes "did this dispatch fail" depend on refcount timing
                 * rather than on control flow.
                 *
                 * true: run the ownership check. Never conditional — see the
                 * class docblock. An unchecked dispatch here deletes owned bytes.
                 */
                Bus::dispatch(new PurgeDeletedFileJob((int) $receipt->getKey(), true));
            } catch (Throwable $exception) {
                /*
                 * ONE POISONED RECEIPT MUST NOT BLOCK THE PAGE BEHIND IT, and an
                 * unstamped receipt is immediately eligible again — which is the
                 * whole reason the stamp comes after the dispatch rather than
                 * before it. A queue outage therefore costs nothing: every
                 * receipt is retried on the next run rather than pushed an hour
                 * into the future for a handoff that never happened.
                 *
                 * REPORTED WITHOUT THROWING, AND THAT IS THE LOAD-BEARING PART.
                 * An unguarded report() re-created the starvation this command
                 * was just fixed for: Laravel's handler may throw when its
                 * logging transport is unavailable, the exception escapes this
                 * catch, the loop aborts at the FIRST failed receipt, and that
                 * receipt stays unstamped and first in sweep order — so every
                 * later run stops on it again and everything behind it starves.
                 *
                 * The two failures are correlated rather than independent: a
                 * full disk is the condition this feature exists to reconcile,
                 * and it takes the log channel down with it.
                 *
                 * Counting before reporting is secondary, and honestly so:
                 * reportWithoutThrowing() cannot throw, so the order no longer
                 * changes any outcome and no test pins it. It is kept because it
                 * costs nothing and bounds the damage if a later edit puts a
                 * bare report() back.
                 */
                $failed++;

                $this->reportWithoutThrowing($exception);

                continue;
            }

            $dispatched++;

            /*
             * Only now, and deliberately not inside the try above: a failure
             * writing this stamp is not something to swallow. The SELECT that
             * produced this row used the same connection moments ago, so an
             * UPDATE failing here means the database is in a state the rest of
             * the run has no business continuing through. It propagates, the
             * scheduler reports it, and the receipts already dispatched are
             * simply swept again next run — a duplicate purge is a non-event,
             * which FileLifecycleTransactionTest pins in both of its shapes.
             */
            $receipt->update(['last_swept_at' => now()]);
        }

        $this->components->info("Swept {$dispatched} of {$backlog} stale deletion receipt(s).");

        /*
         * Says so out loud when the bound bites, on its own line and short
         * enough not to wrap. Without it, a run reporting "swept 100" is
         * indistinguishable between a healthy trickle and a backlog growing
         * faster than the sweep drains it. Successive runs reach the remainder
         * because a dispatched receipt is stamped and sorts behind every
         * untouched one, so this is a signal to look at why the purge jobs are
         * failing rather than an error in itself.
         */
        if ($backlog > $dispatched) {
            $this->components->warn(($backlog - $dispatched).' receipt(s) left for later runs.');
        }

        if ($failed > 0) {
            $this->components->error($failed.' receipt(s) could not be handed to the queue.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Observability must never abort the sweep.
     *
     * Laravel's exception handler is allowed to throw while reporting — when its
     * logging transport is unavailable, for instance — and letting that escape
     * would end the run on its first failed receipt, leaving everything behind
     * that receipt unreachable for as long as the condition lasts.
     *
     * Deliberately a near-copy of FileLifecycleService::reportWithoutThrowing(),
     * whose docblock records the same hazard for the same reason. They are eight
     * lines each and duplicated rather than shared, because extracting a helper
     * would mean editing that service — well-reviewed, unchanged on this branch,
     * and carrying no behavioural gain from the move. A third caller is the
     * point at which it should become one thing; noted for T16 rather than done
     * unilaterally here.
     */
    private function reportWithoutThrowing(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Nothing safer to do: the receipt is already unstamped and will be
            // attempted again on the next run, and the run's exit code still
            // says the page did not fully hand off.
        }
    }
}
