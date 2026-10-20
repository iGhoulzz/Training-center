<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Support\ChosenPasswordPolicy;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class BootstrapProductionOwnerCommand extends Command
{
    protected $signature = 'staff:bootstrap-production-owner';

    protected $description = 'Create the first production owner using securely entered credentials';

    public function handle(SystemRoleWriter $writer): int
    {
        if (! app()->environment('production')) {
            $this->error('This command may only run in production.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Bootstrap requires interactive credential entry.');

            return self::FAILURE;
        }

        try {
            if (User::withTrashed()->exists()) {
                return $this->refuseExistingUsers();
            }

            if (! Role::query()->where('name', Role::SUPER_ADMIN)->where('guard_name', 'web')->exists()) {
                return $this->refuseMissingRole();
            }

            $email = $this->ask('Owner email');
            $password = $this->secret('Chosen password', fallback: false);
            $validator = Validator::make(['email' => $email, 'password' => $password], [
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', new ChosenPasswordPolicy],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->error($message);
                }

                return self::FAILURE;
            }

            $validated = $validator->validated();

            $status = DB::transaction(function () use ($writer, $validated): int {
                // The seeded row exists before any owner does. Lock it first so
                // competing first runs serialize even while users is empty.
                $role = Role::query()->where('name', Role::SUPER_ADMIN)->where('guard_name', 'web')
                    ->lockForUpdate()->first();

                if ($role === null) {
                    return $this->refuseMissingRole();
                }

                // A locking read sees the winner even under an older MySQL
                // REPEATABLE READ snapshot. Deleted accounts also block setup.
                if (User::withTrashed()->lockForUpdate()->first() !== null) {
                    return $this->refuseExistingUsers();
                }

                $owner = User::query()->create([
                    'name' => $validated['email'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'locale' => 'en',
                    'is_active' => true,
                    'must_change_password' => false,
                ]);

                $writer->assignRoles($owner, Role::SUPER_ADMIN);

                return self::SUCCESS;
            });

            if ($status === self::SUCCESS) {
                $this->info('Production owner created.');
            }

            return $status;
        } catch (Throwable) {
            // Database/validation exceptions may carry bound credential values.
            // Never render or report the exception from this setup boundary.
            $this->error('Bootstrap failed; no owner was created.');

            return self::FAILURE;
        }
    }

    private function refuseExistingUsers(): int
    {
        $this->error('Bootstrap refused: the users table is not empty.');

        return self::FAILURE;
    }

    private function refuseMissingRole(): int
    {
        $this->error('Bootstrap refused: seed RolePermissionSeeder before creating the owner.');

        return self::FAILURE;
    }
}
