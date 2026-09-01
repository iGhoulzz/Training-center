<?php

declare(strict_types=1);

namespace App\Domain\Staff\Models;

use App\Domain\Staff\Enums\PathKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A file whose owning row has been removed and whose bytes are still on disk.
 *
 * For an ordinary deletion, written inside the transaction that removes the
 * owning record. For a new upload, briefly used as a provisional write-ahead
 * receipt until the owning transaction commits. PurgeDeletedFileJob removes the
 * receipt only after the bytes are gone, or after proving a committed row owns
 * them. See FileLifecycleService for both orderings.
 *
 * Configuration only — casts and two scopes. The lifecycle lives in the Actions
 * that create these rows and the job that consumes them.
 */
#[Fillable([
    'disk',
    'path',
    'path_kind',
    'delete_after',
    'attempts',
    'last_error',
    'last_swept_at',
])]
class PendingFileDeletion extends Model
{
    /**
     * Rows the reconciliation sweep should hand to a purge job.
     *
     * Everything here is a file the system intended to destroy and has not yet
     * confirmed destroyed. Two conditions, and the second is not decoration:
     *
     *   - written longer than $minutes ago, so a receipt whose original job is
     *     still working through its backoff ladder is left alone; and
     *   - never swept, or last swept longer than $minutes ago, so a receipt the
     *     sweep just re-dispatched is not immediately re-dispatched again. A
     *     re-dispatch gets a fresh job with its own ladder, and the same
     *     threshold governs both waits for the same reason.
     *   - immediately eligible, or past its scheduled deletion time. Retention
     *     changes when a receipt may reach a purge job; it does not change the
     *     sweep's ownership check or starvation-resistant ordering.
     *
     * This is half of the anti-starvation rule. The other half is
     * scopeInSweepOrder(), and NEITHER HALF WORKS ALONE: this one lets a receipt
     * whose stamp has aged past the threshold become eligible again, and an
     * order still led by created_at would then pick it ahead of receipts that
     * have never been swept at all — reinstating exactly the starvation the
     * column was added to remove.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStale(Builder $query, int $minutes = 60): void
    {
        $threshold = now()->subMinutes($minutes);

        $query->where('created_at', '<', $threshold)
            ->where(function (Builder $query) use ($threshold): void {
                $query->whereNull('last_swept_at')
                    ->orWhere('last_swept_at', '<', $threshold);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('delete_after')
                    ->orWhere('delete_after', '<=', now());
            });
    }

    /**
     * The order a bounded sweep must consume the backlog in.
     *
     * Receipts the sweep has NEVER touched come first, whatever their age, so
     * one pass reaches the whole table before any receipt takes a second turn.
     * Then oldest turn first, which rotates steadily. created_at and id break
     * the remaining ties so a bounded page is deterministic rather than whatever
     * the optimizer returns.
     *
     * NULLS FIRST IS STATED RATHER THAN INHERITED. MySQL sorts NULL first on an
     * ascending order and would give this for free, but the property is
     * load-bearing — a database that sorts NULLs last (PostgreSQL's default)
     * would put every never-swept receipt at the BACK and starve the backlog
     * silently, with every test still green because the receipts are all
     * present and the counts all correct.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInSweepOrder(Builder $query): void
    {
        $query->orderByRaw('`last_swept_at` is not null')
            ->orderBy('last_swept_at')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'delete_after' => 'immutable_datetime',
            'last_swept_at' => 'datetime',
            'path_kind' => PathKind::class,
        ];
    }
}
