<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

/**
 * Something tried to remove entries from the activity log.
 *
 * The append-only rule is a project non-negotiable: no delete path exists for
 * any role, including super admin. `ActivityPolicy` states it, refuses every
 * mutation ability, and is enforced by tests — but all of that governs the
 * policy, the resource and the pages, and none of it reached the console.
 *
 * `activitylog:clean` ships with the package, was registered and live, and
 * issued `DELETE FROM activity_log WHERE created_at < ?` against a 365-day
 * window this project had configured itself. docs/ENGINEERING.md places commands
 * that use application code inside the trust boundary rather than in the raw-SQL
 * escape hatch, so that was a real hole rather than an accepted administrative
 * operation (P1-T15, group 3 finding H1).
 *
 * Thrown by RefuseActivityLogCleaning, which the config points at in place of
 * the package's own cleaning action — so this closes the ACTION every caller
 * resolves, not merely the command that happens to be the visible door today.
 *
 * The message routes through __() per the no-hardcoded-strings rule, exactly as
 * LastSuperAdminException does: this is a business-rule rejection that can
 * surface to a person, and the two should not read differently because one is
 * usually seen in a panel and the other on a console.
 */
class ActivityLogIsAppendOnlyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('staff.activity_log_append_only'));
    }
}
