# Password-change containment — closing G1-U3

**Date:** 2026-08-05
**Status:** approved, not yet implemented
**Origin:** `docs/reviews/2026-07-29-phase-1-review.md`, finding G1-U3 — the one
phase 1 review finding left open.

---

## 1. The defect

`ForcePasswordChange` is meant to hold a flagged account on
`/admin/password-change` and let it do nothing else. It does not.

Livewire's `PersistentMiddleware` writes the originating route into the snapshot
on dehydrate, then on `snapshot-verified` reads `memo.path` *back out of the
snapshot*, fabricates a request for that path, matches it to a route, and
applies only that route's middleware. `ForcePasswordChange` exempts by asking
`$request->routeIs(self::PAGE_ROUTE)`. A snapshot carrying
`memo.path=admin/password-change` is therefore evaluated against the exempt
route and passes — **whichever component the snapshot actually belongs to.**

Because the page renders with the full panel layout, five components carry that
path. All five were measured drivable with `must_change_password` set:

| Request | Result |
|---|---|
| `GET /admin/students` (control) | 302 → `/admin/password-change` |
| Replay `Filament\Livewire\Topbar` | 200 |
| Replay `Filament\Livewire\GlobalSearch` | 200 |
| Replay `Filament\Livewire\Sidebar` | 200 |
| Replay `App\Filament\Pages\PasswordChange` | 200 (correct) |
| Replay `Filament\Livewire\Notifications` | 200 |

Severity is **medium**, scoped honestly. No privilege is gained — global search
still runs each resource's `canViewAny()`, so the actor reaches only what their
permissions already allow. What is defeated is *containment*: the flag exists so
an administrator who has just revoked a credential can be sure the holder of
that session does nothing further until they set a new password. Today they can
still search the student register from it.

What genuinely holds, and is worth keeping: the snapshot checksum HMACs the
whole snapshot including `memo`, so nobody can forge a snapshot claiming an
arbitrary originating route. Exposure is limited to components actually
co-rendered on the exempt page — which is exactly what layer 1 below removes.

---

## 2. Design

Two layers. Layer 1 removes the surface; layer 2 enforces the property so the
surface cannot come back unnoticed.

### Layer 1 — the page renders no chrome

`App\Filament\Pages\PasswordChange` extends `Filament\Pages\SimplePage` rather
than `Filament\Pages\Page`, and its view uses the simple page component.

Verified against the installed Filament v5.7.1:
`resources/views/components/layout/simple.blade.php` references none of
sidebar, topbar, global-search or notifications, where the standard
`layout/index.blade.php` references them fifteen times.

With no other component rendered on that page, no snapshot other than the
page's own can ever carry `memo.path=admin/password-change`. That closes the
leak as it exists today.

A locked user must still be able to leave. Stripping the chrome removes the
topbar's logout button, so the page gains an explicit logout action. Logging
out is not a privileged action and does not weaken containment; a page with no
exit does produce support calls and users clearing session cookies by hand.

### Layer 2 — the guard stops trusting the fabricated route alone

`ForcePasswordChange::handle()` becomes:

```
not flagged                        → pass
flagged, route ≠ password-change   → redirect to /admin/password-change
flagged, route = password-change:
    real request is livewire.update → pass only if EVERY component in the
                                      payload resolves to PasswordChange::class
    otherwise                       → pass
```

Two decisions inside that:

**Discriminating a page load from a component update** uses
`HandleRequests::isLivewireRoute()`. It reads `request()->route()` — the
container's *real* request — not the fabricated one the middleware is handed.
That is what makes it a sound discriminator here: Livewire never rebinds the
fabricated request into the container.

**Identifying the component** reads each snapshot's `memo.name` from the request
payload and resolves it through `Factory::resolveComponentClass()` to a class,
which is compared against `PasswordChange::class`. Resolved classes rather than
name strings, so that renaming or re-registering a component cannot silently
widen the exemption.

The check **fails closed**. An unresolvable component name (
`resolveComponentClass()` throws `ComponentNotFoundException`), a malformed
snapshot, or an empty component list all deny. Within `isLivewireRoute()` a
legitimate request always carries at least one component, so denying the empty
case costs nothing.

**Denial is a 302** to `/admin/password-change`, consistent with how the guard
already answers a page request, and something Livewire follows natively. A 403
would surface as an error modal. With layer 1 in place this path should not fire
for legitimate traffic at all.

### Why both layers

Layer 1 alone is a *configuration* fix: correct today, silently wrong the day
someone adds a widget to the page, with no test to catch it. Layer 2 alone
leaves the chrome rendered and polling, so every denied poll becomes a visible
redirect. Together, layer 1 fixes the leak and the UX, and layer 2 enforces the
property regardless of what the page later renders.

This follows the principle recorded from the backup-guard work: enforce the
property, not the technology.

---

## 3. Testing

`Livewire::test()` deliberately skips persistent middleware, so it cannot reach
this behaviour at all. These must be real HTTP posts to the update endpoint —
URI resolved via `HandleRequests::getUpdateUri()`, `X-Livewire: true` header
required or the handler 404s. This is the technique the review already used to
measure the defect, so it is proven against this codebase.

| Case | Expectation |
|---|---|
| Flagged user, replay a co-rendered component with `memo.path=admin/password-change` | **302** → `/admin/password-change` |
| Flagged user, replay the `PasswordChange` component itself | **200** |
| **Control:** good-standing user replays that same co-rendered component | **200** |
| Rendered password-change page | carries no chrome components |

The control is mandatory, not decorative. A 404 satisfies "was refused" just as
well as the guard firing does, so without a passing good-standing replay the
first case proves nothing about the guard.

Layer 1's assertion is what stops a future widget reopening the hole quietly.

The five existing tests in `tests/Feature/Auth/ForcePasswordChangeTest.php` must
keep passing unchanged — in particular "clears the flag once a new password is
set", which proves the flagged user can still get out.

Each new test must be seen to fail before the fix lands. A passing test proves
nothing until it has been observed failing for the right reason.

---

## 4. Scope

**In:** `app/Http/Middleware/ForcePasswordChange.php`,
`app/Filament/Pages/PasswordChange.php`,
`resources/views/filament/pages/password-change.blade.php`,
`tests/Feature/Auth/ForcePasswordChangeTest.php`, translation keys for the
logout action, and closing G1-U3 in the review log.

**Out:** the persistent-middleware registration in `AdminPanelProvider` is
correct and does not change. No other guard is touched. No phase 2 concepts.

Any new user-facing string goes through `__()`; the Arabic file stays empty
until phase 4.
