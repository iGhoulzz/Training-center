<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The audit trail for one model, on the model-event boundary.
 *
 * THE BOUNDARY IS THE MODEL EVENT, NOT THE ACTION
 * -----------------------------------------------
 * Spatie's concern registers created/updated/deleted/restored listeners on the
 * model itself, so a Filament form write, a console command, a seeder and an
 * Action all log identically. Putting the audit call inside our Actions instead
 * would duplicate it across every Action AND miss every ordinary Eloquent write
 * — which is most of the ways a row actually changes.
 *
 * Explicit activity() calls are therefore confined to what no model event can
 * see: pivot writes (attaching a role fires nothing on User), auth events,
 * database-cascaded deletes, and security events whose columns are excluded.
 *
 * EVERY MODEL DECLARES AN ALLOWLIST — logOnly(), NEVER logFillable()
 * -----------------------------------------------------------------
 * A column added later is unlogged until somebody decides it belongs, rather
 * than logged the moment it appears. That inverts the failure mode from "leaks
 * by default" to "misses by default", which is the right direction for a table
 * that will hold names, national IDs and, one careless migration away, secrets.
 *
 * Implementers supply auditedAttributes(). config('activitylog.default_except_attributes')
 * is a backstop beneath this, for a model ever switched to logAll().
 *
 * FORCE DELETES NEED NO SPECIAL HANDLING
 * --------------------------------------
 * Laravel's forceDelete() calls delete(), so `deleted` fires and is recorded.
 * Adding a forceDeleted listener would log the same removal twice.
 * ActivityLogTest pins that behaviour rather than trusting this note.
 */
trait RecordsActivity
{
    use LogsActivity;

    /**
     * The columns this model puts in an audit diff. Allowlist, not denylist.
     *
     * @return array<int, string>
     */
    abstract public function auditedAttributes(): array;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->auditedAttributes())
            /*
             * Only what actually changed, and nothing when nothing did. A save()
             * that moves no audited column writes no entry — which is why
             * password changes are recorded as their own event: the columns that
             * moved are excluded, so the diff would be empty and suppressed here,
             * and the security event would vanish silently.
             */
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
