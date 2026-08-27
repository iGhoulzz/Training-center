# Engineering Standards

Single source of truth for coding conventions. Both `CLAUDE.md` and `AGENTS.md` reference this file — do not duplicate its content into either.

**Target, as actually installed and verified at the close of phase 1:**

| | |
|---|---|
| PHP | **8.4.1 minimum** — a hard floor, not a preference. Symfony 8 requires `>=8.4.1` and `spatie/laravel-activitylog` 5 requires `^8.4`, so 8.3 cannot resolve this dependency set. `composer.json` pins `config.platform.php` to `8.4.1`. |
| Laravel | 13.20.0 |
| Filament | v5.7.1 — v5 kept v4's resource API; the schema import is `Filament\Schemas\Schema`, not `Filament\Forms\Form` |
| MySQL | 8 |
| Pest | 4.7.5 · PHPStan 2.2.5 via Larastan 3.10 · Pint 1.29.3 |
| Key packages | `spatie/laravel-permission` 7.4.2, `bezhansalleh/filament-shield` 4.2.0, `spatie/laravel-activitylog` 5.0.0, `spatie/laravel-backup` 10.3.0 |

This table said "Laravel 11+, PHP 8.3+" for all of phase 1, and `composer.json`
agreed with it (`"php": "^8.3"`) while the locked dependency set cannot run on
8.3. Both now state 8.4.1.

What that mismatch actually did, since it is worth being exact rather than
dramatic: `composer.json` also pins `config.platform.php` to `8.4.1`, so
`composer install` resolves against the pretend platform and *succeeds* on an 8.3
host. The refusal comes later and is explicit — Composer generates
`vendor/composer/platform_check.php` from the platform pin, which fails with
`Your Composer dependencies require a PHP version ">= 8.4.1"`. So the manifest
was wrong rather than dangerous: it advertised support the code did not have, and
anyone reading it to decide what to provision would have got it wrong.

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
- **Do not index a column only searched with a leading wildcard.** Filament's `searchable()` builds `LIKE %term%`, and a B-tree index is ordered by prefix — a search with no known prefix has nothing to seek on, so MySQL scans whatever you do. Such an index costs writes and disk while never being used. `courses.name_ar` is searched but deliberately unindexed for this reason, while `name_en` is indexed because it is also *sorted* on. If wildcard search ever becomes measurably slow, the answer is a `FULLTEXT` index with `MATCH … AGAINST`, or a search engine — not a B-tree.
- Money is `decimal(12, 3)`. **Never float, never `decimal(12,2)`.** The currency is LYD, which subdivides into 1000 dirham per ISO 4217. Two decimal places would silently round dirham-precision amounts and break reconciliation. Display precision is a UI concern, not a storage one.
- **A money field that is dehydrated never uses Filament's `->numeric()`** (phase 2, task 2P,
  enforced by `MoneyCastArchTest` across `app/Domain/Finance`). That method installs a `floatval`
  state cast, so a persisted value would cross a float on its way in and out of the form — the one
  thing `decimal(12,3)` exists to prevent, and invisible because the *display* still looks right.
  Use `->inputMode('decimal')` plus explicit validation rules. An honest test asserts `getState()`,
  not the rendered output, because only the state shows the cast.
  **The pricing fields on `CourseResource` and `BatchResource` are a deliberate exception** and
  keep `->numeric()`: they are `dehydrated(false)`, so their state never reaches persistence, and
  the price is written by `UpdateCoursePriceAction` / `UpdateBatchPriceAction` from raw state. The
  arch test records that exception rather than pretending it does not exist. Narrow the rule to
  dehydrated fields, or the standard reads as an instruction to redesign working, reviewed code.
- **PHP-side money arithmetic goes through `App\Domain\Finance\Support\Money`**, which works in
  integer dirham. Rounding is half-up and defined once: 216.350 at 1.00% is 214.187, where float
  arithmetic yields 214.186.
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

### Permission names

Filament Shield's generated format, `{action}_{model}`: `view_any_student`,
`create_course`, `delete_user`. Custom abilities are bare verbs: `assign_role`,
`reset_user_password`, `assign_instructor`, `manage_settings`. A scoped variant
carries the scope in the name: `update_assigned_batch_enrollment`.

This is not a style preference, and getting it wrong is expensive. The spec's
first draft used dot notation (`students.delete`), which no permission in this
system matches — adopting it would have meant overriding Shield's generator and
re-breaking that override on every upgrade. The wrong form survived in `CLAUDE.md`
and `AGENTS.md` long enough that **every phase 1 reviewer had to be handed an
erratum** telling them to ignore it, because anyone matching the documented
example literally would have flagged every correct call in the codebase.

`RolePermissionSeeder` is the authority on which names exist. Grep it before
inventing one.

### The write boundary: security-sensitive writes go through Actions

**This is the load-bearing rule.** An earlier design tried to enforce the guards by overriding every Spatie/Eloquent write method on `User` and `Role`. Two review rounds kept finding new bypass methods; the models grew fat and it violated this file's own "no business logic in models" standard. That approach is gone. Do not reintroduce it.

> **Any spec or plan section touching money or permissions must name its write
> boundary explicitly** — which Actions perform the writes, which service owns
> the invariant, what is prohibited, and the test that proves it. Never just
> "enforce X".
>
> This rule is the generalisation of the paragraph above. The model-override
> approach was specified in the *plan*, so five task rounds inherited it before
> anybody questioned the architecture rather than the latest bypass. Phase 2 is
> financials — hard invariants over an unbounded write surface, the same shape.

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

**Know what these tests are worth.** They scan for known-bad *code shapes*, which is inherently a game of catch-up: `->relationship(name: 'roles')` with a named argument, a declarative `DeleteAction::make()`, an instance-level `$role->delete()`, or a call from a route closure can all behave identically at runtime while reading differently to a regex. Treat the architecture tests as a fast early warning that points at the offending file — **not as proof that a bypass is impossible.**

The proof is behavioural. Where a protection matters, drive the real component and assert the outcome: `RoleResourceLivewireTest` invokes the actual bulk-delete action through Livewire and asserts the roles survive, which holds no matter how the bypass is spelled. Prefer adding a behavioural test over inventing a sixth regex.

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

Guards 1, 2, and 4 are actor-relative, so they do not apply where there is no actor — that is what `SystemRoleWriter` is for, and its use is confined to seeding and setup. **Guard 3 has no exemption**: `SystemRoleWriter::syncRoles()` engages `SuperAdminInvariantService` whenever the write would actually remove the role, because losing the last super admin is unrecoverable and a seeder is no more entitled to cause that than a request is. The invariant engages only on removal, so bootstrapping a fresh install is never blocked.

### Creating an account requires permission to issue its credential

Creating a staff account requires **both** `create_user` and `reset_user_password`. That is deliberate, not an accidental coupling: the form has no password field (an administrator typing someone else's password is a credential they then know), so creation issues a temporary password through `ResetUserPasswordAction`. Being able to create an account with a password you can see is the same capability as resetting one, so it is gated by the same permission. Both `admin` and `super_admin` hold it.

### Filament modal actions bypass the save hooks — and `->url()` does not disable them

`CreateAction` and `EditAction` in their **modal** form persist with a bare `$model::create($data)` / `$record->update($data)`, which never reaches a page's `afterCreate()` / `afterSave()`. For any resource whose writes must route through an Action, that silently does nothing — roles and `is_active` would appear to save and not.

**Setting `->url()` is not a fix.** It replaces the browser click behaviour, but the server-side create/edit handler stays registered and mountable, so a crafted Livewire mount can still reach it. Use a **plain `Action::make('create')->url(...)`** instead — it has no handler to reach, because none was ever registered — and gate it explicitly with the resource's `canCreate()`, since a plain Action carries no resource-aware authorization of its own. `UserResource` does this, and a test asserts the registered action is not a `CreateAction`.

### Bulk actions cannot be authorized per record

Filament authorizes a bulk action **once**, against the `*Any` policy method, and never consults the per-record method for the selected rows. `deleteAny()` receives no records, so it cannot express "unless one of them is protected."

That made bulk delete a clean bypass: the `super_admin` role could not be deleted individually, but could be deleted as part of a selection. `RolePolicy::deleteAny()`, `forceDeleteAny()`, and `restoreAny()` therefore return **`false` unconditionally**. Roles are a handful of deliberately-managed rows; delete them one at a time, where the protection applies.

Apply the same reasoning to any future resource whose per-record policy has a protected case — a `*Any` method that merely checks a permission silently discards that protection.

`replicate()` is guarded too: copying `super_admin` would produce a role holding every permission under a different primary key, which no rank check would recognise as super admin while conferring the same power.

### One config value that must not change

**`super_admin.define_via_gate` in `config/filament-shield.php` must stay `false`.** Setting it `true` makes `Gate::before` return true for every ability, silently defeating guard 2. A test pins it.

---

## Time, documents and state (phase 2)

Three rules this phase paid for, each in a review round.

**A local reporting period becomes a half-open UTC range, once, in one place.** The centre reads
its day on Africa/Tripoli; the database stores instants in UTC. Every report converts the local
period to `[start, end)` in UTC through `ReportPeriod` and nothing re-derives it — a second
conversion is a second definition, and the two disagree across a DST boundary. Test the
conversion at an hour where a mistake would show, not at midday when every candidate agrees.
Libya's 2013 DST change is the anchor case: a fixed offset passes a naive test and a timezone
database is required to pass an honest one.

**An issued document is a snapshot, not a live query.** A receipt or an exported report captures
what it said at the moment it was issued — figures, names, codes, and the locale — and reprinting
it later reproduces that, not today's data. A later correction to a charge, a student's name or a
reversal timestamp must not change a document already given to somebody. This is the opposite of
the balance rule above, and both are deliberate: **balances are always derived, documents are
always frozen.**

*When* the capture happens differs by document, and the difference is not incidental:

- **A receipt documents an event, so its snapshot is written inside that event's transaction.**
  `RecordPaymentAction` creates the receipt snapshot in the same transaction as the payment; there
  is no window in which a payment exists without the document that describes it.
- **An export documents a question asked at a moment, so it freezes at request time**, outside any
  transaction. `ReportPage::captureSnapshot()` reads the report and the requester's locale
  synchronously when the export is requested, and the queued job renders only what was frozen —
  which is what stops chunked work from mixing two states of the ledger.

**A guarded field is read from raw state, never from `getState()` — but read the ability first.**
Filament does not dehydrate a hidden component, so `getState()` silently drops a field whose step
never rendered, turning an Action's refusal of a crafted value into a silent acceptance of the
default. Raw state is therefore what a guarded field is read from.

**Which order the check runs in depends on what the field's contract is, and getting this backwards
has already cost a defect.**

- **Refusal contract — read raw state, let the Action refuse.** `EnrollAndCollect` reads
  `discount_id` from raw state precisely so an actor without `apply_discount` who crafts one meets
  `EnrollAndBillAction`'s refusal, rather than having the value pruned and being silently billed at
  full price. The Action is the boundary and it must see the attempt.
- **Ignore contract — check the ability *before* reading raw state.** The pricing boundary
  (finance design §3, rules 1–2) is the opposite: without `manage_pricing` the pricing path is
  skipped entirely and any injected state is discarded unread. It is never "authorize and refuse";
  it is "not this actor's field, ignore it". An earlier revision called the Action unconditionally,
  and because an admin holds no `manage_pricing`, **renaming a course authorized, refused, and
  rolled the rename back** with an error naming a field the admin never touched. Reading the
  ability first also makes a crafted injection inert instead of a way to deny an admin their own
  edits.

The question to ask of a new guarded field is which of those two it is: does the actor's attempt
need to be refused on the record, or does it simply not belong to them?

---

## Error handling

- Typed custom exceptions carrying context, not generic `\Exception`.
- Multi-table operations run in `DB::transaction()`.
- Domain exceptions render as readable UI messages. Stack traces never reach users.
- Dispatch queued jobs with `afterCommit()` when dispatching inside a transaction.

---

## Testing

Feature tests over unit tests — the risk in this system is in how pieces connect.

- `RefreshDatabase` on every feature test by default. A test that must observe
  real commits from another connection may use `DatabaseTruncation`, which
  clears rows **before** each case but not after the final case in its file. It
  must also register an `afterAll` hook that resets
  `RefreshDatabaseState::$migrated` so the next database test rebuilds before
  observing that residue. A test that deliberately creates partial migration
  states uses `DatabaseMigrations`: the full rebuild per case is expensive, but
  it is the recovery boundary when the schema repair being tested can itself
  fail.
- Factories with states for all test data.
- `Mail::fake()`, `Queue::fake()`, `Event::fake()`. Never hit real external services.
- **Every permission test asserts both the positive and the negative** — what a role can do *and* what it cannot. Negative assertions are where authorization bugs hide.
- Test unhappy paths as thoroughly as happy paths.

A task is not complete until its tests pass. Report actual test output; never claim passing tests without running them.

### Rules learned the expensive way

Each of these cost a review round or more in phase 1. They are here because the
mistakes were invisible from inside the change that made them.

**Test the surface, not the instance you just fixed.** When fixing a rule,
enumerate the *complete* surface — every policy method, every write path — as a
dataset, so an omission fails rather than hides. Escalation guard 4 was fixed on
`create` and `update`, tested on exactly those two, and reported green while
`delete`, `forceDelete`, `restore`, `replicate` and `reorder` stayed open.

**Every security claim in documentation needs a test named after it.** Three
claims in this file asserted properties the code did not have. If a sentence
asserts a property, a test asserts the same property — or the sentence does not
go in.

**Verification probes run inside a rolled-back transaction**, or after
`php artisan migrate:fresh --seed`. Committing probe setup into the shared
development database produced both false holes and false all-clears: a leftover
super admin meant the "last super admin" under test was not the last one.

**A passing test proves nothing until you have watched it fail.** Break the thing
the test protects, confirm the failure, then restore. Phase 1 shipped several
tests that could not fail — a delete probe that never reached the server, an
assertion an index made unfailable, a scanner that matched its own error message.
Every one of them read as coverage.

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
| Agent tooling | Laravel Boost (dev-only) — MCP server and ecosystem docs |

### The gates

One command, and there is only one definition of it:

```bash
composer verify        # validate --strict, then formatting + analysis, then the full suite
composer verify:fast   # the same without the suite, for use while working
```

`Tooling\Gate::fastChecks()` in `scripts/Tooling/Gate.php` is that definition. Both agents' Stop hooks, `.githooks/pre-commit`, `.githooks/pre-push` and CI all reach it; **none of them restates it.** A duplicated command list is how two agents end up held to different standards and how CI ends up green on a rule the developer machine quietly dropped — this file previously told Codex to run `vendor/bin/phpstan analyse` with no `--memory-limit`, which exhausts PHP's default on this codebase and reports a crash rather than an analysis.

Adding a check means adding it to `fastChecks()`. Nowhere else.

### Git hooks

Committed under `.githooks/`, and **inert until you opt in**:

```bash
git config core.hooksPath .githooks
```

`pre-commit` runs the fast gate and never rewrites your files — Pint runs with `--test`, because a hook that reformats mid-commit changes what you already reviewed. `pre-push` runs `composer verify` plus the frontend build, and **refuses a dirty worktree**: the gate checks files on disk while a push publishes commits, and those differ exactly when uncommitted changes are present.

No hook migrates, seeds, cleans backups, updates dependencies, commits, pushes, or touches history.

### The suite serialises

Every worktree shares one MySQL database, so `tests/bootstrap.php` takes a machine-wide lock before any test runs. A run reporting that it is waiting is correct, not hung. `--parallel` is refused: every worker would queue behind the same lock, making a "parallel" run slower than a serial one.

The lock is taken by the **test process**, not by a wrapper. A wrapper cannot hold it safely on Windows, where lock ownership belongs to the acquiring process — killing the wrapper frees the lock while its suite is still connected. Measured, not assumed.

### Formatting scope

Pint covers PHP and Filament. **No Prettier, ESLint, Stylelint or Blade formatter**, deliberately: the frontend is two Blade templates, one CSS entry and one JS entry, and a formatter per file type would be more configuration than content. Revisit when phase 4 brings Arabic and RTL and the frontend surface becomes real — a Blade-aware Prettier, and possibly ESLint, are the candidates.

---

## Commits

- Conventional commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`.
- Reference the task ID: `feat(enrollment): add batch instructor hour allocation [P1-T06]`.
- Never commit with failing tests, Pint violations, or Larastan errors.
