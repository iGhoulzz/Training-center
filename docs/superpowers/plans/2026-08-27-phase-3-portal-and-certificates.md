# Phase 3 — Student Portal and Certificates Implementation Plan

**Status: awaiting step-0 review. No task may begin until step 0 closes.**

**Goal:** A student signs in to `/portal` and sees their own record, enrolments and
outstanding balance and nothing belonging to anyone else; staff mark a batch's
students complete; an administrator issues an immutable certificate record for a
certificate printed elsewhere; and anyone holding a reference can verify it on the
open internet without an account.

**Architecture:** A second Filament panel on its own auth guard, with Spatie
pinned to `web` so authentication and authorization resolve on different guards
deliberately. Certificates are an append-only register whose central invariant —
one valid certificate per enrolment — is enforced twice, by a row lock for the
application path and by a stored generated column with a unique index for every
other path. The public verifier is a three-route surface built from a fixed
six-field projection.

**Tech stack:** Laravel 13.20, Filament 5.7.1, `spatie/laravel-permission` 7.4.2,
Filament Shield 4.2.0, MySQL 8.4, Pest.

**Reference documents:**
- Design: `docs/superpowers/specs/2026-08-27-phase-3-portal-and-certificates-design.md` — **authoritative for this phase**
- System design: `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md`
- Standards: `docs/ENGINEERING.md`
- Workflow: `docs/WORKFLOW.md`

---

## Step 0 — the plan is reviewed before any of it is implemented

Codex reviews this document before task 1 is cut. The rule exists because a
phase-1 task needed five rounds and every one of them inherited a defect that was
visible in the written plan, and because a phase-2 plan-level architecture error
cost five remediation rounds.

**The review is of the plan, not of the design.** The design is approved and
closed; §15 of it records that no business decision remains open. A finding that
re-opens a design decision is answered by pointing at the design section that
settled it, unless it identifies a fact about the codebase the design got wrong —
in which case the design is corrected first and this plan follows.

Record each round's outcome under this heading as phase 2 did, so the reasoning
survives the merge.

---

## Waves, ownership and dependencies

Per `docs/WORKFLOW.md`: **at most one Claude task and one Codex task run at a
time**, they must share no files and no seams, and a downstream worktree is cut
from updated `main` only after its dependency has merged with green CI.

| Wave | Claude | Codex | Unblocked by finishing |
|---|---|---|---|
| 1 | **T1** Portal foundation | *(reviews T1)* | everything portal- or permission-shaped |
| 2 | **T4** Certificate foundation | **T2** Portal credentials | T4 unblocks T3, T5, T6, T7, T8 |
| 3 | **T3** Completion marking | **T9** Finance query surface | T3 unblocks T5; T9 unblocks T7 |
| 4 | **T5** Certificate Actions | **T10** Export retention | |
| 5 | **T7** Portal pages | **T6** Enrolment deletion and certificates | |
| 6 | **T8** Public verifier | **T11** Worker-readiness timing | |
| 7 | **T13** Finance status arch test | **T12** `failOnNotice` | |
| 8 | **T14** Phase reconciliation | *(reviews T14)* | milestone closes |

**T4 runs before T3, and this is a step-0 correction.** The first draft had T3 in
wave 2 and T4 in wave 3, which meant T3's completion-reversal check — "refuse
while a valid certificate exists" — had no table to consult. The draft answered
that with a temporary interface T3 would define and T5 would rewire, and that is
a fabricated seam: an interface invented to paper over an ordering mistake, whose
only consumer replaces it two waves later. **Reordering removes it.** T3 now
consumes T4's real model and table, and there is one certificate check with one
test rather than a fake and a real one that must be kept in agreement.

**Why T1 is a wave on its own.** It creates the guard, the panel, the
`AuthenticatedStudent` resolver, and **the entire phase-3 permission set**. Four
later tasks would otherwise each append to `RolePermissionSeeder` and its seeding
test — the collision shape that cost phase 2 a wave. Phase 2 closed the same seam
the same way, in its own T1.

**Ownership follows readiness, not preference.** T2 goes to Codex because T3 is
the enrolment-invariant work and invariant-heavy work stays with the lead; T9 and
T10 go to Codex because they are self-contained phase-2 follow-ups that unblock
Claude's next task without touching it.

### Dependency notes

- **T2 depends on T1** for `access_student_portal` and the `student` role's
  ability set — it assigns that role and the account must be able to sign in.
- **T3 depends on T1 and T4** — on T1 for `complete_enrollment` and
  `complete_assigned_batch_enrollment`, and on T4 because reversing a completion
  must refuse while a valid certificate exists, which needs the real table.
- **T5 depends on T3 and T4.** Issuance requires an enrolment in `completed`, a
  status nothing could produce before T3.
- **T7 depends on T1, T4 and T9.** T4 because the enrolments page carries
  certificate fields; T9 because the balance page must not loop.
- **T8 depends on T4** only. It builds its fixtures from the certificate factory
  and does not need the Actions.
- **T6 depends on T4** — it refuses a deletion based on rows that must exist.
- **T10, T11, T12, T13 depend on nothing** and are scheduled to fill the waves
  their partner tasks occupy.

---

## Same-wave file isolation, checked pair by pair

Checked against the file scopes as written below, not assumed.

| Wave | Pair | Overlap in declared scopes |
|---|---|---|
| 2 | T4 certificates · T2 credentials | **none** — new certificate migrations, model, enum, policy, factory and reference generator against `Domain/Staff/Actions`, `StudentResource.php`, `lang/en/credentials.php` and `ActionBoundaryArchTest`. |
| 3 | T3 completion · T9 queries | **none** — `CompleteEnrollmentAction`, `ReverseEnrollmentCompletionAction`, `CompletionRule`, `BatchResource/RelationManagers/EnrollmentsRelationManager.php` and `lang/en/enrollment.php` against `EnrollmentQueryService.php` and the new `StudentBalanceQuery.php`. The enrolment list lives under **`BatchResource`**, the credential button under **`StudentResource`**; different files, and in any case now different waves. |
| 4 | T5 certificate Actions · T10 export retention | **none** — certificate Actions and resource against `pending_file_deletions`, `PurgeDeletedFileJob`, `SweepPendingFileDeletionsCommand` and `ReportExporter`. |
| 5 | T7 portal pages · T6 enrolment deletion | **none** — `Filament/Portal` directories, `StudentPanelProvider` and `lang/en/portal.php` against `DeleteEnrollmentAction` and its tests. |
| 6 | T8 verifier · T11 worker readiness | **none** — `routes/web.php`, `VerifyCertificateController.php`, the verify views and `lang/en/verify.php` against `tests/Feature/Finance/AdjustChargeConcurrencyTest.php` alone. |
| 7 | T13 arch test · T12 gate | **none** — a new Finance test plus the `DatabaseIsolationTest` exempt list against `Tooling\Gate` and the PHPUnit configuration. |

Files touched by more than one phase-3 task are **sequential across waves, never
concurrent**:

| File | Tasks | Waves |
|---|---|---|
| `database/seeders/RolePermissionSeeder.php` | T1 only, deliberately | 1 |
| `app/Models/User.php` | T1 only | 1 |
| `app/Providers/Filament/StudentPanelProvider.php` | T1 → T7 | 1 → 5 |
| `lang/en/portal.php` | T1 → T7 | 1 → 5 |
| `lang/en/enrollment.php` | T3 → T6 | 3 → 5 |
| `app/Domain/Enrollment/Services/EnrollmentQueryService.php` | T9, then consumed by T7 | 3 → 5 |
| `app/Domain/Enrollment/Actions/ReverseEnrollmentCompletionAction.php` | T3 only — T5 does **not** touch it | 3 |
| Phase documents | T13 for phase-2 §4; T14 for the other three | 7 → 8 |

---

## The shared wiring seams

A seam is a file whose content is an enumeration that grows whenever a task adds
a unit of some kind. No task owns it; each appends to it. Phase 2's lesson is
that a declared file scope says what a task **owns** and nothing about the
registries it **joins**, and that when two branches do the same thing for the same
reason, git's confidence is highest exactly where "keep both" is wrong.

Every row was read against `main` at `0c42be1`:

| Seam | A task joins it when it… | State for phase 3 |
|---|---|---|
| `database/seeders/RolePermissionSeeder.php` + `tests/Feature/RolePermissionSeederTest.php` | needs a permission | **closed by T1**, which seeds the whole phase-3 set. No later task appends. The test is the **existing** file at that path — it is extended, not replaced, and no second seeding test is created. |
| `AdminPanelProvider` — `discoverResources()` | adds the first resource in a domain namespace | **closed for `Domain/Enrollment`** — the line exists at `AdminPanelProvider.php:40-43`. T5's certificate resource is covered by it and **must not add a second**. |
| `AdminPanelProvider` — `discoverPages()` | adds a standalone panel page outside `app/Filament/Pages` | **not joined by any phase-3 task.** Portal pages register on the *student* panel. |
| `StudentPanelProvider` — `discoverPages()` | adds a portal page | **created by T1, joined only by T7.** A new seam this phase introduces; named here so it is declared rather than discovered. |
| `AppServiceProvider` — `Gate::policy()` | adds a Policy class | **not joined.** `App\Domain\Enrollment\Models\StudentCertificate` resolves to `App\Domain\Enrollment\Policies\StudentCertificatePolicy` through `Gate::guessPolicyName()`'s prefix walk unaided. T4 registers nothing and **proves** discovery resolves it rather than assuming it. |
| `AppServiceProvider` — `RateLimiter::for()` | registers a named limiter | **joined only by T8**, for `certificate-verification`. |
| `tests/Feature/Staff/ActionBoundaryArchTest.php` | adds an Action calling a raw Spatie role writer, `->delete()`, or `is_active => false` | **joined only by T2**, for `IssuePortalCredentialAction` in the `assignRole` allowlist. The certificate Actions never delete and never touch roles. |
| `tests/Feature/DatabaseIsolationTest.php` — the exempt list | adds a test file declaring no database isolation trait | **joined only by T13.** |
| `routes/web.php` | adds a route | **joined only by T8.** |
| `routes/console.php` | adds or changes a scheduler entry | **not joined.** T10's retention rides the existing hourly sweep; adding a second scheduled command is explicitly the wrong shape (design §11.1). |
| `config/auth.php` | adds a guard | **joined only by T1.** |
| `tests/Feature/LocalizationTest.php` | adds a translation catalogue | **closed** — the Arabic-empty dataset derives from `lang/en`. |

**No test asserts most of these, and both failure directions are silent.** After
any rebase touching a seam, verify by **counting**, not reading:

```bash
grep -c "Domain/Enrollment/Filament/Resources" app/Providers/Filament/AdminPanelProvider.php
grep -c "discoverPages" app/Providers/Filament/StudentPanelProvider.php
grep -n "RateLimiter::for" app/Providers/AppServiceProvider.php
```

Expect `1`, `2` (from T7 on), and one `certificate-verification` entry (from T8 on).

**Each task re-reads this table against `main` rather than trusting it.** The
table is hand-maintained against a codebase that keeps growing registries; phase 2
established that a seam the inventory omits is a seam nobody is asked to declare,
so the omission propagates as compliance.

---

## File ownership: translation catalogues

Every task owns its own catalogue, so no two tasks edit one array:

| Task | T1 | T2 | T3 | T5 | T7 | T8 |
|---|---|---|---|---|---|---|
| File | `portal.php` | `credentials.php` | `enrollment.php`¹ | `certificates.php` | `portal.php`² | `verify.php` |

¹ T3 **extends** the existing `lang/en/enrollment.php` with completion labels. No
other phase-3 task touches it.
² T7 extends T1's `portal.php`. They are sequential (waves 1 and 5), so this is an
ordering fact rather than a conflict.

Every catalogue ships with an **empty** `lang/ar/` counterpart. Arabic arrives in
phase 4; the structure is enforced from commit one.

---

## Task 0 (every task): isolation

Per `docs/WORKFLOW.md`:

```bash
git checkout main && git pull --ff-only
git worktree add ../Training-center-worktrees/P3-T{NN} -b p3/t{nn}-{slug}
```

**From updated `main`, after the dependency PR has merged with green CI** — never
from a dependency's branch, which would put the upstream work inside this task's
diff.

Then in the new worktree: `composer install`, copy `.env` from the main checkout
**without reading it** (it holds the database password), `npm ci`,
`git config core.hooksPath .githooks`, and `composer dump-autoload`.

Check for `vendor/autoload.php`, not for a `vendor/` directory — an interrupted
`composer install` leaves the directory without the file, and a stale autoloader
kills the whole suite with an error that reads like a broken merge.

Verify with `composer verify`. The suite serialises across worktrees on a
machine-wide lock; a run that says it is waiting is correct, not hung.
`--parallel` is refused.

At the end: open a PR, get the other agent's review, resolve, merge, then **tag
the branch tip and push the tag** before deleting anything. Every task is
squash-merged, so its review history is otherwise unreachable from `main`.

---

## The mutation probe requirement, stated once

**Every guard this phase introduces carries a probe that must fail when the guard
is removed**, and the task's evidence includes the failing output, not a claim
that it would fail.

This is not ceremony. Phase 2 found every one of its defects by running an
experiment and none by reading, including four fixes that looked correct on the
page: an exclusion list whose `->get` also matched `->getKey`, silently disabling
the pattern it was added for; a guard whose negative control asserted that
Eloquent builder writes fire model events, which they do not, so it blessed the
shape it existed to catch; a test that ended in a bare `assertNotified()` already
satisfied by the preceding step; and an RTL test calling `app()->setLocale()`
before a request that `SetLocale` then overwrote.

**Restore a mutation from a file copy, never with `git checkout`** — that destroys
uncommitted work. Run Pint after restoring, or the gate fails on the restore
rather than on the change.

---

## Task 1 — Portal foundation
**Wave 1 · Owner: Claude · `p3/t01-portal-foundation` · depends on nothing**

The task every other portal task builds on, and the only task that touches the
permission seeder.

**File scope**
- `config/auth.php` — the `student` guard, `session` driver, existing `users` provider
- `app/Models/User.php` — `protected $guard_name = 'web';` and the panel-aware `canAccessPanel()`
- `app/Providers/Filament/StudentPanelProvider.php` — **new**
- `bootstrap/providers.php` — register it
- `app/Http/Middleware/ForcePasswordChange.php` — **de-hardcode the panel**; see below
- `app/Filament/Pages/PasswordChange.php` — **de-hardcode the redirect**; see below
- `app/Domain/Enrollment/Support/AuthenticatedStudent.php` — **new**
- `database/seeders/RolePermissionSeeder.php` — **the entire phase-3 permission set**
- `lang/en/portal.php`, `lang/ar/portal.php` (empty)
- `tests/Feature/Auth/PanelAccessTest.php` — **existing**, extended
- `tests/Feature/Auth/ForcePasswordChangeTest.php` — **existing**, extended to the student panel
- `tests/Feature/Auth/LivewirePersistentGuardTest.php` — **existing**, extended to the student panel
- `tests/Feature/RolePermissionSeederTest.php` — **existing**, extended
- `tests/Feature/Portal/GuardResolutionTest.php` — new
- `tests/Feature/Portal/PortalLogoutTest.php` — new
- `tests/Feature/Portal/PortalPasswordChangeTest.php` — new
- `tests/Feature/Portal/AuthenticatedStudentTest.php` — new
- **Joins:** `RolePermissionSeeder` (closes it), `config/auth.php`. Creates the `StudentPanelProvider` `discoverPages()` seam without joining it.

**Four of those files are existing tests, named because they exist.** An earlier
draft of this plan invented `tests/Feature/Portal/PanelAccessTest.php` and
`tests/Feature/Staff/PermissionSeedingTest.php`; neither path exists, and the
first would have been a second file with the same basename testing the same
method. Extend what is there.

**Produces**
- `AuthenticatedStudent::resolve(): Student` — the single answer to "whose portal is this", consumed by every T7 page.
- The `student` guard and panel, consumed by T2 (accounts must be able to sign in) and T7.
- The full phase-3 permission set, consumed by T2, T3, T4, T5.

**Consumes** nothing.

**Does**

Adds the guard and the panel. The panel carries the admin panel's middleware
stack and — critically — the same `persistentMiddleware` list: `SetLocale`,
`AuthenticateSession`, `ForcePasswordChange`. P1-T15 finding 5 established why:
Livewire updates arrive on Livewire's own route, so non-persistent middleware
never runs for them, and a guard that does not run there is skippable.

**`User` gains `protected $guard_name = 'web';` and this is the task's central
claim.** Filament's `Authenticate` middleware calls
`$this->auth->shouldUse(Filament::getAuthGuard())`
(`vendor/filament/filament/src/Http/Middleware/Authenticate.php:25`);
`AuthManager::shouldUse()` delegates to `setDefaultDriver()`, which **writes**
`config('auth.defaults.guard')` (`AuthManager.php:206-224`); Spatie's
`Guard::getDefaultName()` reads that value and returns it whenever it is among
the guards matching the model's provider. Without the pin, every permission
lookup in a portal request resolves against `guard_name = 'student'`, for which
no rows exist, and every check returns false silently.

`canAccessPanel()` becomes a `match` on `$panel->getId()` with `default => false`.
`admin` requires `is_active` and `access_admin_panel`; `student` requires
`is_active`, `access_student_portal`, **and a linked, non-trashed student record**
(`Student` uses `SoftDeletes`).

**The forced-password-change gate is admin-hardcoded and must be made
panel-aware.** This is a step-0 correction; the first draft said only "ported"
and scoped neither file. `ForcePasswordChange.php` carries
`private const PAGE_ROUTE = 'filament.admin.pages.password-change'` (line 23),
`private const LOGOUT_ROUTE = 'filament.admin.auth.logout'` (line 29), and
`redirect()->to('/admin/password-change')` (line 172). `PasswordChange.php` ends
its success path with `$this->redirect('/admin')` (line 209). Left alone, a
student with a temporary password is redirected into a panel they cannot enter,
and the guard's exemption never matches the portal's own password route — the
trap the admin panel's version was specifically built to avoid.

Both resolve their route and redirect **from the panel the request is on**, via
`Filament::getCurrentPanel()`, rather than from a constant. `PasswordChange.php`
line 194 already reads the guard from `Filament::getAuthGuard()` and its comment
anticipates exactly this — "if a panel is ever given its own guard" — so the guard
half needs no change, only the routes.

**The password page is registered on the student panel explicitly.** It lives at
`app/Filament/Pages/PasswordChange.php`, which the student panel does not
discover, so it goes in `StudentPanelProvider`'s `->pages([...])` array by class
name. Discovery is not an option: the page sets
`protected static string $layout = 'filament-panels::components.layout.simple'`
and Filament's `discoverPages()` filters on `Page::class`, which is why the admin
panel registers it the same way.

`RolePermissionSeeder` seeds **fourteen abilities** in one place:

| Group | Abilities | Held by |
|---|---|---|
| Portal access | `access_student_portal` | student |
| Own reads | `view_own_student_record`, `view_own_enrollment`, `view_own_balance`, `view_own_certificate` | student |
| Completion | `complete_enrollment` | super admin, admin |
| | `complete_assigned_batch_enrollment` | staff |
| Credentials | `issue_portal_credential`, `reset_portal_credential` | super admin, admin, staff |
| Certificates | `view_any_student_certificate`, `view_student_certificate` | super admin, admin, staff |
| | `issue_student_certificate`, `replace_student_certificate`, `revoke_student_certificate` | super admin, admin |

**One portal-access ability plus four `view_own_*` abilities — five on the student
role — and fourteen new abilities in total.** The first draft said eleven, having
counted the certificate set as one entry.

**Generic certificate permissions are never seeded at all.**
`create_student_certificate`, `update_student_certificate`,
`delete_student_certificate`, `delete_any_student_certificate` and Shield's
`force_delete_*`, `restore_*`, `replicate_*` and `reorder_*` variants are absent
from every role and from the permissions table. This follows the reasoning
`RolePermissionSeeder` already records for `create_charge` and its siblings:
seeding an ability nothing honours invites someone to wire it up later. A
certificate is issued, replaced or revoked — never created, updated or deleted —
and the policy refuses those three unconditionally (T4).

The `student` role receives **none** of the certificate permissions, generic or
custom. Attaching Shield's generic set to it is a one-line mistake with a
register-wide blast radius.

Pages arrive in T7.

**Done when**

A real request to a student-panel page, signed in on the `student` guard, finds a
granted `view_own_*` permission returns **true** — and the same test **fails when
`$guard_name` is removed from `User`**, with the failing output recorded · a
student is refused at `/admin` · a staff account is refused at `/portal` · an
inactive account is refused at both · a `student`-role account with **no linked
student** is refused at `/portal` · an **unknown panel id** is refused · logging
out of `/portal` is asserted to also end an `/admin` login in the same session,
pinning `LogoutController`'s `session()->invalidate()` so nobody later "fixes" it
into a per-guard logout · `AuthenticatedStudent` resolves the signed-in student
and **throws rather than returning null** for a user with no student row · a
student holding a temporary password lands on the **portal's** password page, not
`/admin/password-change`, and on success is redirected to `/portal` — and the
same containment still holds on the admin panel, proven by
`ForcePasswordChangeTest` and `LivewirePersistentGuardTest` passing **unchanged**
for staff while gaining student cases · a flagged student driving any other
portal Livewire component is still contained, which is what
`persistentMiddleware` buys · `tests/Feature/RolePermissionSeederTest.php` names
**all fourteen** new abilities, asserts the student role holds exactly five, and
asserts `create_student_certificate`, `update_student_certificate`,
`delete_student_certificate` and `delete_any_student_certificate` **do not exist
in the permissions table at all** · `composer verify` green.

---

## Task 2 — Portal credentials
**Wave 2 · Owner: Codex · `p3/t02-portal-credentials` · depends on 1**

Two Actions that create and reset a login, and the refusals that stop a
staff-held ability becoming staff-account creation.

**File scope**
- `app/Domain/Staff/Actions/IssuePortalCredentialAction.php` — **new**
- `app/Domain/Staff/Actions/ResetPortalCredentialAction.php` — **new**
- `app/Domain/Staff/Support/TemporaryPassword.php` — **new**; extracted from `ResetUserPasswordAction`
- `app/Domain/Staff/Actions/ResetUserPasswordAction.php` — **declared crossing**, to consume the extracted collaborator
- `app/Domain/Enrollment/Filament/Resources/StudentResource.php` — the issue and reset actions
- `app/Domain/Staff/Exceptions/StudentHasPortalAccountException.php` — **new**
- `app/Domain/Staff/Exceptions/StudentHasNoEmailException.php` — **new**
- `app/Domain/Staff/Exceptions/EmailAlreadyRegisteredException.php` — **new**
- `app/Domain/Staff/Exceptions/ProtectedAccountException.php` — **new**
- `lang/en/credentials.php`, `lang/ar/credentials.php` (empty)
- `tests/Feature/Staff/ActionBoundaryArchTest.php` — **seam**; one allowlist entry
- `tests/Feature/Portal/IssuePortalCredentialTest.php`
- `tests/Feature/Portal/ResetPortalCredentialTest.php`
- `tests/Feature/Portal/PortalCredentialConcurrencyTest.php`

**Produces** portal accounts, consumed by nothing in code — T7's tests build
their own fixtures.

**Consumes** T1's `student` role and `access_student_portal`.

**Does**

`IssuePortalCredentialAction(User $actor, Student $student)`, self-authorising on
`issue_portal_credential`, one transaction, locking the student row so a
concurrent double-issue is a clean refusal rather than a unique-index
`QueryException` on `students.user_id`.

It refuses when the student already has a `user_id`; when the student has no
email; and when **any** `users` row holds that email, **`withTrashed()`
included**. No reuse, no automatic relink, no inspection of that account's roles
— the email is the whole test. The refusal message says **an administrator must
resolve the existing account holding this email**. It must not say "restore it":
restoring is right only when the archived row is that student's own former
account, and an unrelated archived account using the address is a different
problem the message must not mis-describe.

**Two students may share an email** — `students.email` is indexed and not unique.
Two concurrent issuances therefore both pass the `users` lookup, because the
student-row lock cannot see across student rows, and one dies on
`users_email_unique`. The Action catches **that specific duplicate-key failure,
identified by index name**, and returns the same "email already belongs to an
account" refusal. A collision on any other index is not swallowed.

On success, inside the one transaction: create the `users` row, assign **exactly
the literal `student` role**, link `students.user_id`, and issue a one-time
temporary password with `must_change_password` set.

`ResetPortalCredentialAction(User $actor, Student $student)` — **never an
arbitrary `User`**; a reset Action that accepts any user is a staff-account reset
waiting for a caller. It locks the student, derives the linked account from the
locked row, and refuses a missing or trashed account.

**It refuses any linked account holding `access_admin_panel`, tested as a
permission and not as panel accessibility.** This is a step-0 correction. The
first draft said "refuses any account that can reach `/admin`", which reads as
`canAccessPanel()` — and that method requires `is_active`. A **deactivated** staff
account linked to a student row would therefore not "reach `/admin`", would pass
the check, and could have its password reset by a front-desk staffer holding only
`reset_portal_credential`. Reactivating it afterwards is an administrative act,
so the window is real. The permission is the durable fact; panel accessibility is
a transient one, and a guard must test the durable fact.

The refusal is on the permission alone: `$account->can('access_admin_panel')`,
evaluated with no regard to `is_active` or the trashed state, and the test builds
a **deactivated** staff account to prove it.

`TemporaryPassword` is the existing generator extracted verbatim —
`Str::password(16, symbols: false)`, hash, `must_change_password` — and
`ResetUserPasswordAction` is migrated to call it. A second implementation would
be a second place for the rule to drift.

**The arch-test entry proves less than it looks like.**
`ActionBoundaryArchTest:149-156` allowlists `assignRole|removeRole|syncRoles` to
`SyncUserRolesAction` and `SystemRoleWriter`, matched by **file basename**.
Adding `IssuePortalCredentialAction` proves only *"this file may call
`assignRole`"*. It does **not** prove *"it assigns the `student` role"*. That is a
behavioural test, and the task ships both. Neither is described as doing the
other's job.

**Done when**

A staff member holding only `issue_portal_credential` issues a credential and the
new account holds **exactly** the `student` role · the same staff member cannot
create a staff account through any path · issuance is refused for a student with
no email, for a student already linked, and for an email held by an **active**
account, a **soft-deleted** account, and an account holding a **non-student**
role, each asserted separately · **two connections issuing credentials for two
different students sharing one email produce one success and one typed refusal,
not a `QueryException`** · resetting refuses a student with no account and one
with a trashed account · **resetting refuses a linked account holding
`access_admin_panel` while that account is deactivated** — the case a
`canAccessPanel()` check would have let through, and the test builds the account
deactivated on purpose · a signed-in
student is rejected on their next Livewire request after a reset, proving
persistent `AuthenticateSession` does the work · **the arch rule fails when a raw
`assignRole` is injected into an unlisted file, naming it** · the behavioural test
**fails when the literal `'student'` is replaced with a caller-supplied value** ·
`ResetUserPasswordAction` still passes its existing phase-1 tests unchanged ·
`composer verify` green.

---

## Task 3 — Completion marking
**Wave 3 · Owner: Claude · `p3/t03-completion-marking` · depends on 1, 4**

The status that makes a certificate possible, and the first task to make
`EnrollmentStatus::Completed` reachable since phase 1 declared it.

**File scope**
- `database/migrations/…_add_completed_at_to_enrollments_table.php` — **new**
- `app/Domain/Enrollment/Support/CompletionRule.php` — **new**
- `app/Domain/Enrollment/Actions/CompleteEnrollmentAction.php` — **new**
- `app/Domain/Enrollment/Actions/ReverseEnrollmentCompletionAction.php` — **new**
- `app/Domain/Enrollment/Filament/Resources/BatchResource/RelationManagers/EnrollmentsRelationManager.php` — the bulk action
- `app/Domain/Enrollment/Exceptions/EnrollmentNotCompletableException.php` — **new**
- `app/Domain/Enrollment/Exceptions/CompletionNotReversibleException.php` — **new**
- `lang/en/enrollment.php` — completion labels
- `tests/Feature/Enrollment/CompleteEnrollmentTest.php` — new
- `tests/Feature/Enrollment/CompletionAuthorizationTest.php` — new
- `tests/Feature/Enrollment/CompletionConcurrencyTest.php` — new
- `tests/Feature/Enrollment/WithdrawCompletedEnrollmentTest.php` — **new; see below**
- `tests/Feature/Enrollment/EnrollmentsRelationManagerTest.php` — **existing**; the bulk action, and the crafted-status test at line 146
- **Joins no seam.**

**Produces** `EnrollmentStatus::Completed` as a reachable state and
`enrollments.completed_at`, both consumed by T5.

**Consumes** T1's two completion abilities and T4's `StudentCertificate` model.

**Does**

`CompletionRule` is modelled on `EnrollmentUpdateRule` but is a **separate
class**, because that one asks about `update_enrollment`. Same three-step shape:
unrestricted `complete_enrollment`, then `complete_assigned_batch_enrollment`,
then a **locking** pivot read on `batch_instructor` taken **after**
`EnrollmentMutex` is acquired.

The locking read is not optional. Inside a transaction an ordinary read is served
from the REPEATABLE READ snapshot, which may already have been opened by any
earlier ordinary query in that transaction. A snapshot read of the pivot can be
stale even while the row is locked — the shape that let two tills double-collect.
Only a locking read is current.

**Staff must not hold `complete_enrollment`.** P1-T11's lesson: holding the
unrestricted permission satisfies the first branch, the scoping never runs, and
every scoped test still passes. A test builds a bespoke role holding only the
scoped ability, because the seeded roles hide exactly this gap.

Transitions, exactly: complete is `active → completed` with a server-generated
`completed_at`; reverse is `completed → active`, clearing `completed_at`, with a
mandatory reason written to the activity log. Complete refuses `withdrawn` and
refuses a second completion. Reverse refuses `active` and `withdrawn`, and
**locks the certificate rows after the enrolment** — preserving the global
**batch → enrolment** order `EnrollStudentAction` established — refusing while any
certificate for that enrolment is `valid`.

**The certificate check reads T4's real table.** An earlier draft had this task
in wave 2, before `student_certificates` existed, and proposed a temporary
interface T3 would define and T5 would rewire. That interface existed only to
paper over the ordering, and its test could only ever agree with the fake behind
it. T4 now runs first and there is one check with one test.

The Filament bulk action ticks rows explicitly and **runs the Action once per
row** — own lock, own authorization check, own activity-log entry. There is no
"complete the whole batch" button: the student who dropped out in week three and
was never withdrawn would be certified by default.

**`WithdrawEnrollmentAction:82` refuses a non-active enrolment, and nothing tests
that branch.** Its docblock promises
`@throws EnrollmentNotWithdrawableException if the enrolment is completed`, and a
repository-wide search for `EnrollmentStatus::Completed` in `tests/` returns
exactly one hit — the crafted-payload test in `EnrollmentsRelationManagerTest`,
which asserts the opposite thing. The branch has been unreachable since phase 1
because nothing could produce a completed enrolment, so **this task writes that
test for the first time** in `WithdrawCompletedEnrollmentTest.php`. An earlier
draft said the existing test would be "re-verified", which assumed a test that is
not there.

**`EnrollmentsRelationManagerTest:146`'s premise changes.** It asserts *"There is
no status field, and completion is phase 3"* while proving a crafted `status` in
an enrol payload cannot reach the column. That remains true — the enrol form still
has no status field, and completion arrives on a separate bulk action — but the
comment is now wrong about the phase, and the test must be **re-run and its
comment corrected** rather than left asserting a reason that has expired.

**Done when**

An admin completes an active enrolment and `completed_at` is set from the server
clock · a staff member assigned to the batch completes an enrolment on it · the
same staff member is refused on a batch they are not assigned to, **using a
bespoke role holding only `complete_assigned_batch_enrollment`** · granting that
staff member `complete_enrollment` is asserted **not** to be the seeded
configuration · completing a `withdrawn` enrolment is refused, and completing a
`completed` one is refused · reversal clears `completed_at` and records the
reason · reversal of an `active` and of a `withdrawn` enrolment are both refused ·
**two connections completing the same enrolment produce one success and one typed
refusal** · the bulk action over three selected rows writes **three** activity-log
entries, not one · **the scoped-permission test fails when the pivot read drops
`lockForUpdate()`**, demonstrated under concurrency with the failing output
recorded · reversal is refused while a `valid` certificate exists **against the
real `student_certificates` table**, and permitted once it is revoked ·
**withdrawing a genuinely completed enrolment raises
`EnrollmentNotWithdrawableException`** — a branch that has never been executed by
any test until now · `EnrollmentsRelationManagerTest:146` still passes and its
comment no longer says completion is a future phase · `composer verify` green.

---

## Task 4 — Certificate foundation
**Wave 2 · Owner: Claude · `p3/t04-certificate-foundation` · depends on 1**

The register's schema and its three database constraints. No Actions.

**File scope**
- `database/migrations/…_create_student_certificates_table.php` — **new**
- `database/migrations/…_add_valid_enrollment_id_to_student_certificates_table.php` — **new**
- `database/migrations/…_add_check_constraints_to_student_certificates_table.php` — **new**
- `app/Domain/Enrollment/Models/StudentCertificate.php` — **new**
- `app/Domain/Enrollment/Enums/CertificateStatus.php` — **new**
- `app/Domain/Enrollment/Policies/StudentCertificatePolicy.php` — **new**
- `app/Domain/Enrollment/Support/CertificateReference.php` — **new**
- `database/factories/StudentCertificateFactory.php` — **new**
- `tests/Feature/Enrollment/CertificateSchemaTest.php`
- `tests/Feature/Enrollment/CertificateReferenceTest.php`
- `tests/Feature/Enrollment/CertificatePolicyTest.php`
- **Joins no seam.** `Domain/Enrollment` resource discovery already exists at `AdminPanelProvider.php:40-43`, and the policy resolves through convention — see below.

**Produces** the table, model, enum, factory, `CertificateReference` generator and
policy, consumed by T5, T6, T7 and T8.

**Consumes** T1's Shield `student_certificate` permission set.

**Does**

The table per design §6.1. **One schema statement per migration** — MySQL does
not roll back DDL, so a migration carrying two can fail on the second having
committed the first, and the retry then dies on the first. Three migrations:
table, generated column plus its unique index, then the two `CHECK` constraints.

```sql
valid_enrollment_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'valid' THEN enrollment_id ELSE NULL END) STORED,
UNIQUE KEY uniq_valid_certificate_per_enrollment (valid_enrollment_id)
```

```sql
CONSTRAINT chk_student_certificates_status CHECK (
    status IN ('valid', 'revoked', 'replaced')
)
```

```sql
CONSTRAINT chk_student_certificates_revocation CHECK (
    (status = 'revoked'
        AND revoked_at IS NOT NULL
        AND revoked_by IS NOT NULL
        AND CHAR_LENGTH(TRIM(revocation_reason)) > 0)
    OR (status <> 'revoked'
        AND revoked_at IS NULL
        AND revoked_by IS NULL
        AND revocation_reason IS NULL)
)
```

The status constraint is **not decoration**. The generated column reads
`CASE WHEN status = 'valid'`, so a row inserted with a mistyped status yields NULL
in `valid_enrollment_id` and **slips past the unique index entirely** — leaving a
certificate that is neither counted as valid nor visibly wrong, while the enum
cast throws on hydration and the verifier fails on a reference that resolves to a
real row. `CHAR_LENGTH(TRIM(...)) > 0` rather than `NOT NULL` because `NOT NULL`
admits `''`, which would make "mandatory reason" true in the form and false in the
database.

`CertificateReference` generates `TC-{year}-{8 random non-sequential characters}`,
uppercase. `issued_at` and the year derive from **one `Africa/Tripoli` reading,
taken once** — the application runs in UTC, so a January issuance could otherwise
carry the previous year's number while its `issued_at` says January. The
reference is deliberately unlike the sequential `ENR-`/`CHG-`/`RCT-` series and
**must not be "hardened" in the other direction either**; phase 2's
placeholder-UUID trick is not needed, because a random reference does not depend
on the row id.

The model is thin: casts, relationships, `RecordsActivity`, and **no business
logic**. `reference_number` is excluded from `auditedAttributes()` for the reason
phase 2 established for its own references.

**`StudentCertificatePolicy` is not registered in `AppServiceProvider`.**
`Gate::guessPolicyName()` walks every namespace prefix longest-first
(`vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:721-724`), which
resolves `App\Domain\Enrollment\Models\StudentCertificate` to
`App\Domain\Enrollment\Policies\StudentCertificatePolicy` unaided. **That is a
claim this task proves with `Gate::getPolicyFor()`, not one it assumes** — nine of
the eleven existing registrations are redundant and two are not, and the
difference was only established by measurement.

**Done when**

One `valid` plus several `replaced` and `revoked` rows for the same enrolment
insert cleanly · **a second `valid` row for one enrolment raises a unique-constraint
violation on a direct insert bypassing every Action** · **a row inserted with
`status = 'Valid'` is refused by `chk_student_certificates_status`**, proven by
direct insert, and the test **fails when that constraint is dropped** · a
`revoked` row missing any revocation field is refused, and one with
`revocation_reason = '   '` is refused, both by direct insert · a non-revoked row
carrying revocation fields is refused · each migration is applied and rolled back
independently · **the reference tests are deterministic**: the generator takes an
injected randomness source, and the tests drive it with fixed sequences — one
asserting the rendered shape `TC-2026-XXXXXXXX` character by character, one
asserting the suffix alphabet excludes nothing it should not, and one feeding two
identical draws to prove the caller sees a collision rather than the generator
silently deduplicating. **A loop over 10,000 random values is not a test**: it
asserts a property of that run, it cannot fail reproducibly, and a suite that
rolls the dice 10,000 times per run is exactly the shape phase 2 recorded as
green locally and red for two hours a day · a reference generated at
`2026-12-31T23:30Z` carries **2027**, because Tripoli is already into the new
year, with the clock frozen rather than sampled ·
`Gate::getPolicyFor(StudentCertificate::class)`
returns the policy with **no** `AppServiceProvider` entry · the policy refuses
every action for an actor holding no certificate permission, tested with a bespoke
role · `composer verify` green.

---

## Task 5 — Certificate Actions
**Wave 4 · Owner: Claude · `p3/t05-certificate-actions` · depends on 3, 4**

The register's three transitions, and the invariant tested from both directions.

**File scope**
- `app/Domain/Enrollment/Actions/IssueStudentCertificateAction.php` — **new**
- `app/Domain/Enrollment/Actions/ReplaceStudentCertificateAction.php` — **new**
- `app/Domain/Enrollment/Actions/RevokeStudentCertificateAction.php` — **new**
- `app/Domain/Enrollment/Filament/Resources/StudentCertificateResource.php` — **new**
- `app/Domain/Enrollment/Filament/Resources/StudentCertificateResource/Pages/ListStudentCertificates.php` — **new**
- `app/Domain/Enrollment/Filament/Resources/StudentCertificateResource/Pages/ViewStudentCertificate.php` — **new**
- `app/Domain/Enrollment/Exceptions/EnrollmentNotCompletedException.php` — **new**
- `app/Domain/Enrollment/Exceptions/CertificateAlreadyIssuedException.php` — **new**
- `app/Domain/Enrollment/Exceptions/NoValidCertificateException.php` — **new**
- `lang/en/certificates.php`, `lang/ar/certificates.php` (empty)
- `tests/Feature/Enrollment/IssueCertificateTest.php`
- `tests/Feature/Enrollment/ReplaceCertificateTest.php`
- `tests/Feature/Enrollment/RevokeCertificateTest.php`
- `tests/Feature/Enrollment/CertificateConcurrencyTest.php`
- `tests/Feature/Enrollment/CertificateAuthorizationTest.php`
- **Joins no seam** — `Domain/Enrollment` resource discovery already covers the new resource. **Do not add a second `discoverResources()` line.** Verify by counting after any rebase.

**Produces** the three transitions, consumed by nothing in code.

**Consumes** T4's model, enum, reference generator and policy; T3's `completed`
status and `EnrollmentMutex` ordering.

**Does**

All three Actions are actor-first and self-authorising. Each **locks the enrolment
and its current certificate rows before checking status and writing** — that
shared lock is the anti-race boundary, and it is what stops two concurrent issue
requests both concluding that no valid certificate exists.

Issue requires the enrolment in `completed` and no `valid` certificate; it
snapshots `student_name`, `course_name` and `completed_on` at that moment, and
those **deliberately do not follow a later correction** to the student or course.

Replace requires a `valid` certificate. **One transaction**: the old row moves to
`replaced` **before** the new row is inserted, so the generated column frees the
unique slot; a failed insert rolls the whole thing back rather than leaving an
enrolment with no valid certificate. The **new** row's `replaces_certificate_id`
points at the previous one. Reprinting an unchanged physical certificate keeps
the same reference and creates no row.

Revoke requires a `valid` certificate and a non-blank reason.

**Retry is discriminated by index name.** A collision on the reference's unique
index retries the **whole transaction** with a fresh random suffix, bounded. A
collision on `uniq_valid_certificate_per_enrollment` is the "a valid certificate
already exists" refusal and **must never retry** — retrying it turns a correct
refusal into a loop. No raw driver error reaches a user.

**An outstanding balance never blocks anything.** The issue form displays the
figure from `ChargeQueryService::outstandingForEnrollment()`; the Action does not
consult it and has no code path that could. This follows from the system as built
— enrolling always raises a bill, collecting at the desk is optional, and system
design §12 records that no screen exists to collect a later instalment, so
blocking would strand every partial payer permanently.

Issued rows are **never deleted**. The resource offers no delete action and the
policy refuses `delete` unconditionally.

**Done when**

Issuing against a completed enrolment writes one `valid` row whose snapshot
matches the student and course **at that moment**, and renaming the course
afterwards does not change it · issuing against an `active` enrolment is refused ·
issuing twice is refused · **two connections issuing for one enrolment produce one
success and one typed refusal — never two rows and never a driver error** ·
replacement marks the old row `replaced`, writes a new `valid` row, and sets the
**new** row's `replaces_certificate_id` · **a forced failure on the replacement
insert leaves the original row `valid`**, proving the transaction · revocation
requires a reason and a blank-after-trim one is refused · **a forced reference
collision retries and succeeds; a forced valid-certificate collision refuses
without retrying** — two distinct tests, because one cannot tell them apart ·
issuing for an enrolment with an outstanding balance **succeeds**, and the form
displays the figure · each Action invoked directly with an unauthorized actor is
denied, tested with bespoke single-ability roles · **`delete_student_certificate`
is created inside the test** — T1 deliberately never seeds it — granted to an
actor, and the policy still refuses, proving the refusal is unconditional rather
than a consequence of the permission's absence · **the concurrency test
fails when the lock is removed from the issue Action**, with the failing output
recorded · `composer verify` green.

Reversal-while-valid belongs to **T3**, which owns
`ReverseEnrollmentCompletionAction` and asserts it against T4's real table. This
task does not restate it — one claim, one owner, one test.

---

## Task 6 — Enrolment deletion and certificates
**Wave 5 · Owner: Codex · `p3/t06-enrollment-deletion` · depends on 4**

One Action, made aware of a table that did not exist when it was written.

**File scope**
- `app/Domain/Enrollment/Actions/DeleteEnrollmentAction.php`
- `app/Domain/Enrollment/Exceptions/EnrollmentHasCertificateException.php` — **new**
- `lang/en/enrollment.php` — **conflict-free by wave**: T3 (wave 3) has merged before this starts
- `tests/Feature/Enrollment/EnrollmentDeletionWithCertificateTest.php` — new
- `tests/Feature/Finance/EnrollmentDeletionWithChargeTest.php` — **existing**; declared crossing, re-verified unchanged
- **Joins no seam.**

**Produces** nothing consumed elsewhere.

**Consumes** T4's table.

**Does**

`student_certificates.enrollment_id` is `restrictOnDelete`, so the database
refuses — but **a foreign-key error is not a business refusal**. The Action locks
through `EnrollmentMutex`, checks explicitly for **any** certificate including
`revoked` and `replaced` ones, and raises a translated business exception. The
restrictive foreign key stays, as protection against bypasses and races.

System design §12 keeps an enrolment created in error deletable; a certified
enrolment is not a mistake, and issued rows are never deleted.

**Done when**

An enrolment with no certificate is still deletable, and the existing phase-2
charge-deletion behaviour is unchanged, asserted rather than assumed · an
enrolment with a `valid` certificate is refused with a **translated typed
exception**, not a `QueryException` · an enrolment with only a `revoked`
certificate is refused · an enrolment with only a `replaced` certificate is
refused · **the check fails over to the foreign key when the application check is
removed**, demonstrating both layers exist, with the failing output recorded ·
`composer verify` green.

---

## Task 7 — Portal pages
**Wave 5 · Owner: Claude · `p3/t07-portal-pages` · depends on 1, 4, 9**

The four read-only pages, and the two-student tests the correctness claim
actually rests on.

**File scope**
- `app/Domain/Enrollment/Filament/Portal/Pages/Overview.php` — **new**
- `app/Domain/Enrollment/Filament/Portal/Pages/MyEnrollments.php` — **new**
- `app/Domain/Finance/Filament/Portal/Pages/MyBalance.php` — **new**
- `app/Providers/Filament/StudentPanelProvider.php` — **seam**; two `discoverPages()` lines, sole writer this wave
- `lang/en/portal.php` — extends T1's catalogue
- `resources/views/portal/overview.blade.php` — **new**
- `resources/views/portal/my-enrollments.blade.php` — **new**
- `resources/views/portal/my-balance.blade.php` — **new**
- `tests/Feature/Portal/OverviewPageTest.php` — new
- `tests/Feature/Portal/MyEnrollmentsPageTest.php` — new
- `tests/Feature/Portal/MyBalancePageTest.php` — new
- `tests/Feature/Portal/PortalRowIsolationTest.php` — new
- `tests/Feature/Portal/PortalQueryCountTest.php` — new
- `tests/Feature/Portal/PortalScopeArchTest.php` — new
- **Joins:** `StudentPanelProvider`'s `discoverPages()`.

**Produces** the portal surface.

**Consumes** T1's `AuthenticatedStudent` and abilities; T4's certificate model;
T9's `scopeToStudent()` and bulk balance query.

**Does**

Four pages, all read-only. The only write a student can perform anywhere in phase
3 is changing their own password, which T1 already ships.

| Page | Shows | Requires |
|---|---|---|
| Overview | name, student code, status | `view_own_student_record` |
| My enrolments | course, batch, dates, enrolment status | `view_own_enrollment` |
| — its certificate fields | reference and status when one is `valid` | **additionally** `view_own_certificate` |
| My balance | per-enrolment outstanding and a total | `view_own_balance` |
| Password | change own password | portal access and the existing own-password rules — **no `view_own_*` ability exists for it** |

**Scoping is two mechanisms, and the plan is explicit about what each proves.**

Every page resolves the viewing student through `AuthenticatedStudent`; no page
derives a `student_id` for itself. `PortalScopeArchTest` proves no portal page
queries without it — **and that is a source scan, which proves a call was made,
not that the resulting query was constrained.** It is partial defence in depth and
is named as such.

What the correctness claim rests on is `PortalRowIsolationTest`: sign in as
student A, create distinctive enrolments, balances and certificates for student B,
and assert **none of B's data appears anywhere in A's rendered pages** — run for
each page.

**The balance page must not loop.** `ChargeQueryService::outstandingForEnrollment()`
is a `charges` lookup plus `ChargeBalance::outstandingFor()` — roughly two queries
per enrolment. The page consumes T9's bulk query, which returns per-enrolment
figures and the total in a fixed number of statements using the same canonical
`ChargeBalance` calculation. **Nothing derived is stored**; this is about how many
statements produce the same derived answer.

The certificate fields are a **separate grant on a shared page**, not a page of
their own.

**Done when**

Each page renders for a student holding its ability and is refused for one who
does not, tested with bespoke single-ability roles · **a student holding
`view_own_enrollment` but not `view_own_certificate` sees the enrolment list with
the certificate columns absent**, asserted as absent rather than assumed hidden ·
`PortalRowIsolationTest` proves student B's enrolments, balance figures and
certificate reference appear on **none** of A's pages · **the balance page issues
a fixed number of queries for one enrolment and for twenty**, asserted by query
count, so a reintroduced loop fails the test · **the enrolments page does the
same** — its certificate lookup and its course/batch columns are both per-row
temptations, and a page whose query count grows with the number of enrolments
fails, exactly as the balance page does. The first draft asserted this for the
balance page alone, which would have let the N+1 reappear one page over · a
student with a `replaced` or
`revoked` certificate and no `valid` one sees no reference · **the arch test fails
when a page is made to query without the resolver, naming the offending file** ·
`grep -c "discoverPages" app/Providers/Filament/StudentPanelProvider.php` returns
2 · `composer verify` green.

---

## Task 8 — The public verifier
**Wave 6 · Owner: Claude · `p3/t08-public-verifier` · depends on 4**

The first thing in this system that serves the open internet.

**File scope**
- `routes/web.php` — **seam**; three routes in one middleware group
- `app/Http/Controllers/VerifyCertificateController.php` — **new**
- `app/Http/Middleware/VerificationResponseHeaders.php` — **new**
- `app/Domain/Enrollment/Data/CertificateVerificationView.php` — **new**; the six-field projection
- `app/Providers/AppServiceProvider.php` — **seam**; the `certificate-verification` named limiter
- `resources/views/verify/form.blade.php` — **new**
- `resources/views/verify/show.blade.php` — **new**
- `resources/views/verify/not-found.blade.php` — **new**; no form, no CSRF token, no echo of input
- `lang/en/verify.php`, `lang/ar/verify.php` (empty)
- `tests/Feature/Verification/VerifyCertificateTest.php` — new
- `tests/Feature/Verification/VerificationDisclosureTest.php` — new
- `tests/Feature/Verification/VerificationHeadersTest.php` — new
- `tests/Feature/Verification/VerificationRateLimitTest.php` — new
- **Joins:** `routes/web.php`, `AppServiceProvider`'s `RateLimiter::for()`.

**Produces** the public surface.

**Consumes** T4's table and enum.

**Does**

Three routes:

| Route | Purpose |
|---|---|
| `GET /verify/certificates` | render the form |
| `POST /verify/certificates` | validate and normalize a complete reference, then redirect |
| `GET /verify/certificates/{reference}` | exact lookup |

A form taking a **complete** reference is still exact-match lookup, not the
browsable register, partial search or autocomplete system design §6 forbids.

**The reference shape is validated in the controller, not by a route
constraint.** This is a step-0 correction of a contradiction in the first draft,
which asked for both a `->where()` regex on the route *and* a byte-identical
rendered 404 for malformed input. Those cannot both hold: a route constraint that
rejects a malformed reference means the route never matches, and Laravel returns
its own `NotFoundHttpException` page — visibly different from the verifier's
rendered result, and therefore a signal that distinguishes "wrong shape" from "no
such certificate". The route takes the segment unconstrained; the controller
normalizes, validates the shape, and renders the same not-found result whatever
went wrong.

**"Omitted" means an empty POST** — the form submitted with a blank field. It does
not redirect back with a validation error, because a validation error is itself a
signal about the input. It renders the same not-found result at 404 as every
other miss.

**The not-found view carries no form and echoes nothing** — no submitted value, no
CSRF token, no error bag. That is what makes "byte-identical" an achievable
assertion rather than an aspiration: a page containing a CSRF token differs
between any two renders.

**The limiter and the headers apply to both the submission and the lookup, in one
middleware group** — the reasoning `routes/web.php` already uses for the
private-file routes, so they cannot be applied to one route and forgotten on the
other. Headers: `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`,
`X-Robots-Tag: noindex, nofollow, noarchive`.

`certificate-verification` is a **named limiter referenced by real routes**,
per-IP, two windows on one limiter: 10 per minute and 100 per hour. Being
referenced is the property whose absence got P1-T03's `login` limiter deleted —
a registered-but-unreferenced limiter reads as a control while protecting
nothing.

**The response is built from `CertificateVerificationView`, never from the
model** — status, printed student name, course name, completion date, issue date,
centre confirmation. A column added to `student_certificates` in a later phase
therefore cannot leak through the public surface by default; the projection would
have to be edited deliberately.

A `revoked` or `replaced` certificate **states its status** and withholds the
detail: no revocation reason, no replacement reference. A malformed reference, an
unknown reference and a missing one all render the **same** not-found result at
404 — nothing distinguishes a typo from a non-existent certificate.

The page is standalone Blade with self-hosted assets. **No third-party scripts,
fonts, analytics, images or styles**, any of which could receive the reference
through a request or a referrer. It does not load the Filament asset pipeline.

**Done when**

A valid reference returns the six fields and no others · **a column added to
`student_certificates` in the test does not appear in the response**, asserting
the projection is a projection · a revoked certificate states "revoked" and its
date, and the response contains **neither** the revocation reason nor any
replacement reference, asserted by absence of the stored strings · a replaced
certificate states it is superseded and does not name its successor · **four
cases produce byte-identical rendered bodies at 404** — a well-formed unknown
reference on GET, a malformed reference on GET, an empty POST, and a malformed
POST — asserted by comparing the response bodies to each other, which is only
possible because the not-found view holds no CSRF token · **no request reaches
Laravel's own 404 page**, asserted by checking the body is the verifier's, which
is what a route constraint would have broken · the three headers are present on the
lookup **and** on the POST, and the test **fails when either route is moved out of
the group** · the eleventh request in a minute from one IP is refused, and the
limiter is asserted to be reached from the route rather than merely registered ·
the rendered page requests **no** external origin, asserted against the emitted
HTML · **the response contains no financial figure, date of birth, national ID,
phone, email or address**, asserted by seeding all of them on the student and
searching the output · `composer verify` green.

---

## Task 9 — Finance query surface
**Wave 3 · Owner: Codex · `p3/t09-finance-query-surface` · depends on nothing**

Two published read operations the portal needs, shaped so a caller cannot take
half of one.

**File scope**
- `app/Domain/Enrollment/Services/EnrollmentQueryService.php` — add `scopeToStudent()`
- `app/Domain/Finance/Services/StudentBalanceQuery.php` — **new**
- `app/Domain/Finance/Data/StudentBalanceSummary.php` — **new**
- `app/Domain/Finance/Data/EnrollmentBalance.php` — **new**
- `tests/Feature/Finance/EnrollmentQueryServiceTest.php` — **existing**, extended
- `tests/Feature/Finance/StudentBalanceQueryTest.php` — new
- **Joins no seam.**

The query-service test lives under **`tests/Feature/Finance/`**, not
`tests/Feature/Enrollment/` — an earlier draft named the latter, which does not
exist.

**Produces**, both consumed by T7:

```php
// App\Domain\Enrollment\Services\EnrollmentQueryService
public function scopeToStudent(Builder $query, string $enrollmentIdColumn, int $studentId): Builder;

// App\Domain\Finance\Services\StudentBalanceQuery
public function forStudent(int $studentId): StudentBalanceSummary;

// App\Domain\Finance\Data\StudentBalanceSummary
final readonly class StudentBalanceSummary
{
    /** @param array<int, EnrollmentBalance> $enrollments keyed by enrollment id */
    public function __construct(public array $enrollments, public Money $total) {}
}

// App\Domain\Finance\Data\EnrollmentBalance
final readonly class EnrollmentBalance
{
    public function __construct(
        public int $enrollmentId,
        public ?int $chargeId,       // null when the enrolment has no bill
        public Money $outstanding,   // Money::zero() when there is no bill
    ) {}
}
```

**Every money value is `App\Domain\Finance\Support\Money`** — never a float,
never a string, never a `decimal` cast leaking out of the query. `outstanding` is
`Money::zero()` rather than `null` for an unbilled enrolment, so the caller never
has to decide what a missing balance means, and `total` is the `Money` sum of the
rows rather than a separately computed aggregate.

**Consumes** nothing.

**Does**

The carried phase-2 item was recorded as `joinStudentTo()`. **Reshaped:** a method
that merely joins the student table leaves every caller responsible for
remembering the `WHERE` clause — which is the exact part that must never be
forgotten on the portal. `scopeToStudent()` joins **and** constrains in one call,
so a caller cannot take the join without the restriction. Its signature
deliberately mirrors `joinCatalogueTo(Builder $query, string $enrollmentIdColumn,
…)` so the two read as siblings.

Both existing methods stay for their existing callers; **nothing is removed**.
Today the choice is a per-row `studentIdFor()`, which is an N+1, or
`joinCatalogueTo()`, which throws on empty dimensions and answers a different
question.

`StudentBalanceQuery` returns per-enrolment outstanding figures and a total in a
**fixed number of statements**, and it **reuses `ChargeBalance` rather than
restating its arithmetic**: the SQL comes from
`ChargeBalance::outstandingExpression()`, the same expression
`outstandingFor()` uses, so there is one definition of outstanding in the system
and this is a second *caller* of it, not a second copy.

Restating the subtraction here would be the `paid_amount` mistake wearing a
third name — two expressions that agree today and diverge the first time a
write-off or an adjustment changes what counts. `Money` values are rehydrated
from the integer dirham the expression produces, never from a float.

**No derived value is stored** — that non-negotiable is untouched. The total is
summed in PHP through `Money::add()` over the rows, so it cannot disagree with
the rows it is a total of.

**Done when**

`scopeToStudent()` returns only the given student's rows, asserted with two
students' data present · **there is no way to call it that yields an unconstrained
result**, asserted by inspecting the produced SQL · the bulk query's per-enrolment
figures are **identical** to `outstandingForEnrollment()` called individually, for
a student with a paid bill, a partly paid bill, an unpaid bill and a written-off
bill · the query count is **the same for one enrolment and for twenty**, asserted
by count · a student with no enrolments returns an empty `enrollments` array and
`Money::zero()` rather than throwing · an enrolment with **no bill** returns
`chargeId === null` and `Money::zero()`, not a missing row · a written-off charge
is excluded from the total exactly as `ChargeBalance` excludes it · **every
returned value is a `Money` instance**, asserted by type, so no float or string
escapes the service · `composer verify` green.

---

## Task 10 — Export retention
**Wave 4 · Owner: Codex · `p3/t10-export-retention` · depends on nothing**

The phase-2 deferral, closed as an enhancement to the generic file lifecycle.

**File scope**
- `database/migrations/…_add_delete_after_to_pending_file_deletions_table.php` — **new**
- `database/migrations/…_add_path_kind_to_pending_file_deletions_table.php` — **new**
- `app/Console/Commands/SweepPendingFileDeletionsCommand.php`
- `app/Domain/Staff/Jobs/PurgeDeletedFileJob.php`
- `app/Domain/Staff/Models/PendingFileDeletion.php`
- `app/Domain/Staff/Services/FileLifecycleService.php` — the shared API; see below
- `app/Domain/Finance/Exports/ReportExporter.php` — **generation point**
- `app/Domain/Finance/Exports/PrepareReportCsvExport.php` — **generation point**
- `app/Domain/Finance/Jobs/GenerateReportPdfJob.php` — **generation point**
- `tests/Feature/Staff/PendingFileDeletionSweepTest.php` — **existing**, extended
- `tests/Feature/Staff/FileLifecycleTransactionTest.php` — **existing**, re-verified
- `tests/Feature/Finance/ExportRetentionTest.php` — new

**Three generation points, not one.** The first draft named `ReportExporter.php`
alone. XLSX and CSV are prepared through `PrepareReportCsvExport.php` and PDFs
are produced by `GenerateReportPdfJob.php`; a receipt written at only one of them
leaves the other two accumulating exactly as today, and the task would report
success having fixed a third of the problem.
- **Joins no seam.** **`routes/console.php` is deliberately not touched** — a second scheduled command is the wrong shape.

**Produces** nothing consumed elsewhere.

**Consumes** nothing.

**Does**

Generated XLSX and PDF exports accumulate on the private disk indefinitely and
land in every nightly backup. **Retention is 7 days** — an export is regenerable
at any time by re-running the report, so retention only has to cover the gap
between generating and fetching.

`pending_file_deletions` gains a nullable `delete_after` and a path-kind
discriminator, because Filament's XLSX pipeline produces a **directory** while
`path` today names a single file. Exports write a receipt at generation time with
`delete_after` at +7 days, and **the existing hourly sweep honours it**. One
schema statement per migration.

**The shared API is `FileLifecycleService::record()`, widened rather than
duplicated.** It is today:

```php
/**
 * @param  array<int, array{disk: string, path: string}>  $files
 * @return array<int, int>
 */
public function record(array $files): array
```

It becomes:

```php
/**
 * @param  array<int, array{
 *     disk: string,
 *     path: string,
 *     kind?: 'file'|'directory',
 *     delete_after?: \Carbon\CarbonImmutable|null,
 * }>  $files
 * @return array<int, int>
 */
public function record(array $files): array
```

**Both new keys are optional and default to today's behaviour** — `kind` defaults
to `'file'`, `delete_after` to `null`, meaning eligible immediately. Every
existing caller therefore compiles and behaves identically without being edited,
which is the property that makes this an extension of the file lifecycle rather
than a second one. `record()` keeps its contract of being called **inside the
transaction that removes the owning row**; the three export generation points
call it outside any such transaction because an export owns no row — that is the
one documented divergence, and it is why they pass `delete_after` rather than
relying on the immediate path.

The purge job branches on `kind` and nothing else. **No caller passes a raw
`deleteDirectory` anywhere** — the capability exists only behind this API and only
under the fence below.

**Recursive directory deletion is a materially more dangerous capability than
today's unlink, and is fenced accordingly:**

- Permitted **only** for the configured export disk and the canonical export path
  prefix. Every other disk and prefix is refused.
- Traversal segments, the disk root, and unrelated directories are rejected.
- `delete_after` is an **eligibility filter**, not a replacement for the sweep's
  ordering. `SweepPendingFileDeletionsCommand` orders by `last_swept_at` with
  never-attempted rows first, and its docblock at line 57 records that ordering by
  `created_at` was the original bug: a page that never drained blocked everything
  behind it forever.
- Ordinary immediate file-deletion receipts behave **exactly** as before, including
  the existing ownership check that refuses to unlink bytes a committed row owns.

**Done when**

An export receipt with `delete_after` in the future is **not** swept, and the same
receipt after the window **is** · a receipt with a null `delete_after` is swept
immediately, exactly as today · **all three generation points write a receipt** —
XLSX through `PrepareReportCsvExport`, CSV through it likewise, and PDF through
`GenerateReportPdfJob` — each asserted separately, because one covered path does
not imply the others · **a directory receipt naming a path outside the
export prefix is refused**, and one naming the disk root is refused, and one
containing a traversal segment is refused — three separate assertions · the export
directory and its contents are gone after a successful sweep · **never-attempted
rows are still swept first, proven across two consecutive sweep runs**, not one ·
the existing staff photo and certificate deletion tests pass **unchanged**, and
so does `FileLifecycleTransactionTest` — **no existing `record()` caller is
edited**, which is the evidence that the API was widened rather than replaced ·
the ownership check still refuses to unlink owned bytes · **the prefix fence fails
when removed**, demonstrated against a path outside it, with the failing output
recorded · `composer verify` green.

---

## Task 11 — Worker-readiness timing
**Wave 6 · Owner: Codex · `p3/t11-worker-readiness` · depends on nothing**

**File scope**
- `tests/Feature/Finance/AdjustChargeConcurrencyTest.php`
- **Joins no seam.**

**Does**

**The first draft of this task was wrong about the failure, and its acceptance
criterion was unachievable.** It said the test "fails whenever a second suite runs
concurrently" and asked for a demonstration of it passing while one did. That
cannot be demonstrated: `tests/bootstrap.php` takes a machine-wide lock in the
test process itself and **serialises suites** — a second suite does not run
concurrently, it queues. `--parallel` is refused outright, deliberately and with
a message saying why. The scenario the criterion described does not exist.

The real defect is narrower and entirely local to this test. It **manually spawns
a real queue worker** and then waits a **hard-coded 10 seconds** for it to become
ready. The single test takes **11.6s** uncontended, so almost all of its runtime
is that fixed wait. Nothing checks whether the worker actually came up: if the
machine is loaded — a slow `composer dump-autoload`, a cold opcache, a laptop on
battery, another worktree's suite holding the database lock right up to the
moment this one starts — the worker may not be listening at the 10-second mark
and the test fails for a reason unrelated to the code it exercises. It is a
sleep pretending to be a synchronisation point.

**Replace the fixed wait with a readiness poll**: check for the condition that
actually means "the worker is up and consuming", on a short interval, up to a
generous ceiling, and fail with a message naming what it waited for if the
ceiling is reached. On an unloaded machine this returns as soon as the worker is
ready, which should take the test well below 11.6s; on a loaded one it waits as
long as it genuinely needs to.

**A fixed sleep raised from 10s to 20s is the same defect with a larger
constant**, and is not acceptable here — it makes the suite slower in the common
case and still fails in the uncommon one.

**Done when**

The readiness condition is **named** — what is polled, and why that specific
signal means the worker is consuming rather than merely started · the poll
returns early on an unloaded machine, with the uncontended runtime reported
before and after · **the ceiling path is exercised**: with the worker deliberately
prevented from starting, the test fails with the readiness message rather than
with a downstream assertion error, and that output is recorded · **a test proves
the poll waits for readiness rather than for a duration** — a worker delayed by
several seconds is still awaited and the test still passes, which a fixed sleep
tuned below that delay would not do · the concurrency behaviour the test exists
to prove is unchanged, asserted by the test still failing when the lock under
test is removed · `composer verify` green.

---

## Task 12 — `failOnNotice` in the gate
**Wave 7 · Owner: Codex · `p3/t12-fail-on-notice` · depends on nothing**

**File scope**
- `phpunit.xml`
- `scripts/Tooling/Gate.php` — the single gate definition
- `tests/Unit/Tooling/WarningGateTest.php` — **existing**, extended
- **Joins no seam.**

The gate lives at `scripts/Tooling/Gate.php` and its test at
`tests/Unit/Tooling/WarningGateTest.php`. An earlier draft wrote `tooling/` and
named no test, which would have left the existing one to be discovered or, worse,
duplicated.

**Does**

Its own PR with its own evidence. **A gate change never rides on another change's
review.**

Relevant history: CI once reported a pass on 1,347 warnings because it had no
`.env` and phpdotenv's `@`-suppressed read surfaced under PHPUnit. Local output is
condensed and hides this; `PAO_DISABLE=true` shows the truth. This task must
establish what the suite currently emits before changing what the gate does with
it.

**Done when**

The current notice and warning count is **measured and reported**, with and
without `PAO_DISABLE=true` · every notice the change would newly fail on is either
fixed or explicitly accepted with a reason · the gate fails on an injected notice,
demonstrated, with the assertion living in `tests/Unit/Tooling/WarningGateTest.php`
alongside the existing warning cases · CI and local agree, since
`Tooling\Gate::fastChecks()` in `scripts/Tooling/Gate.php` is the single
definition both reach · `composer verify` green.

---

## Task 13 — The Finance status arch test
**Wave 7 · Owner: Claude · `p3/t13-finance-status-arch-test` · depends on nothing**

A phase-2 control that was documented and never built.

**File scope**
- `tests/Feature/Finance/FinanceStatusColumnArchTest.php` — **new**
- `tests/Feature/DatabaseIsolationTest.php` — **seam**; the exempt list, sole writer this wave
- `docs/superpowers/specs/2026-08-09-phase-2-financials-design.md` — **§4 only**, the false claim
- **Joins:** `DatabaseIsolationTest`'s exempt list.

**Does**

Phase-2 design §4 states: *"The same rule holds throughout Finance: no status
string column exists on any table in this domain. An architecture test enforces
it."* **It does not.** The repository contains two architecture tests —
`tests/Feature/Finance/MoneyCastArchTest.php` and
`tests/Feature/Staff/ActionBoundaryArchTest.php` — and neither enforces this. This
is a document asserting a property the code lacks, which is this project's most
frequent defect class.

The test is **scoped to Finance-owned tables only**, which puts
`student_certificates.status` naturally outside it — that column lives in
Enrollment and records a decision a human made, not a value derivable from other
rows.

**Name what the guard actually proves.** If it inspects the schema, it proves no
such column exists **now**, on the tables it enumerates — and the enumeration is
itself hand-maintained, which is the same shape as every other inventory this
project has been bitten by. Say so in the test's docblock rather than letting the
name imply more.

**§4 of the phase-2 design is corrected by this task, not by T14.** T14 owns the
other three document corrections. Declared here so the two never touch the same
file concurrently — they are in different waves regardless.

**Done when**

The test passes against `main` as it stands · **it fails when a status string
column is added to a Finance table**, demonstrated with the failing output —
and the proof runs against a **disposable database**, not the shared one. A
migration that adds a column to a Finance table, run to prove a guard fires, is a
schema mutation on the database every worktree shares and `tests/bootstrap.php`
serialises access to; rolling it back afterwards is a second chance to get it
wrong, and a failure between the two leaves every other suite on the machine
running against a mutated schema. Build the probe schema on a throwaway
connection, or assert the rule against a fixture schema rather than the live one ·
it does **not** fire on `student_certificates.status` · the table
enumeration is derived rather than hand-listed, or the docblock states plainly
that it is hand-maintained and what that costs · phase-2 design §4 no longer
claims a test that did not exist · `composer verify` green.

---

## Task 14 — Phase reconciliation
**Wave 8 · Owner: Claude · `p3/t14-reconciliation` · depends on every other task**

**File scope**
- `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` — §3 and §4
- `docs/superpowers/specs/2026-08-09-phase-2-financials-design.md` — **§2 line 54 only**; §4 belongs to T13
- `docs/superpowers/specs/2026-08-27-phase-3-portal-and-certificates-design.md`
- `docs/superpowers/plans/2026-08-27-phase-3-portal-and-certificates.md` — this file
- `docs/ENGINEERING.md` — phase-3 conventions
- **Joins no seam.**

**Does**

Three document corrections — the fourth is T13's:

1. **System design §3**, the surfaces table: `/portal` is a **Filament panel**, not
   Blade + Tailwind.
2. **System design §4**, the login limiter: it does **not** return. Filament
   self-throttles at `rateLimit(5)` and the portal introduces no Laravel auth
   routes. The named limiter phase 3 does introduce is `certificate-verification`,
   built by T8 and referenced by real routes.
3. **Phase-2 design §2, line 54**: "later installments: search the student, open
   the unpaid bill, record another payment" describes a screen system design §12
   records as deliberately scrapped, after it was raised twice in review of P2-T09.

Then: mark this plan COMPLETE, record every deviation from it rather than quietly
absorbing them, record anything raised and deliberately not absorbed so it has an
owner, and carry the phase-3 conventions into `docs/ENGINEERING.md`.

**Re-check every seam against `main`**, by counting rather than reading — phase 2's
T12 found a scheduling seam nobody had declared, and the seam inventory is
hand-maintained.

**Done when**

Every correction above is made and the old wording appears nowhere — **grep the
repository for the removed phrasing**, because a doc line repeated in a second
place is this project's most frequent defect · every deviation is recorded with its
reason · every raised-and-not-absorbed item names what it needs · the seam counts
are re-verified and reported · every task branch is tagged `task/P3-*-end` and the
tags are pushed · `composer verify` green.

---

## Branch cleanup is an operational step, not a task

Merged phase-1 and phase-2 branches can be deleted now that every `task/P1-*` and
`task/P2-*` tag is on origin. **Confirm the tag exists first**, then delete
through:

```bash
gh api -X DELETE repos/iGhoulzz/Training-center/git/refs/heads/<branch>
```

which skips the pre-push hook entirely. `git push --delete` fires the hook and
pays the full ~15–20 minute gate for nothing. Older `p1/*`, `agent/*`, `sec/*`,
`maint/*` and `claude/*` branches are untouched until each is checked.

This is not a PR and produces no diff.

---

## What this plan does not cover

From system design §12 and the phase-3 design's scope, recorded so they do not
reappear as assumptions: certificate template design, PDF generation, physical
printing and printer integration; public student self-registration; collecting a
later instalment through the interface; two-factor authentication; and the Arabic
translation pass, which is phase 4's work — every catalogue this phase adds ships
with an empty `lang/ar/` counterpart.
