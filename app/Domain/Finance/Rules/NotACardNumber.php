<?php

declare(strict_types=1);

namespace App\Domain\Finance\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses PAN-shaped input in a free-text field.
 *
 * Design section 5: card numbers, PINs and CVVs are never stored anywhere in
 * this system. `payment_tenders.external_reference` is the terminal's own
 * reference — free text, typed by whoever is at the desk next to a card
 * machine — which is exactly the field somebody will one day type a PAN
 * into.
 *
 * THE SHAPE, EXACTLY
 * -------------------
 * Strip spaces and dashes. If what remains is entirely digits and its
 * length is 13 to 19 inclusive, refuse it. Anything else passes.
 *
 * A LETTER ANYWHERE MAKES THIS NOT PAN-SHAPED, WHATEVER THE DIGITS LOOK LIKE
 * ----------------------------------------------------------------------------
 * `AUTH-1234567890` is a perfectly ordinary terminal reference and must
 * pass — so must a 12-digit reference and a 20-digit one. This is a
 * targeted guard against one shape, not a general ban on digits: a rule
 * that fires on ordinary references gets deleted by whoever it interrupts,
 * and then guards nothing at all.
 *
 * THIS UNIT BUILDS AND TESTS THE RULE ONLY
 * -----------------------------------------
 * There is no caller of this class anywhere in this codebase yet, and that
 * is deliberate rather than dead code. The collection form that applies it
 * to `payment_tenders.external_reference` belongs to task 9 (enrol and
 * collect) — a later task's file, outside this unit's scope.
 */
final class NotACardNumber implements ValidationRule
{
    /**
     * 13 to 19 digits is the range ISO/IEC 7812 actually issues — the
     * inclusive bounds this rule is a targeted guard around, not a round
     * number picked for convenience.
     */
    private const MIN_PAN_LENGTH = 13;

    private const MAX_PAN_LENGTH = 19;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Spaces and dashes ignored, per the class docblock — a terminal
        // reference is typed by a person, and `4111 1111 1111 1111` is the
        // same PAN shape as `4111111111111111` with the grouping a human
        // would naturally type.
        $stripped = str_replace([' ', '-'], '', (string) $value);

        // ctype_digit() is false for a blank string, so an empty reference
        // passes rather than tripping the length check below on zero.
        $isPanShaped = ctype_digit($stripped)
            && strlen($stripped) >= self::MIN_PAN_LENGTH
            && strlen($stripped) <= self::MAX_PAN_LENGTH;

        if ($isPanShaped) {
            $fail(__('payments.not_a_card_number'));
        }
    }
}
