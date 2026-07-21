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

- Permission-based, never role-based, in code: `$user->can('delete_student')`. **Never** `$user->hasRole('admin')`.
- No `role` column on `users` — Spatie's pivot tables own role assignment.
- Every model has a Policy. Every Filament resource is gated by it.
- The four escalation guards:
  1. A non-super-admin cannot create, edit, or delete a super admin, nor add or remove the `super_admin` role.
  2. No user can modify their own roles or permissions.
  3. The last active super admin cannot be deleted, deactivated, or stripped of the `super_admin` role.
  4. An authenticated role or permission write requires the `assign_role` ability.

### The write boundary: security-sensitive writes go through Actions

**This is the load-bearing rule.** An earlier design tried to enforce the guards by overriding every Spatie/Eloquent write method on `User` and `Role`. Two review rounds kept finding new bypass methods; the models grew fat and it violated this file's own "no business logic in models" standard. That approach is gone. Do not reintroduce it.

| Layer | Responsibility |
|---|---|
| Traits | Capabilities only — `HasRoles`, `SoftDeletes`, `HasFactory`, `Notifiable` |
| Models | Casts, relationships, scopes, and a fresh ID-based `isSuperAdmin()`. No actor-aware logic. |
| Policies | Answer authorization questions |
| Actions | Execute every security-sensitive write, receiving the actor explicitly |
| `SuperAdminInvariantService` | Owns the last-super-admin invariant (transaction + row lock) |
| Architecture tests | Enforce that nothing reaches around the Actions |

Every Action takes the actor as its first argument and authorizes itself with `Gate::forUser($actor)->authorize(...)`:

```php
app(SyncUserRolesAction::class)->execute($actor, $target, ['admin']);
app(UpdateRolePermissionsAction::class)->execute($actor, $role, $permissions);
app(DeactivateUserAction::class)->execute($actor, $target);
app(DeleteUserAction::class)->execute($actor, $target);
```

Actions require a real actor. **Seeders, factories, and console setup use the separately named trusted path**, `App\Domain\Staff\Actions\SystemRoleWriter` — never the request-path Actions. The name is deliberately unmistakable so a system write can never be confused for an authorized one.

Filament must call these Actions explicitly from its create/update/delete hooks. **`Select::make('roles')->relationship('roles')` is forbidden** — it persists via the relation's `sync()`/`detach()`, reaching around every guard, and it *is* reachable from the UI.

### Architecture tests are the enforcement

`tests/Feature/Staff/ActionBoundaryArchTest.php` scans `app/` and fails the build if production code:

- imports `Spatie\Permission\Models\Role` (only `App\Models\Role` may)
- calls `assignRole`/`removeRole`/`syncRoles` outside `SyncUserRolesAction` / `SystemRoleWriter`
- calls `assignToModels`/`removeFromModels`/`syncModels` anywhere
- writes role/permission/user relations via `attach`/`detach`/`sync`
- changes permissions outside `UpdateRolePermissionsAction` / `SystemRoleWriter`
- reintroduces write-guard method overrides on `User` or `Role`

Allowlists are narrow and explicit. Each rule has been mutation-tested — injecting a violation makes the corresponding rule fail and name the offending file. If you add a legitimate new write path, extend the allowlist deliberately; do not weaken a rule.

### The trust boundary, stated honestly

**Model events do not protect against arbitrary database access, and we no longer claim they do.** Raw SQL, manual `tinker`, query-builder bulk writes (`User::query()->update(...)`), and quiet saves bypass the application layer entirely. These are **trusted administrative operations**. True database-wide enforcement would require MySQL triggers and is deliberately out of scope.

What the boundary does guarantee: every write reachable from the application — HTTP, Filament, Livewire, jobs, commands that use application code — goes through an Action that authorizes the actor, and the architecture tests prevent new code from reaching around it.

### Super-admin identity is resolved by role id, not by name

Rank resolves through `User::isSuperAdmin()` / `Role::isSuperAdmin()`, comparing the **super_admin role's immutable primary key** against the account's live role pivot, read fresh every call:

- **Key, not name.** Spatie writes pivots by key. A check reading a role object's in-memory `name` could be desynced from the id actually written by mutating an unsaved name.
- **Fresh, not the loaded relation.** A stale in-memory `roles` relation once let the last super admin be deleted. Querying the pivot ignores any stale copy.

### The one permitted role check

`UserPolicy`, `RolePolicy`, and the Actions express *rank* via `isSuperAdmin()`. This is the sole exception to the permission-only rule and it is deliberate: "is this person a super admin" cannot be reduced to a permission without inventing a synthetic permission that duplicates the role. Do not "fix" it.

### Guard 3 is atomic

`SuperAdminInvariantService::protect()` opens the transaction, takes a `lockForUpdate()` on the super_admin role row, verifies a survivor remains, and runs the mutation **inside that same transaction**. Concurrent removals serialize on the lock rather than both passing an unlocked count. A test proves the lock and the mutation share one transaction.

### Guards and the CLI path

Guards 1, 2, and 4 are actor-relative, so they do not apply where there is no actor — that is what `SystemRoleWriter` is for, and its use is confined to seeding and setup. **Guard 3 has no exemption**: `SuperAdminInvariantService` applies to system writes too, because losing the last super admin is unrecoverable.

### One config value that must not change

**`super_admin.define_via_gate` in `config/filament-shield.php` must stay `false`.** Setting it `true` makes `Gate::before` return true for every ability, silently defeating guard 2. A test pins it.

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
