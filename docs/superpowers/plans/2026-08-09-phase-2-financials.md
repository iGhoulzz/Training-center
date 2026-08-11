# Phase 2 — Financials Implementation Plan

**Status:** Revision 6 — the financial design review is closed. Scheduling is reorganised into waves under the revised `docs/WORKFLOW.md`. **Task 1 may begin after the scheduling pull request is approved and merged.**

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

---

## Waves, ownership and dependencies

Per `docs/WORKFLOW.md`: **at most one Claude task and one Codex task run at a time**, they must share no files, and a downstream worktree is cut from updated `main` only after its dependency has merged with green CI.

| Wave | Claude | Codex | Unblocked by finishing |
|---|---|---|---|
| 1 | **T1** Finance foundation | *(reviews T1)* | everything |
| 2 | **T5** Charge corrections | **T2** Pricing & discounts | T2 unblocks T3 |
| 3 | **T3** Enrol & bill | **T7** Compensation | T3 unblocks T4/T8/T10; T7 unblocks T8 |
| 4 | **T4** Payments & tenders | **T8** Payroll runs | T4 unblocks T6/T9/T10 |
| 5 | **T10** Report queries | **T6** Receipts | both unblock T11; T6 unblocks T9 |
| 6 | **T9** Enrol-and-collect | **T11** Report pages & export | |
| 7 | **T12** Phase reconciliation | *(reviews T12)* | milestone closes |

Seven waves, and **both agents have work in waves 2 through 6** — no idle slot in the middle of the phase.

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

| Wave | Pair | Overlap |
|---|---|---|
| 2 | T5 charges · T2 pricing | none — `ChargeResource`/`charges.php` against `DiscountResource`/pricing Actions/`pricing.php` and the two Enrollment resource tests |
| 3 | T3 billing · T7 compensation | none — Finance Actions, the two query services and `billing.php` against the compensation resource and `payroll.php` |
| 4 | T4 payments · T8 payroll | none — payment Actions and `payments.php` against payroll Actions and `payroll.php` |
| 5 | T10 reports · T6 receipts | none — `Reports/` and `ReportPeriod` against the receipt job, view, controller and `receipt.php` |
| 6 | T9 flow · T11 report pages | none — the collect page and `collect.php` against report pages, exporters and `reports.php` |

Three files are touched by more than one task, and every case is **sequential across waves**, never concurrent: `tests/Feature/Staff/ActionBoundaryArchTest.php` (T1 → T3 → T6), `lang/en/payroll.php` (T7 → T8), and `RecordPaymentAction` (T4, then T6 adds its dispatch line).

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

The whole schema in one task with one owner, because every other task builds on it.

**File scope**
- `database/migrations/` — nine new tables, plus **four** single-statement `enrollments.reference` migrations (add nullable · backfill · index · tighten)
- `app/Domain/Finance/Models/` — all nine models (configuration only)
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
Every table from design §9 with its `CHECK` constraints, foreign keys, indexes and generated columns — including the three-shape `CHECK` on `payroll_lines`, the nullable `frozen_rate`, the nullable-and-indexed `posting_period_start` paired to `finalized_at`, and **`payments.request_fingerprint`**, which task 4 needs and owns no migration to create.

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
`DatabaseTruncation` for the three files that need real commits or DDL. The
backfill file continues restoring the four reference migrations after every
scenario; truncation clears row data between scenarios without wrapping them in
the transaction that would invalidate their proofs. No behavioral case is
combined, skipped, or moved out of the full suite.

**Done when**
All thirteen existing tests retain their individual names and assertions · the
isolation architecture test recognizes a real `DatabaseTruncation` declaration
and still rejects prose-only mentions · focused before/after timings are
recorded for all three files · the full `composer verify` gate remains green ·
CI partitioning is not added unless the measured result still justifies that
extra workflow surface.

**Measured outcome (2026-08-11, local MySQL)**
The three pre-change focused runs totalled 155.092 seconds
(79.510 + 14.206 + 61.376). The same thirteen cases after the change passed in
17.118 seconds with all 85 assertions intact. The full gate passed 1,322 tests
(1,321 passed, one skipped) and 4,016 assertions in 448.481 seconds, down from
the approximately 590-second pre-change run. That reduction is large enough
that CI partitioning is deliberately deferred; adding jobs and Composer entry
points now would cost more complexity for less benefit than this direct fix.

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

## Task 3 — Enrol and bill
**Wave 3 · Owner: Claude · `p2/t03-enroll-and-bill` · depends on 1, 2**

The highest integration risk in the phase, and the task that closes the unbilled-enrolment path.

**File scope**
- `app/Domain/Finance/Actions/` — `EnrollAndBillAction`, `IssueChargeAction`, `DeleteUncommittedChargeAction`
- `app/Domain/Finance/Services/ChargeQueryService.php` (read-only)
- `app/Domain/Enrollment/Services/EnrollmentQueryService.php` — **does not exist yet**; built here with the full surface **tasks 4, 6, 8 and 10** need (design §12 names each consumer's requirement)
- **Declared crossings:** `DeleteEnrollmentAction`, `EnrollmentsRelationManager`
- `tests/Feature/Staff/ActionBoundaryArchTest.php` — the new enrolment rule
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
- `app/Domain/Finance/Jobs/GenerateReceiptJob.php`, `app/Domain/Finance/Actions/AttachReceiptAction.php`
- **Declared crossing: `tests/Feature/Staff/ActionBoundaryArchTest.php`** — registering `AttachReceiptAction` as the third and last internal collaborator, with `GenerateReceiptJob` as its only permitted caller
- `resources/views/finance/receipt.blade.php`
- `app/Http/Controllers/Finance/ReceiptDownloadController.php`, `routes/web.php`
- **Declared crossing:** one dispatch line in `RecordPaymentAction` — sequential after task 4 merges, so not a parallel edit
- `lang/en/receipt.php`, `lang/ar/receipt.php` (empty)
- `tests/Feature/Finance/ReceiptGenerationTest.php` - every required field in a rendered PDF, retry-safety, one receipt after a replayed payment
- `tests/Feature/Finance/ReceiptDownloadTest.php` - policy-authorized download, unreachable without it, reversed payment's receipt retained

**Does**
Queued generation to the private disk, dispatched `afterCommit()`. Every field listed in design §2. Download through a policy-authorized controller reusing the `StaffCertificateDownloadController` and `AuthenticatePrivateFileSession` pattern.

**Done when**
Every required field appears, verified against a rendered PDF · the file lands on the private disk and is unreachable without authorization · a reversed payment's receipt is not deleted · **generation is retry-safe and idempotent** — a re-run of the job for the same payment leaves exactly one receipt and one stored path, and `AttachReceiptAction` refuses a payment that already has one (the queue mechanics are this task's to choose and prove) · **a replayed payment submission produces exactly one receipt** — the assertion task 4 could not make, because receipts did not exist there · the enrolment reference and course and batch codes are read through `EnrollmentQueryService` · the template renders at `dir="rtl"` without layout breakage, logical CSS properties only · `composer verify` green.

---

## Task 7 — Compensation
**Wave 3 · Owner: Codex · `p2/t07-compensation` · depends on 1**

**File scope**
- `app/Domain/Finance/Actions/ChangeCompensationAction.php`
- `app/Domain/Finance/Services/CompensationPeriodInvariantService.php`
- `app/Domain/Finance/Filament/Resources/StaffCompensationResource*`, `Policies/StaffCompensationPolicy.php`
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
- `app/Domain/Finance/Actions/` — `CreatePayrollRunAction`, `FinalizePayrollRunAction`, `AdjustPayrollLineAction`
- `app/Domain/Finance/Services/PayrollCalculator.php`
- `app/Domain/Finance/Filament/Resources/PayrollRunResource*` and its draft-review page
- `app/Domain/Finance/Policies/PayrollRunPolicy.php`
- `lang/en/payroll.php` — **additions only**; the file is created in task 7
- `tests/Feature/Finance/PayrollSegmentTest.php` — partial previous month plus full current month, mid-period raise, denominators
- `tests/Feature/Finance/PayrollFinalizationTest.php` — double-pay refused at the database, overlap refused by the lock, shape-versus-type
- `tests/Feature/Finance/PayrollAdjustmentRunTest.php` — the four invariants and the posting period

**Does**
All three run types. `monthly_salary` builds **segment lines** by intersecting the run period, each calendar month, and each compensation row's validity, freezing segment bounds, rate, days and denominator. `instructor_batch` is on-demand: the draft lists assignments not already paid in a finalized run, derived by looking at finalized lines with no stored paid flag, and **freezes the assigned hours as well as the rate**. `adjustment` runs correct finalized lines with signed amounts and a mandatory reason.

`AdjustPayrollLineAction` enforces design §7's four rules: the employee is derived from the locked target line, the target must be finalized, the amount must be non-zero, and a correction may not target another correction. A correction carries the **posting period of the line it corrects**.

Finalization copies the frozen figures, `finalized_at` and `posting_period_start` onto each line and is irreversible. **It also verifies that each line's shape matches the run's type** — a row-level `CHECK` cannot see `payroll_runs.type`, so the constraint proves a line is internally coherent and only the Action can prove it belongs where it sits. Overlap between finalized salary segments is refused under a `users`-row lock, and **locks are taken in ascending user id order** so two runs over overlapping staff cannot deadlock. Reads instructor assignments through `EnrollmentQueryService`.

**Done when**
**A run covering a partial previous month plus a full current month produces the correct segment lines with the correct per-month denominators** · a mid-period raise splits a month into two correctly-priced segments · an assignment already paid in a finalized run cannot appear in another, **proven at the database** by inserting the duplicate directly · **overlapping salary segments across two runs are refused by the lock**, with a test that fails if the lock is removed · **two concurrent finalizations over overlapping staff complete without deadlock** · **a finalized instructor line still explains its amount after `assigned_hours` is changed underneath it** · **a draft line persists with a null posting period, and finalization refuses a line whose shape does not match its run's type** · a finalized run cannot be edited, deleted, or have a draft adjustment added, and is corrected only by an adjustment run · **correcting March in June produces a correction line carrying March's posting period**, asserted on the line, since the wage-cost report belongs to task 10 · a correction targeting a correction is refused · a zero-amount correction is refused · a rate changed after finalization does not move the finalized figure · `composer verify` green.

---

## Task 9 — Enrol-and-collect flow
**Wave 6 · Owner: Claude · `p2/t09-enroll-and-collect` · depends on 3, 4, 6**

The phase's primary user-facing surface.

**File scope**
- `app/Domain/Finance/Filament/Pages/EnrollAndCollect.php` and its schema/steps
- `resources/views/filament/finance/`
- `lang/en/collect.php`, `lang/ar/collect.php` (empty)
- `tests/Feature/Finance/EnrollAndCollectFlowTest.php` — the full flow through Livewire, staff sees no discount, double-submit

**Does**
The eight-step flow from design §2. Allocation is decided by context and never shown to the operator. The idempotency key is minted when the collection step is first rendered.

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
**Wave 6 · Owner: Codex · `p2/t11-reports-export` · depends on 6, 10**

**File scope**
- `app/Domain/Finance/Filament/Pages/Reports/`
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
**Wave 7 · Owner: Claude · `p2/t12-reconciliation` · depends on all**

**File scope**
- `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md`, `docs/ENGINEERING.md`, `docs/CHANGELOG.md`, this plan
- `lang/en/`, `lang/ar/` — gaps found by the i18n sweep
- `tests/Feature/LocalizationTest.php`, `tests/Feature/ActivityLogTest.php` — coverage extensions only
- No `app/` changes. If the sweep finds a hardcoded string in application code, that is a fix in the owning task's file, raised rather than absorbed here.

**Does**
The i18n sweep and its enforcement test extended over every new surface. Activity-log coverage confirmed for every financial mutation in design §12. Then the documentation, in the same pass: the system design corrected wherever phase 2 changed it, `docs/ENGINEERING.md` updated with any convention this phase established — the local-period-to-UTC rule and the never-dehydrate-a-guarded-field rule are both candidates — this plan marked complete with its deviations recorded, and `docs/CHANGELOG.md` written in plain language.

**Done when**
No hardcoded user-facing string survives the enforcement test · every financial mutation produces a log entry with the right actor · no document contradicts the code · `composer verify` green on `main`.
