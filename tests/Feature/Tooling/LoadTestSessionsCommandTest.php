<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Pages\Reports\OutstandingAgedReportPage;
use App\Domain\Finance\Filament\Pages\Reports\PaymentMethodReportPage;
use App\Domain\Finance\Filament\Pages\Reports\RevenueReportPage;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config(['session.driver' => 'database', 'session.lifetime' => 480]);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    $this->manifestDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'load-session-test-'.bin2hex(random_bytes(8));
    mkdir($this->manifestDirectory, 0700);
    $this->manifest = $this->manifestDirectory.DIRECTORY_SEPARATOR.'sessions.json';
});

afterEach(function (): void {
    if (is_file($this->manifest)) {
        unlink($this->manifest);
    }

    rmdir($this->manifestDirectory);
});

function eligibleLoadUser(): User
{
    $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

    foreach (['access_admin_panel', 'view_any_student', 'create_student', 'create_enrollment', 'create_payment', 'view_financial_report', 'view_payment'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function mintLoadSessions(User $user, string $path, ?string $database = null): PendingCommand
{
    return test()->artisan('load:mint-sessions', [
        '--user' => $user->getKey(),
        '--count' => 2,
        '--confirm-database' => $database ?? DB::connection()->getDatabaseName(),
        '--output' => $path,
    ]);
}

function loadComponentSnapshot(string $html, string $component): ?string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $raw) {
        $candidate = html_entity_decode($raw, ENT_QUOTES);
        if ((json_decode($candidate, true)['memo']['name'] ?? null) === $component) {
            return $candidate;
        }
    }

    return null;
}

it('mints distinct authenticated database sessions with encrypted cookies and matching CSRF tokens', function (): void {
    $user = eligibleLoadUser();
    mintLoadSessions($user, $this->manifest)->assertSuccessful();

    $manifest = json_decode(file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['sessions'])->toHaveCount(2)
        ->and($manifest['cookie_name'])->toBe(config('session.cookie'));

    $ids = [];
    foreach ($manifest['sessions'] as $session) {
        $ids[] = $session['id'];
        $decrypted = app('encrypter')->decrypt($session['cookie'], false);
        expect(CookieValuePrefix::validate($manifest['cookie_name'], $decrypted, [app('encrypter')->getKey()]))->toBe($session['id']);

        $row = DB::table('sessions')->where('id', $session['id'])->first();
        expect($row)->not->toBeNull()
            ->and((int) $row->user_id)->toBe((int) $user->getKey());
        $stored = base64_decode($row->payload, true);
        if (config('session.encrypt')) {
            $stored = app('encrypter')->decrypt($stored);
        }
        $payload = config('session.serialization', 'php') === 'json'
            ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR)
            : unserialize($stored);
        expect($payload[auth('web')->getName()])->toBe($user->getKey())
            ->and($payload['_token'])->toBe($session['csrf']);
    }

    expect(array_unique($ids))->toHaveCount(2);

    if (DIRECTORY_SEPARATOR === '/') {
        expect(fileperms($this->manifest) & 0777)->toBe(0600);
    }
});

it('authenticates a real staff panel request using a minted cookie', function (): void {
    $user = eligibleLoadUser();
    Student::factory()->create(['student_code' => 'PERF-000100']);
    Student::factory()->create(['student_code' => 'OTHER-001']);
    mintLoadSessions($user, $this->manifest)->assertSuccessful();
    $manifest = json_decode(file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);
    app('auth')->forgetGuards();

    $response = $this->withUnencryptedCookie($manifest['cookie_name'], $manifest['sessions'][0]['cookie'])
        ->get('/admin/students');
    expect(request()->cookies->get($manifest['cookie_name']))->toBe($manifest['sessions'][0]['id']);
    expect(request()->session()->get(auth('web')->getName()))->toBe($user->getKey());
    expect(auth('web')->user()?->getKey())->toBe($user->getKey());
    $response->assertSuccessful();

    $snapshot = loadComponentSnapshot((string) $response->getContent(), 'App\\Domain\\Enrollment\\Filament\\Resources\\StudentResource\\Pages\\ListStudents');
    expect($snapshot)->not->toBeNull();

    $searchResponse = $this->withHeaders(['X-Livewire' => 'true', 'X-CSRF-TOKEN' => $manifest['sessions'][0]['csrf']])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['tableSearch' => 'PERF-000100'],
                'calls' => [],
            ]],
        ])->assertSuccessful();

    $searchHtml = $searchResponse->json('components.0.effects.html');
    $searchSnapshot = json_decode($searchResponse->json('components.0.snapshot'), true);
    expect($searchHtml)->toContain('PERF-000100')
        ->not->toContain('OTHER-001')
        ->and($searchSnapshot['data']['tableSearch'])->toBe('PERF-000100');
});

it('renders applied report filters and fixture-backed rows through real Livewire updates', function (): void {
    $user = eligibleLoadUser();
    $student = Student::factory()->create(['student_code' => 'PERF-000001']);
    $course = Course::factory()->create(['code' => 'PERF-COURSE']);
    $batch = Batch::factory()->for($course)->create(['code' => 'PERF-BATCH']);
    $enrollment = Enrollment::factory()->for($batch)->for($student)->create();
    $charge = Charge::factory()->create([
        'enrollment_id' => $enrollment->getKey(),
        'list_price' => '1000.000',
        'amount' => '1000.000',
        'due_date' => '2026-06-01',
    ]);
    $payment = Payment::factory()->create([
        'student_id' => $student->getKey(),
        'received_at' => '2026-06-30 08:00:00',
    ]);
    PaymentAllocation::factory()->create(['charge_id' => $charge->getKey(), 'payment_id' => $payment->getKey(), 'amount' => '400.000']);
    PaymentTender::factory()->create(['payment_id' => $payment->getKey(), 'amount' => '400.000']);

    mintLoadSessions($user, $this->manifest)->assertSuccessful();
    $manifest = json_decode(file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);
    app('auth')->forgetGuards();
    $this->withUnencryptedCookie($manifest['cookie_name'], $manifest['sessions'][0]['cookie']);

    $cases = [
        [RevenueReportPage::class, ['from' => '2026-01-01', 'to' => '2026-12-31'], ['PERF-BATCH', '400.000']],
        [PaymentMethodReportPage::class, ['from' => '2026-01-01', 'to' => '2026-12-31'], ['Cash', '400.000']],
        [OutstandingAgedReportPage::class, ['date' => '2026-06-30'], ['PERF-000001', '600.000']],
        [StudentPaymentHistoryPage::class, ['student_id' => $student->getKey()], ['PERF-000001', '400.000']],
    ];

    foreach ($cases as [$page, $filters, $markers]) {
        $response = $this->get($page::getUrl())->assertSuccessful();
        $snapshot = loadComponentSnapshot((string) $response->getContent(), $page);
        expect($snapshot)->not->toBeNull();

        $updates = [];
        foreach ($filters as $field => $value) {
            $updates['filters.'.$field] = $value;
        }
        $updated = $this->withHeaders(['X-Livewire' => 'true', 'X-CSRF-TOKEN' => $manifest['sessions'][0]['csrf']])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => $updates,
                    'calls' => [['path' => '', 'method' => 'applyFilters', 'params' => []]],
                ]],
            ])->assertSuccessful();

        $updatedSnapshot = json_decode($updated->json('components.0.snapshot'), true);
        $applied = $updatedSnapshot['data']['appliedFilters'][0] ?? $updatedSnapshot['data']['appliedFilters'];
        $html = $updated->json('components.0.effects.html');
        foreach ($filters as $field => $value) {
            expect((string) $applied[$field])->toBe((string) $value);
        }
        foreach ($markers as $marker) {
            expect($html)->toContain($marker);
        }
    }
});

it('rejects a minted session if the password changes before its first request', function (): void {
    $user = eligibleLoadUser();
    mintLoadSessions($user, $this->manifest)->assertSuccessful();
    $manifest = json_decode(file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);

    $user->forceFill(['password' => Hash::make('changed-after-mint')])->save();
    app('auth')->forgetGuards();

    $this->withUnencryptedCookie($manifest['cookie_name'], $manifest['sessions'][0]['cookie'])
        ->get('/admin/students')
        ->assertRedirect('/admin/login');
});

it('prints the manifest path without printing any credential', function (): void {
    $user = eligibleLoadUser();
    $exit = Artisan::call('load:mint-sessions', [
        '--user' => $user->getKey(),
        '--count' => 2,
        '--confirm-database' => DB::connection()->getDatabaseName(),
        '--output' => $this->manifest,
    ]);
    $manifest = json_decode(file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain($this->manifest);

    foreach ($manifest['sessions'] as $session) {
        expect($output)->not->toContain($session['cookie'])
            ->and($output)->not->toContain($session['csrf'])
            ->and($output)->not->toContain($session['id']);
    }
});

it('refuses an unsafe database before creating a manifest or session', function (string $unsafe): void {
    $user = eligibleLoadUser();
    $database = DB::connection()->getDatabaseName();

    if ($unsafe === 'production') {
        $this->app->instance('env', 'production');
    } elseif ($unsafe === 'allowlist') {
        config([
            'performance.allowed_databases' => ['other_database'],
            'performance.allowed_database_pattern' => null,
        ]);
    }

    try {
        mintLoadSessions($user, $this->manifest, $unsafe === 'confirmation' ? $database.'-wrong' : $database)->assertFailed();
    } finally {
        $this->app->instance('env', 'testing');
    }

    expect(file_exists($this->manifest))->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe(0);
})->with(['production', 'confirmation', 'allowlist']);

it('refuses missing permissions, inactive accounts, and forced password changes', function (string $invalid): void {
    $user = eligibleLoadUser();

    if ($invalid === 'permission') {
        $user->revokePermissionTo('create_payment');
    } elseif ($invalid === 'inactive') {
        $user->update(['is_active' => false]);
    } else {
        $user->update(['must_change_password' => true]);
    }

    mintLoadSessions($user, $this->manifest)->assertFailed();

    expect(file_exists($this->manifest))->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe(0);
})->with(['permission', 'inactive', 'password-change']);

it('refuses a user without the quick-create student permission needed by the load flow', function (): void {
    $user = eligibleLoadUser();
    $user->revokePermissionTo('create_student');

    mintLoadSessions($user, $this->manifest)->assertFailed();

    expect(file_exists($this->manifest))->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('refuses output inside the repository and does not overwrite a manifest', function (): void {
    $user = eligibleLoadUser();
    $inside = base_path('tests/Load/forbidden-sessions.json');

    mintLoadSessions($user, $inside)->assertFailed();
    expect(file_exists($inside))->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe(0);

    file_put_contents($this->manifest, 'sentinel');
    mintLoadSessions($user, $this->manifest)->assertFailed();
    expect(file_get_contents($this->manifest))->toBe('sentinel')
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('revokes exactly the signed manifest sessions and leaves an unrelated session', function (): void {
    $user = eligibleLoadUser();
    mintLoadSessions($user, $this->manifest)->assertSuccessful();
    $ids = array_column(json_decode(file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR)['sessions'], 'id');

    DB::table('sessions')->insert([
        'id' => str_repeat('z', 40),
        'user_id' => $user->getKey(),
        'payload' => base64_encode(serialize(['_token' => 'unrelated'])),
        'last_activity' => time(),
    ]);

    $this->artisan('load:revoke-sessions', [
        '--manifest' => $this->manifest,
        '--confirm-database' => DB::connection()->getDatabaseName(),
    ])->assertSuccessful();

    expect(DB::table('sessions')->whereIn('id', $ids)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', str_repeat('z', 40))->exists())->toBeTrue()
        ->and(file_exists($this->manifest))->toBeFalse();
});

it('refuses revoked or tampered manifests before deleting any session', function (string $unsafe): void {
    $user = eligibleLoadUser();
    mintLoadSessions($user, $this->manifest)->assertSuccessful();
    $manifest = json_decode(file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);
    $ids = array_column($manifest['sessions'], 'id');
    $database = DB::connection()->getDatabaseName();

    if ($unsafe === 'tamper') {
        $manifest['sessions'][0]['id'] = str_repeat('z', 40);
        file_put_contents($this->manifest, json_encode($manifest, JSON_THROW_ON_ERROR));
    } elseif ($unsafe === 'production') {
        $this->app->instance('env', 'production');
    } elseif ($unsafe === 'allowlist') {
        config([
            'performance.allowed_databases' => ['other_database'],
            'performance.allowed_database_pattern' => null,
        ]);
    }

    try {
        $this->artisan('load:revoke-sessions', [
            '--manifest' => $this->manifest,
            '--confirm-database' => $unsafe === 'confirmation' ? $database.'-wrong' : $database,
        ])->assertFailed();
    } finally {
        $this->app->instance('env', 'testing');
    }

    expect(DB::table('sessions')->whereIn('id', $ids)->count())->toBe(2)
        ->and(file_exists($this->manifest))->toBeTrue();
})->with(['tamper', 'production', 'confirmation', 'allowlist']);
