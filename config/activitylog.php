<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\RefuseActivityLogCleaning;
use App\Domain\Staff\Support\RecordActivityWithContext;
use Spatie\Activitylog\Models\Activity;

/*
 * Read once into a variable so the blank check and the coercion below see the
 * same value without calling env() twice.
 */
$activityLogEnabled = env('ACTIVITYLOG_ENABLED');

return [

    /*
     * If set to false, no activities will be saved to the database.
     *
     * COERCED, BECAUSE A BLANK VALUE SILENTLY DISABLED THE WHOLE AUDIT TRAIL
     * (P1-T15, group 3 finding M1).
     *
     * env()'s second argument is a default for a MISSING key. A key that exists
     * and is empty returns '', sails straight past the default, and Spatie's
     * ActivityLogStatus has no declare(strict_types=1) — so coercive typing
     * turned '' into false and nothing was recorded from that deploy onward.
     * Nothing failed and nothing warned; the panel kept rendering the entries
     * written before it, so the first sign was the log stopping at a date.
     *
     * THE BLANK IS HANDLED BEFORE filter_var, NOT BY IT. FILTER_VALIDATE_BOOL
     * treats an empty string as a recognised FALSE — it is listed alongside
     * '0', 'off' and 'no' — so FILTER_NULL_ON_FAILURE never fires for it and a
     * filter_var-only fix reproduces the bug exactly. Only a genuinely
     * unrecognisable value reaches the ?? below.
     *
     * A deliberate "false" or "0" still reads as false, which a test asserts —
     * otherwise this would be a hardcoded true with the setting removed.
     */
    'enabled' => $activityLogEnabled === null || $activityLogEnabled === ''
        ? true
        : filter_var($activityLogEnabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,

    /*
     * NULL BECAUSE NOTHING MAY BE CLEANED (P1-T15, group 3 finding H1).
     *
     * This was 365 — a live retention window on a log whose non-negotiable rule
     * is that no delete path exists for any role, including super admin. The
     * real barrier is the cleaning action below, which refuses every caller;
     * this is the second one, and it fails `activitylog:clean` on its own
     * validation ("The days option must be a positive integer") before the
     * action is reached at all.
     *
     * Two barriers because neither covers the other's case: this one does not
     * survive an explicit `--days=30`, and the action does not make the bare
     * command fail early and legibly.
     */
    'clean_after_days' => null,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject relationship on activities
     * will include soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => Activity::class,

    /*
     * These attributes will be excluded from logging for all models.
     * Model-specific exclusions via logExcept() are merged with these.
     *
     * THE EFFECTIVE DEFENCE FOR SECRETS, AND NOT MERELY A BACKSTOP.
     *
     * LogsActivity::excludedAttributes() merges this list on top of whatever the
     * model's logOnly() allowlist selected, so it wins: `password` is stripped
     * even if a model names it. Verified by mutation — adding `password` to
     * User's allowlist alone changes nothing; only removing it from BOTH exposes
     * the hash.
     *
     * The two layers are therefore independent and are tested independently:
     * this list is pinned by its own assertion, and each model's allowlist is
     * asserted not to name a secret. Neither test can stand in for the other.
     *
     * The allowlists remain the primary control over what is logged AT ALL —
     * "misses by default" rather than "leaks by default" for every ordinary
     * column — but for credentials this list is what actually holds.
     *
     * SECRETS ONLY. must_change_password and last_login_at were here too, which
     * muddled the list's purpose and produced a real contradiction: User listed
     * must_change_password as audited while this stripped it, so the docblock
     * claimed something the code did not do. Noise suppression belongs in the
     * allowlists — last_login_at is simply not named there — and this list means
     * exactly one thing.
     */
    'default_except_attributes' => [
        'password',
        'remember_token',
    ],

    /*
     * When enabled, activities are buffered in memory and inserted in a
     * single bulk query after the response has been sent to the client.
     * This can significantly reduce the number of database queries when
     * many activities are logged during a single request.
     *
     * Only enable this if your application logs a high volume of activities
     * per request. Buffered activities will not have an ID until the
     * buffer is flushed.
     */
    /*
     * HARD DISABLED. NOT ENVIRONMENT-SWITCHABLE, AND NOT A PERFORMANCE KNOB.
     *
     * With buffering off, each activity is save()d inline, inside whatever
     * transaction is open — so when a domain transaction rolls back, its audit
     * rows roll back with it. That is the whole guarantee.
     *
     * Enabled, the buffer flushes on `terminating` / shutdown, OUTSIDE the
     * transaction: a write that rolled back would still leave an audit row
     * claiming it happened. An audit trail that records events which never
     * occurred is worse than none, because it is trusted.
     *
     * The env() call is deliberately removed so this cannot be flipped per
     * environment. ActivityLogTest asserts both the config value and the
     * rollback behaviour, and the rollback test flushes the buffer explicitly —
     * otherwise enabling buffering would leave it green, because nothing in a
     * test ever reaches `terminating`.
     */
    'buffer' => [
        'enabled' => false,
    ],

    /*
     * These action classes can be overridden to customize how activities
     * are logged and cleaned. Your custom classes must extend the originals.
     */
    'actions' => [
        /*
         * Attaches the request IP to EVERY entry — model-generated and explicit
         * alike. A model's beforeActivityLogged() hook is per-SUBJECT and so
         * cannot reach auth events, which have no subject at all; this is the
         * one point both paths pass through.
         */
        'log_activity' => RecordActivityWithContext::class,

        /*
         * THE APPEND-ONLY RULE'S LAST OPEN DOOR, CLOSED (P1-T15, finding H1).
         *
         * This was the package's own CleanActivityLogAction, which issues
         * `DELETE FROM activity_log WHERE created_at < ?`. ActivityPolicy claims
         * "no policy, no UI control and no application code path can remove or
         * alter an entry", and that was false while this line pointed at a
         * deleting action reachable from `activitylog:clean` and from any job or
         * package that resolves it.
         *
         * Replaced rather than merely unscheduled, because the action is what
         * every caller reaches; the command is only its most obvious door.
         */
        'clean_log' => RefuseActivityLogCleaning::class,
    ],
];
