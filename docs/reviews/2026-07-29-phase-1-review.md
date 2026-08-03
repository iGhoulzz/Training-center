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

### Resolution — findings 3, 5 and 6

Fixed on `p1/t15-storage-errors-and-doc-drift`, five commits, **branched from
`main` rather than from the unmerged sweep branch**: the file scopes do not
overlap, and basing on it would have put the sweep's whole diff inside this
branch's review. Findings 1, 2 and 4 are recorded against their own branches.

**Finding 3 — a storage failure had no answer.** Handled at both levels, the same
way `LastSuperAdminException` already is. `bootstrap/app.php` renders it as
**503**: the request was well-formed and the actor entitled, so the honest code is
"the server's storage cannot accept this, retry" rather than 500. The exception's
own message is deliberately **not** used — it carries the disk name and stored
path for the log, and a response body is not a log.

The two Filament write paths catch it and raise a translated notification,
because the renderer is the wrong answer inside the panel: it would replace the
page with a bare status response and lose the rollback with it. **The rollback is
what the photo test asserts, not the notification text.** Without it the job title
commits while the photo silently does not, leaving a record the administrator
believes they updated in full.

`PurgeDeletedFileJob` is deliberately untouched and a test now says so. Throwing
is how it retries, and a renderer reaches HTTP paths only — "handle the exception
everywhere" is exactly the change that would quietly convert a retryable
data-destruction failure into a silent success.

**Finding 5 — the download route trusted its own row's disk.** The path guard and
the disk are not independent guards: `staff-certificates/x.pdf` is a perfectly
contained path under every root there is, so a foreign `disk` value did not BREAK
the containment rule — **it moved the boundary the rule was measured against.**
And unlike a traversal, which Flysystem refuses as a second line of defence,
reading a contained path from the wrong disk is a completely legitimate
filesystem operation that nothing downstream objects to; this controller was the
only place it could be refused. An allowlist rather than the Action's constant,
because the `disk` column exists precisely so a later move does not orphan the
rows already written.

The regression test was **seen to return 200 and stream the canary** before the
fix, and asserts the body lacks that canary rather than only the status.
`StaffProfilePhotoController` needed no change: it already resolves the Action's
constant rather than any stored value.

**Finding 6 — and the guard that matters more than the wording.** Ten files
corrected. Four had gone past staleness into teaching the reasoning group 1
disproved, eighty lines above the block correcting that very belief.

A wording fix alone would drift again, so the claim is now enforced.
`PolicyAbilitySurfaceTest` already guarantees every policy states an answer for
every ability, which makes **any** comment asserting otherwise false by
construction — so a scan can simply reject all of them. The detector is a named
function with two sample sets rather than an inline regex, per the rule T14 paid
for; its ability list excludes bare `view`/`create`/`update`/`delete`, which are
ordinary English in this codebase's prose and would fire on sentences making no
claim about the policy surface. `appCommentsOnly()` joins
`appSourceWithoutComments()` in `tests/Pest.php` — the exact inverse, because a
test checking what comments CLAIM must see only comments, or
`public function deleteAny()` satisfies the search for "no deleteAny()".

**Round two found a cross-namespace disclosure the disk fix did not close.**
The disk guard settled WHICH ROOT a path resolves against and said nothing about
WHERE UNDER IT — and **the private disk is shared.** Staff photos sit beside
certificates today, and the `pending_file_deletions` migration states that phase
2 receipts and phase 3 student certificates will reuse it. A certificate row
naming `staff-photos/{ULID}.png` was perfectly contained, passed the disk check,
and made the route authorize the CERTIFICATE while streaming the PHOTO. Confirmed
by driving it: a certificate-only actor received **200 and the photo bytes**.

`StaffProfilePhotoController` has always required its own generated shape, so the
asymmetry was the defect. The route now requires parity, and the extension
alternation is **derived from `UploadStaffCertificateAction::EXTENSIONS`** rather
than copied — a hand-maintained list would silently 404 an entire credential type
the day that map grows, and the symptom would look like missing files rather than
a stale regex.

**Mutation testing found the first dataset incomplete, and the gap was real.**
Dropping the two-segment requirement broke nothing, because every malformed
sample happened to put something non-ULID in the SECOND segment: the shape regex
caught them all and the count never got a say. A valid certificate name with a
segment after it passes both checks, and the bytes are reachable when a directory
of that name exists on disk. The sample was added rather than the check removed.

Two non-blocking items from the same round: the storage-failure tests now assert
`assertTableActionHalted()` and `assertNotified()` — the row count alone also
passed for an action that failed silently and closed the modal — and the drift
detector is renamed `claimsABulkOrSoftDeleteAbilityIsUndefined()` with its gap
stated outright, having been named for more than it covered.

**Mutation testing: ten mutations, ten caught** (seven in round one, three on the
shape guard). Disk allowlist removed;
renderer made never to match; each Filament catch removed separately; a stale
claim reintroduced; a detector rule deleted; the detector over-broadened to bare
`delete()`. The last two are the pair that matters — deleting a rule fails the
must-catch set, over-broadening fails the must-not-catch set.

---

### Resolution — findings 2 and 4

Both fixed on `p1/t15-file-deletion-sweep`, eight commits. **The other four
findings in this group are not in that branch's scope**: finding 1 landed earlier
on `p1/t15-private-file-access`; findings 3, 5 and 6 were handled separately on
the storage-errors branch recorded above.

**Finding 2 — the sweep that did not exist.** `files:sweep-pending-deletions`
re-dispatches purge jobs for receipts past a staleness threshold, scheduled hourly
on its own mutex. Three decisions are recorded because the code alone does not
argue for them:

- **The ownership check is always on.** The table holds ordinary deletion receipts
  and provisional upload receipts and **nothing on the row says which**, so a
  sweep dispatching the unchecked form would unlink bytes a committed row still
  owns. The checked form is correct for both, because an ordinary receipt has no
  surviving owner for the check to find. Proven as a pair in
  `FileLifecycleTransactionTest` — owned bytes survive, orphaned bytes do not.
  That pair has to live there rather than beside the other sweep tests: the job's
  ownership read runs on an independent connection and cannot see rows
  `RefreshDatabase` is holding uncommitted, so under the usual wrapper it would
  report every file unowned and the test would pass for the wrong reason.
- **The threshold outlives the job's retry ladder**, asserted against `backoff()`
  rather than repeated as a literal, so lengthening the ladder fails the build.
- **No attempt ceiling.** Abandoning a receipt would leave a document the centre
  is no longer entitled to hold on disk forever — the outcome the feature exists
  to prevent. The bound is on the batch size instead, and it rotates.

**The bound did not rotate on the first attempt, and Codex caught it.** The run
selected its page by `created_at`, which never changes, so a page that never
drained was selected again by every subsequent run and the receipts behind it
were never dispatched at all — the bound became a permanent ceiling rather than a
rate limit. `pending_file_deletions.last_swept_at` is now stamped after each
successful dispatch; `scopeStale()` additionally requires never-swept or
swept-longer-ago-than-the-threshold, and `scopeInSweepOrder()` puts never-swept
receipts ahead of every swept one. **Neither half works alone** — with only the
filter, a stamp aging past the threshold makes the head eligible again and a
`created_at`-led order picks it ahead of everything behind it, which is the same
starvation an hour later. Each half has its own test.

**The part worth carrying forward is not the defect but the claim.** Both the
command's comment and this document stated "successive runs will reach them".
That sentence was written from the intent and never from the behaviour, and **no
single-run test can tell those apart** — starvation only becomes visible on a
second run. The single-run ordering test below looked like coverage of exactly
this property and was not.

Two handoff guarantees were missing and are now pinned. **Dispatch, then stamp**:
a stamp written first marks a receipt as handed off when it was not, so a queue
outage would push the whole backlog a full threshold into the future for work
that never happened. A dispatch failure leaves `last_swept_at` untouched, is
reported and counted, and the rest of the page is still attempted; the run exits
non-zero. **Duplicate purge execution is a non-event**, pinned in both shapes —
the receipt already gone, and the receipt still present with the bytes already
unlinked. The second matters most, because it rests on `Storage::delete()`
reporting success for an absent file, which is the assumption
`PurgeDeletedFileJob` makes when it treats a `false` return as a real failure.

Joining the backup pipeline's mutex was considered and **rejected**: it would let
a slow or stuck nightly backup hold file deletion off for hours, and buys nothing,
because ordinary purge jobs already run at arbitrary times including mid-archive.
A test pins the separation so the decision is not silently reversed.

G2-U2's verified locking behaviour is untouched. The sweep decides which receipts
reach `PurgeDeletedFileJob`, never what it does once they arrive.

**Finding 4 — the cascade.** `staff_profiles.user_id` now restricts.
**Two existing tests were asserting the defect was the feature** and are inverted:
`StaffProfileTest`'s "deletes the profile when the user is force deleted" and
`StaffCertificateTest`'s "deletes the certificates when the user account is force
deleted — two cascades in a row". The profile→certificate cascade is deliberately
left in place; it is safe precisely because an Action now always stands in front
of it.

**A third test needed correcting for a different reason, and it is the more useful
lesson.** `InstructorHoursTest`'s `makeInstructor` gives every instructor a staff
profile, so once profiles restricted, its allocation-restriction test began
raising 1451 from `staff_profiles` — it would have kept reporting
`batch_instructor.user_id` as enforced even if that constraint were reverted to a
cascade. Both it and its control now clear the profile first, and the refusal
asserts which table refused. Verified by reverting `batch_instructor.user_id`: the
test fails, as it must. **Adding a constraint can silently hollow out an unrelated
test that was pinning a different one.**

**Mutation testing: eighteen mutations, seventeen caught, one survived.** Twelve
on the original branch, six more on the rotation repair (stamp removed,
nulls-first term removed, whole sweep order removed, eligibility condition
removed, stamp moved before dispatch, dispatch failure rethrown).

The survivor is the part worth keeping. Deleting the whole `ORDER BY` from the
sweep left the ordering test green. The first explanation — that InnoDB was
returning primary-key order — was **wrong**, and rearranging the fixtures so age
and insertion order disagreed changed nothing. `EXPLAIN` settled it: `type:
range, key: pending_file_deletions_created_at_index`. The staleness filter is
served by an ordered range scan over `created_at`, so rows arrive oldest-first
whether or not the code asks, and **no arrangement of data can make that test
fail.** It now asserts the clause itself, with the measurement recorded in place
of the assumption: the clause is the only thing that can fail when somebody
removes it, and the plan hiding its absence is an optimizer choice rather than a
guarantee.

A second trap, already documented in `tests/Pest.php` and walked into anyway:
`expect()->toContain()` is variadic, so a failure message passed as its second
argument becomes a second expected value.

**Round two found the same starvation through the error path.** The catch that
kept one poisoned receipt from blocking the page called `report($exception)`
unguarded — and Laravel's handler may throw when its logging transport is
unavailable. The exception escaped the catch, ended the loop at the first failed
receipt, and left it unstamped and still first in sweep order, so every later run
stopped on it again.

**The two failures are correlated rather than independent**, which is what makes
it realistic: a full disk is the condition this feature exists to reconcile, and
it takes the log channel down with it.

**And the codebase already knew.** `FileLifecycleService::reportWithoutThrowing()`
exists for this exact hazard and its docblock says so. This was an established
pattern not applied, not a subtle one missed — the general lesson being that a
hazard solved once in a codebase should be searched for, not rediscovered.

Two smaller notes recorded so they are not re-litigated:

- **Counting the failure before reporting it is not load-bearing**, and the
  comment says so. Once the helper cannot throw, the order changes no outcome and
  no test pins it; a mutation moving it survives, correctly. It is kept only as
  cheap insurance against a later edit restoring a bare `report()`.
- **The helper is a near-copy of the service's rather than a shared extraction.**
  Extracting means editing that service — well-reviewed, unchanged on this
  branch, no behavioural gain from the move. A third caller is the point at which
  it should become one thing; **raised for T16**.

**Gates on the storage-errors branch tip, real output:**

| Gate | Result |
|---|---|
| `php artisan test` | **835 passed**, 0 failed, 2365 assertions |
| `vendor/bin/pint --test` | passed |
| `composer analyse` | 0 errors |

**Group 2 is now fully dispositioned** across three branches: finding 1 on
`p1/t15-private-file-access` (merged), 2 and 4 on `p1/t15-file-deletion-sweep`
(merged), and 3, 5 and 6 here. Both branch histories are retained in this log.

**Gates on the file-deletion-sweep branch tip, real output:**

| Gate | Result |
|---|---|
| `php artisan test` | **829 passed**, 0 failed, 2380 assertions |
| `vendor/bin/pint --test` | passed |
| `composer analyse` | 0 errors |

**A verification hazard this task hit for real, recorded because the log already
warned about it and it happened anyway.** Both T15 worktrees share
`training_center_test`, and `RefreshDatabase` runs `migrate:fresh` against it. A
review run and an implementation run overlapping produced 27 errors of the shape
`Table 'users' already exists` / `migrations doesn't exist` / a deadlock on
`drop table` — failures belonging to neither branch, in a shape that reads like
broken code. **Runs across worktrees must be serialized until each worktree has
its own test database.** Pint and PHPStan are unaffected, being database-independent.

Codex independently verified the pre-rotation tip at 822 tests / 2352 assertions,
Pint and PHPStan clean, and approved everything except the starvation finding —
including the restrictive staff-profile foreign key and the corrected
instructor-allocation test.

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

### Resolution — H1, H2, M1, M2, L4, L5 and L9

Fixed on `p1/t15-activity-log-integrity`, seven commits from `main` at `e252f14`.
**Group 3's remaining findings (M3, M4, M5, L2, L3, L6, L7, L8) are not in this
branch's scope** and are planned as `p1/t15-backup-retention` and
`p1/t15-detector-coverage`, run sequentially — the three share `AppServiceProvider`
and one test database.

**H1 — the append-only rule had a live delete path.** `activitylog:clean` was
registered and issuing `DELETE FROM activity_log WHERE created_at < ?` against a
365-day window this project had set itself. **The refusal goes in the ACTION, not
the command**: `Config::cleanActivityLogAction()` is what a queued job or another
package resolves, with no artisan invocation in front of it — the same shape as
`SyncUserRolesAction` being reachable with no Filament `Select` in front of it.
It throws rather than returning zero, because returning zero prints "Deleted 0
record(s) … All done!" and exits successfully, which reads as a retention policy
that ran and found nothing.

Two barriers, and **mutation testing proved neither redundant**: `clean_after_days`
is null so the bare command fails its own validation early and legibly, which does
not survive an explicit `--days=30`; the action covers that and does not make the
bare command fail cleanly. Restoring either one alone fails a different test.

**H2 — instructor hours had no audit entry at all.** A `belongsToMany`
`syncWithoutDetaching()`/`detach()` fires no Eloquent event, so `LogsActivity`
never saw these writes and neither Action called `activity()`. This is what phase 2
pays wages from — the one place "who changed this, and to what" is a money
question. Three distinct events, with the previous figure recorded on a change,
because "who moved Sara from 18 to 30" is what a payroll dispute asks. Both reads
happen before their write, since afterwards the old figure is gone.

**M1 — a blank `ACTIVITYLOG_ENABLED` disabled all recording**, silently: nothing
failed, nothing warned, and the panel kept rendering entries written before the
deploy. **The first fix reproduced the bug exactly.** `FILTER_VALIDATE_BOOL`
treats `''` as a recognised FALSE, listed alongside `'0'` and `'off'`, so
`FILTER_NULL_ON_FAILURE` never fires for it and a `filter_var`-only fix changes
nothing. The blank is handled before the filter.

**M2 — system entries named a real person.** `causedByAnonymous()` nulls the
causer columns and leaves the relation `causedBy()` associated, so the recorder
still found a Model and snapshotted its name. The panel renders "System" from the
null `causer_id` while the row names somebody for a change they did not make, and
the Who column searches `properties->causer_name`. The column now gates the
snapshot. Pinned in both directions — never writing `causer_name` fails three
existing tests that depend on the snapshot surviving a deletion or a rename.

**L4 — and the mutation that made it more than cosmetic.** The secret-exclusion
test named five models by hand while eight use `RecordsActivity`, and the misses
included `StaffCertificate`, the only one carrying `disk`, `path` and
`original_filename`. A coverage fix passes on arrival and proves nothing, so it
was run both ways: injecting `password` into
`StaffCertificate::auditedAttributes()` **fails the derived test and passes the
old hand-written one.** The hole was real. The test also asserts the scan is
non-empty and contains `StaffCertificate`, because an empty array satisfies every
assertion inside the loop.

**L5 — and a decorative config it exposed.** Shield's role form offered twelve
`*_activity` write permissions on a log with no write path. Nothing was
exploitable — `ActivityPolicy` refuses all of them — but it teaches the wrong
thing, and the next person to act on that belief builds a feature to match.
Fixing it revealed that **`policies.merge` was true, which combines a resource's
`manage` list with the full default set instead of replacing it — so the existing
entry restricting `RoleResource` to five abilities had never taken effect.**
`merge` is now false, which is what the config's own comment already claimed.
`manage` rather than `exclude`, because excluding the resource would also remove
the two read permissions the seeder does create.

**L9** is editorial: `ListActivities`' docblock claimed no view page existed while
`getPages()` registers one. No test of its own — the view page's read-only
behaviour is already covered — but it mattered, because somebody auditing the
write surface would have taken the file's word for it.

**Mutation testing: seventeen mutations, seventeen caught**, including both
directions of M1, M2, L5 and the unchanged-hours guard, plus the two-way L4 pair
above. One dataset gap was found and closed rather than argued away: no test
detached an instructor holding no allocation, so logging a removal that never
happened was unpinned.

**Codex's round on this branch found two more audit-integrity defects in H2's own
work, and both were about recording things that were not true.**

- **A non-change logged as a change.** Assigning the same hours twice wrote
  `instructor_hours_changed`. Resubmitting an unchanged form is ordinary, and a
  reader settling a payroll dispute cannot tell that entry from a real one. **The
  rule already existed on the other Action** — `RemoveInstructorAction` refuses to
  log a detach that removed nothing — and was simply missing here. The pivot write
  is skipped with it.
- **A caller-supplied name in the audit trail.** The removal entry read
  `instructor_name` from the `$instructor` argument, so an unsaved edit on the
  caller's instance stored a name the database never held. Every property now
  comes from the row the Action queried itself. `AssignInstructorAction` needs no
  equivalent change, because it takes an id and reloads under a lock — now stated
  in the code so the asymmetry does not read as an oversight.

The general lesson, and it is the same one twice: **an audit trail must record
only what actually changed, and only values it read itself.** A false entry is
worse than a missing one, because nothing distinguishes it from a true one.

**Gates on the branch tip, real output:**

| Gate | Result |
|---|---|
| `php artisan test` | **876 passed**, 0 failed, 2481 assertions |
| `vendor/bin/pint --test` | passed |
| `composer analyse` | 0 errors |

### Resolution — M3, M4, L6 and L7

Fixed on `p1/t15-backup-retention`, branched from `main` at `2049eb5` — that is,
**after branch A merged**, so the diff reflects the tree it will land on and the
two do not collide in this file. **M5, L2, L3 and L8 are not in scope** and are
planned as `p1/t15-detector-coverage`.

**M3 — a rename would have orphaned every archive.** The destination directory
and the directory the monitor inspects were both `env('APP_NAME')`. Every
consequence was invisible: tonight's backup succeeds, the monitor finds that one
fresh archive and reports healthy, cleanup never sees the old directory again so
nothing is deleted and nothing is reported. **Retention silently resets to one
night**, and the first sign is a restore that finds two years missing.
`BACKUP_ARCHIVE_NAME` is now its own setting, defaulting to the name
`docs/RESTORE.md` already told operators to look for.

**M4 — the storage alert fired below normal operation.** 5 GB against tiers that
keep roughly a hundred full archives, each holding the database and both upload
roots. **A threshold below steady state is worse than none**: it fires every
night for ever, people learn to ignore the backup alert, and the one signal this
design rests on is lost in the noise it generates. The ceiling is now computed
from the tiers themselves — hoisted into variables so the two cannot drift — and
is bounded on both sides, because raising it to infinity would satisfy the fix
and remove the growth warning `config/backup.php` promises elsewhere.

**L6 and L7 are documentation, and both are tested.** The runbook now carries the
full retention table, with each figure read from config so changing a tier
without updating the document fails the build. And it records that
`assertReadyForProduction()` gates every artisan command including the `migrate`
the runbook itself prescribes — quoting the exact error, because on a rebuilt
server that message appears in the middle of restoring from a backup and reads
like a broken restore rather than a working guard.

**A vacuous test was written and discarded on the way, and the trap is worth
recording.** The `$_ENV` override technique used throughout
`BackupConfigurationTest` **works only for a key that was absent at bootstrap** —
Laravel's Env repository is immutable, so `APP_NAME`, which is in `.env`, keeps
its original value however `$_ENV` is written afterwards. The first M3 test
therefore passed against the unfixed code. The "must not derive from `APP_NAME`"
half is now asserted against the config source with comments stripped, which is
the only thing that can fail, and the positive half through
`BACKUP_ARCHIVE_NAME`, which really is absent. **Checked rather than assumed:
`ACTIVITYLOG_ENABLED` and `BACKUP_ALERT_EMAIL` are both absent from `.env`, so
their existing tests are sound.**

`expect()->toContain()` caught me a second time — it is variadic, so a failure
message passed as its second argument becomes a second expected value. Fixed, and
the whole suite scanned; the one remaining match is a legitimate multi-value
assertion.

**Codex's round found four assertions that overclaimed, and one comment that was
simply wrong.** Every one is worth recording, because the code was right and the
tests were not — the failure mode this whole review exists to catch.

- **The runbook check read the wrong setting.** `backup.name` is the bucket
  DIRECTORY; filenames come from `destination.filename_prefix`, which was a
  *third* hardcoded copy of the same word. Changing the prefix left the test
  green and made the runbook wrong. The prefix is now derived from the same
  name, and a second test pins the two together.
- **The retention check searched for bare numbers.** Deleting the weekly and
  yearly rows changed nothing, because `8` and `2` appear all over the document.
  Each row is now rebuilt from config and matched whole.
- **The storage alert only had to sit below 1 TB**, which a headroom of twenty
  would satisfy while making the check blind to the growth it exists to see. The
  multiplier is now a named config value, asserted as an exact product and
  bounded at both ends.
- **The L7 assertion proved nothing about ordering.** Finding the error message
  and `BACKUP_S3_BUCKET` *somewhere* in a document that already named both is not
  evidence that the operator is told to set them BEFORE running `migrate`. The
  text between the migrate command and the next step is now extracted and must
  carry ordering language, the variable, and the exact error.
- **Two new settings were undocumented.** `BACKUP_ARCHIVE_NAME` and
  `BACKUP_EXPECTED_ARCHIVE_MB` are now in `.env.example`, with the warnings that
  matter: renaming the archive on a live deployment orphans the bucket unless the
  directory is moved first, and the expected size should be MEASURED from real
  archives rather than left at a guess.

**And a factual correction, not a stylistic one.** The comment called the
retention periods overlapping and the archive total an over-estimate.
`DefaultStrategy` builds each period where the previous one ends
(`subDays($keepAll)` then `subDays($keepAll)->subDays($keepDaily)`), so they are
successive and the total of 112 is exact.

**Mutation testing: nine mutations, nine caught.** Four on the original work —
including both bounds of the storage alert and the monitor/destination coupling,
which mattered because that assertion passed on arrival while both names still
came from `APP_NAME` — and five replaying the reviewer's own scenarios: the
prefix drifting, each retention row deleted, the headroom widened from 2 to 20,
and the ordering language removed.

**One more test-shape lesson.** The tightened L7 assertion first failed because
the phrase it searched for was split across a line break by a blockquote marker.
Markdown wraps prose wherever the author stopped, so a test matching raw text is
dictating where the document wraps rather than checking what it says. The section
is now flattened — quote markers stripped, whitespace collapsed — before matching.

**A third round found three more, and the first is the one worth remembering.**

- **One fix broke another.** Documenting `BACKUP_ARCHIVE_NAME` in `.env.example`
  — the correction from the previous round — means a fresh install copies it into
  `.env`, which puts the key in the bootstrap set, which is *exactly* the
  condition under which the `$_ENV` override technique does not work. **The
  round-three fix invalidated the round-two one**, and the test then failed on
  any machine with a normal `.env`, before reaching any application behaviour.

  The override now runs in a **subprocess** with the variable supplied before
  Laravel boots. Dotenv's immutable loading leaves an existing environment
  variable alone rather than overwriting it from `.env`, so the passed value wins
  regardless of what the developer has locally. It also makes the *negative* half
  provable: `APP_NAME` can now be set before boot, so "a rename must not move the
  archive directory" is asserted behaviourally rather than only by scanning
  source. Verified by adding the key to `.env` and re-running — still green.

- **The archive count is not exact, and the comment was wrong in the opposite
  direction from the one before it.** The periods are successive, but
  `DefaultStrategy` keeps one backup per CALENDAR GROUP (`YW`, `Ym`, `Y`), so
  eight weeks can touch nine ISO weeks, twelve months thirteen calendar months,
  and two years three calendar years. Each calendar-grouped tier now carries a
  +1 — 115, described as a conservative upper bound. Pinned as an equality rather
  than a floor, because a floor passes for an estimate with the allowance dropped.

- **The migrate warning sat below the command it warns about.** An operator works
  down a runbook and runs each block as they reach it, so that warning is read
  after the failure it predicts. Moved above, and the test now asserts position
  rather than presence — locating the fenced command rather than a mention of it,
  since the warning text itself names artisan commands.

**Running total: thirteen mutations, thirteen caught**, plus one control — the
reproduction Codex supplied, with the key present in `.env`, must leave every
test passing, and does.

**A fourth round found one more, and it was a documentation defect rather than a
test problem.** With `BACKUP_ARCHIVE_NAME` set to a custom value, the runbook
said "pick the newest `training-center-*.zip`" while the bucket held
`preexisting-from-env-*.zip`. **A runbook that names the wrong file is read
during an incident**, by somebody who then concludes the backups are gone.
`.env.example` explicitly invites changing that name, so coupling a static
document to a per-deployment value was the mistake — the runbook now names the
SETTING and states its default, and the test asserts that rather than requiring
the current machine's value to appear in a static document.

The default now lives in one place, `BackupConfiguration::DEFAULT_ARCHIVE_NAME`,
because four things must agree about it: the env default, `.env.example`, the
runbook, and the test. Written out four times, the runbook is the copy that goes
stale.

**Fixing it surfaced a real bug introduced by the previous round.** `env()`'s
default covers a MISSING key, not an empty one — the M1 trap, reproduced here the
moment `.env.example` started shipping these keys. A blank `BACKUP_ARCHIVE_NAME`
would write every archive to the bucket root with the monitor looking there too:
**the M3 failure with no rename required.** A blank `BACKUP_EXPECTED_ARCHIVE_MB`
is sharper still — `(int) ''` is `0`, sizing the storage alert at zero megabytes
and reporting every backup unhealthy from the first night. Both now fall back,
non-numeric input included, and both are tested.

**And one mutation survived, in the same file and the same shape as a finding
already fixed.** Deleting the setting name from step 1 alone changed nothing,
because the word still appeared elsewhere in the document — presence rather than
position, exactly what the migrate-ordering assertion had already been corrected
for. The assertion is now scoped to the step that tells an operator which file to
download.

**Final total: nineteen mutations, nineteen caught**, plus two environment
controls — a custom archive name and a blank one both leave the whole suite green.

**The theme across all four rounds is one thing.** Every finding was a test or a
comment that claimed more than it enforced: the wrong setting read, bare numbers
matched, a range where an equality was needed, presence where ordering was meant,
an environment assumption that a later fix invalidated, and two successive wrong
descriptions of the same vendor algorithm. The last round is the exception that
proves it: there the *document* was wrong, and the test was right to notice.

**Twice a fix created the next round's finding** — documenting the setting broke
the override test, and shipping the keys in `.env.example` made blank values
reachable. Neither was visible from inside the change that caused it.

**Gates on the branch tip, real output:**

| Gate | Result |
|---|---|
| `php artisan test` | **888 passed**, 0 failed, 2528 assertions |
| `vendor/bin/pint --test` | passed |
| `composer analyse` | 0 errors |

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

### G2-U2 — can the purge job deadlock against an in-flight upload? **NO. No finding.**

Two connections. A plays the upload: insert the certificate row naming a path,
then lock the owning profile row — `UploadStaffCertificateAction`'s order — held
open and uncommitted. B plays the job: the two locking ownership reads in
`PurgeDeletedFileJob::isOwned()`'s order.

| Observation | Value |
|---|---|
| B's outcome | **BLOCKED**, 1205 lock wait timeout, 3.01 s |
| Rows left behind after rollback | **0** |

B waits for the owner, which is exactly what the design says it does. No cycle,
no victim, no possibility of the job purging bytes an uncommitted upload still
intends to use. **The deferred-deletion ownership check is correct.**

Run under the safety constraints imposed for it: fake paths only, no file on disk
touched, every transaction rolled back, and no `pending_file_deletions` receipt
written or removed — so no receipt was ever at risk of being lost to an uncertain
outcome.

*(A first attempt failed on invented column names — `mime_type`, `size_bytes`.
The real table is `id, staff_profile_id, title, issued_on, expires_on,
original_filename, disk, path, created_at, updated_at`. Read from
`Schema::getColumnListing()` rather than guessed the second time.)*

### G2-U3 — does the profile view page leak the account roster? **YES. Confirmed, and it is a real leak.**

Tested with a **synthetic** role, because no seeded role can show this: everyone
holding `view_staff_profile` also holds `view_any_user`. The probe role held
`view_any_staff_profile`, `view_staff_profile` and `access_admin_panel` — and no
user permission at all.

| Observation | Value |
|---|---|
| `GET /admin/staff-profiles/{id}` | **200** |
| Viewer holds `view_any_user` | **false** |
| Canary account name present in the HTML | **true** |
| `<option>` tags rendered | 4 |

`ViewStaffProfile` declares no schema and `StaffProfileResource` defines no
`infolist()`, so Filament falls back to the form — including
`Select::make('user_id')->options(User::query()->pluck('name', 'id'))`. Every
account name is disclosed to an actor with no permission to see any user.

Latent under today's seeding and **not** latent under the next role somebody
adds, which is the shape of every finding in this review. Raise to **Medium**:
the view page needs its own `infolist()` rather than the form fallback.

### G1-U1 — is the case-variant reachable through the form? **Settled, and it changes no disposition.**

Driving `EditUser` with `roles => ['Super_Admin']` as an `admin`:

| Observation | Value |
|---|---|
| `save()` | returned without throwing |
| `isSuperAdmin()` afterwards | **false** |

The account is not escalated: the Action refuses, as it now must. Which of the
two — Filament's `Select` validation or `UserPolicy::assignRole()` — did the
refusing was not isolated, and deliberately so: isolating it would mean reverting
the fix, and the answer cannot change any disposition now.

What this does settle is the **claim boundary**. No remotely exploitable Filament
route was demonstrated at any point, and the Critical rating continues to rest
where the log already says it rests: on `SyncUserRolesAction` being an injectable
public service that a command, job or portal controller reaches with no `Select`
in front of it.

### Summary of the seven

| Experiment | Result |
|---|---|
| G2-U1 locking `exists()` | **Clears the code.** It locks. |
| G2-U2 purge race | **Clears the code.** It blocks; no deadlock. |
| G2-U3 profile view fallback | **Confirms a leak.** Raised to Medium. |
| G3-U1 blank S3 endpoint | **Confirms the concern.** Constructs silently. |
| G3-U2 Shield activity permissions | **Confirms L5.** Twelve write options offered. |
| G3-U3 anonymous causer name | **Confirms M2.** A real name on an anonymous entry. |
| G1-U1 crafted role payload | **Bounds the claim.** Not escalated; no UI route shown. |

Two of the seven cleared code that a static reading had made look suspect. That is
the argument for running experiments before writing fixes: without them, both
would have been "hardened" into a rewrite of correct code.

---

## Wording corrections owed to T16

Recorded as they are noticed, so T16 is a reconciliation pass rather than a
rediscovery.

- **`PrivateFileAccessTest`, the structural test.** Its comment claims it catches
  "the third private-file route somebody adds next year without it". It does not:
  it iterates two hardcoded route names, so it protects the routes that exist and
  cannot discover a new one. The route GROUP is what makes a third route inherit
  the middleware; the test only pins that the two current ones carry it. Reword to
  say so.
- **`CLAUDE.md` permission example.** `students.delete` matches nothing; Shield
  generates `delete_student`. Every reviewer had to be told this as an erratum,
  which is a workaround rather than a fix.

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
