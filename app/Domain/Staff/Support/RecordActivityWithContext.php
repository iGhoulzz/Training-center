<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Attaches the request context every audit entry has to carry.
 *
 * Swapped in through `activitylog.actions.log_activity`, which the package
 * documents as an override point. It is the ONE place both kinds of entry pass
 * through:
 *
 *   - model-generated entries, from the LogsActivity concern on each model
 *   - explicit entries, from activity() calls for events with no model write —
 *     logins, password resets, pivot changes, cascaded deletes
 *
 * A model's own beforeActivityLogged() hook cannot do this job. That hook is
 * invoked on the activity's SUBJECT, and an auth event has no subject at all, so
 * the events where the IP matters most would be exactly the ones missing it.
 *
 * WHY THE IP MAY LEGITIMATELY BE NULL
 * -----------------------------------
 * A seeder, a scheduled job or a console command has no request and therefore no
 * client address. Recording null there is the honest answer; inventing "127.0.0.1"
 * would make a system operation indistinguishable from somebody working locally.
 * ActivityLogTest pins the null case alongside the populated one.
 *
 * The causer is READ here, to snapshot its name, but never SET or overridden.
 * Who the causer is remains the package's decision — overriding it would silently
 * re-attribute entries whose causer was chosen on purpose, as SystemRoleWriter's
 * anonymous entries are. This only records what that decision was.
 *
 * WHY NOT LogActivityAction::beforeLogging()
 * ------------------------------------------
 * The package also exposes a static closure registry, which is fewer lines than
 * this class. It appends to a STATIC array, and statics outlive the application
 * instance: registering from a service provider means every app reboot in a test
 * process pushes another copy, so a full suite run accumulates hundreds of
 * identical callbacks. Correct in effect, and a leak.
 *
 * Resolving from config has no global mutable state — the container builds this
 * per request, and the test suite gets the same object graph as production.
 */
final class RecordActivityWithContext extends LogActivityAction
{
    public function execute(Model $activity, string $description): Model
    {
        /*
         * Reached through the attribute API rather than ->properties, because the
         * parent types this parameter as Model and `properties` belongs to the
         * activity model. Going through getAttribute()/setAttribute() keeps the
         * cast (the column is a collection) without narrowing a signature the
         * package owns.
         */
        $properties = $activity->getAttribute('properties');

        $properties = $properties instanceof Collection ? $properties : collect();

        $properties = $properties->put('ip', request()->ip());

        /*
         * THE ACTOR'S NAME IS SNAPSHOTTED, NOT LOOKED UP LATER.
         *
         * causer is a relation to a soft-deleting model, so resolving it at read
         * time returns NULL once the account is deleted — and the panel would then
         * render a real person's action as "System", which is not a cosmetic
         * problem: it says a machine did something a human did, in the one table
         * that exists to answer who did what.
         *
         * A snapshot also survives a RENAME, which a live lookup does not. An
         * audit trail should say who the actor was at the time, not who the row
         * happens to be called today.
         *
         * causer_id stays on the row, so the account is still identifiable even
         * when its name has changed or the record is gone.
         */
        $causer = $activity->getAttribute('causer');

        if ($causer instanceof Model) {
            $name = $causer->getAttribute('name');

            if (is_string($name) && $name !== '') {
                $properties = $properties->put('causer_name', $name);
            }
        }

        $activity->setAttribute('properties', $properties);

        return parent::execute($activity, $description);
    }
}
