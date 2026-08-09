# Phase 2 — Financials Implementation Plan

**Status:** Awaiting Codex plan review (`docs/WORKFLOW.md` step 0). **No task begins until that review signs off.**

**Goal:** A working system where a student is enrolled, billed, and takes a receipt away from the desk; where balances and revenue are always derivable from source rows; where staff compensation is configured and paid without history moving; and where every figure exports to Excel and PDF.

**Reference documents:**
- Design: `docs/superpowers/specs/2026-08-09-phase-2-financials-design.md` — **authoritative for this phase**
- System design: `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md`
- Standards: `docs/ENGINEERING.md`
- Workflow: `docs/WORKFLOW.md`

---

## Step 0 — Codex reviews this plan first

Non-optional, and it is the reason this document exists before any code.

Phase 1's Task 4 needed five rounds (T04 → T04e) and every one of them inherited the same defect: **the plan specified an unsound enforcement architecture**, visible in the written plan, and nobody questioned the architecture until five rounds of code remediation had each found a different bypass. Phase 2 is hard invariants over an unbounded write surface — the identical shape.

**Codex is reviewing the architecture and the enforcement, not the prose.** The four highest-value targets, in order:

1. **`DeleteEnrollmentAction` and the charge foreign key** (design §12). Every enrolment now has a bill and financial keys restrict on delete, so enrolment deletion breaks the day this ships. The proposed fix deletes across a domain boundary; does it hold?
2. **`IssueChargeAction` performing no ability check** (design §10). It is the one deviation from "every Action authorizes itself", and it exists because staff hold `create_enrollment` and no charge permission. Is the internal-collaborator boundary real, or is it a hole with an explanation attached?
3. **`PaymentInvariantService`** (design §5, §11). Tender total equals allocation total, and allocation never exceeds outstanding, both derived under one lock. Is the lock on the charge sufficient, or is there a second table whose state the decision reads without locking? P1-T10d is the cautionary tale.
4. **The generated-column uniqueness for payroll** (design §7). `finalized_at` is denormalized onto the line so a stored generated column can carry the assignment id only when finalized. Does the salary composite actually admit the two legitimate lines a mid-period raise produces while refusing a duplicate?

---

## Task order and dependencies

| Task | Owner | Depends on | Notes |
|---|---|---|---|
| 1 Finance foundation | Claude | — | Everything depends on it |
| 2 Pricing & discounts | Codex | 1 | |
| 3 Enrol & bill | Claude | 1, 2 | Touches phase 1 Actions |
| 4 Payments & tenders | Claude | 1 | Security-critical |
| 5 Charge corrections | Codex | 1 | |
| 6 Receipts | Codex | 4 | Adds one dispatch line to T04's Action |
| 7 Compensation | Codex | 1 | |
| 8 Payroll runs | Claude | 7 | Invariant-heavy |
| 9 Enrol-and-collect flow | Claude | 3, 4, 6 | The phase's primary UI |
| 10 Report queries | Claude | 4 | |
| 11 Report pages & export | Codex | 10 | |
| 12 Phase reconciliation | Claude | all | |

**Tasks 2, 5 and 7 are mutually independent and share no files** — they parallelize cleanly the moment task 1 lands. Task 4 also runs alongside them.

### Translation files are split per task, deliberately

A single `lang/en/finance.php` touched by nine tasks is a guaranteed merge conflict. Each task owns its own file: `pricing.php`, `billing.php`, `receipt.php`, `payroll.php`, `reports.php`. Arabic counterparts ship **empty** until phase 4.

---

## Task 0 (every task): isolation

Per `docs/WORKFLOW.md`. At the start:

```bash
git worktree add ../Training-center-worktrees/P2-T{NN} -b p2/t{nn}-{slug}
```

Then in the new worktree: `composer install`, copy `.env` from the main checkout **without reading it** (it holds the database password), `npm ci`, and `git config core.hooksPath .githooks`. Run `composer dump-autoload` after pulling — the `Tooling\` mapping is needed before any test runs, and a stale autoloader kills the whole suite with an error that reads like a broken merge.

Verify with `composer verify` — one command, one definition, real output. The suite serialises across worktrees on a machine-wide lock; a run that says it is waiting is correct.

At the end: open a PR, get the other agent's review, resolve, merge, then **tag the branch tip and push the tag** before deleting anything.

---

## Task 1 — Finance foundation
**Owner: Claude · `p2/t01-finance-foundation` · depends on nothing**

The whole schema in one task with one owner, because every other task builds on it and splitting it would mean nine tasks racing to define the same tables.

**File scope**
- `database/migrations/` — nine new tables plus the `enrollments.reference` alter
- `app/Domain/Finance/Models/` — all nine models (configuration only: casts, relations, scopes)
- `app/Domain/Finance/Enums/` — `TenderMethod`, `CompensationType`, `PayrollRunType`
- `app/Domain/Finance/Support/Money.php`, `Reference.php`, `ChargeBalance.php`
- `database/factories/`, `database/seeders/RolePermissionSeeder.php`
- `app/Domain/Enrollment/Models/Enrollment.php` — the `reference` attribute only
- `tests/Feature/Finance/`

**Does**
Every table from design §9 with its `CHECK` constraints, foreign keys, indexes and generated columns. The `Money` value object over integer dirham. `Reference` generating `ENR-`/`CHG-`/`RCT-` after insert inside the transaction. `ChargeBalance` as the single definition of outstanding — SQL expression and PHP computation — placed here rather than in the payments task so tasks 4 and 5 can both use it without an ordering dependency between them.

**Done when**
Migrations run clean and roll back clean · every `CHECK` is proven by an insert that violates it · both generated-column unique indexes are proven by inserting a genuine duplicate and asserting MySQL refuses · `Money` is tested including a case that actually rounds · permissions seeded with the grants in design §10 · `composer verify` green with real output.

---

## Task 2 — Pricing and discounts
**Owner: Codex · `p2/t02-pricing-discounts` · depends on 1**

**File scope**
- `app/Domain/Finance/Filament/Resources/DiscountResource*`, `Policies/DiscountPolicy.php`
- `app/Domain/Finance/Actions/` — `CreateDiscountAction`, `DeactivateDiscountAction`, `DeleteDiscountAction`, `UpdateCoursePriceAction`, `UpdateBatchPriceAction`
- `app/Domain/Finance/Services/PricingService.php`
- **Declared crossing:** `CourseResource`, `BatchResource` and their Create/Edit pages — the price field gate only
- `lang/en/pricing.php`, `lang/ar/pricing.php` (empty)
- `tests/Feature/Finance/`, and the `BatchResourceTest` inversion

**Does**
Live price inheritance (`batches.price ?? courses.default_price`) in `PricingService`. The `manage_pricing` field gate: price fields `disabled()` and `dehydrated(false)` without the ability, **and** the Action refusing the change regardless of what was submitted. `apply_discount` as a seeded ability. A discount's percentage immutable once any charge references it.

**Done when**
An admin can edit a course but cannot change its price — asserted through the real Livewire component, not by checking a policy method · a discount referenced by a charge cannot have its percentage changed and cannot be deleted, the latter refused as a typed exception converted from MySQL 1451 · `BatchResourceTest` now asserts the field is present and gated · `composer verify` green.

---

## Task 3 — Enrol and bill
**Owner: Claude · `p2/t03-enroll-and-bill` · depends on 1, 2**

The highest integration risk in the phase: it touches phase 1 Actions and crosses a domain boundary in both directions.

**File scope**
- `app/Domain/Finance/Actions/IssueChargeAction.php`, `EnrollAndBillAction.php`
- `app/Domain/Finance/Services/ChargeQueryService.php`
- `app/Domain/Enrollment/Services/EnrollmentQueryService.php` — **does not exist yet**; named in the system design and in `docs/ENGINEERING.md` and never built
- **Declared crossing:** `app/Domain/Enrollment/Actions/DeleteEnrollmentAction.php`
- `app/Domain/Finance/Exceptions/`, `lang/en/billing.php`
- `tests/Feature/Finance/`

**Does**
`EnrollAndBillAction` wraps `EnrollStudentAction` and `IssueChargeAction` in one transaction, authorizing `create` on `Enrollment` plus `apply_discount` when a discount was chosen. `IssueChargeAction` performs no ability check and is reachable only from here — enforced by an architecture test, because staff hold `create_enrollment` and no charge permission, and demanding one would break the walk-in enrolment the system exists for. `DeleteEnrollmentAction` consults `ChargeQueryService`, refuses once the bill carries any payment, adjustment or write-off, and otherwise deletes bill and enrolment together.

**Done when**
A staff member with no finance permission can enrol a walk-in and the bill is raised · the charge froze list price, percentage and amount, and does not move when the course price later changes · an enrolment with an unpaid bill is deletable and one with a payment is refused, both asserted · the architecture test fails if `IssueChargeAction` is called from anywhere else — proven by injecting a violation · `composer verify` green.

---

## Task 4 — Payments and tenders
**Owner: Claude · `p2/t04-payments` · depends on 1**

The security-critical task of the phase.

**File scope**
- `app/Domain/Finance/Actions/RecordPaymentAction.php`, `ReversePaymentAction.php`
- `app/Domain/Finance/Services/PaymentInvariantService.php`
- `app/Domain/Finance/Data/RecordPaymentData.php`, `TenderData.php`
- `app/Domain/Finance/Filament/Resources/PaymentResource*`, `Policies/PaymentPolicy.php`
- `app/Domain/Finance/Rules/NotACardNumber.php`
- `tests/Feature/Finance/`

**Does**
Atomic create-and-finalize — payment, tenders and allocations in one transaction, no draft state. `PaymentInvariantService` locks the charge, derives outstanding under that lock, and checks tender total equals allocation total and allocation does not exceed outstanding. Reversal as a set-once lifecycle transition, super admin only. The PAN-shaped-input rule on the external reference. `PaymentPolicy` refusing update and delete unconditionally.

**Done when**
A split payment of 300 card + 700 cash against a 1,000 bill produces one payment, two tenders, one allocation and a zero balance · a card tender with no reference is refused by the database `CHECK`, proven by direct insert · paying more than outstanding is refused, including when outstanding drops between form load and submit · two concurrent payments against one bill yield one success and one typed refusal · a reversed payment leaves every row intact and drops out of the balance · granting `update_payment` does not make the policy allow it · `composer verify` green.

---

## Task 5 — Charge corrections
**Owner: Codex · `p2/t05-charge-corrections` · depends on 1**

**File scope**
- `app/Domain/Finance/Actions/AdjustChargeAction.php`, `WriteOffChargeAction.php`
- `app/Domain/Finance/Filament/Resources/ChargeResource*`, `Policies/ChargePolicy.php`
- `tests/Feature/Finance/`

**Does**
Both Actions super-admin-only with a mandatory reason written into the activity log alongside the before/after diff. `AdjustChargeAction` refuses to drop the amount below what is already allocated. `ChargeResource` is read-plus-two-actions: it sorts and filters outstanding through `ChargeBalance`'s SQL expression, never through a stored column.

**Done when**
An admin holding every charge read permission cannot adjust or write off — asserted through the real component · adjusting below the allocated total is refused · the reason reaches the activity log and is visible in `ActivityResource` · `ChargePolicy::create/update/delete` refuse even when the permission is granted · `composer verify` green.

---

## Task 6 — Receipts
**Owner: Codex · `p2/t06-receipts` · depends on 4**

**Dependency change, pre-approved by the owner:** `composer require mpdf/mpdf`. Native Arabic shaping and RTL, pure PHP, no system dependency, works in a queue worker. See design §8 for why not Browsershot and why not dompdf.

**File scope**
- `app/Domain/Finance/Jobs/GenerateReceiptJob.php`
- `resources/views/finance/receipt.blade.php`
- `app/Http/Controllers/Finance/ReceiptDownloadController.php`, `routes/web.php`
- **Declared crossing:** one dispatch line in `RecordPaymentAction` — sequential after task 4 merges, so it is not a parallel edit
- `lang/en/receipt.php`, `lang/ar/receipt.php` (empty)

**Does**
Queued generation to the private disk, dispatched `afterCommit()`. Every field listed in design §2. Download through a policy-authorized controller reusing the `StaffCertificateDownloadController` and `AuthenticatePrivateFileSession` pattern. All strings through `__()`, including composite ones, which get their own keys rather than being joined in code.

**Done when**
Every required field appears, verified against a rendered PDF · the file lands on the private disk and is not reachable without authorization · a reversed payment's receipt is not deleted · the template renders at `dir="rtl"` without layout breakage, using logical CSS properties only · `composer verify` green.

---

## Task 7 — Compensation
**Owner: Codex · `p2/t07-compensation` · depends on 1**

**File scope**
- `app/Domain/Finance/Actions/ChangeCompensationAction.php`
- `app/Domain/Finance/Services/CompensationPeriodInvariantService.php`
- `app/Domain/Finance/Filament/Resources/StaffCompensationResource*`, `Policies/StaffCompensationPolicy.php`
- `lang/en/payroll.php`, `lang/ar/payroll.php` (empty)
- `tests/Feature/Finance/`

**Does**
A raise closes the previous row and inserts a new one in one transaction. Rates are never overwritten — `update_staff_compensation` is not seeded and the policy refuses. The overlap invariant holds a lock and checks, with the honest statement that it protects the application path only.

**Done when**
A raise produces two rows with contiguous, non-overlapping periods · an overlapping period is refused · two concurrent changes serialize rather than both passing · granting the update permission does not make the policy allow it · `composer verify` green.

---

## Task 8 — Payroll runs
**Owner: Claude · `p2/t08-payroll` · depends on 7**

**File scope**
- `app/Domain/Finance/Actions/CreatePayrollRunAction.php`, `FinalizePayrollRunAction.php`, `AdjustPayrollLineAction.php`
- `app/Domain/Finance/Services/PayrollCalculator.php`
- `app/Domain/Finance/Filament/Resources/PayrollRunResource*` and its draft-review page
- `app/Domain/Finance/Policies/PayrollRunPolicy.php`
- `tests/Feature/Finance/`

**Does**
Both run types. `monthly_salary` pro-rates by days covered, so a mid-period raise produces two lines at two rates. `instructor_batch` is on-demand: the draft lists every assignment not already paid in a finalized run — derived by looking at finalized lines, with no stored paid flag — and the operator ticks which to include. Finalization copies rate, quantity, amount, `finalized_at` and period onto each line and is irreversible. Line adjustments are child rows addable only while draft.

**Done when**
An assignment already paid in a finalized run cannot appear in another, **proven at the database** by inserting the duplicate directly and asserting MySQL refuses · a mid-period raise produces exactly two salary lines and the composite index admits both · a finalized run cannot be edited, deleted, or have an adjustment added · a rate changed after finalization does not move the finalized figure · `composer verify` green.

---

## Task 9 — Enrol-and-collect flow
**Owner: Claude · `p2/t09-enroll-and-collect` · depends on 3, 4, 6**

The phase's primary user-facing surface, and the reason the rest of it is worth having.

**File scope**
- `app/Domain/Finance/Filament/Pages/EnrollAndCollect.php` and its schema/steps
- `resources/views/filament/finance/`
- `lang/en/billing.php` — additions only; the file is created in task 3
- `tests/Feature/Finance/`

**Does**
The eight-step flow from design §2: find or create student, select batch, optional discount, preview original / discount / final, confirm, optionally collect full or partial, cash / card / split tenders, finalize to a receipt. Allocation is decided by context and never shown to the operator.

**Done when**
The full flow is driven through Livewire end to end and produces enrolment, bill, payment, tenders, allocation and receipt · the preview figure matches the issued charge exactly · attempting to collect more than outstanding is refused in the UI **and** by the Action · a card tender without a reference cannot be submitted · every string is translatable and the page renders at `dir="rtl"` · `composer verify` green.

---

## Task 10 — Report queries
**Owner: Claude · `p2/t10-report-queries` · depends on 4**

Query services with **no UI at all**, so every figure is tested in isolation before anything renders it.

**File scope**
- `app/Domain/Finance/Reports/` — one class per report from design §8
- `tests/Feature/Finance/Reports/`

**Done when**
Every report is asserted against a fixture with known figures · **a reversed payment is asserted absent from each report individually**, not once across all of them · written-off charges are excluded from the aged report and present in history · the method breakdown splits a single split-tender payment across two methods · the daily tender report matches the sum of that day's finalized non-reversed tenders · `composer verify` green.

---

## Task 11 — Report pages and export
**Owner: Codex · `p2/t11-reports-export` · depends on 10**

**File scope**
- `app/Domain/Finance/Filament/Pages/Reports/`
- `app/Domain/Finance/Exports/` — Filament exporters
- `app/Domain/Finance/Jobs/GenerateReportPdfJob.php`, `resources/views/finance/reports/`
- `database/migrations/` — publish Filament's `exports` and `failed_import_rows` tables, which this project has never published
- `lang/en/reports.php`, `lang/ar/reports.php` (empty)

**Does**
A Filament page per report, gated on `view_financial_report`. XLSX through Filament's native queued export over the already-installed openspout — **no new Excel dependency**. PDF through the mPDF renderer added in task 6. Both queued, both notifying in-app on completion.

**Done when**
An admin can view and export; a staff member can reach neither · **the export query is scoped by the requesting user's permissions**, asserted by exporting as each role and comparing row counts · a student name beginning with `=` is neutralized in the XLSX output, asserted by reading the generated file · `composer verify` green.

---

## Task 12 — Phase reconciliation
**Owner: Claude · `p2/t12-reconciliation` · depends on all**

**Does**
The i18n sweep and its enforcement test extended over every new surface. Activity-log coverage confirmed for every financial mutation listed in design §12. Then the documentation, in the same pass and not as a follow-up: the system design corrected wherever phase 2 changed it (`charges.status`, `payments.method`, `per_student`, price inheritance, the permission matrix), `docs/ENGINEERING.md` updated with any convention this phase established, this plan marked complete with its deviations recorded, and `docs/CHANGELOG.md` written in plain language.

**Done when**
No hardcoded user-facing string survives the enforcement test · every financial mutation produces a log entry with the right actor · no document contradicts the code · `composer verify` green on `main`.
