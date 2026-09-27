<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PerformanceDatabaseGuard;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class MintLoadTestSessionsCommand extends Command
{
    protected $signature = 'load:mint-sessions
        {--user= : Active staff-panel user ID}
        {--count=20 : Number of independent sessions}
        {--confirm-database= : Exact connected database name}
        {--output= : Absolute manifest path outside the repository}';

    protected $description = 'Mint disposable database sessions for a guarded load run';

    public function handle(PerformanceDatabaseGuard $guard): int
    {
        $sessionIds = [];

        try {
            $guard->assertSafe((string) $this->option('confirm-database'));
            $connection = $this->sessionConnection();
            $this->assertSessionConfiguration($connection);

            $userId = $this->option('user');
            $count = filter_var($this->option('count'), FILTER_VALIDATE_INT);
            $output = $this->option('output');

            if (! is_scalar($userId) || ! ctype_digit((string) $userId) || $count === false || $count < 1 || $count > 100) {
                throw new RuntimeException('A positive --user and --count between 1 and 100 are required.');
            }

            if (! is_string($output)) {
                throw new RuntimeException('--output must be an absolute path outside the repository.');
            }

            $this->assertSafePath($output);

            $user = User::query()->find((int) $userId);
            $permissions = ['view_any_student', 'create_enrollment', 'create_payment', 'view_financial_report', 'view_payment'];

            if (! $user || ! $user->is_active || $user->must_change_password || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
                throw new RuntimeException('The user is not an active, ready staff-panel account.');
            }

            foreach ($permissions as $permission) {
                if (! $user->can($permission)) {
                    throw new RuntimeException('The user lacks a required load-flow permission.');
                }
            }

            $cookieName = (string) config('session.cookie');
            $webGuard = auth()->guard('web');
            if (! $webGuard instanceof SessionGuard) {
                throw new RuntimeException('The staff web guard must use session authentication.');
            }

            $authKey = $webGuard->getName();
            $sessions = [];

            for ($index = 0; $index < $count; $index++) {
                $id = Str::random(40);
                $csrf = Str::random(40);
                $attributes = [$authKey => $user->getAuthIdentifier(), '_token' => $csrf];
                $serialized = config('session.serialization', 'php') === 'json'
                    ? json_encode($attributes, JSON_THROW_ON_ERROR)
                    : serialize($attributes);

                if (config('session.encrypt')) {
                    $serialized = app('encrypter')->encrypt($serialized);
                }

                $connection->table((string) config('session.table', 'sessions'))->insert([
                    'id' => $id,
                    'user_id' => $user->getKey(),
                    'payload' => base64_encode($serialized),
                    'last_activity' => time(),
                ]);
                $sessionIds[] = $id;

                $sessions[] = [
                    'id' => $id,
                    'cookie' => app('encrypter')->encrypt(
                        CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$id,
                        false,
                    ),
                    'csrf' => $csrf,
                ];
            }

            $manifest = [
                'version' => 1,
                'database' => DB::connection()->getDatabaseName(),
                'cookie_name' => $cookieName,
                'sessions' => $sessions,
            ];
            $manifest['signature'] = hash_hmac('sha256', json_encode($manifest, JSON_THROW_ON_ERROR), app('encrypter')->getKey());

            $this->writeManifest($output, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $this->line($output);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($sessionIds !== []) {
                $this->sessionConnection()->table((string) config('session.table', 'sessions'))->whereIn('id', $sessionIds)->delete();
            }

            $this->components->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Session minting failed.');

            return self::FAILURE;
        }
    }

    private function sessionConnection(): ConnectionInterface
    {
        return DB::connection(config('session.connection') ?: null);
    }

    private function assertSessionConfiguration(ConnectionInterface $connection): void
    {
        if (config('session.driver') !== 'database' || (int) config('session.lifetime') !== 480) {
            throw new RuntimeException('Load sessions require the database driver and an eight-hour session lifetime.');
        }

        if ($connection->getDatabaseName() !== DB::connection()->getDatabaseName()) {
            throw new RuntimeException('The session connection must use the confirmed database.');
        }
    }

    private function assertSafePath(string $path): void
    {
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $parent = realpath(dirname($path));
        $repository = realpath(base_path());

        if (! $isAbsolute || $parent === false || $repository === false || file_exists($path) || is_link($path)) {
            throw new RuntimeException('The output must be a new file in an existing absolute directory.');
        }

        $parentPath = strtolower(str_replace('\\', '/', $parent));
        $repositoryPath = strtolower(str_replace('\\', '/', $repository));

        if ($parentPath === $repositoryPath || str_starts_with($parentPath, $repositoryPath.'/')) {
            throw new RuntimeException('The manifest must be outside the repository.');
        }
    }

    private function writeManifest(string $path, string $contents): void
    {
        $temporary = dirname($path).DIRECTORY_SEPARATOR.'.load-sessions-'.bin2hex(random_bytes(16));
        $stream = fopen($temporary, 'x');

        if ($stream === false) {
            throw new RuntimeException('Cannot create the private temporary manifest.');
        }

        try {
            if (! chmod($temporary, 0600) || (DIRECTORY_SEPARATOR === '/' && (fileperms($temporary) & 0777) !== 0600)) {
                throw new RuntimeException('Cannot set owner-only manifest permissions.');
            }

            if (fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream)) {
                throw new RuntimeException('Cannot write the private manifest.');
            }

            fclose($stream);
            $stream = null;

            if (! link($temporary, $path)) {
                throw new RuntimeException('The manifest already exists or cannot be published.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }
}
