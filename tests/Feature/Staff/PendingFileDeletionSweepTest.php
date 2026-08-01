<?php

declare(strict_types=1);

use App\Console\Commands\SweepPendingFileDeletionsCommand;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * The reconciliation sweep (P1-T15, domain-integrity finding 2).
 *
 * scopeStale() shipped in T06b with no caller and nothing scheduled, so a purge
 * job that exhausted its five attempts left the file on disk permanently — and
 * in every nightly backup from then on — after the centre had decided to destroy
 * it. The receipt was the durability guarantee and nothing ever read it.
 *
 * These tests are about WHICH receipts a run picks up and HOW it dispatches
 * them. Whether the dispatched job then does the right thing to the bytes is
 * proven end to end in FileLifecycleTransactionTest, which runs outside
 * RefreshDatabase's wrapper so the job's ownership read on the independent
 * compensation connection can actually see committed rows.
 */
uses(RefreshDatabase::class);

/**
 * A receipt that was written $minutesOld minutes ago.
 *
 * created_at is written straight through the query builder rather than through
 * Eloquent, because the model sets timestamps on save and would overwrite the
 * very age these tests turn on.
 */
function agedReceipt(int $minutesOld, ?string $path = null): PendingFileDeletion
{
    $pending = PendingFileDeletion::query()->create([
        'disk' => 'private',
        'path' => $path ?? 'staff-certificates/'.Str::ulid()->toString().'.pdf',
        'attempts' => 0,
        'last_error' => null,
    ]);

    DB::table('pending_file_deletions')
        ->where('id', $pending->getKey())
        ->update(['created_at' => now()->subMinutes($minutesOld)]);

    return $pending->refresh();
}

/** The scheduled event for a given artisan command, or null. */
function sweepScheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
}

/** The ids the run pushed, in the order it pushed them. */
function dispatchedReceiptIds(): array
{
    return collect(Queue::pushedJobs()[PurgeDeletedFileJob::class] ?? [])
        ->map(fn (array $pushed): int => $pushed['job']->pendingFileDeletionId)
        ->all();
}

/*
|--------------------------------------------------------------------------
| Which receipts a run picks up
|--------------------------------------------------------------------------
*/

it('re-dispatches a receipt that outlived the purge job it belonged to', function () {
    Queue::fake();

    $pending = agedReceipt(90);

    $this->artisan('files:sweep-pending-deletions')->assertSuccessful();

    Queue::assertPushed(
        PurgeDeletedFileJob::class,
        fn (PurgeDeletedFileJob $job): bool => $job->pendingFileDeletionId === (int) $pending->getKey(),
    );
});

it('leaves a fresh receipt to the job that is still retrying it', function () {
    /*
     * A receipt written moments ago almost certainly belongs to a job that is
     * queued, running, or between backoff attempts. Re-dispatching it would
     * duplicate work the normal path is already doing, and would put a second
     * job against the same path while the first is mid-flight.
     */
    Queue::fake();

    agedReceipt(5);

    $this->artisan('files:sweep-pending-deletions')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('waits longer than the purge job\'s own retry ladder before calling a receipt stale', function () {
    /*
     * THE TWO NUMBERS ARE COUPLED, SO THE COUPLING IS ASSERTED.
     *
     * PurgeDeletedFileJob retries on a backoff ladder. Sweeping sooner than the
     * end of that ladder means the sweep competes with the job's own retries
     * rather than picking up after they are exhausted. Derived from backoff()
     * rather than repeated as a literal, so lengthening the ladder without
     * revisiting the threshold fails here instead of quietly overlapping.
     */
    $ladderSeconds = array_sum((new PurgeDeletedFileJob(1))->backoff());

    expect(SweepPendingFileDeletionsCommand::DEFAULT_STALE_MINUTES * 60)->toBeGreaterThan(
        $ladderSeconds,
        'The sweep calls a receipt stale before its purge job has finished retrying, so the '
        .'two now race for the same file.',
    );
});

it('keeps re-dispatching a receipt that has already failed many times', function () {
    /*
     * NO ATTEMPT CEILING, DELIBERATELY.
     *
     * Abandoning a receipt after N failures would leave a document the centre is
     * no longer entitled to hold sitting on disk forever, which is the exact
     * outcome this feature exists to prevent. A permanently failing receipt is
     * meant to stay noisy — the noise is what gets an operator to look.
     */
    Queue::fake();

    $pending = agedReceipt(90);
    DB::table('pending_file_deletions')
        ->where('id', $pending->getKey())
        ->update(['attempts' => 500, 'last_error' => 'the disk has been unreachable for weeks']);

    $this->artisan('files:sweep-pending-deletions')->assertSuccessful();

    Queue::assertPushed(
        PurgeDeletedFileJob::class,
        fn (PurgeDeletedFileJob $job): bool => $job->pendingFileDeletionId === (int) $pending->getKey(),
    );
});

it('succeeds on an empty backlog', function () {
    // The nightly no-op has to be a no-op. A sweep that exits non-zero with
    // nothing to do pages somebody every hour until they mute it.
    Queue::fake();

    $this->artisan('files:sweep-pending-deletions')->assertSuccessful();

    Queue::assertNothingPushed();
});

/*
|--------------------------------------------------------------------------
| How it dispatches them
|--------------------------------------------------------------------------
*/

it('always dispatches with the ownership check enabled', function () {
    /*
     * THE SAFETY PROPERTY OF THE WHOLE SWEEP.
     *
     * pending_file_deletions holds two kinds of row and NOTHING ON THE ROW SAYS
     * WHICH: an ordinary deletion receipt, whose owning record was removed in
     * the same transaction, and a provisional upload receipt, whose owner may
     * have gone on to commit. Dispatching the second kind without the ownership
     * check unlinks the bytes of a file a live row still points at — a staff
     * certificate that silently 404s from then on.
     *
     * So the sweep never guesses. It always dispatches the checked form, which
     * is correct for both: an ordinary receipt has no owner to find, so the
     * check finds none and the bytes are destroyed as intended.
     */
    Queue::fake();

    agedReceipt(90);

    $this->artisan('files:sweep-pending-deletions')->assertSuccessful();

    Queue::assertPushed(
        PurgeDeletedFileJob::class,
        fn (PurgeDeletedFileJob $job): bool => $job->usesCompensationConnection === true,
    );
});

/*
|--------------------------------------------------------------------------
| The bound
|--------------------------------------------------------------------------
*/

it('dispatches no more than the limit in one run', function () {
    /*
     * A mass profile deletion, or a queue that was down overnight, leaves
     * thousands of receipts. Each dispatched job opens a transaction and takes
     * locking reads against staff_certificates and staff_profiles, so an
     * unbounded run turns a backlog into a self-inflicted lock storm on the
     * tables the panel is serving from.
     */
    Queue::fake();

    for ($i = 0; $i < 5; $i++) {
        agedReceipt(90 + $i);
    }

    $this->artisan('files:sweep-pending-deletions', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(PurgeDeletedFileJob::class, 2);
});

it('takes the oldest receipts first, so a backlog drains instead of starving', function () {
    /*
     * Without an explicit order the bound is unstable: MySQL may return the same
     * arbitrary page every run, and the receipts outside it are never reached no
     * matter how many runs happen. Oldest first makes the backlog a queue.
     *
     * THIS ASSERTS THE ORDER CLAUSE AS WELL AS THE ROWS, AND THE REASON IS
     * MEASURED RATHER THAN ASSUMED.
     *
     * Deleting the whole ORDER BY from the command changes nothing observable
     * here. EXPLAIN on the sweep's own query reports
     * `type: range, key: pending_file_deletions_created_at_index`, so the
     * staleness filter is served by an ordered range scan over created_at and
     * the rows come back oldest first whether or not the code asked. Rearranging
     * the fixtures does not help — it is index order, not insertion order.
     *
     * This project prefers behaviour to SQL text, because SQL text can assert a
     * true thing by a weak method. Here there is no behaviour to assert against:
     * the clause is the only thing that CAN fail when somebody removes it, and
     * the plan that currently hides its absence is an optimizer choice rather
     * than a guarantee — a dropped index or a different plan on a larger table
     * takes the ordering away with nothing to catch it.
     */
    Queue::fake();

    $youngest = agedReceipt(100);
    $oldest = agedReceipt(300);
    $middle = agedReceipt(200);

    $statements = captureStatements();

    $this->artisan('files:sweep-pending-deletions', ['--limit' => 2])->assertSuccessful();

    // The rows, which the current plan would give us anyway.
    expect(dispatchedReceiptIds())->toBe([
        (int) $oldest->getKey(),
        (int) $middle->getKey(),
    ])->and(dispatchedReceiptIds())->not->toContain((int) $youngest->getKey());

    // The clause, which nothing else pins.
    $select = collect($statements)->first(
        fn (array $statement): bool => str_contains($statement['sql'], 'from `pending_file_deletions`')
            && str_contains($statement['sql'], 'limit'),
    );

    expect($select)->not->toBeNull('The sweep ran no bounded select against pending_file_deletions.');

    // str_contains() rather than expect()->toContain(), which is variadic and
    // reads a failure message as a second expected value — the same trap
    // tests/Pest.php records against expectOneLevelDeeper().
    expect(str_contains($select['sql'], 'order by `created_at` asc'))->toBeTrue(
        'The sweep no longer orders its page, so which receipts a bounded run reaches is '
        .'whatever the optimizer happens to return. SQL: '.$select['sql'],
    );
});

it('reports the backlog it could not reach in one run', function () {
    /*
     * The bound is safe only if somebody can see it biting. A run that says
     * "dispatched 2" and nothing else is indistinguishable between a healthy
     * trickle and a backlog growing faster than the sweep drains it.
     */
    Queue::fake();

    for ($i = 0; $i < 5; $i++) {
        agedReceipt(90 + $i);
    }

    /*
     * Two substrings on two separate lines, not two on one. Each
     * expectsOutputToContain() registers its own Mockery expectation against
     * doWrite(), and a single written line is dispatched to the first matching
     * one only — so two substrings sharing a line can never both be satisfied,
     * and the test would fail against output that plainly contains them.
     */
    $this->artisan('files:sweep-pending-deletions', ['--limit' => 2])
        ->expectsOutputToContain('Swept 2 of 5 stale deletion receipt(s).')
        ->expectsOutputToContain('3 receipt(s) left for later runs.')
        ->assertSuccessful();
});

it('says nothing about a backlog when it reached the whole one', function () {
    // The control for the warning above: it must mark the bound biting, not
    // appear on every run and become background noise.
    Queue::fake();

    agedReceipt(90);

    $this->artisan('files:sweep-pending-deletions')
        ->expectsOutputToContain('Swept 1 of 1 stale deletion receipt(s).')
        ->doesntExpectOutputToContain('left for later runs')
        ->assertSuccessful();
});

it('refuses a limit that would make the run pointless or invalid', function () {
    // --limit=0 sweeps nothing forever while still reporting success, and a
    // negative one reaches MySQL as a syntax error. Both are worth a clear
    // refusal rather than a silent night.
    Queue::fake();

    agedReceipt(90);

    $this->artisan('files:sweep-pending-deletions', ['--limit' => 0])->assertFailed();

    Queue::assertNothingPushed();
});

/*
|--------------------------------------------------------------------------
| The schedule
|--------------------------------------------------------------------------
*/

it('schedules the sweep, because a scope with no caller reconciles nothing', function () {
    expect(sweepScheduledEvent('files:sweep-pending-deletions'))->not->toBeNull(
        'Nothing runs the sweep, so an exhausted purge job still leaves the file on disk forever.',
    );
});

it('sweeps at least as often as it declares receipts stale', function () {
    /*
     * A one-hour staleness threshold swept once a day means a file the centre
     * decided to destroy at 02:00 survives until the next run — including
     * through the 01:30 backup, which is the specific harm reported.
     *
     * Cron expression rather than a named helper: hourly() and hourlyAt() both
     * produce a minute-and-star hour field, and asserting the shape catches a
     * later change to daily() that a "not null" check would not.
     */
    $event = sweepScheduledEvent('files:sweep-pending-deletions');

    [, $hour] = explode(' ', (string) $event->expression);

    expect($hour)->toBe(
        '*',
        'The sweep runs less often than once an hour, so a stale receipt waits '
        .'longer than the threshold that declared it stale.',
    );
});

it('does not let two sweeps overlap', function () {
    // A run that outlives its window would otherwise re-dispatch the same
    // oldest-first page the next run is already working through.
    expect(sweepScheduledEvent('files:sweep-pending-deletions')->withoutOverlapping)->toBeTrue();
});

it('takes its own lock rather than joining the backup pipeline mutex', function () {
    /*
     * THE DECISION, RECORDED.
     *
     * Sharing the backup mutex was considered and rejected: it would let a slow
     * or stuck nightly backup hold file deletion off for hours, and it buys
     * nothing, because ordinary purge jobs already run at arbitrary times and
     * are the same interaction with the archive window. The sweep is not a
     * fourth stage of the backup pipeline and does not queue behind it.
     */
    $sweep = sweepScheduledEvent('files:sweep-pending-deletions')->mutexName();
    $backup = sweepScheduledEvent('backup:run')->mutexName();

    expect($sweep)->not->toBe($backup);
});
