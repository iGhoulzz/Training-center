<?php

declare(strict_types=1);

use App\Domain\Staff\Support\ActivityEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->instance('env', 'production');
});

it('creates only one active owner with the chosen hashed password and a system role audit', function (string $password): void {
    $this->artisan('staff:bootstrap-production-owner')
        ->expectsQuestion('Owner email', 'real-owner@example.test')
        ->expectsQuestion('Chosen password', $password)
        ->expectsOutput('Production owner created.')
        ->doesntExpectOutputToContain($password)
        ->assertSuccessful();

    $owner = User::query()->sole();
    expect($owner->email)->toBe('real-owner@example.test')
        ->and($owner->is_active)->toBeTrue()
        ->and($owner->must_change_password)->toBeFalse()
        ->and($owner->password)->not->toBe($password)
        ->and(Hash::check($password, $owner->password))->toBeTrue()
        ->and($owner->isSuperAdmin())->toBeTrue()
        ->and($owner->roles()->pluck('name')->all())->toBe(['super_admin']);

    $event = DB::table('activity_log')->where('event', ActivityEvent::ROLES_CHANGED)->sole();
    expect($event->causer_id)->toBeNull()
        ->and((string) $event->properties)->not->toContain($password, $owner->password);
})->with([
    'eight characters' => 'Abcdefg!',
    'no lowercase required' => 'ABCDEFG!',
    'Unicode capital and symbol' => 'Édrisi§9',
    'eight multibyte characters' => 'Aéééééé!',
    'Greek capital' => 'Δabcdef!',
]);

it('refuses every existing account including inactive and soft deleted accounts without changing it', function (string $state): void {
    $user = User::factory()->create(['is_active' => $state !== 'inactive']);
    if ($state === 'deleted') {
        $user->delete();
    }
    $before = User::withTrashed()->findOrFail($user->getKey())->getAttributes();
    $events = DB::table('activity_log')->count();

    $this->artisan('staff:bootstrap-production-owner')
        ->expectsOutput('Bootstrap refused: the users table is not empty.')
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(1)
        ->and(User::withTrashed()->findOrFail($user->getKey())->getAttributes())->toBe($before)
        ->and(DB::table('model_has_roles')->count())->toBe(0)
        ->and(DB::table('activity_log')->count())->toBe($events);
})->with(['active', 'inactive', 'deleted']);

it('refuses a second invocation without replacing the owner credential', function (): void {
    $this->artisan('staff:bootstrap-production-owner')
        ->expectsQuestion('Owner email', 'real-owner@example.test')
        ->expectsQuestion('Chosen password', 'Abcdefg!')
        ->assertSuccessful();
    $before = User::query()->sole()->getAttributes();
    $events = DB::table('activity_log')->count();

    $this->artisan('staff:bootstrap-production-owner')
        ->expectsOutput('Bootstrap refused: the users table is not empty.')
        ->assertFailed();

    expect(User::query()->sole()->getAttributes())->toBe($before)
        ->and(DB::table('activity_log')->count())->toBe($events);
});

it('refuses malformed owner email without creating an account', function (string $email): void {
    $this->artisan('staff:bootstrap-production-owner')
        ->expectsQuestion('Owner email', $email)
        ->expectsQuestion('Chosen password', 'Abcdefg!')
        ->doesntExpectOutputToContain('Abcdefg!')
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
})->with(['not-an-email', '', 'owner@', str_repeat('a', 245).'@example.test']);

it('reports every unmet chosen password requirement without leaking the supplied password', function (string $password, array $messages): void {
    $command = $this->artisan('staff:bootstrap-production-owner')
        ->expectsQuestion('Owner email', 'real-owner@example.test')
        ->expectsQuestion('Chosen password', $password)
        ->doesntExpectOutputToContain($password);

    foreach ($messages as $message) {
        $command->expectsOutput($message);
    }
    $command->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
})->with([
    'seven characters' => ['Abcde!1', ['The password must be at least 8 characters long.']],
    'no capital' => ['abcdefg!1', ['The password must contain at least one capital letter.']],
    'digits are not symbols' => ['Abcdefgh1', ['The password must contain at least one symbol, such as ! or #. Letters, digits and spaces do not count.']],
    'spaces are not symbols' => ['Abcdefgh 1', ['The password must contain at least one symbol, such as ! or #. Letters, digits and spaces do not count.']],
    'seven multibyte characters' => ['Aééééé!', ['The password must be at least 8 characters long.']],
    'all requirements missing' => ['abc', [
        'The password must be at least 8 characters long.',
        'The password must contain at least one capital letter.',
        'The password must contain at least one symbol, such as ! or #. Letters, digits and spaces do not count.',
    ]],
    'Unicode digits and spaces are not symbols' => ["Abcdef١\u{00A0}", ['The password must contain at least one symbol, such as ! or #. Letters, digits and spaces do not count.']],
]);

it('refuses a missing staff super admin role instead of creating roles', function (): void {
    Role::query()->where('name', 'super_admin')->delete();
    Role::query()->create(['name' => 'super_admin', 'guard_name' => 'student']);
    $roles = Role::query()->count();

    $this->artisan('staff:bootstrap-production-owner')
        ->expectsOutput('Bootstrap refused: seed RolePermissionSeeder before creating the owner.')
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(Role::query()->count())->toBe($roles);
});

it('refuses every nonproduction environment before asking for credentials', function (string $environment): void {
    app()->instance('env', $environment);

    $this->artisan('staff:bootstrap-production-owner')
        ->expectsOutput('This command may only run in production.')
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(0);
})->with(['local', 'testing', 'staging']);

it('refuses noninteractive execution instead of choosing default credentials', function (): void {
    $this->artisan('staff:bootstrap-production-owner', ['--no-interaction' => true])
        ->expectsOutput('Bootstrap requires interactive credential entry.')
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(0);
});

it('exposes no password option or argument', function (): void {
    $command = app(Kernel::class)->all()['staff:bootstrap-production-owner'];
    expect($command->getDefinition()->hasOption('password'))->toBeFalse()
        ->and($command->getDefinition()->getArguments())->toBe([]);
});

it('rolls back the owner and role pivot when the trusted writer fails without exposing credentials', function (): void {
    Activity::creating(function (Activity $activity): void {
        if ($activity->event === ActivityEvent::ROLES_CHANGED) {
            throw new RuntimeException('Database failure containing Abcdefg!');
        }
    });

    $this->artisan('staff:bootstrap-production-owner')
        ->expectsQuestion('Owner email', 'real-owner@example.test')
        ->expectsQuestion('Chosen password', 'Abcdefg!')
        ->expectsOutput('Bootstrap failed; no owner was created.')
        ->doesntExpectOutputToContain('Abcdefg!')
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
});

it('serializes concurrent first owners even when both transactions already saw an empty users table', function (): void {
    $token = (string) Str::uuid();
    $release = storage_path("framework/testing/bootstrap-{$token}-release");
    $ready = [
        storage_path("framework/testing/bootstrap-{$token}-a"),
        storage_path("framework/testing/bootstrap-{$token}-b"),
    ];
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");
    $environment = [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => (string) $database['database'],
        'DB_USERNAME' => (string) $database['username'],
        'DB_PASSWORD' => (string) $database['password'],
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
    ];
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        $app->instance('env', 'production');
        [$script, $ready, $release, $email] = $_SERVER['argv'];
        $command = $kernel->all()['staff:bootstrap-production-owner'];
        $input = new Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(true);
        $output = new Symfony\Component\Console\Output\BufferedOutput;
        $style = new class($input, $output, $ready, $release, $email) extends Illuminate\Console\OutputStyle {
                public function __construct($input, $output, private string $ready, private string $release, private string $email)
                {
                    parent::__construct($input, $output);
                }
                public function askQuestion(Symfony\Component\Console\Question\Question $question): mixed
                {
                    if ($question->getQuestion() === 'Owner email') {
                        return $this->email;
                    }
                    if (! $question->isHidden() || $question->isHiddenFallback()) {
                        throw new RuntimeException('Chosen password must be hidden without fallback.');
                    }
                    file_put_contents($this->ready, 'ready');
                    $deadline = hrtime(true) + 30_000_000_000;
                    while (! file_exists($this->release) && hrtime(true) < $deadline) {
                        usleep(25_000);
                    }
                    if (! file_exists($this->release)) {
                        throw new RuntimeException('Bootstrap worker was not released.');
                    }
                    return 'Abcdefg!';
                }
        };
        $status = Illuminate\Support\Facades\DB::transaction(function () use ($command, $input, $style): int {
            App\Models\User::query()->count();
            return $command->run($input, $style);
        });
        echo json_encode(['status' => $status, 'output' => $output->fetch()], JSON_THROW_ON_ERROR);
        PHP;
    $workers = array_map(fn (string $path, int $index): Process => new Process(
        [PHP_BINARY, '-r', $script, $path, $release, "owner{$index}@example.test"], base_path(), $environment, timeout: 45,
    ), $ready, array_keys($ready));

    try {
        foreach ($workers as $worker) {
            $worker->start();
        }
        $deadline = hrtime(true) + 30_000_000_000;
        while ((! File::exists($ready[0]) || ! File::exists($ready[1])) && hrtime(true) < $deadline) {
            foreach ($workers as $worker) {
                expect($worker->isRunning())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            }
            usleep(25_000);
        }
        foreach ($ready as $path) {
            expect(File::exists($path))->toBeTrue('Both bootstrap attempts must reach credential entry.');
        }
        File::put($release, 'release');
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([0, 1])
            ->and(collect($results)->pluck('output')->implode(' '))->toContain('Production owner created.', 'Bootstrap refused: the users table is not empty.')
            ->not->toContain('Abcdefg!')
            ->and(User::query()->count())->toBe(1)
            ->and(User::query()->sole()->isSuperAdmin())->toBeTrue();
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        File::delete([$release, ...$ready]);
    }
});
