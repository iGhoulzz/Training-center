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
 * Nothing here reads the causer: the package resolves that, and overriding it
 * would silently re-attribute entries whose causer was set on purpose — see
 * SystemRoleWriter, which records anonymous entries even when somebody is
 * logged in.
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

        $activity->setAttribute('properties', $properties->put('ip', request()->ip()));

        return parent::execute($activity, $description);
    }
}
