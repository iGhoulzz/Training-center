<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PerformanceDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use RuntimeException;
use Throwable;

final class RevokeLoadTestSessionsCommand extends Command
{
    protected $signature = 'load:revoke-sessions
        {--manifest= : Absolute manifest path produced by load:mint-sessions}
        {--confirm-database= : Exact connected database name}';

    protected $description = 'Revoke only the sessions in a signed load-run manifest';

    public function handle(PerformanceDatabaseGuard $guard): int
    {
        try {
            $guard->assertSafe((string) $this->option('confirm-database'));

            if (config('session.driver') !== 'database') {
                throw new RuntimeException('Revocation requires database-backed sessions.');
            }

            $connection = DB::connection(config('session.connection') ?: null);
            if ($connection->getDatabaseName() !== DB::connection()->getDatabaseName()) {
                throw new RuntimeException('The session connection must use the confirmed database.');
            }

            $path = $this->option('manifest');
            if (! is_string($path) || ! (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) || ! is_file($path) || is_link($path)) {
                throw new RuntimeException('--manifest must name an existing absolute regular file.');
            }

            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! isset($manifest['signature']) || ! is_string($manifest['signature'])) {
                throw new RuntimeException('Invalid load-session manifest.');
            }

            $signature = $manifest['signature'];
            unset($manifest['signature']);
            $expected = hash_hmac('sha256', json_encode($manifest, JSON_THROW_ON_ERROR), app('encrypter')->getKey());

            if (! hash_equals($expected, $signature)
                || ($manifest['version'] ?? null) !== 1
                || ($manifest['database'] ?? null) !== DB::connection()->getDatabaseName()
                || ! is_array($manifest['sessions'] ?? null)
                || $manifest['sessions'] === []) {
                throw new RuntimeException('Invalid or mismatched load-session manifest.');
            }

            $ids = [];
            foreach ($manifest['sessions'] as $session) {
                if (! is_array($session) || ! is_string($session['id'] ?? null) || preg_match('/^[A-Za-z0-9]{40}$/', $session['id']) !== 1) {
                    throw new RuntimeException('Invalid session ID in manifest.');
                }

                $ids[] = $session['id'];
            }

            if (count($ids) !== count(array_unique($ids))) {
                throw new RuntimeException('Duplicate session ID in manifest.');
            }

            foreach ($ids as $id) {
                Session::getHandler()->destroy($id);
            }

            if (! unlink($path)) {
                throw new RuntimeException('Sessions were revoked, but the manifest could not be removed.');
            }

            $this->components->info('Load sessions revoked.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Session revocation failed.');

            return self::FAILURE;
        }
    }
}
