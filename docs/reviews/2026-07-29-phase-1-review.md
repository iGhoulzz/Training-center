# Phase 1 review — findings and dispositions

**Task:** P1-T15
**Review surface:** `main` at `217a380` for group 1. Groups 2 and 3 are repinned
to the tip after group 1's fixes merge; the SHA is recorded against each group
below when it is dispatched.
**Reviewers:** fresh subagents with no implementation context and no access to the
implementation session's history.

---

## Why this document exists

Fourteen tasks were each reviewed as they landed, and those reviews were
substantive. What none of them could see is the seam *between* tasks — a
permission seeded in T02 and first used in T11, a policy written in T05 against a
model that grew two columns in T07. Every defect in this project that reached a
merge did so by being invisible from inside a single task's diff.

**The dispositions matter as much as the findings.** A rejected finding with its
reasoning recorded is a question that stays answered. Without that record, the
same objection returns in phase 2 with nobody able to say why it was dismissed —
and it gets re-litigated, or worse, implemented on the second asking because
nobody remembers the first.

**Task 16 folds every rejection and deferral into the spec**, so a future reader
finds the answer where they look for it rather than in a review log they do not
know exists.

---

## Disposition rules

Every finding gets exactly one, with reasoning:

| Disposition | Means |
|---|---|
| **Fix now** | Becomes a branch `p1/t15-fix-{slug}` with a regression test **shown to fail before the fix**. A fix without a failing-first test is not accepted — it may be fixing nothing. |
| **Defer** | Names the phase it belongs to and why deferring is safe. "Phase 2 will handle it" is not a reason; "no data can be lost before phase 2 because X" is. |
| **Reject** | States the reasoning. A reviewer with no context can be wrong, and answering with reasoning rather than reflexive compliance is why triage exists. |
| **Unverified** | The reviewer could not establish it statically. Triage runs the stated experiment serially and then assigns a real disposition. |

A finding that arrives without severity, `file:line`, a concrete scenario, the
rule it violates, and the missing regression test is **sent back rather than
triaged**. Incomplete findings are where false approvals come from.

---

## Group 1 — Security

**Dispatched:** 2026-07-29 against `217a380`
**Scope:** authentication, Shield/Spatie wiring, `User` and `Role` policies, the
escalation invariants, the Action boundary, staff-account UI.

**Status:** reported and triaged 2026-07-29. Six findings, **all six confirmed by
the lead against `vendor/`, and none rejected.** Two were re-rated after
verification.

### Findings

| # | Severity | File:line | Summary | Disposition |
|---|---|---|---|---|
| 2 | **Critical** (raised from Medium) | `UserPolicy.php:113` | `assignRole()` compares the role name case-sensitively in PHP; MySQL's `utf8mb4_unicode_ci` resolves it case-insensitively, so `'Super_Admin'` passes the guard and attaches the real `super_admin` row | Fix now |
| 1 | **High** (scope widened) | `UserPolicy.php:52-149` plus four more policies | Filament returns `Response::allow()` for a **missing** policy method where Laravel's `Gate` returns `false`; five soft-deletable models have policies with no `restore`/`forceDelete`/`deleteAny` | Fix now |
| 4 | High | `DeleteUserAction.php:32`, `DeactivateUserAction.php:36` | The last-super-admin branch is chosen from an unlocked read taken outside the transaction; a concurrent role grant lands in the gap | Fix now |
| 3 | Medium | `RoleResource.php:34-45` | Inherits Shield's table, whose inline `EditAction` persists with a bare `$record->update()` and never reaches `UpdateRolePermissionsAction` | Fix now |
| 5 | Medium | `AdminPanelProvider.php:79-98` | `ForcePasswordChange` and `AuthenticateSession` are not persistent, so neither runs on `/livewire/update` | Fix now |
| 6 | Medium (raised from Low) | `PasswordChange.php:37-53` | Self-service password change requires no current password, converting session access into permanent credential ownership | Fix now |

**Finding 2 was demonstrated at the Action boundary.** A throwaway probe had an
`admin` call `SyncUserRolesAction::execute($admin, $puppet, ['Super_Admin'])`: no
exception was thrown and `roles_after` came back `['super_admin']`.

**Scope of that claim, stated precisely.** The escalation is proven at the Action
boundary and nowhere else. Filament's `Select` `in` validation still rejected that
exact payload through the UI as shipped, and **no crafted-Livewire experiment was
run**, so this was never shown to be remotely exploitable through the panel. The
severity is Critical on the boundary's own terms: `UserResource.php:50-56`
disclaims the options list as a control — "FILTERING THE OPTIONS LIST IS NOT THE
CONTROL ... Every one of these paths therefore re-authorizes inside its Action on
execute; that is the boundary" — and `SyncUserRolesAction` is an injectable public
service, so a console command, a queued job or a phase-3 portal controller reaches
the defeated guard with no `Select` in front of it. A boundary that holds only
because something in front of it happens to filter is not a boundary.

**Finding 1 is wider than group 1's scope could see.** The reviewer correctly
stayed in its lane and reported `UserPolicy`. The same gap exists in
`StudentPolicy`, `BatchPolicy`, `EnrollmentPolicy` and `CoursePolicy` (no
`deleteAny`, `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny`), and in
`StaffProfilePolicy` and `StaffCertificatePolicy` (no `restore`/`forceDelete`),
while `User`, `Student`, `Batch`, `Enrollment` and `StaffProfile` all use
`SoftDeletes`. Only `RolePolicy` and `ActivityPolicy` are complete. Nothing is
reachable today — no `TrashedFilter`, `RestoreAction`, `ForceDeleteAction` or
bulk action is rendered anywhere — so this is a loaded trap rather than an open
door, and the fix must include an architecture test, because the next person to
add the standard Filament soft-delete idiom is the one who springs it.

### Unverified items

| # | Claim | Experiment | Result |
|---|---|---|---|
| 1 | Filament's `Select` `in` rule blocks the case-variant from the UI | Drive `EditUser` with `roles => ['Super_Admin']` as an admin | **Still open, and it bounds what may be claimed.** The Action-layer probe settled severity without it and the fix landed regardless — but until this runs, no remotely exploitable Filament route has been demonstrated. |
| 2 | Behaviour of Shield's inline `EditAction` on the roles table | Drive `callTableAction('edit', ...)` and observe | **Moot.** The action no longer exists: `RoleResource::table()` replaces Shield's record actions and empties the toolbar. |
| 3 | Whether the `ForcePasswordChange` bypass is reachable by a fresh attacker rather than only a stale open page | Obtain a snapshot from the exempt page, drive another component | Open — the stale-page scenario already justifies the fix |

### Resolution

All six fixed on `p1/t15-security-fixes`, six commits, merged to `main` as
`1afdb7f`. **Every regression test was run and seen to fail before its fix
existed** — 23 of the 29 new cases failed on the first run, covering all six
findings.

Two durable guards came out of it rather than point fixes:

- `tests/Feature/PolicyAbilitySurfaceTest.php` — every policy must state an answer
  for every Filament ability, and the bulk abilities must refuse rather than
  merely exist. Both halves mutation-tested: removing a method fails the first,
  returning `true` fails the second.
- `app/Domain/Staff/Support/RoleSet.php` — role identity resolved to rows and
  compared by primary key, so no future caller can reintroduce a name comparison.

**Two existing tests were asserting the vulnerability was safe** and had to be
inverted: `BatchResourceTest` and `CourseResourceTest` both claimed "there is no
`*Any` method for Filament to authorize against, so it fails closed". True of the
Gate, false of the panel. They now assert through the resource, because a
gate-level assertion passes whether or not the fix is present.

**Gates on `main` at `1afdb7f`, real output:**

| Gate | Result |
|---|---|
| `php artisan test` | **781 passed**, 0 failed, 2246 assertions |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse --memory-limit=1G` | 0 errors |

**A verification failure found along the way, recorded because it matters more
than the findings.** `vendor/` had never been installed in the main checkout, so
the "748 tests passing on main" reported after the T14 merge was never a real
verification — that figure came from the T14 worktree pre-merge, and the run
attributed to `main` could not have executed. Dependencies are now installed there
and every gate above was run in the main checkout. The reviewer surfaced this
incidentally by having to work from the Composer cache.

---

## Group 2 — Domain integrity

**Dispatched:** 2026-07-29, pinned at `0f0e9e0`
**Scope:** staff files, students, courses, batches, instructor allocations,
enrolments — database constraints, transactions, locks, concurrency, UI
reachability.

**Status:** reported; all six findings confirmed by the lead. None rejected.

### Findings

| # | Severity | File:line | Summary | Disposition |
|---|---|---|---|---|
| 1 | **High** | `routes/web.php:25`, `:34` | The two private-file routes carry only `web` and `throttle`. The stock `web` group has no `AuthenticateSession`, so a stolen session survives the password reset meant to contain it, and keeps streaming scanned identity documents by sequential id | Fix now |
| 2 | **High** | `PendingFileDeletion.php:41`, `routes/console.php` | `scopeStale()` has **no caller** and no sweep is scheduled. A purge job that exhausts its retries leaves the file on disk permanently, and in every nightly backup, after the centre has decided to destroy it | Fix now |
| 3 | Medium | `FileStorageException.php:23` | Typed domain exception with no renderer and no catch: a full or read-only disk reaches the administrator as a 500 | Fix now |
| 4 | Medium | `create_staff_profiles_table.php:30` | `user_id` cascades, so a hard delete of a user destroys profile and certificate rows by database cascade with no deletion receipt, orphaning the bytes | Fix now |
| 5 | Low | `StaffCertificateDownloadController.php:72` | Validates the stored path but trusts the stored `disk`, which chooses the root that path is resolved against | Fix now |
| 6 | Low | ten files | Twelve docblocks still assert `deleteAny()` is undefined, four of them teaching the reasoning group 1 disproved | Fix now |

**Verification notes.** Route middleware enumerated at runtime:
`staff.certificates.download` and `staff.profiles.photo` gather exactly `web` and
`throttle`. The resolved `web` group is `EncryptCookies`,
`AddQueuedCookiesToResponse`, `StartSession`, `ShareErrorsFromSession`,
`PreventRequestForgery`, `SubstituteBindings` — no session-integrity middleware,
confirming finding 1. A grep for callers of the stale scope across `app/`,
`tests/`, `routes/` and `database/` returns nothing, and `routes/console.php`
schedules only the three backup commands, confirming finding 2.

**Finding 6 is self-inflicted and recent.** The group 1 fix added `deleteAny()` to
six policies without updating their file headers, so four policies now state
"leaving it undefined makes any bulk delete added later fail closed" eighty lines
above the block correcting exactly that belief. The next person to read the top of
a policy before adding a bulk action learns the wrong lesson from the file that was
just corrected to teach the right one.

**Finding 4 disposition reasoning.** No reachable path exists today:
`UserPolicy::forceDelete()` returns false and no control is registered. It is still
fix-now rather than defer, because the constraint should force a caller through
`DeleteStaffProfileAction` — which reads the paths BEFORE the cascade precisely so
the bytes can be collected — exactly as `enrollments.batch_id` and
`batch_instructor.batch_id` already restrict so history cannot be orphaned. A
trusted operation is still entitled to a constraint that fails loudly.

**What the reviewer cleared, worth recording.** The enrolment domain held up in
full: consistent lock ordering, every decision input read under `lockForUpdate()`,
and every code-level refusal also backed by a constraint where one can express it.
Money is `decimal(12,3)` throughout, with no derived column and no price on any
screen. Both file controllers re-authorize on the request that serves the bytes. No
`AttachAction`, `AssociateAction`, `DeleteBulkAction`, `RestoreAction`,
`ForceDeleteAction` or relationship-bound field exists anywhere in `app/Domain/`.

### Unverified items

| # | Claim | Experiment | Result |
|---|---|---|---|
| U1 | Whether a locking `exists()` read actually takes a lock — MySQL may drop the locking clause inside the scalar subquery. This is the load-bearing claim of `EnrollmentUpdateRule` and of "the authorization that binds is the locking one" | Two sessions: A locks the batch then runs the locking EXISTS; B inserts the matching `batch_instructor` row and must block; check `performance_schema.data_locks` for a RECORD or GAP lock | Open — **the highest-value experiment of the three.** If B does not block, the rule is served from the pre-mutex snapshot and the T11 lock test asserts SQL text rather than behaviour |
| U2 | Whether `PurgeDeletedFileJob::isOwned()` can deadlock against an in-flight upload rather than blocking on it | Force a compensation purge for a path, then hold a second upload open across that path's index gap; read the InnoDB status and `data_lock_waits` | Open |
| U3 | Whether `ViewStaffProfile` falls back to the form schema and so discloses the full account roster through the user Select | As an actor holding profile read but no user read, GET the view page and inspect for option values drawn from `users` | Open — harmless under the seeded roles, since both holders of `view_staff_profile` also hold `view_any_user` |

---

## Group 3 — Cross-cutting operations

**Dispatched:** 2026-07-29, pinned at `0f0e9e0`, concurrent with group 2
**Scope:** append-only activity log, backup and restore, localization, RTL,
hardcoded-string and CSS enforcement.

**Status:** reported. Sixteen findings. The five load-bearing ones are confirmed
by the lead; the rest are accepted on the reviewer's reasoning, which quoted
vendor source throughout and was accurate everywhere it was checked.

### Findings

| # | Severity | File:line | Summary | Disposition |
|---|---|---|---|---|
| H1 | **High** | `config/activitylog.php:20`, `:123` | `activitylog:clean` is a registered artisan command that bulk-deletes audit rows, and this project has configured it with a live 365-day retention window. The append-only rule has an application code path straight through it | Fix now |
| H2 | **High** | `AssignInstructorAction.php:107`, `RemoveInstructorAction.php:56` | Instructor-hour pivot writes produce **no audit entry at all**. A `belongsToMany` sync fires no model event and neither Action calls `activity()` | Fix now |
| M1 | Medium | `config/activitylog.php:14` | `env('ACTIVITYLOG_ENABLED', true)`, and the vendor status class has no `declare(strict_types=1)`, so a **blank** value coerces to false and silently disables the entire audit trail | Fix now |
| M2 | Medium | `RecordActivityWithContext.php:82` | `causedByAnonymous()` nulls the causer ids but never unsets the relation `associate()` loaded, so anonymous system entries still snapshot a person's name into `properties.causer_name` | Fix now |
| M3 | Medium | `config/backup.php:23`, `:385` | The bucket directory is `APP_NAME`. Renaming the app — a cosmetic change with no documented backup implication — orphans every existing archive: monitor reports healthy, cleanup never sees them, retention effectively resets to one night | Fix now |
| M4 | Medium | `config/backup.php:395` | The monitor's 5 GB ceiling is far below what the retention tiers actually store (~112 full archives including both upload roots), so the nightly health alert becomes permanent noise and the one signal the design rests on is lost | Fix now |
| M5 | Medium | `LocalizationTest.php:77` | The 22 `activity.event.*` and `activity.record_type.*` keys are built by interpolation and are outside every completeness check; their fallbacks make the miss look deliberate | Fix now |
| L1 | Low | `lang/en/activity.php:66` | Raw database column names and property keys render to users untranslated inside otherwise-translated lines | Defer to phase 4 |
| L2 | Low | `LocalizationTest.php:566` | The physical-CSS scan covers `resources/` only and filters by extension, so `app/` and `resources/js/` are unscanned; misses `rounded-l-*`, `border-top-left-radius`, and shorthand `margin`/`inset` | Fix now |
| L3 | Low | `LocalizationTest.php:603` | The hardcoded-string detector is a closed list of 21 setter names on one call shape, with no self-tests, and does not scan `resources/views/` | Fix now |
| L4 | Low | `ActivityLogTest.php:197` | The secret-exclusion test hardcodes five of the eight `RecordsActivity` models, omitting `StaffCertificate` — the one owning `disk`, `path` and `original_filename` | Fix now |
| L5 | Low | `config/filament-shield.php:180` | Shield's role form offers twelve `*_activity` write permissions the seeder never creates, on a log whose rule is that no such path exists | Fix now |
| L6 | Low | `docs/RESTORE.md:57` | The runbook's retention description omits the weekly and yearly tiers, understating real retention by about two and a half years | Fix now |
| L7 | Low | `AppServiceProvider.php:62` | The production boot guard blocks the runbook's own `migrate` and `tinker` steps during a real restore; correct guard, undocumented ordering | Fix now |
| L8 | Low | `tests/Pest.php:23` | `RefreshDatabase` is commented out globally on a database every suite shares, so a future author who forgets the opt-in leaves rows behind for whichever file runs next | Fix now |
| L9 | Low | `ListActivities.php:13` | Stale docblock: claims the resource has no view page; `getPages()` registers one | Fix now |

**Verified by the lead.** H1: `php artisan list` shows `activitylog:clean` live, and
`clean_after_days => 365` is this project's own value, not a default left alone.
H2: zero `activity()` calls in either instructor Action. M1: the `env()` call is
present, and `Spatie\Activitylog\Support\ActivityLogStatus` indeed has no
`declare(strict_types=1)`, so coercive typing turns `''` into `false`. M2: vendor
source confirms `causedBy()` calls `->causer()->associate($model)` while
`causedByAnonymous()` nulls only the two columns. M3/M4: both config values read as
described, against the additive retention tiers 60/8/12/2.

**M5 was proven by mutation, not by reading.** Deleting
`activity.event.deleted_by_cascade` from `lang/en/activity.php` and running the
whole `LocalizationTest` file produced **no failure**. That is a hole in the test
T14 called "as much the deliverable as the strings are": the scan matches literal
`__('group.key')` only, and the interpolated-key walk covers exactly four enums.
`eventLabel()` then falls back to the bare event name, so the miss renders as
plausible English rather than as a visible gap — the precise failure mode the
completeness test exists to make loud, reproduced one level up.

**H1 is the most serious finding in this group** and deserves stating plainly:
`ActivityPolicy` asserts that "no policy, no UI control and no application code
path can remove or alter an entry". A registered artisan command that issues
`DELETE FROM activity_log WHERE created_at < ?` is an application code path, and
`ENGINEERING.md` explicitly places "commands that use application code" inside the
trust boundary rather than in the raw-SQL escape hatch. The append-only test suite
proves the policy, the resource and the pages are closed and never looks at the
console surface at all.

**L1 is the one deferral.** Translating audit field names needs an
`activity.field.*` group covering every audited column plus every explicit property
key, and the values are database identifiers whose Arabic wording is a translator's
decision. Deferring is safe because nothing is lost or misreported — the line
renders, in English, inside a phase whose Arabic catalogue is deliberately empty.
It becomes phase 4 work, recorded here so phase 4 inherits it rather than
rediscovers it.

### Unverified items

| # | Claim | Experiment | Result |
|---|---|---|---|
| U1 | Whether a blank `BACKUP_S3_ENDPOINT` — which `.env.example` ships — fails loudly or silently retargets to AWS S3, sending a non-AWS deployment's archives to an unintended host | With `APP_ENV=production` and a full non-AWS `BACKUP_S3_*` set except a blank endpoint, run `backup:run` and record whether it throws at client construction, on upload, or succeeds against the wrong host | Open — decides whether this is a documentation fix or a guard addition |
| U2 | Whether Shield's role form actually renders the twelve `*_activity` checkboxes, and what saving one does | Drive `EditRole` via Livewire, assert the options contain `delete_activity`, submit it, and record whether it throws `PermissionDoesNotExist`, drops the name, or creates the permission | Open — resolve while fixing L5 |
| U3 | Whether `properties.causer_name` is in fact written for a `causedByAnonymous()` entry | One assertion in the existing system-write test: the property must be null | Open — will be settled by M2's regression test, which must fail first |

**What the reviewer cleared, worth recording.** The Filament append-only surface is
genuinely closed — no record, header, toolbar or bulk actions, `canCreate()` false,
`recordUrl()` rather than a mountable ViewAction, all twelve mutation abilities
written out false, and the tests drive the real Livewire components rather than
trusting source. Secret exclusion is correct at both layers and the documented
precedence is accurate. All eleven explicit `activity()` calls sit inside their
Action's transaction. Logging is on the model-event boundary, so ordinary Eloquent,
Filament modal writes, commands and seeders all log identically. The backup boot
guard is invoked as the first statement of `boot()`. `local` is absent from the
destination disks, the three scheduled commands share one mutex, and nothing secret
enters the archive. The literal-key catalogue is complete: 161 keys, none
unresolved, none empty, none resolving to a group. There are no physical CSS
properties and no hardcoded label literals anywhere in the repository today — L2
and L3 are about what the detectors would let through next, not about anything
shipped.

---

## Experiment results

Run 2026-07-30 against `main` at `856baf2`, **before any group 2 or 3 code was
changed**, serialized against `training_center_test`. Recorded here with the
exact observation rather than a conclusion, because two of them contradicted the
expectation that prompted them.

### G2-U1 — does `lockForUpdate()->exists()` actually take a lock? **YES. No finding.**

The load-bearing claim under `EnrollmentUpdateRule::allows(locking: true)` and
"the authorization that binds is the locking one". Two real connections, observed
blocking, not SQL text.

| Scenario | A holds | B inserts the matching row | Result |
|---|---|---|---|
| 1 — control | ordinary `exists()`, no batch lock | must succeed | **INSERTED**, 0.05 s |
| 2 — probe | `lockForUpdate()->exists()`, no batch lock | blocked ⇒ it locks | **BLOCKED**, 1205 lock wait timeout |
| 3 — context | batch `lockForUpdate()` only, no `exists` | — | **BLOCKED**, 1205 |

The locking `EXISTS` takes a gap lock and genuinely escapes the REPEATABLE READ
snapshot. **The T11 design is behaviourally correct**, and the SQL-text test that
worried the reviewer was asserting a true thing by a weak method rather than
asserting a false thing.

**The first design of this experiment was invalid and was discarded.** Its control
held a `lockForUpdate()` on the BATCH row, and B blocked — but
`batch_instructor.batch_id` is a foreign key, so InnoDB locks the parent row when
inserting a child. Control and probe both blocked, and the probe measured nothing.
Scenario 3 is that discarded design, kept as context: it shows the batch lock
alone already serializes instructor inserts through the FK, which is defence in
depth nobody had written down.

### G3-U3 — does an anonymous entry leak the signed-in user's name? **YES. M2 confirmed.**

`SystemRoleWriter` run with a super admin signed in produced:

| Field | Value |
|---|---|
| `causer_id` | `null` |
| `causer_type` | `null` |
| `properties.causer_name` | **"Signed In Person"** |

Correctly anonymous in the columns, and naming a real person in the properties.
The panel renders "System" from the null `causer_id` while the stored row names
somebody for a change they did not make — and the "Who" column searches
`properties->causer_name`, so that person's name matches system rows.

### G3-U2 — does Shield offer activity write permissions? **YES. L5 confirmed.**

`FilamentShield::getAllResourcePermissionsWithLabels()` returns 84 options, of
which **twelve are `*_activity`**, including `delete_activity`,
`delete_any_activity`, `force_delete_activity`, `force_delete_any_activity`,
`restore_activity`, `restore_any_activity` and `reorder_activity` — write
abilities offered on a log whose non-negotiable rule is that no such path exists.
The seeder never creates them, so the form advertises capabilities that cannot be
granted.

### G3-U1 — what does a blank S3 endpoint do? **Partially resolved: it fails silently.**

`config/filesystems.php:138` is `'endpoint' => env('BACKUP_S3_ENDPOINT')` and
`.env.example:77` ships the key blank. Constructing the disk with an empty
endpoint **succeeded**: `League\Flysystem\AwsS3V3\AwsS3V3Adapter` was built with
no exception.

So the actionable half is settled — **nothing fails visibly at configuration
time**, and `BackupConfiguration::REQUIRED` deliberately omits the endpoint.
Whether the SDK then resolves to a regional AWS host (sending a Backblaze or
Wasabi deployment's archives to AWS with credentials that will not authenticate)
is a live-network question that a local run cannot answer, and it does not change
the fix: the guard should require an endpoint whenever the deployment is not AWS,
or the runbook must state that an empty value silently means AWS.

**A measurement error worth recording:** the first probe reported the endpoint key
as absent. It is present with a `null` value — `$disk['endpoint'] ?? '(absent)'`
cannot tell null from missing. The corrected reading is above.

### Still to run

- **G2-U2** — whether `PurgeDeletedFileJob::isOwned()` can deadlock against an
  in-flight upload rather than blocking on it. Needs a held-open upload
  transaction across the `(disk, path)` index gap on a second connection.
- **G2-U3** — whether `ViewStaffProfile` falls back to the form schema and
  discloses the account roster.
- **G1-U1** — whether Filament's `Select` `in` rule blocks the case-variant
  through a crafted Livewire payload. This one bounds a claim rather than a fix:
  the escalation is already fixed and already proven at the Action boundary.

---

## Known context supplied to reviewers

Recorded here because it shapes what the findings can be trusted to mean.

**The permission-naming erratum.** `CLAUDE.md` illustrates the authorization rule
as `$user->can('students.delete')`. No permission of that shape exists — Shield
generates snake-case and `RolePermissionSeeder` creates `delete_user`,
`view_any_student`, `create_enrollment`, `update_assigned_batch_enrollment`. Every
reviewer is told this, because one matching the documented example literally
would flag every correct call in the codebase. **`CLAUDE.md` should be corrected
in T16** — this erratum is a workaround, not a fix.

**The documented role-check exception.** `UserPolicy::outranks()` and
`assignRole()` check the super-admin role deliberately, expressing rank rather
than ability; see the docblock at `app/Domain/Staff/Policies/UserPolicy.php:30`.
Reviewers are told it is intentional **and explicitly invited to challenge its
safety** — the instruction forbids reporting it as an unnoticed oversight, not
reporting it as unsafe. A finding that shows `isSuperAdmin()` can be fooled is
exactly what group 1 is for.

**Static review only.** All suites share one MySQL database,
`training_center_test`, and `RefreshDatabase` runs `migrate:fresh` against it.
Concurrent reviewers running tests would drop each other's schema and produce
failures belonging to nobody — which a reviewer with no context would reasonably
read as "the code is broken". Runtime verification is therefore central and
serialized.
