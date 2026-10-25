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
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->instance('env', 'production');

    $this->owners = [
        ['name' => 'Real Owner', 'email' => 'real-owner@example.test', 'password' => 'Abcdefg!'],
        ['name' => 'Recovery Administrator', 'email' => 'recovery@example.test', 'password' => 'Backup-password#2'],
    ];
    $this->bootstrap = function (array $overrides = []): PendingCommand {
        $owners = array_replace_recursive($this->owners, $overrides);
        $command = $this->artisan('staff:bootstrap-production-owner');

        foreach ($owners as $index => $owner) {
            $number = $index + 1;
            $confirmation = $owner['password_confirmation'] ?? $owner['password'];
            $command->expectsQuestion("Owner {$number} name", $owner['name'])
                ->expectsQuestion("Owner {$number} email", $owner['email'])
                ->expectsQuestion("Owner {$number} chosen password", $owner['password'])
                ->expectsQuestion("Owner {$number} confirm password", $confirmation);

            foreach ([$owner['password'], $confirmation] as $secret) {
                if ($secret !== '') {
                    $command->doesntExpectOutputToContain($secret);
                }
            }
        }

        return $command;
    };
});

it('creates exactly two named active owners with their own hashed passwords and system role audits', function (string $password, int $index): void {
    ($this->bootstrap)([$index => ['password' => $password]])
        ->expectsOutput('Production administrators created.')
        ->assertSuccessful();

    expect(User::withTrashed()->count())->toBe(2)
        ->and(DB::table('activity_log')->where('event', ActivityEvent::ROLES_CHANGED)->count())->toBe(2);
    $expected = array_replace_recursive($this->owners, [$index => ['password' => $password]]);
    foreach ($expected as $credentials) {
        $owner = User::query()->where('email', $credentials['email'])->sole();
        expect($owner->name)->toBe($credentials['name'])
            ->and($owner->is_active)->toBeTrue()
            ->and($owner->must_change_password)->toBeFalse()
            ->and($owner->password)->not->toBe($credentials['password'])
            ->and(Hash::check($credentials['password'], $owner->password))->toBeTrue()
            ->and($owner->roles()->pluck('name')->all())->toBe(['super_admin']);

        $event = DB::table('activity_log')->where('event', ActivityEvent::ROLES_CHANGED)->where('subject_id', $owner->getKey())->sole();
        expect($event->causer_id)->toBeNull()
            ->and(DB::table('activity_log')->get()->toJson())->not->toContain($credentials['password'], $owner->password);
    }
})->with([
    'eight characters, first owner' => ['Abcdefg!', 0],
    'no lowercase required, second owner' => ['ABCDEFG!', 1],
    'Unicode capital and symbol, first owner' => ['Édrisi§9', 0],
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

it('refuses a second invocation without replacing either owner credential', function (): void {
    ($this->bootstrap)()->assertSuccessful();
    $before = User::query()->orderBy('id')->get()->map->getAttributes()->all();
    $events = DB::table('activity_log')->count();

    $this->artisan('staff:bootstrap-production-owner')
        ->expectsOutput('Bootstrap refused: the users table is not empty.')
        ->assertFailed();

    expect(User::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(DB::table('activity_log')->count())->toBe($events);
});

it('refuses malformed email for either owner without creating either account', function (string $email, int $index): void {
    ($this->bootstrap)([$index => ['email' => $email]])->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
})->with([
    'malformed, first owner' => ['not-an-email', 0],
    'overlong, second owner' => [str_repeat('a', 245).'@example.test', 1],
]);

it('reports every unmet chosen password requirement for either owner without leaking it', function (string $password, array $messages, int $index): void {
    $command = ($this->bootstrap)([$index => ['password' => $password]]);

    foreach ($messages as $message) {
        $command->expectsOutput($message);
    }
    $command->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
})->with([
    'seven characters, first owner' => ['Abcde!1', ['The password must be at least 8 characters long.'], 0],
    'seven multibyte characters, second owner' => ['Aééééé!', ['The password must be at least 8 characters long.'], 1],
    'no capital, second owner' => ['abcdefg!1', ['The password must contain at least one capital letter.'], 1],
    'digits are not symbols, first owner' => ['Abcdefgh1', ['The password must contain at least one symbol, such as ! or #. Letters, digits and spaces do not count.'], 0],
    'Unicode digits and spaces are not symbols, first owner' => ["Abcdef١\u{00A0}", ['The password must contain at least one symbol, such as ! or #. Letters, digits and spaces do not count.'], 0],
    'all requirements missing, second owner' => ['abc', [
        'The password must be at least 8 characters long.',
        'The password must contain at least one capital letter.',
        'The password must contain at least one symbol, such as ! or #. Letters, digits and spaces do not count.',
    ], 1],
]);

it('requires a bounded nonblank name for each owner before creating either account', function (string $name, int $index): void {
    ($this->bootstrap)([$index => ['name' => $name]])
        ->doesntExpectOutputToContain('Bootstrap failed')
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
})->with([
    'blank, first owner' => ['   ', 0],
    'Unicode-only whitespace, second owner' => ["\u{00A0}\u{2003}", 1],
    'overlong, second owner' => [str_repeat('a', 256), 1],
]);

it('requires both hidden chosen passwords without creating either account', function (int $index): void {
    ($this->bootstrap)([$index => ['password' => '']])->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
})->with([0, 1]);

it('rejects a mismatched password confirmation for either owner without changing users pivots or audit rows', function (string $confirmation, int $index): void {
    $before = [
        DB::table('users')->get()->toArray(),
        DB::table('model_has_roles')->get()->toArray(),
        DB::table('activity_log')->get()->toArray(),
    ];
    $number = $index + 1;

    ($this->bootstrap)([$index => ['password_confirmation' => $confirmation]])
        ->expectsOutput("The Owner {$number} password field confirmation does not match.")
        ->doesntExpectOutputToContain('Bootstrap failed')
        ->assertFailed();

    expect([
        DB::table('users')->get()->toArray(),
        DB::table('model_has_roles')->get()->toArray(),
        DB::table('activity_log')->get()->toArray(),
    ])->toEqual($before);
})->with([
    'different, first owner' => ['Different-password#3', 0],
    'empty, second owner' => ['', 1],
]);

it('trims outer spaces from both owners names and email addresses before storing them', function (): void {
    ($this->bootstrap)([
        0 => ['name' => '  Real Owner  ', 'email' => ' real-owner@example.test '],
        1 => ['name' => ' Recovery Administrator ', 'email' => ' recovery@example.test '],
    ])->assertSuccessful();

    expect(User::query()->orderBy('id')->get(['name', 'email'])->toArray())->toBe([
        ['name' => 'Real Owner', 'email' => 'real-owner@example.test'],
        ['name' => 'Recovery Administrator', 'email' => 'recovery@example.test'],
    ]);
});

it('rejects duplicate owner emails case insensitively without creating either account', function (string $email): void {
    ($this->bootstrap)([1 => ['email' => $email]])
        ->expectsOutput('The Owner 1 email field has a duplicate value.')
        ->expectsOutput('The Owner 2 email field has a duplicate value.')
        ->doesntExpectOutputToContain('Bootstrap failed')
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
})->with(['REAL-OWNER@example.test']);

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
})->with(['local']);

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

it('rolls back both owners and role pivots when either trusted role write fails without exposing credentials', function (int $failAt): void {
    $writes = 0;
    $observed = null;
    $eventsBefore = DB::table('activity_log')->count();
    Activity::creating(function (Activity $activity) use ($failAt, &$writes, &$observed): void {
        if ($activity->event === ActivityEvent::ROLES_CHANGED && ++$writes === $failAt) {
            $observed = [User::query()->count(), DB::table('model_has_roles')->count()];
            throw new RuntimeException('Database failure containing Abcdefg! and Backup-password#2');
        }
    });

    ($this->bootstrap)()
        ->expectsOutput('Bootstrap failed; no administrators were created.')
        ->assertFailed();

    expect($observed)->toBe([$failAt, $failAt])
        ->and(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0)
        ->and(DB::table('activity_log')->count())->toBe($eventsBefore);
})->with([1, 2]);

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
                    $answers = [
                        'Owner 1 name' => 'Concurrent Owner',
                        'Owner 2 name' => 'Concurrent Recovery Administrator',
                        'Owner 1 email' => $this->email,
                        'Owner 2 email' => 'recovery-'.$this->email,
                    ];
                    if (isset($answers[$question->getQuestion()])) {
                        return $answers[$question->getQuestion()];
                    }
                    if (! $question->isHidden() || $question->isHiddenFallback()) {
                        throw new RuntimeException('Chosen password must be hidden without fallback.');
                    }
                    if (in_array($question->getQuestion(), ['Owner 1 chosen password', 'Owner 1 confirm password'], true)) {
                        return 'Abcdefg!';
                    }
                    if ($question->getQuestion() === 'Owner 2 chosen password') {
                        return 'Backup-password#2';
                    }
                    if ($question->getQuestion() !== 'Owner 2 confirm password') {
                        throw new RuntimeException('Unexpected bootstrap question.');
                    }
                    file_put_contents($this->ready, 'ready');
                    $deadline = hrtime(true) + 30_000_000_000;
                    while (! file_exists($this->release) && hrtime(true) < $deadline) {
                        usleep(25_000);
                    }
                    if (! file_exists($this->release)) {
                        throw new RuntimeException('Bootstrap worker was not released.');
                    }
                    return 'Backup-password#2';
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
            ->and(collect($results)->pluck('output')->implode(' '))->toContain('Production administrators created.', 'Bootstrap refused: the users table is not empty.')
            ->not->toContain('Abcdefg!', 'Backup-password#2')
            ->and(User::query()->count())->toBe(2)
            ->and(User::query()->get()->every(fn (User $owner): bool => $owner->isSuperAdmin()))->toBeTrue()
            ->and(User::query()->orderBy('email')->pluck('email')->all())->toBeIn([
                ['owner0@example.test', 'recovery-owner0@example.test'],
                ['owner1@example.test', 'recovery-owner1@example.test'],
            ]);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        File::delete([$release, ...$ready]);
    }
});
