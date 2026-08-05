<?php

declare(strict_types=1);

use App\Filament\Pages\PasswordChange;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->makeAdmin = function (bool $mustChangePassword): User {
        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => $mustChangePassword,
            // Known, because the form requires the current password since P1-T15.
            'password' => Hash::make('existing-password-1'),
        ]);
        $user->assignRole('admin');

        return $user;
    };

    /**
     * Every root Livewire component the given URL renders, as the browser sees it.
     *
     * Returns the raw `wire:snapshot` string alongside the memo, because a
     * snapshot's checksum HMACs its memo — one that has been decoded, touched and
     * re-encoded is rejected, so the string has to be replayed verbatim.
     *
     * @return array<int, array{name: mixed, path: mixed, snapshot: string}>
     */
    $this->rootComponents = function (string $url): array {
        $html = (string) $this->get($url)->assertSuccessful()->getContent();

        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        return array_map(function (string $raw): array {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);
            $decoded = json_decode($snapshot, true);

            return [
                'name' => $decoded['memo']['name'] ?? null,
                'path' => $decoded['memo']['path'] ?? null,
                'snapshot' => $snapshot,
            ];
        }, $matches[1]);
    };

    /**
     * The payload a browser posts when it drives an already-open component.
     *
     * The component is selected BY NAME rather than by position: the update
     * endpoint answers 404 for a payload it cannot make sense of, and a 404
     * satisfies "was refused" exactly as well as the guard firing does.
     *
     * @return array<string, mixed>
     */
    $this->replay = function (string $url, string $componentName): array {
        $match = null;

        foreach (($this->rootComponents)($url) as $component) {
            if ($component['name'] === $componentName) {
                $match = $component;

                break;
            }
        }

        expect($match)->not->toBeNull("No rendered snapshot for {$componentName} on {$url}.");

        return ['components' => [[
            'snapshot' => $match['snapshot'],
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]]];
    };

    /**
     * The same, but driving TWO genuine components from one render.
     *
     * Both snapshots are real and checksum-valid, which is what separates this
     * from the smuggled-name payload: that one fails closed on a name that does
     * not resolve, so a weakened guard rejects it as a 419 checksum failure and
     * the test would go red for a reason that is not the property. This is the
     * payload an attacker would actually post, and against a guard that checked
     * only the first entry it succeeds.
     *
     * @return array<string, mixed>
     */
    $this->replayBoth = function (string $url, string $first, string $second): array {
        $byName = [];

        foreach (($this->rootComponents)($url) as $component) {
            $byName[$component['name']] = $component['snapshot'];
        }

        expect($byName)->toHaveKeys([$first, $second]);

        return ['components' => array_map(fn (string $snapshot): array => [
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ], [$byName[$first], $byName[$second]])];
    };

    /**
     * Post a payload to Livewire's update endpoint the way the browser does.
     *
     * TWO THINGS HERE ARE LOAD-BEARING, AND BOTH FAIL AS A 404. The URI is
     * resolved rather than written as '/livewire/update', because this install
     * serves the endpoint from an obfuscated prefix; and the X-Livewire header is
     * what makes the endpoint recognise the request as its own. See
     * LivewirePersistentGuardTest, where both mistakes were made and only the
     * good-standing control caught them.
     *
     * POST ONCE PER TEST. PersistentMiddleware::$middlewareAppliedFor is cleared
     * by `flush-state`, which Livewire fires from its testing helpers but not
     * from a real HTTP cycle — so a SECOND post in the same test carrying the
     * same `memo.method|memo.path` skips every persistent middleware, this guard
     * included, and answers 200. That 200 means the guard never ran, not that it
     * allowed anything. Every test below posts once; if you need two, drive them
     * from separate tests.
     */
    $this->interact = fn (array $payload) => $this
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), $payload);
});

it('redirects a flagged user to the password change page', function () {
    $user = ($this->makeAdmin)(true);

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect('/admin/password-change');
});

it('does not redirect an unflagged user', function () {
    $user = ($this->makeAdmin)(false);

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});

it('does not redirect on the password change page itself', function () {
    $user = ($this->makeAdmin)(true);

    $this->actingAs($user)
        ->get('/admin/password-change')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Containment on Livewire's update endpoint (G1-U3)
|--------------------------------------------------------------------------
|
| WHAT WAS WRONG, AND WHY Livewire::test() CANNOT SEE IT.
|
| Livewire's PersistentMiddleware writes the originating route into the snapshot
| on dehydrate, then on `snapshot-verified` reads `memo.path` back OUT of the
| snapshot, fabricates a request for that path, and applies that route's
| middleware. ForcePasswordChange exempted on `$request->routeIs(PAGE_ROUTE)`, so
| a snapshot carrying `memo.path=admin/password-change` passed — whichever
| component it actually belonged to. The page rendered with the full panel
| layout, so five components carried that path and all five stayed drivable while
| the account was supposed to be held on the password form.
|
| Livewire::test() never routes, so the `snapshot-verified` hook bails out before
| any persistent middleware runs. A test written that way passes whether or not
| the guard exists. Every case below is therefore a real HTTP post to the real
| update endpoint.
|
| THE CONTROL IS NOT OPTIONAL. The pass condition for a refusal test is "not
| 200", and a broken payload, a wrong URI or a stale component name all produce
| exactly that. Without a good-standing interaction proving a 200 is reachable at
| all, none of the refusals below mean anything.
*/

it('lets a livewire interaction through while the account is in good standing', function () {
    $user = ($this->makeAdmin)(false);
    $this->actingAs($user);

    // The SAME component the refusal below drives, from the SAME page. Anything
    // else and the two tests are not comparable.
    $payload = ($this->replay)('/admin/password-change', Notifications::class);

    ($this->interact)($payload)->assertSuccessful();
});

it('refuses to drive a component co-rendered on the password change page', function () {
    $user = ($this->makeAdmin)(true);
    $this->actingAs($user);

    $payload = ($this->replay)('/admin/password-change', Notifications::class);

    // Refused, and refused BY THIS GUARD: the redirect target names it. Asserting
    // only "not 200" would accept the 404 the control exists to rule out.
    ($this->interact)($payload)->assertRedirect('/admin/password-change');
});

it('still lets the password form itself be driven', function () {
    $user = ($this->makeAdmin)(true);
    $this->actingAs($user);

    // The other half of the property. A guard that refused everything on the
    // update endpoint would pass every refusal test above and make the page an
    // inescapable trap — the form posts through the same endpoint.
    $payload = ($this->replay)('/admin/password-change', PasswordChange::class);

    ($this->interact)($payload)->assertSuccessful();
});

it('refuses a payload that smuggles a second component in behind the password form', function () {
    $user = ($this->makeAdmin)(true);
    $this->actingAs($user);

    $payload = ($this->replay)('/admin/password-change', PasswordChange::class);

    /*
     * THE WHOLE PAYLOAD IS CHECKED, NOT THE FIRST ENTRY.
     *
     * applyPersistentMiddleware() dedupes by `method|path`, so the guard runs
     * exactly once even when several components share the page's path — checking
     * only the snapshot that happened to trigger it would let everything behind
     * it through. handleUpdate() aborts 404 on a structurally malformed payload
     * before any of this, so the reachable case is a well-formed snapshot string
     * naming a component that does not resolve.
     *
     * This covers the fail-closed half only. Because the smuggled checksum is
     * junk, a weakened guard rejects this payload as a 419 before the property is
     * ever in question — so the two tests below carry the same property with two
     * genuine snapshots, where a first-entry-only guard really does return 200.
     */
    $payload['components'][] = [
        'snapshot' => (string) json_encode([
            'data' => [],
            'memo' => [
                'id' => 'smuggled0000000000000',
                'name' => 'App\Filament\Pages\NoSuchComponent',
                'path' => 'admin/password-change',
                'method' => 'GET',
            ],
            'checksum' => 'not-a-real-checksum',
        ]),
        'updates' => [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ];

    ($this->interact)($payload)->assertRedirect('/admin/password-change');
});

it('lets two genuine components through together while the account is in good standing', function () {
    $user = ($this->makeAdmin)(false);
    $this->actingAs($user);

    // The control for the refusal below, on the identical payload. Without it a
    // 302 there could be the endpoint disliking a two-component post rather than
    // the guard reading past the first entry.
    $payload = ($this->replayBoth)('/admin/password-change', PasswordChange::class, Notifications::class);

    ($this->interact)($payload)->assertSuccessful();
});

it('refuses two genuine components even when the password form comes first', function () {
    $user = ($this->makeAdmin)(true);
    $this->actingAs($user);

    // Ordering is the point: the form is the entry that triggers the guard, and
    // the tray rides behind it. Both snapshots are real, so this fails on the
    // property rather than on a checksum.
    $payload = ($this->replayBoth)('/admin/password-change', PasswordChange::class, Notifications::class);

    ($this->interact)($payload)->assertRedirect('/admin/password-change');
});

it('co-renders nothing on the password change page but the form and the notification tray', function () {
    $user = ($this->makeAdmin)(true);
    $this->actingAs($user);

    $names = array_map(
        fn (array $component): mixed => $component['name'],
        ($this->rootComponents)('/admin/password-change'),
    );

    sort($names);

    /*
     * THE STRUCTURAL GUARD. This is what stops a future widget quietly reopening
     * the hole: it asserts the WHOLE set, so any addition fails it, rather than
     * naming the three components removed today (Topbar, Sidebar, GlobalSearch)
     * and passing for the fourth.
     *
     * Filament\Livewire\Notifications is here because it cannot be removed:
     * filament-panels::components.layout.base renders it unconditionally, with no
     * `@if` and no panel setting to turn it off, so every layout carries it. It
     * is the flash-message tray — it pulls notifications out of the session and
     * has no other state — and layer 2 refuses to drive it anyway, which is what
     * the refusal test above proves. The design spec expected this count to be
     * one; that was wrong about the base layout, not about the property.
     */
    expect($names)->toBe([PasswordChange::class, Notifications::class]);
});

/*
|--------------------------------------------------------------------------
| The way out
|--------------------------------------------------------------------------
|
| Layer 1 took the topbar away, and with it the panel's own logout control, so
| the page carries its own. Route::post('/logout') is registered inside
| Route::middleware($panel->getAuthMiddleware()), which means this guard wraps
| it: without an exemption the new button would redirect back to the form and a
| flagged user would have no exit but closing the browser.
*/

it('offers the flagged user a visible way out', function () {
    $user = ($this->makeAdmin)(true);

    $html = (string) $this->actingAs($user)
        ->get('/admin/password-change')
        ->assertSuccessful()
        ->getContent();

    // The route working is not the same as the user being able to reach it, and
    // the control the panel normally provides left with the topbar.
    expect($html)->toContain('action="'.route('filament.admin.auth.logout').'"');
});

it('lets a flagged user log out', function () {
    $user = ($this->makeAdmin)(true);
    $this->actingAs($user);

    // The target is what discriminates: without the exemption this is a 302 to
    // /admin/password-change — a refusal that assertRedirect() alone would accept.
    $this->post('/admin/logout')->assertRedirect('/admin/login');

    // Genuinely signed out, not merely redirected once.
    $this->get('/admin/password-change')->assertRedirect('/admin/login');
});

it('does not open the logout exemption to a GET', function () {
    $user = ($this->makeAdmin)(true);
    $this->actingAs($user);

    // The exemption is scoped to the exact route name, and that route is POST
    // only, so a link cannot reach it. Routing refuses this, not the guard.
    $this->get('/admin/logout')->assertStatus(405);
});

/*
|--------------------------------------------------------------------------
| The way out, part two: the save must not destroy the session
|--------------------------------------------------------------------------
|
| This replaces a Livewire::test() proof, which could not observe any of it.
|
| AuthenticateSession is persistent, and Utils::applyMiddleware() terminates its
| pipeline in an empty Response — so the middleware's after-callback fires at
| `snapshot-verified` time, BEFORE save() runs, storing the OLD hash. The
| password then changes, the next request mismatches, and the user is logged out
| of the session they just used to fix their account. Measured: the save
| succeeded and the follow-up landed on /admin/login.
*/

it('clears the flag once a new password is set, without signing the session out', function () {
    $user = ($this->makeAdmin)(true);
    $this->actingAs($user);

    $payload = ($this->replay)('/admin/password-change', PasswordChange::class);
    $payload['components'][0]['updates'] = [
        'data.current_password' => 'existing-password-1',
        'data.password' => 'correct-horse-battery',
        'data.password_confirmation' => 'correct-horse-battery',
    ];
    $payload['components'][0]['calls'] = [['path' => '', 'method' => 'save', 'params' => []]];

    ($this->interact)($payload)->assertSuccessful();

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();

    // The redirect save() issues has to land somewhere the user is still allowed
    // to be. Without the session refresh this is a 302 to /admin/login.
    $this->get('/admin')->assertSuccessful();
});

it('rejects a password shorter than twelve characters', function () {
    $user = ($this->makeAdmin)(true);

    $this->actingAs($user);

    Livewire::test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'existing-password-1',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect($user->refresh()->must_change_password)->toBeTrue();
});
