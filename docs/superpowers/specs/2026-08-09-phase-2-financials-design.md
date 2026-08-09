# Phase 2 — Financials: Design

**Date:** 2026-08-09
**Status:** Draft — awaiting owner review, then Codex plan review (`docs/WORKFLOW.md` step 0)
**Supersedes:** the phase 2 sections of `2026-07-20-training-center-dashboard-design.md` wherever the two disagree. Those sections were written at architectural detail before a charge model existed; this document is at implementation detail and is authoritative for phase 2.

---

## 1. Purpose and scope

Phase 2 gives the centre its money. Students are billed for the courses they enrol on, payments are recorded against those bills, staff compensation is configured and paid, and the whole thing reports and exports.

**In scope:** pricing, discounts, charges, payments, allocations, balances, compensation configuration, payroll, financial reports, Excel and PDF export, printed receipts.

**Out of scope, and not to be reintroduced:** everything in section 12 of the system design, plus these, decided here:

- **No refunds.** Money is strictly one-directional. Courses are face-to-face; a student who did not pay simply owes, and a student who paid is not paid back.
- **No credit balances or prepayment.** A payment can never exceed the bill's outstanding amount, so unallocated money cannot exist.
- **No payment gateway.** Cash is handed to staff, cards go through an external terminal. The application records what already happened.
- **No persisted drawer reconciliation.** A live daily tender report only — see §8.
- **No installment schedules.** One bill per enrolment, payable in as many parts as the student likes.
- **No standalone fees.** Every charge belongs to an enrolment.

---

## 2. The central workflow: enrol and collect

Phase 2's primary surface is one guided flow, not a set of CRUD screens the user is expected to assemble.

1. Find or create the student.
2. Select the course batch.
3. Select an optional enrolment discount.
4. **Preview** the original price, the discount, and the final amount.
5. Confirm — creates the enrolment (`ENR-…`) and the bill (`CHG-…`) in one transaction.
6. Optionally collect immediately: the full amount, or an installment.
7. Choose cash, card, or split. A split adds tender lines under one payment — 300 by card and 700 by cash is one payment with two tenders.
8. Finalize — one receipt (`RCT-…`) with its own number.

The system allocates the payment to that bill. **Staff never see the word "allocation" and never make an allocation decision.**

Later installments: search the student, open the unpaid bill, record another payment, issue another receipt. **One bill, many receipts**, all pointing back to the same `CHG-…`.

This flow is the reason the enrolment and finance domains are built together rather than one after the other. Building the resources first and the flow last would produce a system that is technically complete and operationally unusable — the front desk would be asked to visit three screens to take one payment.

### The receipt

Every finalized payment produces a PDF the student is handed. It shows:

student code and name · enrolment reference · course and batch codes · bill reference · original price · discount · final charge · **amount paid** · cash/card breakdown · **remaining balance** · payment date · recording staff member.

Receipts are generated to the private disk (§6 of the system design) and served only through a policy-authorized download. Every string on the receipt goes through `__()`, because phase 4 will need it in Arabic and a receipt is the most public-facing document the system produces.

### Reference series

| Series | Table | Format |
|---|---|---|
| `ENR-` | `enrollments` (new column on a phase 1 table) | `ENR-{year}-{id padded to 6}` |
| `CHG-` | `charges` | `CHG-{year}-{id padded to 6}` |
| `RCT-` | `payments` | `RCT-{year}-{id padded to 6}` |

Each carries a unique index. The reference is written **immediately after insert inside the same transaction** — MySQL forbids a generated column from referencing an `AUTO_INCREMENT` column, so the tidier stored-generated-column approach used elsewhere in this design is not available here.

These are deliberately **not** the phase 3 certificate pattern. A certificate reference is exposed to an unauthenticated public verifier and must therefore be unguessable; a bill reference is read by the person holding the bill, and a sequential, human-readable, phone-dictatable number is the correct trade-off. **Do not "harden" these into random strings.**

Gaps are possible where a transaction rolls back. See §13.

---

## 3. Pricing and discounts

### Price inheritance — resolved

The system design left this open at line 215 and forbade implementing it before phase 2 decided. The decision:

**A null `batches.price` falls back to `courses.default_price` on every read.** Live inheritance, never a copy — identical to the `effective_total_hours` accessor that already proves the pattern at `app/Domain/Enrollment/Models/Batch.php`. Correcting a course price corrects every inheriting batch at once, and there is no stale duplicate to hunt for.

**An issued charge does not move.** The charge stores the list price, the discount percentage applied, and the resulting amount. A later price change or discount edit affects future enrolments only. This is the same freeze rule payroll uses, for the same reason: history that changes when upstream data changes is not history.

### Discounts

A new table of reusable percentage definitions — 10%, 30%. Not in the original design.

- **One optional discount per enrolment. No stacking.**
- A discount applies to **an enrolment**, never permanently to a student.
- **A discount's percentage is immutable once any charge references it.** Superseding a rate means deactivating the definition and creating a replacement. Retroactively editing a percentage would silently restate what the centre charged people in the past — every issued charge froze its own copy, so the stored history would survive, but the definition the reports join against would lie about it.
- `is_active` controls whether the definition is offered on new enrolments. Definitions are never deleted while referenced; the foreign key restricts, and `DeleteDiscountAction` converts MySQL 1451 into a typed refusal exactly as `DeleteCourseAction` and `DeleteBatchAction` already do.

### The rounding rule, stated once

`amount = round(list_price × (100 − percentage) ÷ 100)` in **integer dirham**, half-up.

Defined in one place and tested with a case that actually rounds. A test using a price that divides evenly proves nothing.

### Who may set a price, and who may discount

The permission matrix gives admins full control of courses and batches but pricing at view-only. Those are the same records, so the boundary is **field-level**:

- **`manage_pricing`** — super admin only. Without it, `courses.default_price` and `batches.price` render disabled and de-hydrated, and the Action refuses a price change regardless of what the form submitted. Disabling a field in Filament is a UI affordance; the Action is the boundary.
- **`apply_discount`** — held by staff, admin and super admin **for now**. It exists as a permission precisely so that restricting it later is a `RolePermissionSeeder` edit and not a code change.

---

## 4. Charges (bills)

**One charge per enrolment**, raised automatically as part of enrolling, payable in parts.

### No status column

The system design's `charges.status` enum was `unpaid | partial | paid | waived`. Three of those four are **derived from allocations**, and section 6 of that design forbids storing derived values in the same breath. Storing them is the `paid_amount` mistake wearing a different name: one write path that forgets to recompute, or one direct SQL correction, and the register lies while looking authoritative.

**Resolved:** there is no status column. The table stores the *facts* a human decided — `written_off_at`, `written_off_by`, `written_off_reason` — and unpaid / partial / paid are computed by summing allocations. Filament sorts and filters through a SQL subquery.

The same rule is applied throughout Finance: **no status string column exists on any table in this domain.** Lifecycle is recorded as nullable fact columns and the label is derived. An architecture test enforces it.

### Correcting a charge

`AdjustChargeAction` is the only path, and it exists for **data-entry errors only — never for applying a late discount**. Discounts are selected before enrolment is confirmed and are frozen when the charge is created; anyone reaching for the adjustment tool to give someone 10% off is defeating the mechanism that makes discounts auditable.

- Super admin only, via `adjust_charge`.
- A reason is mandatory and is written into the activity log's properties alongside the before/after diff. The charge table carries no adjustment columns, because the activity log *is* the audit record and duplicating it onto the row creates a second thing to keep true.
- The Action **refuses to drop the amount below what has already been allocated** to the charge.

After an adjustment, `amount` no longer equals `list_price × (100 − percentage) ÷ 100`. That is expected and correct: the frozen figures record what was billed and why, and the adjustment records that a human corrected it. A reviewer should not read the arithmetic divergence as drift.

### Writing off a debt

`WriteOffChargeAction`, super admin only via `write_off_charge`, mandatory reason. A written-off balance stops distorting the aged outstanding report while its history stays fully visible. **Nothing is erased.**

Withdrawal is **not** a financial event. Withdrawing an enrolment leaves the bill exactly as it stands.

### Permissions deliberately not created

`create_charge`, `update_charge` and `delete_charge` are **not seeded**, following the reasoning already recorded for the activity log in `RolePermissionSeeder`: seeding an ability nothing honours invites someone to wire it up later. `ChargePolicy::create()`, `update()` and `delete()` return **false unconditionally**, and a test grants the permission anyway and proves the policy still refuses — a stronger statement than "the permission does not exist".

Charges come into existence only through enrolment, and change only through the two Actions above.

**One exception, stated here so it is not read as a contradiction.** §12 requires that an enrolment created in error stays deletable, which means deleting its unpaid bill with it. That deletion happens inside `DeleteEnrollmentAction`, authorized by `delete_enrollment` and refused outright once the bill carries any payment, adjustment or write-off. It does not consult `ChargePolicy`, because the policy answers "may this actor delete a bill on its own", and the answer to that question is no for everyone. See §10 for the same reasoning applied to issuance.

---

## 5. Payments, tenders and allocations

`RecordPaymentAction` is the **receipt-confirmation boundary**. It is used only after cash has been physically counted or the card terminal has shown Approved. The application is recording an event that already happened in the real world; it is not authorising one.

### One receipt, one or more tenders

A single customer payment is **one parent `payments` row** — student, `received_at`, `recorded_by`, `RCT-…`, notes — carrying **one or more `payment_tenders`**, each with a method, an amount, and an optional external reference.

A split payment is simply a payment with two tenders. A 1,000 bill settled with 300 on card and 700 in cash is one payment, one receipt, two tenders. This replaces the original design's single `payments.method` column, which cannot represent the split-tender checkout every retail counter in the world performs.

**A card tender requires the terminal transaction reference**, enforced by a MySQL `CHECK` constraint rather than validation alone, so it is a property of the database and not of the form that happened to be used.

**Card numbers, PINs and CVVs are never stored.** The external reference field holds a terminal transaction reference and nothing else. A validation rule rejects PAN-shaped input — 13 to 19 digits, spaces and dashes ignored — so a card number cannot be typed there out of habit. This is not theoretical hygiene: the field is free text, sits next to a card machine, and is filled in by whoever is at the desk.

### Nothing derived is stored

**`payments` has no `amount` column, no `method` column and no `status` column.** The payment total is `SUM(tenders)`. Charge balances are sums of allocations. Payment state is derived from `reversed_at`. The only stored facts are the ones a human supplied or performed.

### Finalization is atomic

There is no draft. The whole receipt — payment, tenders, allocations — is written in **one transaction**, which checks every invariant under lock and then generates the receipt. A half-finished payment row cannot exist to be found later and misread as money owed or money received.

Two invariants are checked inside that transaction, with the charge row locked:

1. **Tender total equals allocation total.** A payment is self-consistent or it does not exist.
2. **Allocation never exceeds the bill's outstanding balance**, where outstanding is derived *under the same lock* — so a bill that was settled by someone else between form load and submit produces a clean typed refusal rather than an over-payment.

Never accept more than is outstanding. For cash, return change and record only the amount accepted. For card, enter the exact amount charged.

### Reversal

Super admin only, via `reverse_payment`. Reversal is a **lifecycle transition on an immutable row**: `reversed_at`, `reversed_by` and `reversal_reason` are set once and never unset. The payment, its tenders and its allocations are never rewritten and never deleted.

This deliberately reuses the shape the system design already blessed for phase 3 certificates (`revoked_at` / `revoked_by` / `revocation_reason`) rather than inventing a second answer to the same problem.

**Only finalized, non-reversed payments count as collected revenue.** A reversed payment must drop out of every balance, every report and every total — asserted per report, not assumed.

`update_payment` and `delete_payment` are **not seeded**, and `PaymentPolicy::update()`, `delete()` and `deleteAny()` return false unconditionally, with tests that grant the permission and prove refusal anyway. Corrections happen by reversal and re-recording, and a payment row has no delete path at all — the same shape as the activity log, for the same reason.

### Allocations remain load-bearing

The table stays, and every balance is derived by summing it. The phase 2 UI always targets exactly one bill — the one the user opened — so the allocation is decided by context and never by the operator. The many-charge capability is retained in the schema so that a future "pay both my courses at once" flow needs no migration.

**Superseded during design:** auto oldest-first allocation with operator override, and leftover money held as student credit. Both were agreed earlier in the same session and then displaced — the first by bill-targeted allocation, the second by the no-overpayment rule. Recorded so neither is re-derived from the earlier conversation.

---

## 6. Money arithmetic

The column is `decimal(12,3)`; LYD subdivides into 1000 dirham. Laravel's `decimal:3` cast hands PHP a **string**, and adding two of those with `+` silently converts to float. A float cannot represent 0.001 exactly, so the dirham is precisely the digit that gets lost.

- A **`Money` value object over integer dirham**. All arithmetic is integer arithmetic. Construction from a decimal string, formatting through `__()`.
- **Aggregation happens in SQL**, where MySQL's `DECIMAL` sums are exact, and hydrates into `Money`.
- The discount rounding rule (§3) is the only rounding in the system, defined once.
- An architecture test forbids float casts on money attributes anywhere in `app/Domain/Finance`.

---

## 7. Compensation and payroll

### Compensation

Effective-dated, exactly as the original design requires: **a raise inserts a row and closes the previous one**, in one transaction, through `ChangeCompensationAction`. A rate is never overwritten, because overwriting silently corrupts every historical report.

**`per_student` is dropped** from the type enum — the centre does not pay per head. Types are `salary` (a monthly amount) and `hourly` (a per-hour rate). One person may hold both.

`update_staff_compensation` is not seeded and the policy refuses, for the same reason as the charge and payment write abilities: the only legitimate change to a rate is a new row.

**No overlapping periods per person per type.** MySQL cannot express this as a constraint, so it is a lock plus a check inside `CompensationPeriodInvariantService`, and the honest statement is that it protects the application path and not raw SQL.

### Payroll runs

Two run types, both **draft → finalized → immutable**:

**`monthly_salary`** — a period run over salaried staff. A compensation row covering only part of the period is **pro-rated by days**, so a raise landing mid-period produces two lines at two rates rather than one wrong one.

**`instructor_batch`** — an **on-demand** run. The draft lists every instructor-hour assignment not already paid in a finalized run, whatever the batch's status, and the operator ticks which to include. A batch is paid as one lump, either before it starts or after it finishes.

This resolves a gap the original design did not notice: `batch_instructor.assigned_hours` is per *batch* while a payroll run is per *period*, so a 30-hour batch running January to March had no defined January figure. **There is no calendar slicing and no pay-on-start / pay-on-completion setting.** The centre decides when to pay by choosing when to run.

### Freezing, and what actually prevents double payment

Finalizing copies the rate, the quantity, the computed amount, `finalized_at` and (for salary runs) the period onto each line. Historical payroll does not move when upstream data changes.

**An instructor assignment is paid at most once, and that is a database guarantee.** `finalized_at` on the line makes a stored generated column possible, carrying `batch_instructor_id` only while the line is finalized and NULL otherwise, under a unique index. Unique indexes do not collide on NULL, so any number of draft lines coexist while a second finalized line for the same assignment is refused by MySQL.

This is the same partial-unique-index technique the system design already verified against this project's MySQL 8.4 for phase 3 certificates. It is reused rather than reinvented.

The salary equivalent is a composite over `(user_id, period_start, staff_compensation_id)`, NULL unless the line is a finalized salary line. The compensation id is part of the key deliberately — it is what allows the two legitimate lines a mid-period raise produces while still refusing a genuine duplicate.

### Line adjustments

Bonuses, advance repayments and penalties are **separate audited child rows** — signed amount, mandatory reason, actor — addable only while the run is draft and frozen at finalization. The computed portion stays visibly separate from the manual one, so a line always answers "what did the calculation say, and what did a human change".

A line's total is `computed_amount + SUM(adjustments)` and is derived, never stored.

---

## 8. Reports and export

**Cash basis.** A dinar is revenue in the month it arrived, not the month it was billed. This matches how the centre thinks about its month and cannot be inflated by bills nobody paid.

| Report | Notes |
|---|---|
| Revenue by course, batch and month | From allocations of finalized, non-reversed payments, joined through charge → enrolment → batch → course |
| Outstanding balances, aged | Buckets 0–30 / 31–60 / 61–90 / 90+ by due date; excludes written-off |
| Payment method breakdown | By tender, not by payment — a split payment contributes to two methods |
| **Daily tender report** | Finalized, non-reversed cash and card tender totals for a date |
| Wage cost per period | Per person and in total, from finalized payroll lines only |
| Profit | Collected revenue minus finalized wage cost for a period |
| Per-student payment history | Every bill and every receipt |

**The daily tender report is live, not a persisted reconciliation.** There is no counted-drawer workflow, no stored expected figure and no attested difference. Staff confirmation at the moment of recording is the source of truth. A formal reconciliation may be added later if operational experience shows it is needed — it is deliberately not built now, and this paragraph exists so that its absence reads as a decision rather than an oversight.

Because no overpayment can exist, collected revenue reconciles exactly against tenders with no unallocated bucket to explain.

### Export

**Excel costs no new dependency.** Filament v5 ships queued XLSX export — `Filament\Actions\Exports\Jobs\CreateXlsxFile` over `openspout/openspout`, already installed as a Filament dependency. Maatwebsite Excel is **not** added unless a later requirement genuinely needs formulas, charts or multi-sheet workbooks. The `exports` and `failed_import_rows` migrations are not published in this project yet and must be.

**PDF is mPDF.** Native Arabic letter shaping and RTL, pure PHP, no system dependency, and it works inside a queue worker on any host. Chosen over Chromium via Browsershot, which renders better but adds a runtime dependency whose failure takes every receipt and every export with it; and over dompdf, whose Arabic support is effectively broken and which phase 4 would have to pay to replace. The decision is made now, for phase 4's benefit, because the documents this phase produces are the ones that must survive the bilingual pass.

Every export runs as a queued job and notifies the user in-app when the file is ready. **Export queries are scoped by the requesting user's permissions** — an export is a read path and gets the same authorization as the screen it came from. **Formula-like user input is neutralized on the way out**: any exported cell whose value begins with `=`, `+`, `-`, `@`, tab or carriage return is prefixed so a spreadsheet treats it as text. Student names and notes are user-supplied and end up in files other people open.

---

## 9. Data model

Nine new tables, and one altered.

**Altered:** `enrollments` gains `reference` (unique, `ENR-…`).

### `discounts`
`id, name (unique), percentage decimal(5,2), is_active, timestamps`
`CHECK (percentage > 0 AND percentage <= 100)`. Never deleted while referenced.

### `charges`
`id, enrollment_id (unique FK restrict), reference (unique), list_price, discount_id (nullable FK restrict), discount_percentage (nullable), amount, due_date (indexed), written_off_at, written_off_by (nullable FK restrict), written_off_reason, timestamps`

`CHECK (amount >= 0)`, `CHECK (list_price >= 0)`, and a check that `discount_id` and `discount_percentage` are both null or both present. All money `decimal(12,3)`.

### `payments`
`id, student_id (FK restrict, indexed), reference (unique), received_at (indexed), recorded_by (FK restrict), notes, reversed_at, reversed_by (nullable FK restrict), reversal_reason, receipt_disk, receipt_path, timestamps`

`CHECK` that the three reversal columns are all null or all present. **No amount, no method, no status.**

### `payment_tenders`
`id, payment_id (FK restrict), method (indexed), amount, external_reference (nullable), timestamps`
`CHECK (amount > 0)` · `CHECK (method <> 'card' OR external_reference IS NOT NULL)`

The foreign key **restricts** rather than cascades. A tender is a genuine child record and the cascade rule would normally apply, but restricting makes the parent payment undeletable at the database level while any tender exists — which is the immutability this design claims, expressed where it cannot be argued with.

### `payment_allocations`
`id, payment_id (FK restrict), charge_id (FK restrict), amount, unique(payment_id, charge_id), timestamps`
`CHECK (amount > 0)`

### `staff_compensation`
`id, user_id (FK restrict), type (indexed), amount, effective_from, effective_to (nullable), timestamps`
`CHECK (amount > 0)` · `CHECK (effective_to IS NULL OR effective_to >= effective_from)` · index `(user_id, type, effective_from)`

### `payroll_runs`
`id, type (indexed), period_start (nullable), period_end (nullable), created_by (FK restrict), finalized_at, finalized_by (nullable FK restrict), notes, timestamps`
`CHECK` that a `monthly_salary` run has both period dates and an `instructor_batch` run has neither.

### `payroll_lines`
`id, payroll_run_id (FK cascade), user_id (FK restrict), staff_compensation_id (FK restrict), batch_instructor_id (nullable FK restrict), frozen_rate, frozen_quantity, computed_amount, finalized_at (nullable), period_start (nullable), timestamps`

Plus the generated columns and unique indexes described in §7. `batch_instructor_id` null means a salary line; present means an instructor-batch line. The cascade is correct here — lines are genuine children, and only a draft run is ever deletable.

### `payroll_line_adjustments`
`id, payroll_line_id (FK cascade), amount (signed), reason, created_by (FK restrict), timestamps`
`CHECK (amount <> 0)`

---

## 10. Authorization

Permission names follow Shield's `{action}_{model}`; custom abilities are bare verbs. `RolePermissionSeeder` remains the authority.

**Resources seeded with read permissions:** `charge`, `payment`, `discount`, `staff_compensation`, `payroll_run`.

**Write abilities deliberately not seeded**, with policies that refuse unconditionally and tests that grant the permission anyway and prove the refusal: `create_charge`, `update_charge`, `delete_charge`, `update_payment`, `delete_payment`, `update_staff_compensation`.

**Custom abilities:** `manage_pricing` · `apply_discount` · `adjust_charge` · `write_off_charge` · `reverse_payment` · `run_payroll` · `finalize_payroll` · `view_financial_report` · `export_financial_report`.

| Role | Holds |
|---|---|
| super_admin | everything |
| admin | read on all five finance resources · `create_payment` · `apply_discount` · `view_financial_report` · `export_financial_report` |
| staff | `apply_discount` |
| student | nothing until phase 3 |

Admins therefore record money but cannot set a price, apply an adjustment, write off a debt, reverse a payment, or touch compensation or payroll. That split is what makes the audit trail meaningful, and every one of those denials is asserted as a negative test.

### The one place authorization is not what it looks like

Staff hold `create_enrollment` but no charge permission at all. If charge issuance demanded its own ability, **a staff member could not complete an enrolment** — the front-desk walk-in scenario the system exists for (system design §1) would break.

`EnrollAndBillAction` therefore authorizes `create` on `Enrollment`, plus `apply_discount` when a discount was selected, and issues the bill as a system consequence of a permitted act. `IssueChargeAction` is an **internal collaborator, not a request-path Action**: it performs no ability check of its own, and an architecture test asserts that `EnrollAndBillAction` is its only caller. The same reasoning covers deleting an unpaid bill alongside its enrolment.

This is stated at length because it is the one deviation from "every Action authorizes itself", and an unexplained deviation is indistinguishable from a bug.

---

## 11. The write boundary

Per `docs/ENGINEERING.md`, any design section touching money must name its write boundary explicitly.

| Concern | Owner |
|---|---|
| Enrol and bill | `EnrollAndBillAction` (wraps `EnrollStudentAction` + `IssueChargeAction`, one transaction) |
| Correct a charge | `AdjustChargeAction` |
| Write off a debt | `WriteOffChargeAction` |
| Record money in | `RecordPaymentAction` — tenders, allocation and finalization in one atomic call |
| Undo a payment | `ReversePaymentAction` |
| Change a rate | `ChangeCompensationAction` |
| Payroll | `CreatePayrollRunAction`, `FinalizePayrollRunAction`, `AdjustPayrollLineAction` |
| Change a price | `UpdateCoursePriceAction`, `UpdateBatchPriceAction` — gated on `manage_pricing` |
| Discounts | `CreateDiscountAction`, `DeactivateDiscountAction`, `DeleteDiscountAction` |
| **Payment self-consistency** | `PaymentInvariantService` — locks the charge, derives outstanding under that lock, checks tender total = allocation total, writes |
| **Compensation overlap** | `CompensationPeriodInvariantService` |

**Prohibited, and enforced by extending `tests/Feature/Staff/ActionBoundaryArchTest.php`:** any write to a Finance table outside these Actions · `->relationship()` on a Filament field an Action owns · a `paid_amount`-style cached column on any table · any status string column in the Finance domain · float casts on money · `IssueChargeAction` called from anywhere but `EnrollAndBillAction`.

**What those tests are worth is unchanged.** They scan for known-bad code shapes and are a fast early warning, not proof. Where a protection matters, the proof is behavioural: drive the real Filament component and assert the outcome.

### Invariants and their actual mechanism

| Invariant | Mechanism |
|---|---|
| Tender total equals allocation total | Lock + check in the finalizing transaction |
| A payment never exceeds outstanding | Charge locked, outstanding derived under that lock |
| Amounts are positive | **MySQL `CHECK`** |
| A card tender carries a terminal reference | **MySQL `CHECK`** |
| One charge per enrolment | **Unique index** |
| References are unique | **Unique index** |
| An instructor assignment is paid at most once | **Stored generated column + unique index** |
| One finalized salary line per person, period and rate | **Stored generated column + unique index** |
| No overlapping compensation periods | Lock + check — MySQL cannot express it |
| Adjustments only while draft | Lock + check — MySQL cannot express it |

The trust boundary is unchanged from phase 1 and is restated honestly: locks and Actions protect every write reachable through application code. Raw SQL and manual `tinker` are trusted administrative operations. The rows marked **CHECK** and **unique index** are the ones that hold regardless.

---

## 12. Impact on existing code

### `DeleteEnrollmentAction` breaks the day this ships

Every enrolment now has a charge, and financial foreign keys restrict on delete. The system design explicitly requires that a student recorded in error stays removable — "a student recorded in error on a finished batch must stay removable, or the mistake is permanent" (§6) — and P1-T11 shipped deletion as a separate grant from withdrawal precisely for that case.

**Resolution:** `DeleteEnrollmentAction` consults `ChargeQueryService`, refuses with a typed exception when the bill carries any payment, adjustment or write-off, and otherwise deletes bill and enrolment together in one transaction.

This is the single highest-risk integration point in the phase and the first thing the plan review should examine.

### Cross-domain boundaries

`EnrollmentQueryService` is named in the system design and `docs/ENGINEERING.md` as the way Finance reads enrolment data — **and it does not exist.** Phase 2 builds it. `ChargeQueryService` is the reverse-direction interface Finance publishes for Enrolment.

The architecture test forbids Finance code from *querying* Enrolment models outside the service. Declaring a `belongsTo` where a real foreign key exists stays allowed — an over-strict rule here would be fought and then weakened, which is worse than a precise one.

### A phase 1 test inverts

`BatchResourceTest` asserts the price field is **absent** from `BatchResource`, pinned deliberately so nobody surfaced it before phase 2. That assertion inverts, in the task that adds the price gate and nowhere else.

### Activity log

From this phase the log records **all financial mutations** (system design §7): charge issuance, adjustment and write-off with their reasons, payment recording with its tender breakdown, reversal with its reason, compensation changes, payroll creation and finalization, line adjustments, price changes, and discount creation and deactivation.

The log remains **append-only**. Nothing in this phase creates a deletion path.

### Internationalization

All strings through `lang/en/finance.php`; the Arabic file ships empty until phase 4. Composite strings — anything built by joining fragments, including money formatting and the receipt's field labels — get their own keys rather than being assembled in code, because separators and ordering are themselves localisable.

---

## 13. Open items

Flagged rather than silently decided:

- **Aging buckets** are 0–30 / 31–60 / 61–90 / 90+. Conventional, and changeable at review.
- **Reference numbering can have gaps** where a transaction rolls back, because the number derives from the row id rather than a counter. If the centre's accountant requires gapless bill and receipt sequences, this becomes a counter table with its own lock, and that is a materially different piece of work — say so before implementation.
- **One receipt cannot span two bills.** A student enrolling on two courses the same day gets two bills and two receipts. The allocation table supports the many-charge case so nothing needs re-migrating if this changes, but the phase 2 UI will not offer it.
- **A front-desk staff member can apply a discount but holds no permission to see the resulting bill or take payment.** That follows the permission matrix exactly. In practice the person taking cash holds the admin role, so this may never bite — but it is an odd shape and worth a decision rather than a discovery.

---

## 14. Testing requirements

Beyond `composer verify`, which is unchanged and has one definition:

- **Every permission test asserts the negative.** Specifically that an admin cannot reach `manage_pricing`, `adjust_charge`, `write_off_charge`, `reverse_payment`, `run_payroll` or `finalize_payroll`, and that staff reach none of the finance surfaces.
- **The refusing policies are tested by granting the permission first.** `create_charge`, `update_payment` and the rest must be proven to fail even when held.
- **Concurrency is driven from both directions.** Two simultaneous payments against one bill produce one success and one clean typed refusal, not a race both pass.
- **Database-level guarantees are proven at the database.** Insert a second finalized payroll line for an already-paid assignment and assert MySQL refuses it; insert a card tender with no reference and assert the `CHECK` fires. Not by asserting a button is hidden.
- **Overpayment is tested at the boundary**, including the case where outstanding drops between form load and submit.
- **A reversed payment is asserted absent from every report individually.** A single "reversals are excluded" test over one report is the shape that lets the other six drift.
- **Rounding is tested on a case that rounds.**
- **Every security claim in this document has a test named after it.** If a sentence here asserts a property, a test asserts the same property, or the sentence does not belong.
- **Watch each new test fail before trusting it.** Break the thing it protects, confirm the failure names the right cause, restore from a file copy — never `git checkout`.

---

## 15. Decisions superseded during this design session

Recorded so they are not re-derived from the conversation that produced this document.

| Superseded | By |
|---|---|
| Auto oldest-first allocation with operator override | Allocation is decided by which bill was opened |
| Leftover money held as student credit | No overpayment is possible, so there is no leftover |
| A persisted drawer reconciliation with counted totals and attested differences | A live daily tender report |
| Draft payments finalized as a second step | One atomic create-and-finalize |
| `payments.method` as a single column | `payment_tenders` |
| `charges.status` as a stored enum | Fact columns plus derived state |
| `per_student` compensation | Removed — the centre does not pay per head |
| Pro-rating instructor hours across payroll periods | On-demand runs paying a batch as one lump |
