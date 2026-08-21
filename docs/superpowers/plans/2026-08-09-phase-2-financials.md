# Phase 2 — Financials Implementation Plan

**Status:** Revision 7 — the financial design review is closed. Tasks 1–5, 7, 8 and the two performance/boundary follow-ups have merged; Task 6 is implementing the owner-approved receipt reliability correction recorded below.

**Goal:** A working system where a student is enrolled, billed, and takes a receipt away from the desk; where balances and revenue are always derivable from source rows; where staff compensation is configured and payroll is **approved and posted** without history moving; and where every figure exports to Excel and PDF.

Payroll is posted, never disbursed — the system computes and freezes what is owed, and paying it out happens outside (design §7, system design §12).

**Reference documents:**
- Design: `docs/superpowers/specs/2026-08-09-phase-2-financials-design.md` — **authoritative for this phase**
- System design: `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md`
- Standards: `docs/ENGINEERING.md`
- Workflow: `docs/WORKFLOW.md`

---

## Step 0 — the plan is reviewed before any of it is implemented

Non-optional, and the reason this document exists before any code. Phase 1's Task 4 needed five rounds and every one inherited the same defect: the plan specified an unsound enforcement architecture, visible in the written plan.

### Round 1 outcome (2026-08-09)

The payment and tender structure was **approved**: one payment/receipt parent, multiple tender lines, allocations hidden from operators, atomic recording only after cash is counted or the terminal approves, receipt generation after commit.

Thirteen corrections were required, and the review found one thing no amount of design reading would have: **`EnrollmentsRelationManager` calls `EnrollStudentAction` directly**, so the plan as written shipped a UI path that enrols without billing. Verified at `app/Domain/Enrollment/Filament/Resources/BatchResource/RelationManagers/EnrollmentsRelationManager.php:266`.

Two findings were **declined**, both business rather than architecture, and both are recorded with reasoning in design §16 — write-off removal (retracted by the reviewer) and the staff discount grant (retracted, then changed anyway by the owner's own decision).

Three owner decisions were closed: write-offs stay as designed · `CHG-`/`RCT-` gaps are acceptable, so there is no counter table · `apply_discount` moves to admin and above.

### Round 2 outcome (2026-08-09)

Six findings, all accepted. Three were defects **introduced by revision 2** rather than surviving from revision 1, which is worth recording: a revision that fixes findings can create them.

- **Reference generation was impossible as written** — a non-nullable column whose value is not known until after insert. Now a placeholder replaced inside the same transaction, and three recoverable migrations instead of one mixing DDL with DML.
- **Frozen instructor hours were lost** when revision 2 replaced the generic quantity column with salary-specific day columns, violating the system design's requirement to freeze rate *and* hours. Restored, and `frozen_rate` made nullable so an adjustment line is not forced to store a meaningless rate.
- **The pricing hook would have refused every ordinary admin edit.** An admin has no `manage_pricing`, but the price sits in raw form state when they rename a course, so calling the Action unconditionally authorizes and refuses. Now called only when the normalized price actually changed.
- Idempotency keys are bound to a request fingerprint, so a reused key with a different bill raises rather than returning an unrelated receipt.
- Adjustment runs gained their invariants, a posting-period rule, and deterministic lock ordering.
- The payment Actions are explicitly actor-first and self-authorizing, and reach the student through `EnrollmentQueryService` rather than walking the relation.

### Round 3 outcome (2026-08-09)

Five blockers, all accepted. The pattern from round 2 held: revisions introduce defects, and this round found them in the fixes rather than in the original design.

- **Task 1's scope did not include the code that had to change.** Making `reference` non-nullable while `EnrollStudentAction` sets no reference breaks the next enrolment. The migration and the writer now land together.
- **The placeholder would have been recorded permanently.** `RecordsActivity` logs on model events, so the insert writes `reference = <uuid>` into a log with no delete path, and the replacement writes a phantom "reference changed" entry beside it. Sharing a transaction does not help — both entries commit. `reference` is now excluded from every audit allowlist.
- **"Three recoverable migrations" contained a migration doing two DDL statements**, which is the unrecoverable state the split existed to prevent. Four now, one statement each.
- **`request_fingerprint` was specified in prose and absent from the schema**, and task 4 owns no migration.
- **`posting_period_start` was non-null but only written at finalization**, making every draft line unwritable — and an instructor draft's posting month is genuinely unknowable until it is finalized.
- Price normalization now keeps `null` (inherit) and `0.000` (free) distinct, and rejects excess precision instead of rounding it into a false "unchanged".

### Independent pre-review outcome (2026-08-09)

Before returning to Codex, a fresh reviewer with no implementation context read both documents against the real codebase. It found five blockers and ten correctness issues; all were accepted, and the four load-bearing ones were re-verified against the files before being applied.

- **The pricing gate was wrong at the root.** `CourseResourceTest:241-248` has an admin submit `default_price => 999.999` and asserts the course still exists at `0.000`. Under revision 4 that call would refuse, roll the create back, and fail the test. And `courses.default_price` is `NOT NULL DEFAULT 0` — verified in the migration — so revision 4's "absent or null price calls nothing" exemption was unreachable for courses. Gating by **visibility** instead of by `disabled()`, and checking the ability before reading raw state, fixes both: the phase 1 tests keep passing verbatim and their smuggled-payload cases become the crafted-negative tests the boundary needs.
- **`CourseResourceTest` was in no task's scope** while carrying the same assertions revision 4 said would invert in `BatchResourceTest`.
- **`ChangeCompensationAction` had no ability to authorize on.** `RolePermissionSeederTest` scans every policy under `app/` and fails when a referenced name is unseeded, so this was a build failure, not a design smell. The seeded set is now stated exactly.
- **`LocalizationTest`'s Arabic-empty check is a hardcoded four-file dataset**, so all eight new catalogues would have shipped unchecked. Task 1 makes it derive from `lang/en`.
- Also: the reference update trips the arch test's enrolment `update` rule, which task 1 now owns extending · `receipt_disk`/`receipt_path` had no write owner and now have `AttachReceiptAction` · tasks 5 and 8 asserted things about reports that task 10 owns · aging buckets overlapped at day 90 · the backfill format was unpadded while the generator pads · task 12 had no file scope · task 6's dependency change was outside its scope.

### Round 4 outcome — step 0 closed (2026-08-09)

A bounded cleanup of seven corrections, then confirmation. **The financial design is approved; implementation remains gated on approval and merge of the scheduling pull request.**

Four questions were carried into that round and answered: nothing else under `app/` writes an enrolment row besides `EnrollStudentAction`, so the reference mechanism has one teaching point · excluding `reference` from the audit allowlist under-records nothing, because it is a deterministic function of the subject id the log already carries · each of the four enrolment migrations is independently recoverable · and the fingerprint covers every field that moves money, with `received_at` and `notes` excluded for stated reasons.

**What the remaining questions are for is the implementation PRs, not this document.** Exact SQL locking, queue and file mechanics, and failure handling are reviewed where they are written.

### Implementation correction (2026-08-15)

Task 8 already owned the approved draft-time signed payroll bonuses and deductions, and the design already permitted draft payroll-run deletion, but its Action list omitted the only legal writers. This correction adds those writers without changing a locked business decision: without them the approved UI behavior would either be missing or bypass the Action-only Finance boundary.

### Task 6 receipt correction (2026-08-21)

The first Task 6 implementation rendered a historical-looking receipt by querying live models when the queued job ran. Whole-branch review proved that this is not historical: a charge correction or a renamed student/course/batch between payment and job execution changes the document. It also proved that `afterCommit()` prevents premature dispatch but cannot recover the opposite failure — a payment commits and the process or Redis enqueue fails before any job exists.

The owner approved the smallest durable design for a single centre: a typed one-to-one immutable receipt snapshot created inside `RecordPaymentAction`'s transaction, immediate generation after commit, and a bounded scheduled reconciliation sweep for stale snapshots whose payment has no receipt. The snapshot is issued-document content only and is prohibited from live balance, validation and report code. This is not a cached financial total.

The correction explicitly rejects three suggested expansions: activity-log ids do not become financial event order; no timestamp-precision migration is needed; and `ReversePaymentAction` is not reopened. The snapshot makes same-second reconstruction irrelevant. Task 6 also adopts the existing file-lifecycle compensation path, canonicalizes receipt location, captures locale, isolates subprocess storage, strengthens the `afterCommit()` proof, states the job timeout, and makes PHP GD explicit in CI and deployment requirements.

This is a declared scope expansion discovered during implementation, not work silently absorbed into the original Task 6 list. `routes/console.php` becomes a scheduling seam, and Task 12 must re-check it against `main`.

---

## Waves, ownership and dependencies

Per `docs/WORKFLOW.md`: **at most one Claude task and one Codex task run at a time**, they must share no files, and a downstream worktree is cut from updated `main` only after its dependency has merged with green CI.

| Wave | Claude | Codex | Unblocked by finishing |
|---|---|---|---|
| 1 | **T1** Finance foundation | *(reviews T1)* | everything |
| 2 | **T5** Charge corrections | **T2** Pricing & discounts | T2 unblocks T3 |
| 2P | *(reviews T2P)* | **T2P** Money boundary & guard | closes a T2 defect and lands the money-cast guard before any further money forms are written |
| 3 | **T3** Enrol & bill | **T7** Compensation | T3 unblocks T4/T8/T10; T7 unblocks T8 |
| 4 | **T4** Payments & tenders | **T8** Payroll runs | T4 unblocks T6/T9/T10 |
| 5 | **T10** Report queries | **T6** Receipts | both unblock T11; T6 unblocks T9 |
| 6 | **T9** Enrol-and-collect | *(reviews T9)* | T9 lands the page-discovery line T11 needs |
| 7 | *(reviews T11)* | **T11** Report pages & export | |
| 8 | **T12** Phase reconciliation | *(reviews T12)* | milestone closes |

**Revision 5 split what was wave 6.** T9 and T11 both need the same `discoverPages()` registration, and `docs/WORKFLOW.md` permits only one task per wave to modify a seam, so they run in sequence — see "Same-wave file isolation" below for why that is worth a wave. Both agents still have work in waves 2 through 5.

### Two ownership changes, and why

The dependency graph, not preference, decides these. With one task per agent per wave, the schedule stalls unless ownership follows what is *ready*.

- **T5 charge corrections: Codex → Claude.** After T1 lands, the only tasks ready are T2, T5 and T7 — all originally Codex's — while Claude's next task T3 waits on T2. Claude would sit idle through the wave that unblocks its own work. T5 depends on T1 alone and shares no file with T2.
- **T8 payroll runs: Claude → Codex.** In wave 4 the only work ready alongside T4 is T8, and T4 cannot be handed away — it is the phase's security-critical task. Codex also owns T7, whose `payroll.php` T8 extends and whose compensation model T8 consumes, so the pairing keeps one agent across both halves of payroll instead of handing the file over mid-phase.

**T8 was assigned to Claude on the reasoning that invariant-heavy work stays with the lead**, and that reasoning is not wrong — it is outranked. T4 carries the money-in integrity, the idempotency and the allocation invariants; T8 carries payroll correctness, which is recoverable by an adjustment run in a way a mis-recorded payment is not. If you would rather Claude keep T8, the cost is one extra wave with Codex idle, and that is a legitimate trade to prefer.

### Dependency notes carried forward

**Revision 2:** T4 depends on T3 (payments need bills, and derive the student from the locked charge) · T8 and T10 depend on T3 for `EnrollmentQueryService` · T11 depends on T6 as well as T10, because T6 installs the PDF renderer.

**Revision 4:** T1 takes `EnrollStudentAction` as a declared crossing, because the migration that makes `reference` non-nullable and the code that fills it cannot land in different tasks without shipping a broken `main` between them.

### Same-wave file isolation, checked pair by pair

The rule that matters is that **concurrent** scopes do not overlap. Each pair below was checked against the file scopes as written, not assumed:

| Wave | Pair | Overlap in declared scopes |
|---|---|---|
| 2 | T5 charges · T2 pricing | none — `ChargeResource`/`charges.php` against `DiscountResource`/pricing Actions/`pricing.php` and the two Enrollment resource tests |
| 3 | T3 billing · T7 compensation | none — Finance Actions, the two query services and `billing.php` against the compensation resource and `payroll.php` |
| 4 | T4 payments · T8 payroll | none — payment Actions and `payments.php` against payroll Actions and `payroll.php` |
| 5 | T10 reports · T6 receipts | none — `Reports/` and `ReportPeriod` against receipt snapshot/generation/reconciliation, the view, controller and `receipt.php` |
| 6 | T9 flow · T11 report pages | none — the collect page and `collect.php` against report pages, exporters and `reports.php` |

The files touched by more than one phase task are all **sequential across waves**, never concurrent: `tests/Feature/Staff/ActionBoundaryArchTest.php` (T1 → T3 → T8 → T6), `lang/en/payroll.php` (T7 → T8), `RecordPaymentAction` (T4 → T6), `Payment.php` and `FinanceSchemaTest.php` (T1 → T6), and `.github/workflows/ci.yml` (T00C → T6). T8's narrow architecture-test crossing is valid in Wave 4 because T4 does not touch that seam; T6 begins from the merged tips of every predecessor named here.

### Revision 5 — that table is not the whole check, and wave 2 proved it

**Every cell above is still accurate, and wave 2 collided anyway.** T2 and T5 were compared on their declared scopes, found disjoint, and then both edited `app/Providers/AppServiceProvider.php` and `app/Providers/Filament/AdminPanelProvider.php` — files neither task named. Both were Finance work running concurrently, and **each independently introduced the first Filament resource its own branch knew of**: so each added the same `Domain/Finance` discovery line, and a different policy registration apiece, the latter following guidance that turned out to be wrong — those registrations were redundant, as the wave 4 note below establishes. T2 also extended `ActionBoundaryArchTest`'s delete allowlist, undeclared, for its two discount Actions.

`AppServiceProvider` conflicted loudly during the rebase, which is the safe outcome: it was resolved by hand and both `Gate::policy()` lines kept. `AdminPanelProvider` **auto-merged with no conflict marker** and produced **two identical `discoverResources()` blocks** for the same namespace. A clean rebase, a green tree, Filament scanning one directory twice, and **no test in the suite asserts otherwise** — so nothing would have caught it.

That is the general shape, and it is worth naming: when two branches do **the same thing for the same reason**, git's confidence is highest exactly where "keep both" is wrong.

So the isolation check gains a second half. A declared file scope says what a task **owns**. It says nothing about the registries a task **joins**, and those are where same-wave tasks meet.

### The shared wiring seams

A seam is a file whose content is an enumeration that grows whenever a task adds a unit of some kind. No task owns it; each appends to it. Every row below was read against the current `main`, not assumed:

| Seam | A task joins it when it… | State |
|---|---|---|
| `AppServiceProvider` — the `Gate::policy()` list | adds a Policy class | **open**; every remaining task with a resource joins it |
| `AdminPanelProvider` — `discoverResources()` | adds the first resource in a domain namespace | **closed for Finance** — T2 landed the line, T5's duplicate was removed |
| `AdminPanelProvider` — `discoverPages()` | adds a standalone panel page **outside `app/Filament/Pages`** | **open, and not yet noticed** — see wave 6 below |
| `tests/Feature/Staff/ActionBoundaryArchTest.php` | adds an Action calling `->delete()`/`->forceDelete()`, or writing `is_active => false` | **open**; the delete rule is deliberately broad, so its allowlist grows per Action, not per model |
| `RolePermissionSeeder` + `FinancePermissionSeedingTest` | needs a permission | **closed for phase 2** — T1 seeded the whole set from design §10 |
| `tests/Feature/LocalizationTest.php` | adds a translation catalogue | **closed** — T1 made the Arabic-empty dataset derive from `lang/en` |
| `tests/Feature/DatabaseIsolationTest.php` — the exempt-file list | adds a test file that declares no database isolation trait | **open**; grows per architecture-style test |
| `routes/console.php` — scheduled commands | adds or changes a scheduler entry | **joined by T6** for receipt reconciliation; T10 does not touch it, and T12 must re-check every schedule against `main` |

**The last row was added by T3, and it is the most instructive one here.** It was missing when this table was written in T00C, and the very next task — T2P — joined it, correctly and with the owner's approval, but without declaring it, because nothing in the inventory prompted them to. A seam the inventory omits is a seam nobody is asked to declare, so the omission propagates as compliance.

**The lesson is about the inventory, not about that task.** This table is hand-maintained against a codebase that keeps growing registries, which is the same shape of defect as `KNOWN_PIVOT_MUTATORS` before its reflection guard, and as the money-field list T2P deferred to T12. Until something derives it, **each task re-reads this table against `main` rather than trusting it**, and adds what it finds.

**No test asserts any of these.** A dropped `Gate::policy()` line fails *silently*, because Laravel's convention discovery resolves those policies unaided; a duplicated `discoverResources()` line fails silently too. Silence in both directions is why the verification step below counts rather than reads.

### The revised pair check, wave by wave

| Wave | Pair | Seams each joins | Verdict |
|---|---|---|---|
| 3 | T3 · T7 | T3 → arch test, the delete allowlist for `DeleteUncommittedChargeAction`, in a file it already declares · T7 → `AppServiceProvider`, for `StaffCompensationPolicy` | **No collision.** Different seams, one writer each. T7's crossing is undeclared and is declared below. |
| 4 | T4 · T8 | **Both** → `AppServiceProvider`: `PaymentPolicy` and `PayrollRunPolicy` | **Collision — dissolved without sequencing.** See below: neither task needs to touch the file. |
| 5 | T10 · T6 | T6 → `routes/web.php`, `routes/console.php`, the mPDF dependency and CI's extension list, all declared; T10 → none | **No collision**, single writer. `routes/console.php` is a scheduling seam and T12 re-checks it. |
| 6 | T9 · T11 | **Both** → `AdminPanelProvider`, `discoverPages()` for `app/Domain/Finance/Filament/Pages` | **Collision, and the dangerous kind.** Two identical lines: the shape that auto-merges in silence. **Sequenced — see the revised wave table.** |

#### Wave 4 dissolves, because the seam is optional

The `Gate::policy()` list is **redundant**, and that is checkable rather than arguable. Laravel's `Gate::guessPolicyName()` maps a class whose namespace contains `\Models\` onto the sibling `\Policies\` namespace — `vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:725-727`. Every model here is `App\Domain\{Domain}\Models\{X}` and every policy `App\Domain\{Domain}\Policies\{X}Policy`, so **discovery already resolves all of them**; the nine explicit lines change no behaviour. P2-T05 confirmed the same thing empirically from the other direction — its suite passed before the `ChargePolicy` line was added.

So **T4 and T8 register nothing in `AppServiceProvider`**, join no provider-registration seam, and stay concurrent. Wave 4 is unchanged.

The comment above those lines — "These policies live outside app/Policies, so Laravel's convention-based discovery will not find them. Without these lines every check against them silently falls through to false" — **is false**, and it is the reason every task so far has dutifully appended to a list it did not need. Correcting it, and deciding whether the nine existing lines stay as deliberate explicitness or go, belongs to **T12**, which already owns reconciliation. Neither is urgent: the lines are harmless, and the behaviour is identical either way.

#### Wave 6 does not dissolve, and is sequenced

Page discovery has no convention fallback — an unregistered directory is simply never scanned. The panel discovers pages from **`app/Filament/Pages` only**; every `Pages` directory under `app/Domain` is resource-scoped and reached through its resource. T9's `EnrollAndCollect` and T11's `Pages/Reports/` are each the first *standalone* page in a domain namespace, and **neither can pass its own tests until the line exists**, so the line cannot simply be dropped the way the policy registration can.

T9 and T11 are therefore sequenced. T9 lands the line; T11 starts from `main` afterwards and adds nothing to the provider, because one `discoverPages()` call covers the whole namespace including `Pages/Reports/`.

### What each task must now do

1. **Declare the seams, not just the files.** A task's file scope names the registries it will join and the line it expects to add. A seam discovered during implementation is raised, exactly as an unplanned file would be.
2. **Only one task per wave may modify a given seam** (`docs/WORKFLOW.md`, "Declared shared seams"). Where two would, they are **sequenced rather than run concurrently**: the second starts from `main` after the first has merged with green CI, and rebases onto it. Prevention, not a resolution protocol — a protocol only helps if somebody is looking, and a silent auto-merge is exactly the case where nobody is. Two tasks joining *different* seams in one wave is fine; wave 3 is that case.
3. **After any rebase touching a seam, verify by counting.** Reading the file is what missed it the first time:

```bash
grep -c "Domain/Finance/Filament/Resources" app/Providers/Filament/AdminPanelProvider.php   # expect 1
grep -c "Domain/Finance/Filament/Pages" app/Providers/Filament/AdminPanelProvider.php       # expect 1, from wave 6 on
grep -n "Gate::policy" app/Providers/AppServiceProvider.php                                 # every domain, once each
```

**What this costs: one wave.** Wave 4 survives intact because its seam turned out to be optional. Wave 6 splits, so the phase runs to eight waves rather than seven:

| Wave | Claude | Codex | Change |
|---|---|---|---|
| 3 | **T3** Enrol & bill | **T7** Compensation | unchanged — different seams |
| 4 | **T4** Payments & tenders | **T8** Payroll runs | unchanged — neither joins the policy seam |
| 5 | **T10** Report queries | **T6** Receipts | unchanged |
| 6 | **T9** Enrol-and-collect | *(reviews T9)* | **split** — T9 lands the `discoverPages()` line |
| 7 | *(reviews T11)* | **T11** Report pages & export | **split** — starts after T9 merges |
| 8 | **T12** Phase reconciliation | *(reviews T12)* | was wave 7 |

Waves 6 and 7 each carry one task, and the idle agent reviews — which `docs/WORKFLOW.md` already provides for. One wave is the honest price of never resolving a silent auto-merge by hand.

### File ownership, checked for real

Every task owns its own translation file, so nine tasks never edit one array:

| Task | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 11 |
|---|---|---|---|---|---|---|---|---|---|
| File | `pricing` | `billing` | `payments` | `charges` | `receipt` | `payroll` | `payroll`¹ | `collect` | `reports` |

¹ Task 8 extends task 7's `payroll.php`. They are sequential, not parallel, so this is an ordering fact rather than a conflict.

**`EnrollmentQueryService` is created once, in task 3, with the full surface tasks 4, 6, 8 and 10 need** — design §12 names each consumer's requirement. None of them extends it. If a genuine gap appears, it is raised rather than patched in four branches.

Revision 1 stated a single `lang/en/finance.php` in the design while the plan split the files. The design now agrees with this table.

---

## Task 0 (every task): isolation

Per `docs/WORKFLOW.md`:

```bash
git checkout main && git pull --ff-only
git worktree add ../Training-center-worktrees/P2-T{NN} -b p2/t{nn}-{slug}
```

**From updated `main`, after the dependency PR has merged with green CI** — never from a dependency's branch, which would put the upstream work inside this task's diff.

Then in the new worktree: `composer install`, copy `.env` from the main checkout **without reading it** (it holds the database password), `npm ci`, and `git config core.hooksPath .githooks`. Run `composer dump-autoload` after pulling — the `Tooling\` mapping is needed before any test runs, and a stale autoloader kills the whole suite with an error that reads like a broken merge.

Verify with `composer verify` — one command, one definition, real output. The suite serialises across worktrees on a machine-wide lock; a run that says it is waiting is correct.

At the end: open a PR, get the other agent's review, resolve, merge, then **tag the branch tip and push the tag** before deleting anything.

---

## Task 1 — Finance foundation
**Wave 1 · Owner: Claude · `p2/t01-finance-foundation` · depends on nothing**

The original nine-table foundation schema in one task with one owner, because every other task builds on it. Task 6 later adds the tenth receipt-snapshot table through an additive migration; that dated correction is recorded above and does not rewrite this merged task's history.

**File scope**
- `database/migrations/` — the nine foundation tables, plus **four** single-statement `enrollments.reference` migrations (add nullable · backfill · index · tighten)
- `app/Domain/Finance/Models/` — all nine foundation models (configuration only)
- `app/Domain/Finance/Enums/` — `TenderMethod`, `CompensationType`, `PayrollRunType`
- `app/Domain/Finance/Support/` — `Money`, `Reference`, `ChargeBalance`
- `database/factories/`, `database/seeders/RolePermissionSeeder.php`
- `app/Domain/Enrollment/Models/Enrollment.php` — the `reference` attribute, **excluded from `auditedAttributes()`**
- **Declared crossing: `app/Domain/Enrollment/Actions/EnrollStudentAction.php`.** It inserts an enrolment today and sets no reference. The moment migration 4 makes the column non-nullable, **the next enrolment fails** — so the task that tightens the column is the task that must teach the existing writer to fill it. Leaving this to task 3 ships a broken `main` in between.
- `tests/Feature/Enrollment/EnrollmentTest.php` — the one enrolment test this task edits, covering the reference on the real `EnrollStudentAction` path

  The other four files that create enrolments — `EnrollmentsRelationManagerTest`, `EnrollmentPolicyTest`, `BatchTest`, `BatchDeletionTest` — **are expected to pass unchanged**, because `EnrollmentFactory` is in this task's scope and supplies the reference for factory-built rows. If any of them does break, that is a finding to raise, not a licence to edit outside scope. `EnrollmentsRelationManagerTest` changes in task 3, when the relation manager moves to `EnrollAndBillAction`.
- **Declared crossing: `tests/Feature/Staff/ActionBoundaryArchTest.php`.** Its enrolment `update` rule allows only `WithdrawEnrollmentAction`, and the reference replacement is an update inside `EnrollStudentAction`. The allowlist is extended deliberately, with the reason recorded in the test — **not** worked around by laundering the write through a differently-named variable, which the test's own comments already identify as the hole in its pattern matching. Task 3 edits this file again for its own rule; task 3 follows task 1, so this is ordering, not a conflict.
- **Declared crossing: `tests/Feature/LocalizationTest.php`.** Its Arabic-empty check is a hardcoded four-file dataset, so the eight catalogues this phase adds would ship unchecked. Task 1 makes the dataset derive from the files present in `lang/en`, covering every later task automatically.
- `tests/Feature/Finance/FinanceSchemaTest.php` — every CHECK, index and generated column proven by a violating insert
- `tests/Feature/Finance/MoneyTest.php`, `ReferenceTest.php`, `ChargeBalanceTest.php`
- `tests/Feature/Finance/FinancePermissionSeedingTest.php` — the exact seeded set from design §10
- `tests/Feature/Finance/EnrollmentReferenceBackfillTest.php` — the four migrations, each retried independently

**Does**
Every foundation table from design §9 with its `CHECK` constraints, foreign keys, indexes and generated columns — including the three-shape `CHECK` on `payroll_lines`, the nullable `frozen_rate`, the nullable-and-indexed `posting_period_start` paired to `finalized_at`, and **`payments.request_fingerprint`**, which task 4 needs and owns no migration to create. The additive Task 6 snapshot table is the explicit dated exception, introduced only after delayed rendering proved the original receipt design unsound.

`Money` over integer dirham. `Reference` inserting a unique placeholder and replacing it with the real `ENR-`/`CHG-`/`RCT-` value inside the same transaction, with `reference` excluded from every `auditedAttributes()` so the placeholder cannot reach the append-only log. `EnrollStudentAction` updated to produce a reference. `ChargeBalance` as the single definition of outstanding — SQL expression and PHP computation — placed here rather than in task 4 so tasks 4 and 5 can both use it without an ordering dependency.

**Done when**
Migrations run clean and roll back clean, **each of the four enrolment migrations independently**, and **migration 4 is retried after migration 3 has succeeded and been recorded**, and completes cleanly · **an enrolment created through the existing `EnrollStudentAction` path succeeds after the column is tightened** — the phase 1 enrolment tests pass unchanged in intent · **no activity entry anywhere carries a placeholder**, asserted by pattern over the whole log · **every `CHECK` is proven by an insert that violates it**, including the whitespace card reference, the unpaired payroll finalization columns, the unpaired posting period, and each rejected payroll line shape · **a draft payroll line persists with a null posting period** · both generated-column unique indexes are proven by inserting a genuine duplicate and asserting MySQL refuses · the backfill is proven against pre-existing enrolment rows · **no row survives a transaction holding a placeholder reference**, and concurrent inserts produce no collision · `Money` is tested including a case that actually rounds · permissions seeded per design §10, with staff holding nothing financial · `composer verify` green with real output.

---

## Task 1P — Test database performance follow-up
**Wave 2 support · Owner: Codex · `p2/t01p-test-performance` · depends on 1**

This bounded follow-up runs alongside task 5. Task 2 remains ready in wave 2,
but starts after this task because the workflow permits one active task per
agent. Its file scope does not overlap task 5 or task 2.

**File scope**
- `tests/Feature/Finance/EnrollmentReferenceBackfillTest.php`
- `tests/Feature/Finance/ReferenceConcurrencyTest.php`
- `tests/Feature/Finance/ReferenceTest.php` — comment-only companion update
- `tests/Feature/Staff/FileLifecycleTransactionTest.php`
- `tests/Feature/DatabaseIsolationTest.php`, `tests/Pest.php`
- `docs/ENGINEERING.md` and this plan entry

**Does**
Replace per-test `DatabaseMigrations` rebuilds with Laravel's
`DatabaseTruncation` only for the two files whose subject is real committed rows
or independent connections. Each registers an after-file migration-state reset,
and the isolation architecture test rejects any future truncation file that
omits that boundary. `EnrollmentReferenceBackfillTest` deliberately keeps
`DatabaseMigrations`: it creates partial schemas its own repair can fail to
restore, so a fresh schema per case is part of the proof rather than overhead.
No behavioral case is combined, skipped, or moved out of the full suite.

**Done when**
All thirteen existing tests retain their individual names and assertions · the
isolation architecture test recognizes a complete `DatabaseTruncation`
declaration, rejects the trait without its after-file reset, and rejects
prose-only mentions of both · a test following either truncation file receives a
fresh schema before it can observe committed residue · a failed backfill repair
cannot hand a partial schema to the next case · focused before/after timings are
recorded · the full `composer verify` gate remains green · CI partitioning is
not added unless the measured result still justifies that extra workflow
surface.

**Initial measurement (superseded by cross-review)**
The first implementation converted all three files and measured 155.092 seconds
before versus 17.118 seconds after for the same thirteen cases and 85
assertions. Cross-review proved that raw truncation leaked the final case's rows
and that a failed backfill repair could poison the next case. The corrected
hybrid keeps the backfill rebuild boundary and is re-measured below before this
task closes; the unsafe 17.118-second result is retained here as history, not as
an accepted outcome.

**Corrected outcome after cross-review**
The sound hybrid passed the same thirteen focused tests and 85 assertions in
73.857 seconds, down from 155.092 seconds — an 81.235-second (52%) reduction
without weakening the backfill recovery boundary. The full gate passed 1,328
tests (1,327 passed, one skipped) and 4,021 assertions in 534.971 seconds,
approximately 55 seconds faster than the pre-task run. Six added isolation
cases enforce the truncation reset, including rejection of a prose-only claim.
CI partitioning remains deferred: the suite is back below the ten-minute point
that triggered this follow-up, and T4/T8 can revisit it with their measured
costs rather than pre-adding workflow branches now.

---

## Task 2 — Pricing and discounts
**Wave 2 · Owner: Codex · `p2/t02-pricing-discounts` · depends on 1**

**File scope**
- `app/Domain/Finance/Filament/Resources/DiscountResource*`, `Policies/DiscountPolicy.php`
- `app/Domain/Finance/Actions/` — `CreateDiscountAction`, `DeactivateDiscountAction`, `DeleteDiscountAction`, `UpdateCoursePriceAction`, `UpdateBatchPriceAction`
- `app/Domain/Finance/Services/PricingService.php`
- **Declared crossing:** `CourseResource`, `BatchResource`, and their Create/Edit pages, plus a new `WritesPricingThroughActions` concern
- `lang/en/pricing.php`, `lang/ar/pricing.php` (empty)
- **`tests/Feature/Enrollment/CourseResourceTest.php` and `BatchResourceTest.php`** - task 2 *adds* super-admin cases; the existing admin price-absence and smuggled-payload assertions keep passing unchanged under design 3's visibility rule
- `tests/Feature/Finance/PricingBoundaryTest.php` — super-admin persists, admin's crafted state ignored, null↔0.000, precision rejection
- `tests/Feature/Finance/DiscountDefinitionTest.php` — immutability, delete-before-use, FK refusal after use

**Does**
Live price inheritance in `PricingService`. **The executable write boundary from design §3**: price fields are `dehydrated(false)` for every actor without exception, so generic persistence never sees a price. A save hook checks `manage_pricing` before reading raw state; without the ability it skips pricing entirely, and with the ability it calls the pricing Action only for a real change. The Actions self-authorize, and a refusal throws `Halt::rollBackDatabaseTransaction()`. Copy `WritesUserThroughActions`, which already solves this exact problem for roles and `is_active`.

**For an authorized actor, the Action is invoked only when the price actually changed**, comparing normalized integer dirham rather than strings. The ability check happens before raw state is read, and the normalized comparison happens before the Action is called.

Discount definition create, deactivate and delete all gated on `manage_pricing`. Discount definitions **immutable from creation** — no update path and no `UpdateDiscountAction`. A mistake is deleted and recreated before use; after use the foreign key refuses the delete and the definition is deactivated and replaced.

**Done when**
A super admin sets a price on create **and** changes it on edit, through the real Livewire component, and it persists · **an admin creates a course and a batch, and renames both, and every save succeeds** — the create path matters as much as the edit path, and `courses.default_price` is NOT NULL DEFAULT 0 so there is no null to treat as absent · **the price field is not present in the form for an admin at all**, which is why the phase 1 absence assertions keep passing · **an admin's crafted Livewire state update on the price field is ignored, the pricing Action is never called, and their save succeeds** — the ability is read before raw state, so injected price state is discarded unread rather than authorized and refused · `100` and `100.000` are not treated as a change · **a batch price moving `null` → `0.000` and `0.000` → `null` is detected as a change in both directions**, so "this intake is free" and "go back to inheriting" both reach the Action · **`100.0004` is rejected as invalid rather than rounded and reported unchanged** · no code path writes either price column outside the two Actions, mutation-tested · **no code path edits a discount's name or percentage after creation** · an unreferenced discount can be deleted and recreated · a referenced discount cannot be deleted, refused as a typed exception converted from MySQL 1451 · an admin cannot create or deactivate a discount definition · the existing admin field-absence assertions in `CourseResourceTest` and `BatchResourceTest` remain, and new super-admin cases assert that the field is present and gated · `composer verify` green.

---

## Task 2P — The discount float boundary, and the money-cast guard
**Wave 2 follow-up · Owner: Codex · `p2/t02p-money-boundary` · depends on 2, 5 · merges before wave 3 opens**

Two findings from P2-T05's cross-review, neither of which belonged in that branch: one is a defect in task 2's merged code, and the other is a phase-wide protection the design already claims to have.

**File scope**
- `app/Domain/Finance/Filament/Resources/DiscountResource.php` — the `percentage` field
- `tests/Feature/Finance/DiscountDefinitionTest.php` — the regression
- `tests/Feature/Finance/MoneyCastArchTest.php` — **new file**, the guard
- Joins no seam. Nothing under `app/Providers/`, and the guard is a new file rather than an addition to `ActionBoundaryArchTest`, which task 3 is editing in the next wave.

**Does**

`DiscountResource::form()`'s `percentage` calls `->numeric()`, which installs Filament's `NumberStateCast` — `get()` and `set()` both `floatval()`. `CreateDiscount::handleRecordCreation()` then reads the cast `$data['percentage']`, so the value feeding design §3's rounding rule reaches `CreateDiscountAction` having already been a float. Same defect P2-T05 fixed in `ChargeResource`, and the same fix: drop the component call, restore the keypad with `->inputMode('decimal')`.

**The distinction that matters: `->numeric()` the component call installs a state cast; `'numeric'` the validation rule does not.** Validation reads the value, it does not rewrite the state. Keeping `['numeric', 'decimal:0,2', 'min:0.01', 'max:100']` as explicit `->rules()` preserves every constraint the form has today — including the bounds `->minValue()`/`->maxValue()` were adding, which is why those two calls go with it rather than being left behind to compare string lengths.

Then the guard **design §6 line 308 says already exists**: "an architecture test forbids float casts on money attributes anywhere in `app/Domain/Finance`". No such test exists — nothing under `tests/` scans for it. The design has been asserting a protection the code does not have since the phase opened, which is this project's most frequent defect, in the one place it protects money. Build it: no `(float)`/`floatval()`/`(double)` in `app/Domain/Finance`, and no `->numeric()` on a field whose name is a money or rate column. Both rules **proven by mutation** — inject a violation, watch the rule name the file, remove it again.

**Done when**
The mounted `percentage` state is the exact string `'10.00'` rather than `10.0`, asserted through `getState()` the way `ChargeResourceTest` asserts the charge amount · the existing discount immutability, delete-before-use and FK-refusal assertions pass unchanged · the guard fails when a `(float)` cast is injected anywhere under `app/Domain/Finance`, and when `->numeric()` is injected on a money field, each proven by injection rather than by inspection · `composer verify` green.

**Why before task 3, rather than alongside it.** Task 3 issues the first real charge, and tasks 4, 8 and 11 all build money-carrying forms on top of it. A guard that arrives after them protects nothing they were written against.

---

## Task 3 — Enrol and bill
**Wave 3 · Owner: Claude · `p2/t03-enroll-and-bill` · depends on 1, 2**

The highest integration risk in the phase, and the task that closes the unbilled-enrolment path.

**File scope**
- `app/Domain/Finance/Actions/` — `EnrollAndBillAction`, `IssueChargeAction`, `DeleteUncommittedChargeAction`
- `app/Domain/Finance/Services/ChargeQueryService.php` (read-only)
- `app/Domain/Enrollment/Services/EnrollmentQueryService.php` — **does not exist yet**; built here with the full surface **tasks 4, 6, 8 and 10** need (design §12 names each consumer's requirement)
- **Declared crossings:** `DeleteEnrollmentAction`, `EnrollmentsRelationManager`
- `tests/Feature/Staff/ActionBoundaryArchTest.php` — the new enrolment rule, **and the delete allowlist**: the deletion rule there is deliberately broad enough to match any `->delete()` in `app/`, so `DeleteUncommittedChargeAction` must be named in it. That is a seam this task is the only writer of this wave; T7 joins a different one.
- **Existing phase 1 tests:** `EnrollmentsRelationManagerTest` and any other enrolment test whose expectations change now that enrolling raises a bill
- `app/Domain/Finance/Exceptions/`, `lang/en/billing.php`
- `tests/Feature/Finance/EnrollAndBillTest.php` — the wrapped transaction, discount authorization, frozen charge figures
- `tests/Feature/Finance/EnrollmentDeletionWithChargeTest.php` — deletable untouched, refused once money moved
- `tests/Feature/Finance/EnrollmentQueryServiceTest.php` — the four-consumer contract

**Does**
`EnrollAndBillAction` wraps `EnrollStudentAction` and `IssueChargeAction` in one transaction, authorizing `create` on `Enrollment` plus `apply_discount` when a discount was chosen. **`EnrollmentsRelationManager` is migrated to call it**, and an architecture test asserts nothing under `app/` calls `EnrollStudentAction` except `EnrollAndBillAction`.

`DeleteUncommittedChargeAction` locks the charge and checks every disqualifying condition — any allocation, adjustment or write-off — inside the transaction that deletes it. `ChargeQueryService` stays read-only.

`due_date` is set to the enrolment date, per design §4.

**Done when**
A staff member with no finance permission enrols a walk-in and the bill is raised at full price · a staff member is not offered a discount and a crafted attempt to apply one is refused · **the architecture rule fails when a violation is injected, naming the offending file** · the existing enrolment tests pass against the new behaviour rather than being deleted or skipped · the charge froze list price, percentage and amount, and does not move when the course price later changes · `due_date` equals the enrolment date · an enrolment with an untouched bill is deletable and one with a payment is refused, both asserted · `composer verify` green.

---

## Task 4 — Payments and tenders
**Wave 4 · Owner: Claude · `p2/t04-payments` · depends on 1, 3**

The security-critical task of the phase.

**File scope**
- `app/Domain/Finance/Actions/` — `RecordPaymentAction`, `ReversePaymentAction`
- `app/Domain/Finance/Services/PaymentInvariantService.php`
- `app/Domain/Finance/Data/` — `RecordPaymentData`, `TenderData`
- `app/Domain/Finance/Filament/Resources/PaymentResource*`, `Policies/PaymentPolicy.php`
- **Joins no seam. Do not register `PaymentPolicy` in `AppServiceProvider`** — T8 runs in the same wave and the file may have only one writer per wave. Nothing is lost: Laravel's discovery resolves `App\Domain\Finance\Policies\PaymentPolicy` from `App\Domain\Finance\Models\Payment` unaided (`Gate.php:725-727`), so the registration would change no behaviour. `PaymentPolicy` still must be *tested* through the panel exactly as before — discovery resolving it is a claim this task proves, not one it assumes.
- `app/Domain/Finance/Rules/NotACardNumber.php`
- `lang/en/payments.php`, `lang/ar/payments.php` (empty)
- `tests/Feature/Finance/RecordPaymentTest.php` — split tenders, allocation equality, overpayment refusal, derived student
- `tests/Feature/Finance/PaymentIdempotencyTest.php` — replay, conflict, concurrency, settled-bill replay
- `tests/Feature/Finance/PaymentReversalTest.php`, `PaymentAuthorizationTest.php`

**Does**
Atomic create-and-finalize — payment, tenders and allocations in one transaction, no draft state. `PaymentInvariantService` locks the charge, derives outstanding under that lock, and checks tender total equals allocation total and allocation does not exceed outstanding.

Both Actions are **actor-first and self-authorizing**, like every other request-path Action here. **The student is resolved through `EnrollmentQueryService`** from the locked charge; `RecordPaymentData` has no student field. **The idempotency key** is a client-generated UUID under a unique index, stored with a canonical request fingerprint: an identical replay returns the existing payment, a mismatched one raises `IdempotencyConflictException`. Reversal as a set-once lifecycle transition, super admin only. The PAN-shaped-input rule. `PaymentPolicy` refusing update and delete unconditionally.

**Done when**
A split payment of 300 card + 700 cash against a 1,000 bill produces one payment, two tenders, one allocation and a zero balance · a card tender with a blank-after-trim reference is refused by the database `CHECK`, proven by direct insert · **a tender/allocation mismatch is refused with a typed exception and leaves no partial row** · paying more than outstanding is refused, including when outstanding drops between form load and submit · two concurrent payments against one bill yield one success and one typed refusal · **the same key and request submitted twice, sequentially and concurrently, yields exactly one payment** · **the same key with a different bill, tender split, or terminal reference raises and creates nothing** — two submissions differing only in reference are two approved card transactions, not a replay · **the fingerprint is stable across tender ordering and decimal representation** · **a replay of a payment that settled its bill in full returns the original payment** rather than failing an overpayment check, proving replay detection runs before revalidation · **a crafted cross-student allocation stores the payment against the bill's real student** · **each Action invoked directly with an unauthorized actor is denied** · a reversed payment leaves every row intact and drops out of the balance · granting `update_payment` does not make the policy allow it · `composer verify` green.

Receipt assertions belong to tasks 6 and 9 — **receipts do not exist yet at this point in the phase**, so this task asserts one *payment* and cannot honestly assert one receipt.

---

## Task 5 — Charge corrections
**Wave 2 · Owner: Claude · `p2/t05-charge-corrections` · depends on 1** — reassigned from Codex; see "Two ownership changes" above

**File scope**
- `app/Domain/Finance/Actions/` — `AdjustChargeAction`, `WriteOffChargeAction`
- `app/Domain/Finance/Filament/Resources/ChargeResource*`, `Policies/ChargePolicy.php`
- `lang/en/charges.php`, `lang/ar/charges.php` (empty)
- `tests/Feature/Finance/AdjustChargeTest.php` — reason required, refusal below allocated, activity-log properties
- `tests/Feature/Finance/WriteOffChargeTest.php`, `ChargeResourceTest.php`

**Does**
Both Actions super-admin-only with a mandatory reason written into the activity log alongside the before/after diff. `AdjustChargeAction` refuses to drop the amount below what is already allocated. `ChargeResource` is read-plus-two-actions, sorting and filtering outstanding through `ChargeBalance`'s SQL expression.

**Done when**
An admin holding every charge read permission cannot adjust or write off, asserted through the real component · adjusting below the allocated total is refused · the reason reaches the activity log and is visible in `ActivityResource` · a written-off charge leaves the debt in the student's history and is flagged as written off (the aged-report exclusion is asserted in task 10, which owns the reports) · `ChargePolicy::create/update/delete` refuse even when the permission is granted · `composer verify` green.

---

## Task 6 — Receipts
**Wave 5 · Owner: Codex · `p2/t06-receipts` · depends on 4**

**Dependency change, pre-approved by the owner:** `composer require mpdf/mpdf`. See design §8 for why not Browsershot and why not dompdf.

**File scope**
- `composer.json`, `composer.lock` — the mPDF dependency change above
- `database/migrations/2026_08_21_000100_create_payment_receipt_snapshots_table.php`, `database/migrations/2026_08_21_000200_add_receipt_pending_index_to_payments_table.php`, `database/factories/PaymentReceiptSnapshotFactory.php`
- `app/Domain/Finance/Models/PaymentReceiptSnapshot.php`, `app/Domain/Finance/Models/Payment.php`, `app/Domain/Finance/Policies/PaymentReceiptSnapshotPolicy.php` — typed one-to-one relationship/casts and an unconditionally refusing direct-access policy; receipt access authorizes the owning payment. Laravel's existing convention discovery resolves the policy, so Task 6 adds no `AppServiceProvider` line and joins no provider seam
- `app/Domain/Finance/Jobs/GenerateReceiptJob.php`, `app/Domain/Finance/Actions/AttachReceiptAction.php`, `app/Domain/Finance/Actions/ReconcilePendingReceiptsAction.php`
- `app/Console/Commands/ReconcilePendingReceiptsCommand.php`
- `app/Domain/Finance/Support/ReceiptLocation.php`, `app/Domain/Finance/Services/ReceiptFileOwnershipService.php`
- **Declared crossing: `app/Domain/Staff/Services/FileLifecycleService.php`** — add `persistNewFileWithOwnerLock()`, preserving the existing write-ahead compensation and root-commit handling while allowing the caller to take the payment lock before writing bytes
- **Declared crossing: `app/Domain/Staff/Jobs/PurgeDeletedFileJob.php`** — consult the published Finance receipt-ownership service through `FileLifecycleService::compensationConnectionName()` before deleting a provisional receipt file
- **Declared crossing: `tests/Feature/Staff/ActionBoundaryArchTest.php`** — register `AttachReceiptAction` with `GenerateReceiptJob` as sole caller and `ReconcilePendingReceiptsAction` with `ReconcilePendingReceiptsCommand` as sole caller; prohibit snapshot reads by live Finance calculations/reports
- `resources/views/finance/receipt.blade.php`
- `app/Http/Controllers/Finance/ReceiptDownloadController.php`, `routes/web.php`
- **Declared scheduling seam: `routes/console.php`** — append `receipts:reconcile-pending` every five minutes with its own `withoutOverlapping(15)` mutex; preserve every existing backup and file-cleanup schedule; Task 12 re-checks this seam against `main`
- **Declared crossing: `app/Domain/Finance/Actions/RecordPaymentAction.php`** — create the immutable snapshot inside the payment transaction and dispatch the normal generation job after commit; sequential after task 4 merged
- **Declared crossing: `app/Domain/Staff/Support/ActivityEvent.php`, `lang/en/activity.php`** — add the semantic `receipt_generated` event and label; the activity id/timestamp is audit evidence only, never receipt ordering
- `lang/en/receipt.php`, `lang/ar/receipt.php` (empty)
- `.github/workflows/ci.yml` — install PHP GD explicitly
- **Declared design/plan crossing:** `docs/superpowers/specs/2026-08-09-phase-2-financials-design.md`, this plan — record the owner-approved receipt correction rather than leaving the implementation to contradict its authority
- `tests/Feature/Finance/FinanceSchemaTest.php` — snapshot schema, types, constraints and one-to-one guarantee
- `tests/Feature/Finance/ReceiptGenerationTest.php` — snapshot determinism, real rendered PDF, retry-safety, locale/direction, after-commit timing, isolated subprocess storage and one receipt after a replayed payment
- **Declared test-only crossing: `tests/Feature/Finance/PaymentConcurrencyTest.php`** — add one assertion to the existing T4 two-till replay harness proving the winner creates exactly one receipt snapshot; duplicating that load-bearing subprocess fixture inside Task 6 would create two concurrency answers that can drift
- `tests/Feature/Finance/ReceiptDownloadTest.php` — policy-authorized download, canonical path/ref validation, unreachable without authorization, reversed payment's receipt retained
- `tests/Feature/Finance/ReceiptSnapshotPolicyTest.php` — direct snapshot view and every write refuse for all actors without adding permissions
- `tests/Feature/Finance/ReceiptReconciliationTest.php` — lost-enqueue recovery, bounded two-run anti-starvation proof and schedule registration
- `tests/Feature/Finance/ReceiptFileLifecycleTest.php` — root-transaction rollback/commit ambiguity, exact ownership protection and failure/success concurrency at one deterministic path

**Does**
`RecordPaymentAction` creates one immutable typed receipt snapshot in the same transaction as the payment, tenders and allocation. The snapshot freezes every mutable or computed field shown on the issued document, including the effective locale; immutable tender rows remain the source of the cash/card breakdown. Snapshot data is document-only and may not feed live balances, reports, authorization or payment validation.

Normal generation remains queued after commit. `GenerateReceiptJob` renders only the snapshot plus immutable tender rows, declares a 60-second timeout below the 90-second queue `retry_after`, restores locale after rendering, and attaches one canonical private file through `AttachReceiptAction` and `FileLifecycleService`. `ReceiptLocation` alone derives and validates the RCT reference/path. Download stays policy-authorized and reuses the `StaffCertificateDownloadController` and `AuthenticatePrivateFileSession` pattern.

`ReconcilePendingReceiptsCommand` runs every five minutes with an explicit 15-minute mutex expiry. Its internal Action selects at most 100 snapshots whose payment has no receipt, whose `created_at` is at least ten minutes old, and whose attempt stamp is null or at least ten minutes old; it orders never attempted first, then oldest attempt, then id; takes a row lock to re-check both age predicates and receipt absence before committing `last_reconciliation_attempt_at` and dispatching; and uses non-throwing reporting on enqueue failure. This avoids racing a newborn snapshot's normal queued job and repairs a database commit followed by process/Redis dispatch loss without introducing a general outbox.

Receipt file persistence reuses `FileLifecycleService`'s provisional cleanup receipt and root-commit compensation. `PurgeDeletedFileJob` asks `ReceiptFileOwnershipService`, on the exact compensation connection supplied by `FileLifecycleService`, to take the payment lock and decide whether the canonical disk/path is now owned before unlinking. No Activity-log id ordering, timestamp-precision migration, or `ReversePaymentAction` change is part of this task.

**Done when**
Every required field appears, verified against a real rendered PDF · changing the charge, student/course/batch display data or recording staff after payment does not change a delayed or repeated receipt, while live balances/reports still read source rows · exactly one snapshot exists after sequential and concurrent payment replay · the file lands on the private disk and is unreachable without authorization · a reversed payment's receipt is not deleted · **generation is retry-safe and idempotent** — repeated and concurrent jobs leave exactly one receipt, one canonical pointer and one activity event · the enrolment reference and course/batch codes used to create the snapshot come through `EnrollmentQueryService` · the download refuses traversal, another payment's file and coherent reference/path corruption · an owned receipt survives orphan cleanup and a failed attempt cannot delete a later winner's bytes.

Removing `afterCommit()` makes the synchronous-queue timing test fail: nothing exists before an outer commit, rollback leaves nothing, and commit creates file/pointer/event · a committed payment whose enqueue is forced to fail is generated by a later sweep · a newborn snapshot is not swept before ten minutes · a backlog larger than 100 proves across **two runs** that never-attempted rows are not starved even after old attempt stamps age back into eligibility · the schedule asserts five-minute frequency and 15-minute mutex expiry · ownership cleanup asserts its payment lock uses the compensation connection · every child process writes only to the parent's fake private root · English and Arabic-locale fallback renders prove their LTR/RTL PDF direction and pass visual inspection with mPDF-compatible direction-neutral markup · the job timeout is asserted below `retry_after` · CI and the documented VPS runtime provide GD for PHP 8.4 CLI/FPM and workers are restarted · `composer verify` green.

---

## Task 7 — Compensation
**Wave 3 · Owner: Codex · `p2/t07-compensation` · depends on 1**

**File scope**
- `app/Domain/Finance/Actions/ChangeCompensationAction.php`
- `app/Domain/Finance/Services/CompensationPeriodInvariantService.php`
- `app/Domain/Finance/Filament/Resources/StaffCompensationResource*`, `Policies/StaffCompensationPolicy.php`
- **Declared seam: `app/Providers/AppServiceProvider.php`** — one `Gate::policy(StaffCompensation::class, StaffCompensationPolicy::class)` line. T3 joins no seam this wave, so this task is the only writer; the resource-discovery line for the Finance namespace already exists from T2 and must not be added again.
- `lang/en/payroll.php`, `lang/ar/payroll.php` (empty)
- `tests/Feature/Finance/CompensationTest.php` — raise closes and inserts, overlap refused, update permission refused
- `tests/Feature/Finance/CompensationConcurrencyTest.php` — two first rows from an empty table

**Does**
A raise closes the previous row and inserts a new one in one transaction, authorized on **`create_staff_compensation`** — create is the write ability for this table, because the only legitimate change to a rate is a new row. Rates are never overwritten. **The overlap invariant locks the `users` row**, not the compensation rows — when a person has none, there is nothing else to lock.

**Done when**
A raise produces two rows with contiguous, non-overlapping periods · an overlapping period is refused · **two concurrent first compensation rows, starting from an empty table, produce one row and one refusal** · granting the update permission does not make the policy allow it · `composer verify` green.

---

## Task 8 — Payroll runs
**Wave 4 · Owner: Codex · `p2/t08-payroll` · depends on 3, 7** — reassigned from Claude; see "Two ownership changes" above

**File scope**
- `app/Domain/Finance/Actions/` — `CreatePayrollRunAction`, `FinalizePayrollRunAction`, `AdjustPayrollLineAction`, `AddPayrollLineAdjustmentAction`, `DeletePayrollRunAction`
- `app/Domain/Finance/Services/PayrollCalculator.php`
- `app/Domain/Finance/Filament/Resources/PayrollRunResource*` and its draft-review page
- `app/Domain/Finance/Policies/PayrollRunPolicy.php`
- **Joins no provider-registration seam and does not edit `AppServiceProvider`.** Same as task 4 and for the same reason: `PayrollRunPolicy` is resolved by discovery while another task shares the wave.
- **Declared crossing: `tests/Feature/Staff/ActionBoundaryArchTest.php`** — the narrow deletion-allowlist entry for `DeletePayrollRunAction`. T4 does not touch this seam, so Wave 4 isolation remains valid.
- `lang/en/payroll.php` — **additions only**; the file is created in task 7
- `tests/Feature/Finance/PayrollSegmentTest.php` — partial previous month plus full current month, mid-period raise, denominators
- `tests/Feature/Finance/PayrollFinalizationTest.php` — double-pay refused at the database, overlap refused by the lock, shape-versus-type
- `tests/Feature/Finance/PayrollAdjustmentRunTest.php` — correction invariants and posting period, draft-time adjustment validation, and draft-only run deletion

**Does**
All three run types. `monthly_salary` builds **segment lines** by intersecting the run period, each calendar month, and each compensation row's validity, freezing segment bounds, rate, days and denominator. `instructor_batch` is on-demand: the draft lists assignments not already paid in a finalized run, derived by looking at finalized lines with no stored paid flag, and **freezes the assigned hours as well as the rate**. `adjustment` runs correct finalized lines with signed amounts and a mandatory reason.

`AdjustPayrollLineAction` enforces design §7's four rules: the employee is derived from the locked target line, the target must be finalized, the amount must be non-zero, and a correction may not target another correction. A correction carries the **posting period of the line it corrects**.

`AddPayrollLineAdjustmentAction` is actor-first and self-authorizing on `run_payroll`. It accepts a signed, non-zero amount and mandatory reason only while its transaction has locked and re-checked the target draft run and line; it refuses finalized runs. `DeletePayrollRunAction` authorizes `delete_payroll_run`, locks and re-checks the target run, and deletes only a draft run through the schema cascades.

Finalization copies the frozen figures, `finalized_at` and `posting_period_start` onto each line and is irreversible. **It also verifies that each line's shape matches the run's type** — a row-level `CHECK` cannot see `payroll_runs.type`, so the constraint proves a line is internally coherent and only the Action can prove it belongs where it sits. Overlap between finalized salary segments is refused by a stable `users`-row lock followed by a locking range scan of the finalized segments themselves; the second lock is required because a parent-row lock does not refresh an earlier `REPEATABLE READ` snapshot. **User locks are taken in ascending id order before the current run's line locks** so two runs over overlapping staff cannot deadlock. Reads instructor assignments through `EnrollmentQueryService`.

**Done when**
**A run covering a partial previous month plus a full current month produces the correct segment lines with the correct per-month denominators** · a mid-period raise splits a month into two correctly-priced segments · an assignment already paid in a finalized run cannot appear in another, **proven at the database** by inserting the duplicate directly · **overlapping salary segments across two runs are refused after both transactions have opened stale snapshots**, with a mutation check proving that removing the locking range scan finalizes both · **two concurrent finalizations over overlapping staff complete without deadlock** · **a finalized instructor line still explains its amount after `assigned_hours` is changed underneath it** · **a draft line persists with a null posting period, and finalization refuses a line whose shape does not match its run's type** · **a draft-time signed bonus or deduction requires a non-zero amount and reason, and is refused after finalization** · **a draft run deletes through `DeletePayrollRunAction` and its schema cascades, while a finalized run is refused even with `delete_payroll_run`** · a finalized run cannot be edited, deleted, or have a draft adjustment added, and is corrected only by an adjustment run · **correcting March in June produces a correction line carrying March's posting period**, asserted on the line, since the wage-cost report belongs to task 10 · a correction targeting a correction is refused · a zero-amount correction is refused · a rate changed after finalization does not move the finalized figure · `composer verify` green.

---

## Task 9 — Enrol-and-collect flow
**Wave 6 · Owner: Claude · `p2/t09-enroll-and-collect` · depends on 3, 4, 6**

The phase's primary user-facing surface.

**File scope**
- `app/Domain/Finance/Filament/Pages/EnrollAndCollect.php` and its schema/steps
- **Declared seam: `app/Providers/Filament/AdminPanelProvider.php`** — one `discoverPages(in: app_path('Domain/Finance/Filament/Pages'), for: 'App\Domain\Finance\Filament\Pages')` line. The panel discovers pages from `app/Filament/Pages` alone today, so this page is unreachable without it. **This task is the sole writer of that line**; T11 needs it too and is sequenced into wave 7 behind this merge, so one line serves both and no second copy is ever written.
- `resources/views/filament/finance/`
- `lang/en/collect.php`, `lang/ar/collect.php` (empty)
- `tests/Feature/Finance/EnrollAndCollectFlowTest.php` — the full flow through Livewire, staff sees no discount, double-submit

**Does**
The eight-step flow from design §2. Allocation is decided by context and never shown to the operator. The idempotency key is minted when the collection step is first rendered.

**The discount selector lists active definitions only** — `Discount::query()->active()`. This is the UI half of a rule the server already enforces: P2-T03's `EnrollAndBillAction` refuses a deactivated definition with `DiscountNotApplicableException` rather than applying it or silently dropping it to full price. Both halves are required and neither replaces the other: an unfiltered picker offers a choice that will be refused, and a picker-only filter would leave `DeactivateDiscountAction` with no server-side effect at all.

**Done when**
The full flow is driven through Livewire end to end and produces enrolment, bill, payment, tenders, allocation and receipt · the preview figure matches the issued charge exactly · a staff member sees no discount selector · attempting to collect more than outstanding is refused in the UI **and** by the Action · a card tender without a reference cannot be submitted · **a double-submitted collection produces one payment and one receipt** · every string is translatable and the page renders at `dir="rtl"` · `composer verify` green.

---

## Task 10 — Report queries
**Wave 5 · Owner: Claude · `p2/t10-report-queries` · depends on 3, 4**

Query services with **no UI at all**, so every figure is tested before anything renders it.

**File scope**
- `app/Domain/Finance/Reports/` — one class per report from design §8
- `app/Domain/Finance/Support/ReportPeriod.php` — the local-to-UTC boundary conversion, defined once
- `tests/Feature/Finance/Reports/RevenueReportTest.php`, `OutstandingAgedReportTest.php`, `TenderBreakdownReportTest.php`, `DailyTenderReportTest.php`, `WageCostReportTest.php`, `ProfitReportTest.php`, `StudentPaymentHistoryTest.php`
- `tests/Feature/Finance/Reports/ReportPeriodTest.php` — local Africa/Tripoli boundaries converted to half-open UTC

**Does**
Every report from design §8. **Periods are local `Africa/Tripoli` calendar ranges converted to half-open UTC ranges** before they reach the database, through the timezone database rather than a fixed offset, so the `received_at` index is used. Reads enrolment data through `EnrollmentQueryService`.

**Done when**
Every report is asserted against a fixture with known figures · **a reversed payment is asserted absent from each report individually** · written-off charges are excluded from the aged report and present in history · **correcting March in June moves March's wage cost and not June's, and wage cost and profit agree** — the assertion task 8 could not make, because the reports live here · the method breakdown splits a single split-tender payment across two methods · the daily tender report matches that day's non-reversed tenders · **boundary tests pass at 00:00:00 local on the first of a month, 23:59:59 local on the last, and either side of midnight UTC** · aging is measured from `due_date` · `composer verify` green.

---

## Task 11 — Report pages and export
**Wave 7 · Owner: Codex · `p2/t11-reports-export` · depends on 6, 9, 10** — moved out of wave 6 by revision 5: it shares the page-discovery seam with task 9, so it follows that merge rather than running beside it

**File scope**
- `app/Domain/Finance/Filament/Pages/Reports/`
- **Joins no seam — the `discoverPages()` line already exists**, landed by task 9 in wave 6, and one call covers the whole namespace including `Pages/Reports/`. **Verify it rather than add it:** `grep -c "Domain/Finance/Filament/Pages" app/Providers/Filament/AdminPanelProvider.php` must be 1 both before and after this task's rebase.
- `app/Domain/Finance/Exports/` — Filament exporters
- `app/Domain/Finance/Jobs/GenerateReportPdfJob.php`, `resources/views/finance/reports/`
- `database/migrations/` — publish Filament's `exports` and `failed_import_rows` tables. **A deliberate exception to task 1 owning the schema:** these are vendor-published tables serving only this task, and nothing else in the phase depends on them
- `lang/en/reports.php`, `lang/ar/reports.php` (empty)
- `tests/Feature/Finance/Reports/ReportPageAccessTest.php` - admin views and exports, staff reaches neither
- `tests/Feature/Finance/Reports/ReportExportTest.php` - permission-scoped export rows, formula neutralization read back from the generated file

**Does**
A Filament page per report, gated on `view_financial_report`. XLSX through Filament's native queued export over the already-installed openspout — **no new Excel dependency**. PDF through the mPDF renderer task 6 installs. Both queued, both notifying in-app.

**Done when**
An admin can view and export; a staff member can reach neither · **the export query is scoped by the requesting user's permissions**, asserted by exporting as each role and comparing row counts · a student name beginning with `=` is neutralized in the XLSX output, asserted by reading the generated file · `composer verify` green.

---

## Task 12 — Phase reconciliation
**Wave 8 · Owner: Claude · `p2/t12-reconciliation` · depends on all**

**File scope**
- `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md`, `docs/ENGINEERING.md`, `docs/CHANGELOG.md`, this plan
- `lang/en/`, `lang/ar/` — gaps found by the i18n sweep
- `tests/Feature/LocalizationTest.php`, `tests/Feature/ActivityLogTest.php` — coverage extensions only
- **Read-only scheduling-seam re-check: `routes/console.php`** — compare against current `main` and prove the receipt reconciliation, backup pipeline and pending-file deletion sweep each appear exactly once with their intended mutexes. Task 6 owns the receipt entry; task 12 does not rewrite it merely to claim ownership.
- **`app/Providers/AppServiceProvider.php` — comment only, and the one exception to "no `app/` changes" below.** The comment above the `Gate::policy()` list says those policies "live outside app/Policies, so Laravel's convention-based discovery will not find them" and that "without these lines every check against them silently falls through to false". **Both claims are false** — `Gate::guessPolicyName()` maps `\Models\` to `\Policies\` (`Gate.php:725-727`), which resolves every policy in this codebase. It is also the claim that made each phase-2 task dutifully append to a list it did not need. Correct the comment; then decide, and record, whether the existing lines stay as deliberate explicitness or go. Either is defensible; the comment asserting a necessity that does not exist is not.
- No other `app/` changes. If the sweep finds a hardcoded string in application code, that is a fix in the owning task's file, raised rather than absorbed here.

**Does**
The i18n sweep and its enforcement test extended over every new surface. Activity-log coverage confirmed for every financial mutation in design §12. Re-read the hand-maintained seam inventory against `main`, including counting every `routes/console.php` schedule after Task 6; a dropped or duplicated scheduler line is a finding, not a documentation edit. Then the documentation, in the same pass: the system design corrected wherever phase 2 changed it, `docs/ENGINEERING.md` updated with any convention this phase established — the local-period-to-UTC rule, the never-dehydrate-a-guarded-field rule, the immutable-issued-document-snapshot distinction, and **the money-field rule task 2P enforces** (`->numeric()` installs a float state cast, so a money field uses `->inputMode('decimal')` and validation rules instead) are all candidates. That file is deliberately left to this task rather than edited by each task that learns something, for the reason the seam analysis gives — this plan marked complete with its deviations recorded, and `docs/CHANGELOG.md` written in plain language.

**Done when**
No hardcoded user-facing string survives the enforcement test · every financial mutation produces a log entry with the right actor · no document contradicts the code · `composer verify` green on `main`.
