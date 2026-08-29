<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues the one-time credential shape shared by staff and portal accounts.
 */
final class TemporaryPassword
{
    /**
     * Generate, hash, and persist a temporary password for an account.
     *
     * @return string The plaintext password, shown once to the authorized actor.
     */
    public function issue(User $account): string
    {
        $plain = Str::password(16, symbols: false);

        $account->forceFill([
            'password' => Hash::make($plain),
            'must_change_password' => true,
        ])->save();

        return $plain;
    }
}
