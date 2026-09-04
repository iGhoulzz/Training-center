<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * @return array<string, string>
 */
function portalCredentialWorkerEnvironment(): array
{
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");

    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) ($database['host'] ?? ''),
        'DB_PORT' => (string) ($database['port'] ?? ''),
        'DB_DATABASE' => (string) ($database['database'] ?? ''),
        'DB_USERNAME' => (string) ($database['username'] ?? ''),
        'DB_PASSWORD' => (string) ($database['password'] ?? ''),
    ];
}

function portalCredentialWorker(int $actorId, int $studentId, string $readyPath, string $releasePath, string $resultPath): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $actorId, $studentId, $readyPath, $releasePath, $resultPath] = $_SERVER['argv'];

        try {
            $result = Illuminate\Support\Facades\DB::transaction(static function () use ($actorId, $studentId, $readyPath, $releasePath): array {
                App\Models\User::query()->count();
                file_put_contents($readyPath, 'ready');

                // 60 seconds of headroom for slow CI nodes; the poll exits early on success.
        $deadline = hrtime(true) + 60_000_000_000;
                while (! file_exists($releasePath) && hrtime(true) < $deadline) {
                    usleep(25_000);
                }

                if (! file_exists($releasePath)) {
                    throw new RuntimeException('Credential worker was not released to issue the account.');
                }

                $plain = app(App\Domain\Staff\Actions\IssuePortalCredentialAction::class)->execute(
                    App\Models\User::query()->findOrFail((int) $actorId),
                    App\Domain\Enrollment\Models\Student::query()->findOrFail((int) $studentId),
                );

                return ['outcome' => 'issued', 'password_length' => strlen($plain)];
            });
        } catch (App\Domain\Staff\Exceptions\EmailAlreadyRegisteredException) {
            $result = ['outcome' => 'email_already_registered'];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'error', 'message' => $throwable::class.': '.$throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $actorId, (string) $studentId, $readyPath, $releasePath, $resultPath],
        base_path(),
        portalCredentialWorkerEnvironment(),
    );
}

it('returns one typed refusal when two students with the same email are issued credentials concurrently', function (): void {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    $actor->givePermissionTo('issue_portal_credential');
    $students = Student::factory()->count(2)->create(['email' => 'shared@example.test']);

    $token = (string) Str::uuid();
    $paths = collect(['a-ready', 'a-result', 'b-ready', 'b-result', 'release'])
        ->mapWithKeys(fn (string $name): array => [
            $name => storage_path("framework/testing/portal-credential-{$token}-{$name}.json"),
        ]);
    $workers = [
        portalCredentialWorker((int) $actor->getKey(), (int) $students[0]->getKey(), $paths['a-ready'], $paths['release'], $paths['a-result']),
        portalCredentialWorker((int) $actor->getKey(), (int) $students[1]->getKey(), $paths['b-ready'], $paths['release'], $paths['b-result']),
    ];

    try {
        foreach ($workers as $worker) {
            $worker->start();
        }

        // 60 seconds of headroom for slow CI nodes; the poll exits early on success.
        $deadline = hrtime(true) + 60_000_000_000;
        while ((! File::exists($paths['a-ready']) || ! File::exists($paths['b-ready'])) && hrtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                Assert::fail('Worker died unexpectedly: '.$worker->getErrorOutput());
            }
            usleep(25_000);
        }

        expect(File::exists($paths['a-ready']))->toBeTrue('Credential worker A never established its old users snapshot.')
            ->and(File::exists($paths['b-ready']))->toBeTrue('Credential worker B never established its old users snapshot.');

        File::put($paths['release'], 'release');

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }

        $results = collect([$paths['a-result'], $paths['b-result']])
            ->map(fn (string $path): array => json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR));
        $outcomes = $results
            ->pluck('outcome')
            ->sort()
            ->values()
            ->all();

        expect($outcomes)->toBe(['email_already_registered', 'issued'], $results->toJson())
            ->and(User::query()->where('email', 'shared@example.test')->count())->toBe(1)
            ->and(Student::query()->whereNotNull('user_id')->count())->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        foreach ($paths as $path) {
            File::delete($path);
        }
    }
});
