<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

/**
 * Something tried to log an activity event that is not in the vocabulary.
 *
 * WHY THIS IS A THROW AND NOT A WARNING
 * -------------------------------------
 * Event labels are looked up by interpolation and fall back to the raw event, so
 * an unregistered event does not look broken — it renders as
 * `some_new_event` and reads like a deliberate technical label (P1-T15, group 3
 * finding M5). The whole point of ActivityEvent is that the completeness test
 * can see the vocabulary; an event that never joins it is invisible to that test
 * and reaches an administrator untranslated.
 *
 * A STATIC SCAN CANNOT CLOSE THIS. The test that refuses a literal at
 * `->event('…')` is a style check and nothing more: assigning the same string to
 * a variable first walks straight past it, and so does any future job, command
 * or package that builds an event name at run time. This class is the boundary
 * those all share, because config/activitylog.php routes every entry through
 * RecordActivityWithContext.
 *
 * IT REFUSES IN EVERY ENVIRONMENT, DELIBERATELY. An unregistered event is a
 * programming mistake — somebody added a call without adding a constant — not an
 * operational condition to degrade around. Refusing everywhere means the first
 * test that exercises the path fails, which is far cheaper than discovering it
 * from a screenshot of an audit log in Arabic. It follows the same reasoning as
 * ActivityLogIsAppendOnlyException: this project prefers a loud refusal to a
 * quiet, plausible-looking wrong answer.
 *
 * A null event is untouched. Plain `activity()->log('…')` records no event at
 * all, which is a legitimate shape and has no label to miss.
 *
 * The message routes through __() per the no-hardcoded-strings rule.
 */
class UnregisteredActivityEventException extends RuntimeException
{
    public function __construct(string $event)
    {
        parent::__construct((string) __('staff.unregistered_activity_event', ['event' => $event]));
    }
}
