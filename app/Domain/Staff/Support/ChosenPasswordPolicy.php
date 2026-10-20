<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Chosen credentials only; existing and issued temporary passwords are exempt.
 * Capitals are Unicode Lu; letters, numbers and whitespace are not symbols.
 */
final class ChosenPasswordPolicy implements ValidationRule
{
    public const int MINIMUM_LENGTH = 8;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (mb_strlen((string) $value) < self::MINIMUM_LENGTH) {
            $fail(__('auth.password_too_short', ['minimum' => self::MINIMUM_LENGTH]));
        }

        if (preg_match('/\p{Lu}/u', (string) $value) !== 1) {
            $fail(__('auth.password_needs_capital'));
        }

        if (preg_match('/[^\p{L}\p{N}\s]/u', (string) $value) !== 1) {
            $fail(__('auth.password_needs_symbol'));
        }
    }
}
