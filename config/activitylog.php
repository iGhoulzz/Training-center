<?php

declare(strict_types=1);

use App\Domain\Staff\Support\RecordActivityWithContext;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Models\Activity;

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * When the clean command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     */
    'clean_after_days' => 365,

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
        'clean_log' => CleanActivityLogAction::class,
    ],
];
