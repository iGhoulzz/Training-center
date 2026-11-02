<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Staff\Actions\ConsoleAccountRecovery;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-console recovery: give a locked-out account a temporary password.
 *
 * A trusted actorless path (ConsoleAccountRecovery) that is audited, for the case
 * where no administrator can sign in to use the panel's own reset. See
 * docs/DEPLOYMENT.md, "Locked out of the admin".
 */
final class IssueTemporaryPasswordCommand extends Command
{
    protected $signature = 'staff:issue-temporary-password {email : The account\'s email address} {--force : Do not ask for confirmation}';

    protected $description = 'Issue a one-time temporary password for a staff account from the server console (audited)';

    public function handle(ConsoleAccountRecovery $recovery): int
    {
        try {
            $email = Str::trim((string) $this->argument('email'));
            $account = User::withTrashed()->where('email', $email)->first();

            if ($account === null) {
                $this->error('No account has that email address.');

                return self::FAILURE;
            }

            if ($account->trashed()) {
                $this->error('That account is deleted. Run staff:restore-user first.');

                return self::FAILURE;
            }

            if (! $this->option('force') && ! $this->confirm("Replace the password of {$account->email} with a temporary one? Their open sessions end and the change is recorded in the activity log.")) {
                $this->warn('Cancelled; nothing was changed.');

                return self::FAILURE;
            }

            $plain = $recovery->issueTemporaryPassword($account);

            if ($plain === null) {
                $this->error('That account is deleted. Run staff:restore-user first.');

                return self::FAILURE;
            }

            $this->info("Temporary password for {$account->email} (shown once; they must change it at the next sign-in):");
            $this->line($plain);

            if (! $account->is_active) {
                $this->warn('This account is deactivated and cannot sign in until an administrator reactivates it.');
            }

            return self::SUCCESS;
        } catch (Throwable) {
            // A database exception may carry bound values. Never render or
            // report it from this recovery boundary.
            $this->error('Could not issue a temporary password; nothing was changed.');

            return self::FAILURE;
        }
    }
}
