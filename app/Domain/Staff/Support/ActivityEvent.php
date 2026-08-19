<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

/**
 * Every event name the activity log can record.
 *
 * WHY THIS EXISTS (P1-T15, group 3 finding M5)
 * --------------------------------------------
 * Event labels are looked up by interpolation — `activity.event.{$event}` — and
 * ActivityResource::eventLabel() falls back to the raw event when the key is
 * missing. So a forgotten translation renders as `deleted_by_cascade` rather
 * than as a visible gap, which reads like a deliberate technical label and is
 * exactly how the miss survives review. Confirmed by mutation: deleting
 * `activity.event.deleted_by_cascade` from lang/en/activity.php failed nothing.
 *
 * The completeness test needs to know the whole vocabulary, and A SOURCE SCAN
 * CANNOT LEARN IT. Two call sites pass a variable rather than a literal —
 * SystemRoleWriter routes three events through one private helper, and
 * AssignInstructorAction chooses between two names on a branch — so a regex over
 * `->event('…')` silently misses five of the seventeen. A detector with a hole
 * is worse than none, because it reads as coverage.
 *
 * Declaring the vocabulary once removes the hole instead of papering over it:
 * the Actions reference these constants, and LocalizationTest walks all() and
 * demands a translation for each. Adding an event without a label now fails the
 * build rather than shipping a raw string to an administrator.
 *
 * MODEL EVENTS ARE INCLUDED even though Eloquent writes them rather than this
 * application. They land in the same column, are rendered by the same label
 * lookup, and need the same four keys; leaving them out would have made the
 * completeness check incomplete in precisely the way it exists to prevent.
 */
final class ActivityEvent
{
    /*
     * Written by Eloquent through the LogsActivity concern.
     */
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const RESTORED = 'restored';

    /*
     * Written explicitly, because a database cascade or a pivot write fires no
     * Eloquent event and would otherwise leave no trace at all.
     */
    public const DELETED_BY_CASCADE = 'deleted_by_cascade';

    public const ROLES_CHANGED = 'roles_changed';

    public const PERMISSIONS_CHANGED = 'permissions_changed';

    public const PASSWORD_RESET = 'password_reset';

    public const PASSWORD_CHANGED = 'password_changed';

    public const PHOTO_UPDATED = 'photo_updated';

    public const PHOTO_REMOVED = 'photo_removed';

    public const RECEIPT_GENERATED = 'receipt_generated';

    public const INSTRUCTOR_ASSIGNED = 'instructor_assigned';

    public const INSTRUCTOR_HOURS_CHANGED = 'instructor_hours_changed';

    public const INSTRUCTOR_REMOVED = 'instructor_removed';

    /*
     * Authentication, which has no subject model at all.
     */
    public const LOGGED_IN = 'logged_in';

    public const LOGGED_OUT = 'logged_out';

    public const LOGIN_FAILED = 'login_failed';

    /**
     * The whole vocabulary, for the completeness test to walk.
     *
     * Derived by reflection rather than repeated as a literal array: a
     * hand-maintained copy beside the constants is one more thing to forget,
     * and forgetting it would silently shrink the very check this class exists
     * to make complete.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        /** @var array<string, string> $constants */
        $constants = (new \ReflectionClass(self::class))->getConstants();

        return array_values($constants);
    }
}
