<?php

declare(strict_types=1);

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A file whose owning row has been removed and whose bytes are still on disk.
 *
 * Written inside the transaction that removes the owning record; deleted by
 * PurgeDeletedFileJob once the bytes are actually gone. See the migration for
 * why the intent has to be committed before the unlink is attempted.
 *
 * Configuration only — casts and one scope. The lifecycle lives in the Actions
 * that create these rows and the job that consumes them.
 */
#[Fillable([
    'disk',
    'path',
    'attempts',
    'last_error',
])]
class PendingFileDeletion extends Model
{
    /**
     * Rows that have been waiting longer than $minutes.
     *
     * The reconciliation query: everything here is a file the system intended
     * to destroy and has not yet confirmed destroyed. A scheduled sweep that
     * re-dispatches these is Task 13's concern (backups and maintenance); the
     * scope lands now so the sweep does not have to invent the definition.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStale(Builder $query, int $minutes = 60): void
    {
        $query->where('created_at', '<', now()->subMinutes($minutes));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
        ];
    }
}
