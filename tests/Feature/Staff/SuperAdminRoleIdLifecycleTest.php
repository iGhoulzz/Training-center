<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Support\SuperAdminRoleId;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| How long the memoized role id may live (P35-T02)
|--------------------------------------------------------------------------
|
| The speed-up is the easy half. The lifetime is the requirement, and it is
| the half a later refactor is likely to get wrong, so each rule is asserted
| against behaviour rather than against the binding's spelling.
|
| Three ways this could be built and be wrong:
|
|   1. A static property on Role, or a plain singleton. Both survive from one
|      queued job to the next inside a long-lived worker, so a worker that
|      first resolved before the roles table was seeded would answer "no
|      super-admin role" for as long as it ran.
|   2. Memoizing the null. One call made before the canonical role exists —
|      during bootstrap, during RolePermissionSeeder, or in a request that
|      creates it — would freeze a permanent false for the rest of that
|      request.
|   3. Memoizing membership rather than the role id. That is the stale
|      relation User::isSuperAdmin()'s docblock records: it once let the last
|      super admin be deleted.
*/

uses(RefreshDatabase::class);

/** Delete the canonical super-admin role row, whatever the seeder made. */
function forgetSuperAdminRole(): void
{
    Role::query()->where('name', Role::SUPER_ADMIN)->delete();
}

it('does not memoize a missing role into a permanent false within one request', function () {
    /*
     * Rule 2. No seeder here — the role genuinely does not exist yet, which
     * is the bootstrap and mid-request-creation case.
     */
    $resolver = app(SuperAdminRoleId::class);

    expect($resolver->value())->toBeNull();

    $this->seed(RolePermissionSeeder::class);
    $created = Role::query()->where('name', Role::SUPER_ADMIN)->value('id');

    expect($created)->not->toBeNull()
        ->and($resolver->value())->toBe(
            $created,
            'The resolver answered null before the role existed and kept answering null after '.
            'it was created. A negative result must never be memoized, or every isSuperAdmin() '.
            'check for the rest of the request is a permanent false.',
        );
});

it('memoizes a found role id so repeated calls issue one statement', function () {
    $this->seed(RolePermissionSeeder::class);

    $resolver = app(SuperAdminRoleId::class);
    $resolver->value();

    $lookups = 0;
    DB::listen(function ($query) use (&$lookups): void {
        if (str_contains($query->sql, 'from `roles` where `name` = ?')) {
            $lookups++;
        }
    });

    foreach (range(1, 20) as $ignored) {
        $resolver->value();
    }

    expect($lookups)->toBe(0, "Twenty repeated calls issued {$lookups} lookups; the first call should be the only one.");
});

it('answers correctly on a later job after a worker first resolved before the role existed', function () {
    /*
     * The bootstrap-order case: a worker boots and resolves before the roles
     * table is seeded, then runs a job afterwards.
     *
     * BE PRECISE ABOUT WHAT THIS PROVES. It passes under singleton() too,
     * because rule 2 alone carries it — a null was never memoized, so the
     * second call re-reads whether or not the container was reset. It is here
     * for the scenario, not as the scoped-versus-singleton discriminator.
     * That discriminator is the next two tests, which start from a value that
     * WAS memoized; both fail if this binding becomes a singleton.
     */
    $jobOne = app(SuperAdminRoleId::class)->value();

    expect($jobOne)->toBeNull();

    $this->seed(RolePermissionSeeder::class);
    $realId = Role::query()->where('name', Role::SUPER_ADMIN)->value('id');

    app()->forgetScopedInstances();

    $jobTwo = app(SuperAdminRoleId::class)->value();

    expect($jobTwo)->toBe(
        $realId,
        'The second job reused the first job\'s resolver. The binding must be scoped(), '.
        'not singleton() and not a static property, or a long-lived worker answers with '.
        'whatever was true when it booted.',
    );
});

it('rebuilds the resolver instance across a job boundary', function () {
    $this->seed(RolePermissionSeeder::class);

    $first = app(SuperAdminRoleId::class);
    app()->forgetScopedInstances();
    $second = app(SuperAdminRoleId::class);

    expect($second)->not->toBe(
        $first,
        'forgetScopedInstances() left the same instance in the container, so the binding '.
        'is a singleton rather than scoped.',
    );
});

it('still authorizes against current role membership after the role id is memoized', function () {
    /*
     * Rule 3, and the second half of the task's mutation requirement: memoizing
     * the role id must not memoize who holds it.
     */
    $this->seed(RolePermissionSeeder::class);
    $system = app(SystemRoleWriter::class);

    // Two, so revoking one does not trip the last-super-admin invariant —
    // which is a different guard, tested in SuperAdminInvariantTest.
    $system->assignRoles(User::factory()->create(['is_active' => true]), 'super_admin');

    $user = User::factory()->create(['is_active' => true]);
    $system->assignRoles($user, 'super_admin');

    expect($user->refresh()->isSuperAdmin())->toBeTrue();

    // Same request, same memoized role id, membership revoked underneath it.
    $system->syncRoles($user, ['staff']);

    expect($user->refresh()->isSuperAdmin())->toBeFalse(
        'isSuperAdmin() answered from a cached membership. Only the role id may be '.
        'memoized; the pivot read is the authorization decision and must be fresh.',
    );
});

it('re-reads the role id after the canonical role row is replaced', function () {
    /*
     * The memo is keyed to nothing but the request, so a role row replaced
     * mid-request is not visible until the next one. That is acceptable —
     * the row is protected by RolePolicy and a unique index — but it must not
     * be true ACROSS requests, which is what this asserts.
     */
    $this->seed(RolePermissionSeeder::class);

    $original = app(SuperAdminRoleId::class)->value();

    forgetSuperAdminRole();
    $replacement = Role::query()->create([
        'name' => Role::SUPER_ADMIN,
        'guard_name' => config('auth.defaults.guard'),
    ]);

    app()->forgetScopedInstances();

    expect(app(SuperAdminRoleId::class)->value())
        ->not->toBe($original)
        ->and(app(SuperAdminRoleId::class)->value())->toBe($replacement->getKey());
});
