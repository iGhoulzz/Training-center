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
 * Strip every separator a grouped number is written with — whitespace of any
 * kind, including the non-breaking space, and every dash, including the en and
 * em dashes a word processor substitutes. If what remains is entirely digits
 * and its length is 13 to 19 inclusive, refuse it. Anything else passes.
 * See SEPARATORS for why "spaces and dashes" was not enough.
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

    /**
     * Every separator a grouped card number is written with, not two of them.
     *
     * `\p{Z}` is Unicode separators, which is where the non-breaking space
     * lives; `\s` and `\p{Cc}` cover the ASCII whitespace a keyboard produces,
     * tab included; `\p{Pd}` is every dash, so the en and em dashes a word
     * processor substitutes for a typed hyphen are stripped alongside it.
     *
     * THE FIRST VERSION OF THIS RULE REMOVED ONLY `' '` AND `'-'`, AND CODEX'S
     * CROSS-REVIEW WALKED A PAN STRAIGHT PAST IT. Measured on this branch:
     * `4111\t1111\t1111\t1111`, the same number joined by non-breaking spaces,
     * and the same again with en dashes were all accepted, because what
     * remained after stripping was not `ctype_digit()` and the length check
     * never ran. Pasting a card number out of formatted text produces exactly
     * those characters, and the database `CHECK` behind this field only asks
     * for a non-blank reference — it would have stored the PAN, against design
     * section 5's categorical rule that card numbers are never stored anywhere
     * in this system.
     */
    private const SEPARATORS = '/[\p{Z}\s\p{Cc}\p{Pd}]+/u';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /*
         * Nothing to say about a non-string. The `string` rule owns that
         * question, and casting here would raise an array-to-string warning
         * rather than a validation error.
         */
        if (! is_string($value)) {
            return;
        }

        $stripped = preg_replace(self::SEPARATORS, '', $value);

        /*
         * preg_replace() returns null on malformed UTF-8. Falling back to the
         * ASCII strip degrades this to the narrower rule rather than to no rule
         * at all — a plainly grouped PAN is still caught, which is the
         * direction to fail in.
         */
        if (! is_string($stripped)) {
            $stripped = str_replace([' ', '-'], '', $value);
        }

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
