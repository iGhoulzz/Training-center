<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Exceptions\ActivityLogIsAppendOnlyException;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * The activity log has no cleaning action, and says so out loud.
 *
 * WHY THIS REPLACES THE PACKAGE'S ACTION RATHER THAN REMOVING A COMMAND
 * --------------------------------------------------------------------
 * `activitylog:clean` was the visible door, but it is not the boundary. The
 * command resolves `Config::cleanActivityLogAction()`, and so would a queued
 * job, a scheduled task, or another package — reaching the delete with no
 * artisan invocation in front of it, in exactly the way `SyncUserRolesAction`
 * can be reached with no Filament `Select` in front of it. Closing only the
 * command would have hidden the door and left the path.
 *
 * Overriding the configured action closes every caller at once, and the package
 * explicitly supports it: "These action classes can be overridden ... Your
 * custom classes must extend the originals."
 *
 * IT THROWS RATHER THAN RETURNING ZERO. Returning 0 would let
 * `activitylog:clean` print "Deleted 0 record(s) ... All done!" and exit
 * successfully, which reads as a retention policy that ran and found nothing —
 * the most misleading possible outcome for an operator checking whether the log
 * is being trimmed. A refusal has to be legible as a refusal.
 *
 * DEFENCE IN DEPTH, DELIBERATELY. config/activitylog.php also sets
 * clean_after_days to null, which makes the bare command fail its own validation
 * before it ever reaches here. That second barrier does not cover
 * `--days=30`, and this one does; neither is sufficient alone and both are cheap.
 */
final class RefuseActivityLogCleaning extends CleanActivityLogAction
{
    /**
     * @throws ActivityLogIsAppendOnlyException always
     */
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        throw new ActivityLogIsAppendOnlyException;
    }
}
