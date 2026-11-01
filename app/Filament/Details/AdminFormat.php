<?php

declare(strict_types=1);

namespace App\Filament\Details;

/**
 * The one way the admin turns a stored value into display text, so a list column
 * (`AdminColumns`) and a detail entry (`AdminDetails`) cannot disagree about it.
 *
 * Money is rendered from the decimal STRING the database or the `Money` cast hands
 * over, through the domain's own `amount_lyd` lang key, and never passes through a
 * float.
 */
final class AdminFormat
{
    /**
     * An amount as text, or null when there is none (so the caller's placeholder shows).
     *
     * @param  string  $amountKey  The domain's `amount_lyd` lang key, for example `charges.amount_lyd`.
     */
    public static function money(string $amountKey, mixed $state): ?string
    {
        return $state === null
            ? null
            : __($amountKey, ['amount' => (string) $state]);
    }
}
