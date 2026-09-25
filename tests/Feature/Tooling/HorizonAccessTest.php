<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use App\Providers\HorizonServiceProvider;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\Events\LongWaitDetected as LongWaitDetectedEvent;
use Laravel\Horizon\Listeners\SendNotification;
use Laravel\Horizon\Lock;
use Laravel\Horizon\Notifications\LongWaitDetected;

uses(RefreshDatabase::class);

it('allows an active roleless user holding only the activity log read permission to view the Horizon dashboard', function (): void {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    $actor->givePermissionTo('view_any_activity');

    expect($actor->fresh()?->roles)->toBeEmpty();

    app()->detectEnvironment(fn (): string => 'production');

    $this->actingAs($actor->fresh())
        ->get('/horizon')
        ->assertSuccessful();
});

it('forbids an unauthorized authenticated user from the Horizon dashboard outside local environments', function (): void {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    app(SystemRoleWriter::class)->assignRoles($actor, 'staff');

    app()->detectEnvironment(fn (): string => 'production');

    $this->actingAs($actor)
        ->get('/horizon')
        ->assertForbidden();
});

it('forbids an inactive user holding the activity log read permission from the Horizon dashboard outside local environments', function (): void {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => false]);
    $actor->givePermissionTo('view_any_activity');

    expect($actor->fresh()?->can('view_any_activity'))->toBeTrue();

    app()->detectEnvironment(fn (): string => 'production');

    $this->actingAs($actor->fresh())
        ->get('/horizon')
        ->assertForbidden();
});

it('routes long wait alerts by mail to the configured operations address', function (): void {
    Notification::fake();

    config()->set('horizon.alert_email', 'queue-alerts@example.com');
    config()->set('mail.default', 'smtp');
    app()->detectEnvironment(fn (): string => 'production');
    (new HorizonServiceProvider($this->app))->boot();

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturnTrue();
    $this->app->instance(Lock::class, $lock);

    app(SendNotification::class)->handle(new LongWaitDetectedEvent('redis', 'default', 61));

    Notification::assertSentOnDemand(
        LongWaitDetected::class,
        function (LongWaitDetected $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            return array_values($channels) === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'queue-alerts@example.com';
        },
    );
});

it('refuses to route Horizon alerts to an invalid email address', function (): void {
    config()->set('horizon.alert_email', 'not-an-email');

    expect(fn () => (new HorizonServiceProvider($this->app))->boot())
        ->toThrow(InvalidArgumentException::class, 'HORIZON_ALERT_EMAIL');
});

it('requires a Horizon alert recipient in production', function (): void {
    config()->set('horizon.alert_email');
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new HorizonServiceProvider($this->app))->boot())
        ->toThrow(InvalidArgumentException::class, 'HORIZON_ALERT_EMAIL');
});

it('treats a blank Horizon alert recipient as unset outside production', function (): void {
    config()->set('horizon.alert_email', '');

    expect(fn () => (new HorizonServiceProvider($this->app))->boot())
        ->not->toThrow(InvalidArgumentException::class);
});

it('refuses a non-delivering Horizon alert mailer in production', function (string $transport): void {
    config()->set('horizon.alert_email', 'queue-alerts@example.com');
    config()->set('mail.default', 'alerts');
    config()->set('mail.mailers.alerts', ['transport' => $transport]);
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new HorizonServiceProvider($this->app))->boot())
        ->toThrow(InvalidArgumentException::class, 'MAIL_MAILER');
})->with(['log', 'array']);

it('uses the agreed Redis wait threshold and worker topology', function (): void {
    expect(config('horizon.waits.redis:default'))->toBe(60)
        ->and(config('horizon.defaults.supervisor-1.connection'))->toBe('redis')
        ->and(config('horizon.defaults.supervisor-1.queue'))->toBe(['default'])
        ->and(config('horizon.defaults.supervisor-1.balance'))->toBe('auto')
        ->and(config('horizon.environments.production.supervisor-1.maxProcesses'))->toBe(10);
});

it('takes a Horizon metrics snapshot every five minutes', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'horizon:snapshot'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});
