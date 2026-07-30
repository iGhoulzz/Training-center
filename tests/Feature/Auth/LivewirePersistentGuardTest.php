<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Livewire persistent middleware, proven behaviourally (P1-T15, finding 5)
|--------------------------------------------------------------------------
|
| WHY THESE ARE REAL HTTP TESTS AND NOT Livewire::test().
|
| The test harness cannot exercise this. Livewire applies persistent middleware
| from a `snapshot-verified` hook that opens with an explicit bail-out:
|
|   // Only apply middleware to requests hitting the Livewire update endpoint,
|   // and not any fake requests such as a test.
|   if (! app(HandleRequests::class)->isLivewireRoute()) return;
|
| — vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php
|
| isLivewireRoute() resolves the CURRENT route and checks its name ends with
| `livewire.update`. Livewire::test() never routes, so the hook returns early and
| every persistent middleware is skipped. A test written that way passes whether
| or not the middleware is registered — which is exactly the gap these close.
|
| So each test does what a browser does: load a real panel page, take the page
| component's snapshot out of the rendered HTML, and POST it back to the update
| endpoint. The snapshot carries Livewire's own checksum, so it cannot be
| fabricated; it has to come from a genuine render, which is also what makes this
| the stale-open-page scenario rather than a synthetic one.
|
| SecurityReviewRegressionTest's registration assertions stay: they fail fast and
| name the missing class. They assert wiring. These assert consequence.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->makeAdmin = function (array $attributes = []): User {
        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
            'password' => Hash::make('existing-password-1'),
            ...$attributes,
        ]);
        $this->system->assignRoles($user, 'admin');

        return $user->refresh();
    };

    /**
     * Load a panel page and return the payload a browser would POST back.
     *
     * The component is selected BY NAME. A panel page renders five components —
     * Topbar, GlobalSearch, Sidebar, the page itself, Notifications — and the
     * first `wire:snapshot` in the markup is the Topbar, whose snapshot the
     * update endpoint rejects with a 404. Taking "the first one" produced a
     * refusal-shaped failure on every request, including ones that should have
     * succeeded, and two guard tests passed against it for the wrong reason.
     */
    $this->openPageComponent = function (string $url, string $componentName): array {
        $html = (string) $this->get($url)->assertSuccessful()->getContent();

        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        $snapshot = null;

        foreach ($matches[1] as $raw) {
            $candidate = html_entity_decode($raw, ENT_QUOTES);
            $decoded = json_decode($candidate, true);

            if (($decoded['memo']['name'] ?? null) === $componentName) {
                $snapshot = $candidate;

                break;
            }
        }

        expect($snapshot)->not->toBeNull("No rendered snapshot for {$componentName} on {$url}.");

        return [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ];
    };

    /**
     * Post an already-open component back, the way an idle tab does.
     *
     * TWO THINGS HERE ARE LOAD-BEARING, AND BOTH FAIL AS A 404.
     *
     * The URI is resolved rather than written as '/livewire/update': this
     * install serves the endpoint from an obfuscated prefix.
     *
     * The X-Livewire header is what makes the endpoint recognise the request as
     * its own; without it the route matches and the handler still answers 404.
     *
     * A 404 satisfies "was refused", so either mistake produces guard tests that
     * pass while proving nothing. That is not hypothetical — it happened twice
     * while writing these, and only the good-standing control caught it.
     */
    $this->interact = fn (array $payload) => $this
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), $payload);

    $this->listUsers = 'App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers';
});

/*
|--------------------------------------------------------------------------
| The control comes first, because everything else depends on it
|--------------------------------------------------------------------------
*/

it('lets a livewire interaction through while the account is in good standing', function () {
    /*
     * WITHOUT THIS TEST THE OTHER TWO ARE WORTHLESS.
     *
     * Their pass condition is "the interaction was refused", and a refusal is
     * indistinguishable from a broken payload, a wrong URI, or the wrong
     * component. Both refusal tests below passed on a 404 before this control
     * existed. It is the only thing establishing that a successful interaction
     * is reachable at all.
     */
    $user = ($this->makeAdmin)();
    $this->actingAs($user);

    $payload = ($this->openPageComponent)('/admin/users', $this->listUsers);

    ($this->interact)($payload)->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| ForcePasswordChange
|--------------------------------------------------------------------------
*/

it('refuses an interaction from a component opened before the password reset', function () {
    $user = ($this->makeAdmin)();
    $this->actingAs($user);

    // The tab was opened while the account was in good standing.
    $payload = ($this->openPageComponent)('/admin/users', $this->listUsers);

    /*
     * ONLY the rotation flag, deliberately.
     *
     * ResetUserPasswordAction also writes a new hash — but changing both here
     * would let AuthenticateSession refuse the request first, and this test
     * would pass without ForcePasswordChange being registered at all. One guard
     * per test, or the mutation probes cannot tell them apart.
     */
    $user->forceFill(['must_change_password' => true])->save();

    $response = ($this->interact)($payload);

    // Refused, and refused BY THE GUARD: the redirect target names it. Asserting
    // only "not 200" would accept any error at all.
    $response->assertRedirect('/admin/password-change');
});

it('does not execute a mutation from a component opened before the password reset', function () {
    $actor = ($this->makeAdmin)();
    $victim = ($this->makeAdmin)();

    $this->actingAs($actor);

    $payload = ($this->openPageComponent)('/admin/users', $this->listUsers);

    // The interaction is a real delete, not a refresh.
    $payload['components'][0]['calls'] = [[
        'path' => '',
        'method' => 'mountAction',
        'params' => ['delete', ['table' => true, 'recordKey' => (string) $victim->getKey()]],
    ]];

    // The flag alone, for the same reason as above.
    $actor->forceFill(['must_change_password' => true])->save();

    ($this->interact)($payload)->assertRedirect('/admin/password-change');

    // The point of the guard is that the write did not happen.
    expect(User::withTrashed()->find($victim->getKey())->trashed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| AuthenticateSession
|--------------------------------------------------------------------------
*/

it('invalidates the session when the password hash changes under it', function () {
    $user = ($this->makeAdmin)();
    $this->actingAs($user);

    // The first request through AuthenticateSession stores password_hash_web;
    // every later request compares against it.
    $payload = ($this->openPageComponent)('/admin/users', $this->listUsers);

    // Another session replaces the credential — an administrator containing a
    // compromise, or the owner locking out a thief. must_change_password stays
    // false, so ForcePasswordChange is NOT what refuses this one.
    $user->forceFill(['password' => Hash::make('changed-elsewhere-3')])->save();

    ($this->interact)($payload)->assertRedirect('/admin/login');

    // And the session is genuinely gone, not merely redirected once.
    $this->get('/admin/users')->assertRedirect('/admin/login');
});
