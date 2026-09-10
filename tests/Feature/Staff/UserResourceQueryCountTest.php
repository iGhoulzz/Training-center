<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The user list re-read one row's primary key once per row (P35-T02)
|--------------------------------------------------------------------------
|
| P3-T15's audit measured 28 repeated super-admin role lookups on a 10-row
| user page. Both row actions call UserPolicy::outranks(), which calls
| User::isSuperAdmin(), which resolved Role::superAdminId() with a fresh
| SELECT every time.
|
| THIS FILE ASSERTS THE INVARIANT, NOT A MAGIC TOTAL. Pinning "33 queries"
| would break on any unrelated column added to the table and would say
| nothing about which half moved. The two properties that matter are counted
| separately by SQL shape:
|
|   - the role-id lookup must be issued ONCE, however many rows render;
|   - the pivot exists() read must still be issued PER ROW.
|
| The second is not an oversight to be optimised away later. It is the
| authorization decision. User::isSuperAdmin()'s docblock records that
| reading a stale roles relation once let the last super admin be deleted, so
| a test that let the pivot count stop growing would be asserting the
| presence of that bug.
|
| Measured on this exact code, at ten rows:
|
|     binding removed:  29 role lookups, 29 pivot reads, 61 statements
|     binding present:   1 role lookup,  29 pivot reads, 33 statements
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);
});

/**
 * A super admin actor, plus $others staff accounts for the table to render.
 *
 * Roles are written through SystemRoleWriter, the trusted system path — the
 * request-path Actions require an actor and would refuse a fixture.
 */
function listUsersActorWithPeers(int $others): User
{
    /** @var SystemRoleWriter $system */
    $system = test()->system;

    $actor = User::factory()->create(['is_active' => true]);
    $system->assignRoles($actor, 'super_admin');

    foreach (range(1, $others) as $ignored) {
        $system->assignRoles(User::factory()->create(['is_active' => true]), 'staff');
    }

    return $actor->refresh();
}

/**
 * Render ListUsers for $actor and count the two statement shapes separately.
 *
 * @return array{roleLookups: int, pivotReads: int, total: int}
 */
function listUsersStatementShapes(User $actor): array
{
    /*
     * Spatie caches the whole permission map on its first read in this
     * process. Priming it before the listener attaches keeps that one-time
     * miss out of the measurement, which would otherwise depend on test
     * ordering rather than on this page.
     */
    $actor->can('view_any_user');

    /*
     * Each measurement stands for one HTTP request. Without this the scoped
     * resolver built during an earlier render is still in the container, so a
     * second measurement in the same test process would count zero lookups —
     * correct behaviour, but it would prove the memo rather than the page.
     */
    app()->forgetScopedInstances();

    $roleLookups = 0;
    $pivotReads = 0;
    $total = 0;

    DB::listen(function ($query) use (&$roleLookups, &$pivotReads, &$total): void {
        $total++;

        // Role::superAdminId() — the canonical row, by name and guard.
        if (str_contains($query->sql, 'from `roles` where `name` = ?')) {
            $roleLookups++;
        }

        // User::isSuperAdmin() — does THIS user hold that role, right now?
        if (str_contains($query->sql, 'model_has_roles') && str_contains($query->sql, 'as `exists`')) {
            $pivotReads++;
        }
    });

    Livewire::actingAs($actor)->test(ListUsers::class)->assertOk();

    return ['roleLookups' => $roleLookups, 'pivotReads' => $pivotReads, 'total' => $total];
}

it('resolves the super-admin role id once however many rows the user list renders', function () {
    $shapes = listUsersStatementShapes(listUsersActorWithPeers(9));

    expect($shapes['roleLookups'])->toBe(
        1,
        "The user list issued {$shapes['roleLookups']} super-admin role lookups for ten rows. ".
        'Role::superAdminId() must resolve once per request, through the scoped '.
        'SuperAdminRoleId binding in AppServiceProvider::register().',
    );
});

it('does not cache the pivot read that decides whether a user is a super admin', function () {
    /*
     * The guard against over-caching. Memoizing isSuperAdmin() itself would
     * make this page cheaper still and would reintroduce the stale-relation
     * bug User::isSuperAdmin()'s docblock describes, so the per-row read is
     * required to keep growing with the row count.
     */
    $threeRows = listUsersStatementShapes(listUsersActorWithPeers(2));
    $tenRows = listUsersStatementShapes(listUsersActorWithPeers(9));

    expect($threeRows['pivotReads'])->toBeGreaterThan(0)
        ->and($tenRows['pivotReads'])->toBeGreaterThan(
            $threeRows['pivotReads'],
            "Three rows issued {$threeRows['pivotReads']} pivot reads and ten issued ".
            "{$tenRows['pivotReads']}. The role-membership read is the authorization ".
            'decision and must stay fresh per row; only the role id is memoized.',
        );

    // And the memo still holds across the larger page.
    expect($tenRows['roleLookups'])->toBe(1)
        ->and($threeRows['roleLookups'])->toBe(1);
});
