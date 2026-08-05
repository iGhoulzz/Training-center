# Password-change containment — closing G1-U3

**Date:** 2026-08-05
**Status:** revised after Codex review, awaiting re-review
**Origin:** `docs/reviews/2026-07-29-phase-1-review.md`, finding G1-U3 — the one
phase 1 review finding left open.

---

## 1. The defect

`ForcePasswordChange` is meant to hold a flagged account on
`/admin/password-change` and let it do nothing else. It does not.

Livewire's `PersistentMiddleware` writes the originating route into the snapshot
on dehydrate, then on `snapshot-verified` reads `memo.path` *back out of the
snapshot*, fabricates a request for that path, and applies that route's
middleware. `ForcePasswordChange` exempts via `$request->routeIs(PAGE_ROUTE)`, so
a snapshot carrying `memo.path=admin/password-change` passes — **whichever
component it actually belongs to.** The page renders with the full panel layout,
so five components carry that path, and all five were measured drivable
(`Topbar`, `Sidebar`, `GlobalSearch`, `Notifications`, and the page itself)
while `GET /admin/students` correctly redirected.

**Medium severity, scoped honestly.** No privilege is gained — global search
still runs each resource's `canViewAny()`. What is defeated is *containment*:
the flag exists so an administrator who has just revoked a credential knows the
holder of that session does nothing further until they set a new password.

The snapshot checksum HMACs `memo`, so an arbitrary originating route cannot be
forged. Exposure is limited to components actually co-rendered on the page.

---

## 2. Design

Layer 1 removes the surface; layer 2 enforces the property so it cannot return
unnoticed.

### Layer 1 — the page renders no chrome

**`PasswordChange` keeps `extends Page`.** It must: the panel calls
`discoverPages(in: app_path('Filament/Pages'))`, which passes `Page::class` to
`discoverComponents()`. `SimplePage` extends `BasePage`, *not* `Page`, so
switching to it would drop the page from discovery and delete the
`filament.admin.pages.password-change` route that `PAGE_ROUTE` depends on.

Instead the page keeps its class and takes the chrome-free layout directly:

```php
protected static string $layout = 'filament-panels::components.layout.simple';

protected function getLayoutData(): array
{
    return ['hasTopbar' => false, 'maxContentWidth' => …, 'maxWidth' => …];
}
```

`hasTopbar => false` is **required, not cosmetic.** The simple layout renders
both `Filament\Livewire\SimpleUserMenu` and the database-notifications component
inside a single `@if (($hasTopbar ?? true) && filament()->auth()->check())`
block. Left at its default the layout would still co-render two components and
the leak would survive in reduced form.

A locked user must still be able to leave, so the page carries its own logout
control (see below). Logging out is not a privileged action and does not weaken
containment.

### Layer 2 — the guard stops trusting the fabricated route alone

```
not flagged                          → pass
route = filament.admin.auth.logout   → pass          (POST only)
route ≠ password-change              → redirect
route = password-change:
    real request is livewire.update  → pass only if EVERY component in the
                                       payload resolves to PasswordChange::class
    otherwise                        → pass
```

**The logout exemption is mandatory.** `Route::post('/logout')` is registered
inside `Route::middleware($panel->getAuthMiddleware())`, so `ForcePasswordChange`
wraps it; without the exemption the new logout button would redirect back to the
password form instead of logging out. It is scoped to the exact route name
`filament.admin.auth.logout`, which is POST-only and CSRF-protected. A GET is
refused by routing, not by this guard.

**Discriminating a page load from a component update** uses
`HandleRequests::isLivewireRoute()`, which reads `request()->route()` — the
container's *real* request, not the fabricated one the middleware is handed.
Livewire never rebinds the fabricated request into the container.

**Identifying the component** reads each snapshot's `memo.name` from the payload
and resolves it via `Factory::resolveComponentClass()` to a class, compared
against `PasswordChange::class`. Resolved classes rather than name strings, so
re-registering a component cannot silently widen the exemption. The whole
payload is checked, not just the first entry: `applyPersistentMiddleware()`
dedupes by `method|path`, so the guard runs once even when several components
share the page's path.

**Denial is a 302.** `Utils::applyMiddleware()` calls `abort($response)` on a
`RedirectResponse`, so this surfaces correctly through the Livewire endpoint. A
403 would surface as an error modal.

**Fail-closed, stated accurately.** `handleUpdate()` aborts 404 on an empty or
structurally malformed outer payload *before* `update()` fires
`snapshot-verified`, so the guard never sees those cases — an "empty payload
denies" branch would be dead code. What can reach the guard is a valid first
snapshot accompanied by a further component whose snapshot is a well-formed
string but whose name does not resolve. `resolveComponentClass()` throws
`ComponentNotFoundException` there, and that denies.

### Layer 3 — the save must not destroy the session

`AuthenticateSession` is persistent. `Utils::applyMiddleware()` terminates the
pipeline in an empty `Response`, so its `tap()` after-callback fires at
`snapshot-verified` time — **before** `save()` runs — storing the *old* hash.
The password then changes, and the next request mismatches and calls
`logoutCurrentDevice()`. Today a successful password change therefore logs the
user out and the success redirect lands on the login page.

**Decided: the current session stays signed in.** After the update, `save()`
refreshes `password_hash_{guard}` and regenerates the session ID. Other sessions
still fail on their stale hash, which is the containment an admin-forced reset
exists for. This matches Laravel's own self-service password-change behaviour.

---

## 3. Testing

`Livewire::test()` skips persistent middleware and cannot reach any of this.
Tests must be real HTTP posts to the update endpoint — URI from
`HandleRequests::getUpdateUri()`, `X-Livewire: true` header or the handler 404s.

| Case | Expectation |
|---|---|
| Flagged, replay a co-rendered component with `memo.path=admin/password-change` | **302** → `/admin/password-change` |
| Flagged, replay the `PasswordChange` component itself | **200** |
| **Control:** good-standing user replays that same co-rendered component | **200** |
| Flagged, valid `PasswordChange` snapshot + additional unresolvable component | **302** |
| **Structural:** rendered page's root Livewire snapshots | `PasswordChange` is the **only** one |
| Flagged, `POST /admin/logout` | logs out, session invalidated, redirects |
| Flagged, `GET /admin/logout` | refused |
| **Real HTTP save:** flagged user submits the form over the update endpoint | flag cleared, **session still authenticated** |

The good-standing control is mandatory: a 404 satisfies "was refused" exactly as
well as the guard firing, so without it the first case proves nothing.

The structural test is what stops a future widget reopening the hole. It asserts
on the count of root snapshots rather than on named components, so it fails for
*any* addition rather than only the ones known today.

The real-HTTP save test replaces the existing `Livewire::test()` proof, which
cannot observe the session behaviour in layer 3. The other four existing tests in
`tests/Feature/Auth/ForcePasswordChangeTest.php` keep passing unchanged.

Each new test must be seen to fail before the fix lands.

---

## 4. Scope

**In:** `app/Http/Middleware/ForcePasswordChange.php`,
`app/Filament/Pages/PasswordChange.php`,
`resources/views/filament/pages/password-change.blade.php`,
`tests/Feature/Auth/ForcePasswordChangeTest.php`, translation keys for the
logout control, closing G1-U3 in `docs/reviews/2026-07-29-phase-1-review.md`,
and **`docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md`
line 91**, which still claims the guard "is registered non-persistently" and
justifies it with the same argument the review disproved. It has been persistent
since P1-T15; the authoritative spec must not contradict the code.

**Out:** the `AdminPanelProvider` middleware registration is correct and does not
change. No other guard is touched. No phase 2 concepts.

New user-facing strings go through `__()`; the Arabic file stays empty until
phase 4.
