<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Staff\Actions\ConsoleAccountRecovery;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-console recovery: restore a soft-deleted staff account.
 *
 * A trusted actorless path (ConsoleAccountRecovery) that is audited. The account
 * keeps the roles it held when it was deleted. See docs/DEPLOYMENT.md, "Locked
 * out of the admin".
 */
final class RestoreUserCommand extends Command
{
    protected $signature = 'staff:restore-user {email : The deleted account\'s email address}';

    protected $description = 'Restore a deleted staff account from the server console (audited)';

    public function handle(ConsoleAccountRecovery $recovery): int
    {
        try {
            $email = Str::trim((string) $this->argument('email'));
            $account = User::withTrashed()->where('email', $email)->first();

            if ($account === null) {
                $this->error('No account has that email address.');

                return self::FAILURE;
            }

            if (! $account->trashed() || ! $recovery->restore($account)) {
                $this->error('That account is not deleted; nothing was changed.');

                return self::FAILURE;
            }

            $this->info("Restored {$account->email} with the roles it held. If its password is lost, run staff:issue-temporary-password.");

            return self::SUCCESS;
        } catch (Throwable) {
            // A database exception may carry bound values. Never render or
            // report it from this recovery boundary.
            $this->error('Could not restore the account; nothing was changed.');

            return self::FAILURE;
        }
    }
}
