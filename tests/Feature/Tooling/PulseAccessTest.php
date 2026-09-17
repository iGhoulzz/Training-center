<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Ingests\StorageIngest;
use Laravel\Pulse\Recorders;
use Laravel\Pulse\Storage\DatabaseStorage;

uses(RefreshDatabase::class);

it('allows users with the activity log read permission to view the Pulse dashboard', function (string $role): void {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    app(SystemRoleWriter::class)->assignRoles($actor, $role);

    $this->actingAs($actor)
        ->get('/pulse')
        ->assertSuccessful();
})->with(['super admin' => 'super_admin', 'admin' => 'admin']);

it('forbids an authenticated staff user from viewing the Pulse dashboard', function (): void {
    $this->seed(RolePermissionSeeder::class);

    $staff = User::factory()->create();
    app(SystemRoleWriter::class)->assignRoles($staff, 'staff');

    $this->actingAs($staff)
        ->get('/pulse')
        ->assertForbidden();
});

it('forbids guests from every Pulse route', function (): void {
    $pulsePath = trim((string) config('pulse.path'), '/');
    $pulseRoutes = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => Str::startsWith(trim($route->uri(), '/'), $pulsePath));

    expect($pulseRoutes)->not->toBeEmpty();

    $pulseRoutes->each(function (Route $route): void {
        $this->call($route->methods()[0], '/'.trim($route->uri(), '/'))
            ->assertForbidden();
    });
});

it('uses direct database Pulse storage with the agreed retention, thresholds, and sampling', function (): void {
    expect(config('pulse.storage.driver'))->toBe('database')
        ->and(config('pulse.ingest.driver'))->toBe('storage')
        ->and(config('pulse.storage.trim.keep'))->toBe('7 days')
        ->and(config('pulse.ingest.trim.keep'))->toBe('7 days')
        ->and(config('pulse.recorders.'.Recorders\SlowQueries::class.'.threshold'))->toBe(500)
        ->and(config('pulse.recorders.'.Recorders\SlowRequests::class.'.threshold'))->toBe(1000)
        ->and(config('pulse.recorders.'.Recorders\SlowJobs::class.'.threshold'))->toBe(1000)
        ->and(config('pulse.recorders.'.Recorders\SlowOutgoingRequests::class.'.threshold'))->toBe(1000)
        ->and(app(Storage::class))->toBeInstanceOf(DatabaseStorage::class)
        ->and(app(Ingest::class))->toBeInstanceOf(StorageIngest::class);

    collect(config('pulse.recorders'))
        ->filter(fn (array $recorder): bool => $recorder['enabled'] ?? false)
        ->each(function (array $recorder): void {
            expect($recorder['sample_rate'])->toBe(1);
        });
});
