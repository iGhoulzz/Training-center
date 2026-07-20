# Engineering Standards

Single source of truth for coding conventions. Both `CLAUDE.md` and `AGENTS.md` reference this file — do not duplicate its content into either.

**Target:** Laravel 11+ (latest stable), PHP 8.3+, Filament, MySQL.

---

## Applying Laravel best practices to a Filament app

Standard Laravel guidance is written for API-first applications. This project is a server-rendered Filament admin panel plus Blade public pages. The following table records which conventions apply and which do not, so nobody re-litigates it per pull request.

| Convention | Applies here? | Notes |
|---|---|---|
| Action classes | **Yes** | Every non-trivial write operation is an Action. Filament resources call Actions. |
| DTOs | **Yes** | Structured data into Actions and Services. Plain readonly PHP classes. |
| Services | **Yes** | Cross-domain orchestration and published interfaces between domains. |
| Policies | **Yes** | Every model has a Policy. Filament Shield generates the baseline. |
| Backed enums + model casts | **Yes** | All status columns. |
| Form Requests | **Blade surfaces only** | Filament validates in its own schema definitions. Do not force Form Requests into Filament resources. |
| API Resources | **Not yet** | No API exists in phases 1–4. Use them if one is added. |
| Repository layer | **No** | Filament's table and form builders consume Eloquent query builders directly. A repository between Filament and Eloquent fights the framework for no benefit. Query scopes on models instead. |
| Sanctum / Passport | **No** | Session auth via Filament panels. |

---

## Architecture

- **Thin controllers, fat actions.** Controllers handle HTTP only. Zero business logic.
- **No business logic in models.** Relationships, scopes, casts, and accessors only.
- **Domain organization.** Code lives under `app/Domain/{Enrollment,Finance,Staff,Content}/`, not in a flat `app/Models` + `app/Services` split.
- **Cross-domain access goes through a published service interface.** Finance reads enrollment data via `EnrollmentQueryService`, never by querying `enrollments` directly.
- **One class, one purpose.** When a file grows past roughly 300 lines, that is a signal it is doing too much.

```
app/Domain/Enrollment/
├── Actions/          # EnrollStudentAction, IssueCertificateAction
├── Data/             # DTOs
├── Enums/            # EnrollmentStatus, BatchStatus
├── Exceptions/       # Typed domain exceptions
├── Models/           # Student, Course, Batch, Enrollment
├── Policies/
├── Services/         # Published cross-domain interfaces
└── Filament/         # Resources, pages, widgets for this domain
```

---

## Database

- Every foreign key gets a real constraint with explicit `onDelete`: `restrictOnDelete()` for anything financial, `cascadeOnDelete()` only for genuine child records.
- Index every foreign key, and every column used in `WHERE` or `ORDER BY`. Composite indexes for common query pairs.
- Money is `decimal(12, 3)`. **Never float, never `decimal(12,2)`.** The currency is LYD, which subdivides into 1000 dirham per ISO 4217. Two decimal places would silently round dirham-precision amounts and break reconciliation. Display precision is a UI concern, not a storage one.
- Status columns are `string(30)` with a `default`, an index, and a backed enum cast.
- Explicit nullability on every column.
- One migration per logical change. Never edit a migration that has run in production.
- **No derived values stored.** Balances and totals are computed from source rows, never cached into a column.

---

## Authorization

- Permission-based, never role-based, in code: `$user->can('students.delete')`. **Never** `$user->hasRole('admin')`.
- No `role` column on `users` — Spatie's pivot tables own role assignment.
- Every model has a Policy. Every Filament resource is gated by it.
- The escalation guards are enforced in Policies **and at the model layer**, and covered by tests:
  1. An admin cannot create, edit, or delete a super admin. Enforced in the role writers **and** in the `deleting`/`forceDeleting`/`updating` hooks, so a direct `$user->update()` or `$user->delete()` against a super admin by a lesser actor is refused, not only a policy-gated one.
  2. No user can modify their own roles or permissions.
  3. The last active super admin cannot be deleted, deactivated, or stripped of the `super_admin` role — from any surface.
  4. An authenticated role or permission write requires the `assign_role` ability. Without it a staff account could assign `admin` to another user, or grant itself a permission, straight through the model layer.

### Super-admin identity is resolved by role id, not by name

Rank is resolved through `User::isSuperAdmin()` / `Role::isSuperAdmin()`, which compare the **super_admin role's immutable primary key** against the account's live role pivot, read fresh every call. Two reasons, both remediation fixes:

- **Key, not name.** Spatie detaches role pivots by key. A guard that read a role object's `name` could be desynced from the id actually written by mutating an unsaved name in memory. Comparing by key closes that.
- **Fresh, not the loaded relation.** Reading a stale, in-memory `roles` relation let the last super admin be deleted when that relation happened to be empty. Querying the pivot ignores any stale copy.

The name→id anchor is only safe because the row itself is frozen — see below.

### The super_admin role row is protected like the activity log

`App\Models\Role` (registered via `config/permission.php`) makes the `super_admin` role **immutable, undeletable, and permission-locked**, for everyone including a super admin:

- It cannot be renamed or have its guard changed (renaming defeated every name-keyed check).
- It cannot be deleted (deletion cascaded away every assignment).
- Its permission set cannot be reduced — `syncPermissions()` is pinned to the full set (this is what neutralises Shield's role editor silently stripping hidden custom permissions on save) and `revokePermissionTo()` is refused.
- No other role may take the `super_admin` name.
- Spatie's role-side pivot helpers (`removeFromModels()` / `syncModels()`) re-assert guard 3 inside a locked transaction.

This lives on the model, not only in `RolePolicy`, because Shield's role pages write straight through Eloquent — a policy alone would not hold.

### The one permitted role check

`UserPolicy` and the model-layer guards express *rank* via `isSuperAdmin()`. This is the sole exception to the permission-only rule, and it is deliberate: "is this person a super admin" cannot be reduced to a permission check without inventing a synthetic permission that duplicates the role. Do not "fix" it.

### Guard 3 is atomic

The last-super-admin check and the write it protects run inside a transaction, and the check takes a `lockForUpdate()` on the super-admin role row before counting survivors. Two concurrent removals therefore serialise on that lock instead of both passing an unlocked count.

### Writes that bypass the guards

- **Filament `Select::make('roles')->relationship('roles')` is NOT safe.** It persists by calling the relation's `sync()`/`detach()` directly, never `User::syncRoles()`, so it bypasses every guard above. This *is* reachable from the UI. Task 5's `UserResource` must detach the role field from the relationship writer and route through `App\Domain\Staff\Actions\SyncUserRolesAction` (which calls `User::syncRoles()`); the action's docblock carries the exact field configuration.
- Eloquent events do not fire for the following, so **none may be used on `User`**: `User::query()->update([...])`/`->delete()` (query-builder bulk writes), `updateQuietly()`/`deleteQuietly()`, `$user->roles()->detach()`. Closing these at the storage layer would need database triggers, which is out of scope. Treat this list as a review checklist item.

### Two subtleties worth knowing before touching this code

- **`forceDelete()` needs its own hook.** Spatie's `bootHasRoles()` registers a `deleting` listener that detaches all roles when force-deleting. Trait boot methods run before `booted()`, so by the time a `deleting` guard runs, the role pivot rows are already gone and `isSuperAdmin()` answers false. The guard hooks `forceDeleting`, which fires before any detaching.
- **`super_admin.define_via_gate` in `config/filament-shield.php` must stay `false`.** Setting it `true` makes `Gate::before` return true for every ability, which silently defeats guard 2 — `modifyOwnRoles` would never run. A test pins this value.

### Guards and the CLI path

The model-layer enforcement of guards 1, 2, and 4 **skips when no user is authenticated**, because seeders, factories, queued jobs, and console commands legitimately assign roles with no principal to authorize against. Those guards therefore protect the request path, not the CLI path. **Guard 3 has no such exemption** and holds everywhere, including in tinker and seeders — losing the last super admin is unrecoverable, so it is absolute.

---

## Error handling

- Typed custom exceptions carrying context, not generic `\Exception`.
- Multi-table operations run in `DB::transaction()`.
- Domain exceptions render as readable UI messages. Stack traces never reach users.
- Dispatch queued jobs with `afterCommit()` when dispatching inside a transaction.

---

## Testing

Feature tests over unit tests — the risk in this system is in how pieces connect.

- `RefreshDatabase` on every feature test.
- Factories with states for all test data.
- `Mail::fake()`, `Queue::fake()`, `Event::fake()`. Never hit real external services.
- **Every permission test asserts both the positive and the negative** — what a role can do *and* what it cannot. Negative assertions are where authorization bugs hide.
- Test unhappy paths as thoroughly as happy paths.

A task is not complete until its tests pass. Report actual test output; never claim passing tests without running them.

---

## Security

- `$fillable` on every model. Never `$guarded = []`.
- `config('key')` in application code. Never `env()` outside config files.
- Rate-limit all public and auth endpoints.
- Sanitize any user-supplied content rendered as HTML.
- `.env` stays out of version control.

---

## Internationalization

Enforced from the first commit, even though Arabic strings arrive in phase 4:

- **No hardcoded user-facing strings.** Everything goes through `lang/` files.
- **Logical CSS properties only** — `margin-inline-start`, `padding-inline-end`, `text-align: start`. Never `margin-left`, `padding-right`, `text-align: left`.
- Test any new layout at `dir="rtl"` before considering it done.

---

## Tooling

| Purpose | Tool |
|---|---|
| Formatting | Laravel Pint — must pass before commit |
| Static analysis | Larastan — must pass before commit |
| Testing | Pest |
| RBAC | `spatie/laravel-permission` + Filament Shield |
| Activity log | `spatie/laravel-activitylog` |
| Backups | `spatie/laravel-backup` |

---

## Commits

- Conventional commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`.
- Reference the task ID: `feat(enrollment): add batch instructor hour allocation [P1-T06]`.
- Never commit with failing tests, Pint violations, or Larastan errors.
