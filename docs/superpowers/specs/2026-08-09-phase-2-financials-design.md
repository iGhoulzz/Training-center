# Phase 2 — Financials: Design

**Date:** 2026-08-09
**Status:** Revision 3 — incorporates both rounds of the Codex step-0 review. Awaiting re-review.
**Supersedes:** the phase 2 sections of `2026-07-20-training-center-dashboard-design.md` wherever the two disagree. Those sections were written at architectural detail before a charge model existed; this document is at implementation detail and is authoritative for phase 2.

**Revision 2 changed:** the enrolment write path (§12), salaried payroll segmentation and post-finalization correction (§7), payment idempotency and derived student identity (§5), the pricing write boundary (§3), charge due dates (§4), reporting time zones (§8), compensation locking (§7), and the schema guarantees in §9. Two review findings were **declined** — see §16.

**Revision 3 changed:** reference generation, which revision 2 left impossible — a non-nullable column written after insert (§2) · the enrolment backfill, split into three recoverable migrations (§2) · frozen instructor hours, dropped by revision 2 in violation of the system design (§7) · adjustment-run invariants, posting period and lock ordering (§7) · idempotency fingerprinting (§5) · the pricing hook, which as revision 2 wrote it would have refused **every ordinary admin edit** (§3) · explicit actor-first authorization on the payment Actions and the cross-domain route to the student (§5) · the `EnrollmentQueryService` contract, widened to four consumers (§12).

---

## 1. Purpose and scope

Phase 2 gives the centre its money. Students are billed for the courses they enrol on, payments are recorded against those bills, staff compensation is configured and payroll is **approved and posted**, and the whole thing reports and exports.

Payroll is never *paid* by this system — see §7. The word is avoided deliberately throughout.

**In scope:** pricing, discounts, charges, payments, allocations, balances, compensation configuration, payroll, financial reports, Excel and PDF export, printed receipts.

**Out of scope, and not to be reintroduced:** everything in section 12 of the system design, plus these, decided here:

- **No refunds.** Money is strictly one-directional. Courses are face-to-face; a student who did not pay simply owes, and a student who paid is not paid back.
- **No credit balances or prepayment.** A payment can never exceed the bill's outstanding amount, so unallocated money cannot exist.
- **No payment gateway.** Cash is handed to staff, cards go through an external terminal. The application records what already happened.
- **No persisted drawer reconciliation.** A live daily tender report only — see §8.
- **No installment schedules.** One bill per enrolment, payable in as many parts as the student likes.
- **No standalone fees.** Every charge belongs to an enrolment.
- **No payroll disbursement.** Finalizing a payroll run approves and posts it; paying the money out happens outside the system. See §7.

---

## 2. The central workflow: enrol and collect

Phase 2's primary surface is one guided flow, not a set of CRUD screens the user is expected to assemble.

1. Find or create the student.
2. Select the course batch.
3. Select an optional enrolment discount (requires `apply_discount` — admin and above).
4. **Preview** the original price, the discount, and the final amount.
5. Confirm — creates the enrolment (`ENR-…`) and the bill (`CHG-…`) in one transaction.
6. Optionally collect immediately: the full amount, or an installment.
7. Choose cash, card, or split. A split adds tender lines under one payment — 300 by card and 700 by cash is one payment with two tenders.
8. Finalize — one receipt (`RCT-…`) with its own number.

The system allocates the payment to that bill. **Staff never see the word "allocation" and never make an allocation decision.**

Later installments: search the student, open the unpaid bill, record another payment, issue another receipt. **One bill, many receipts**, all pointing back to the same `CHG-…`.

### The receipt

Every finalized payment produces a PDF the student is handed. It shows:

student code and name · enrolment reference · course and batch codes · bill reference · original price · discount · final charge · **amount paid** · cash/card breakdown · **remaining balance** · payment date · recording staff member.

Receipts are generated to the private disk (§6 of the system design) and served only through a policy-authorized download. Every string goes through `__()`.

### Reference series

| Series | Table | Format |
|---|---|---|
| `ENR-` | `enrollments` (new column on a phase 1 table) | `ENR-{year}-{id padded to 6}` |
| `CHG-` | `charges` | `CHG-{year}-{id padded to 6}` |
| `RCT-` | `payments` | `RCT-{year}-{id padded to 6}` |

Each carries a unique index and is **non-nullable**. MySQL forbids a generated column from referencing an `AUTO_INCREMENT` column, so the stored-generated-column approach used elsewhere in this design is unavailable, and the final value cannot be known until the row has an id.

**The row is therefore inserted carrying a unique placeholder — a UUID — and updated to its real reference inside the same transaction.** Both writes are in one transaction, so no other connection ever observes the placeholder, and the column keeps `NOT NULL UNIQUE` throughout.

Revision 2 said only that the reference was "written immediately after insert", while the column was non-nullable — which cannot work, because there is nothing to write at insert time and the insert fails first. The alternative considered and rejected was making the column nullable and letting the Actions guarantee presence: that is a convention rather than a guarantee, and this project's whole position is that validation which matters is mirrored in the database. A placeholder costs one extra `UPDATE` and keeps the constraint real.

A test asserts that no row survives a transaction carrying a placeholder value.

These are deliberately **not** the phase 3 certificate pattern. A certificate reference is exposed to an unauthenticated public verifier and must be unguessable; a bill reference is read by the person holding the bill, and a sequential, human-readable, phone-dictatable number is the correct trade-off. **Do not "harden" these into random strings.**

**Gaps are accepted.** The owner confirmed on 2026-08-09 that these are internal tracking references, not registered fiscal invoice sequences, so a number burned by a rolled-back transaction is not a problem. There is no counter table and no sequence lock. **If the centre ever becomes subject to a gapless fiscal numbering requirement, this is a schema and concurrency change, not a formatting change.**

### Backfilling `ENR-` onto existing rows

`enrollments` already holds rows in every development and test database.

**Three separate migrations, not one.** MySQL does not roll back DDL, so a single migration mixing `ALTER TABLE` with a data backfill leaves a half-migrated table that `migrate:rollback` cannot repair and that the next `migrate` refuses to re-apply:

1. Add `reference` as nullable.
2. Backfill every existing row as `ENR-{year of enrolled_at}-{id}` — pure DML, re-runnable, and independently recoverable if it fails part-way.
3. Add the unique index and tighten the column to non-nullable.

The order matters in both directions: adding a non-nullable unique column to a populated table fails outright, and indexing before the backfill means the backfill races the constraint it is trying to satisfy.

---

## 3. Pricing and discounts

### Price inheritance — resolved

The system design left this open at line 215 and forbade implementing it before phase 2 decided.

**A null `batches.price` falls back to `courses.default_price` on every read.** Live inheritance, never a copy — identical to the `effective_total_hours` accessor at `app/Domain/Enrollment/Models/Batch.php`. Correcting a course price corrects every inheriting batch at once.

**An issued charge does not move.** The charge stores the list price, the discount percentage, and the resulting amount. A later price change affects future enrolments only.

### Discounts

Reusable percentage definitions — 10%, 30%. Not in the original design.

- **One optional discount per enrolment. No stacking.**
- A discount applies to **an enrolment**, never permanently to a student.
- **A discount's percentage is immutable once any charge references it.** Superseding a rate means deactivating the definition and creating a replacement. Retroactively editing a percentage would restate what the centre charged people in the past.
- `is_active` controls whether it is offered on new enrolments. Definitions are never deleted while referenced; the foreign key restricts and `DeleteDiscountAction` converts MySQL 1451 into a typed refusal, as `DeleteCourseAction` and `DeleteBatchAction` already do.

### The rounding rule, stated once

`amount = round(list_price × (100 − percentage) ÷ 100)` in **integer dirham**, half-up.

Defined in one place and tested with a case that actually rounds.

### The pricing write boundary is executable, not declarative

Revision 1 said price fields would be disabled and de-hydrated and "the Action refuses the change regardless of what was submitted". **There was no Action in the persistence path.** `EditCourse` and `EditBatch` are bare `EditRecord` classes with no save hooks, so Filament persists with a generic `$record->update($data)`. Declaring a write boundary that no code implements is exactly the failure the system design records for phase 1's escalation guards.

The boundary is therefore built the way `UserResource` already builds one, using `WritesUserThroughActions` as the template:

- **Price fields are `dehydrated(false)` for every actor, without exception.** Generic persistence never sees a price, so there is no path by which one is written outside an Action — including the super admin's own path. A field that is merely `disabled()` for some actors leaves the write shape intact for others.
- A `WritesPricingThroughActions` concern, shared by the create and edit pages of both resources, reads `$this->form->getRawState()` in the save hook and calls `UpdateCoursePriceAction` / `UpdateBatchPriceAction`.
- **The Action is called only when the price actually changed.** The raw state is normalized to integer dirham and compared with the persisted value — a string comparison would read `100` and `100.000` as a change. If they match, no Action runs.

  This is load-bearing, not an optimisation. An admin has no `manage_pricing`, but the price is still present in the form's raw state when they rename a course. Calling the Action unconditionally would authorize-and-refuse on **every ordinary admin edit**, so renaming a course would fail with an authorization error about a field the admin never touched. A test proves an admin can edit a non-price field while the price is left alone.
- Those Actions authorize `manage_pricing` and are the **only** writers of `courses.default_price` and `batches.price`, initial value included. On create, an absent or null price calls nothing.
- A refusal throws `Halt::rollBackDatabaseTransaction()` so a rejected price change undoes the attribute write with it, rather than leaving the rename committed and the price refused.

**Tests, both directions:** a super admin sets and changes a price through the real Livewire component and it persists; an admin crafting a Livewire state update on the price field does not persist a value and the Action refuses. Asserting a disabled field is not a test of this boundary.

### Who may set a price, and who may discount

- **`manage_pricing`** — super admin only. Gates course and batch prices **and** the discount definitions themselves: creating, deactivating and deleting a discount all require it. Setting the rates the centre charges is one capability, wherever it is expressed.
- **`apply_discount`** — **admin and above.** Ordinary staff enrol walk-ins at full price and the discount selector does not render for them.

  *Changed in revision 2.* An earlier answer put this with any enroller; the owner settled it at admin-and-above on 2026-08-09. It stays a separate permission from `manage_pricing` because choosing a pre-approved discount and setting the centre's rates are different acts, and the grant may move again.

---

## 4. Charges (bills)

**One charge per enrolment**, raised automatically as part of enrolling, payable in parts.

### Due date

`due_date` is **the date the charge was raised** — the enrolment date. Frozen at issue like every other figure on the row.

The centre bills at enrolment and expects payment at or near enrolment; there is no invoicing term to express. Aging therefore measures from the day the student incurred the debt, which is the only date the workflow actually produces. Revision 1 left this undefined, which made the aged report unimplementable — there was no answer to "aged from what".

### No status column

The system design's `charges.status` enum was `unpaid | partial | paid | waived`. Three of those four are **derived from allocations**, which the same document forbids storing two sections earlier. Storing them is the `paid_amount` mistake wearing a different name.

**Resolved:** no status column. The table stores the *facts* a human decided — `written_off_at`, `written_off_by`, `written_off_reason` — and unpaid / partial / paid are computed by summing allocations. Filament sorts and filters through a SQL subquery.

The same rule holds throughout Finance: **no status string column exists on any table in this domain.** Lifecycle is nullable fact columns; the label is derived. An architecture test enforces it.

### Correcting a charge

`AdjustChargeAction` is the only path, for **data-entry errors only — never for applying a late discount**.

- Super admin only, via `adjust_charge`.
- A mandatory reason is written into the activity log's properties alongside the before/after diff. The table carries no adjustment columns; the activity log *is* the audit record.
- The Action **refuses to drop the amount below what has already been allocated**.

After an adjustment, `amount` no longer equals `list_price × (100 − percentage) ÷ 100`. That is expected: the frozen figures record what was billed, and the adjustment records that a human corrected it.

### Writing off a debt

`WriteOffChargeAction`, super admin only via `write_off_charge`, mandatory reason. A written-off balance stops distorting the aged outstanding report while its history stays fully visible. **Nothing is erased**, and the debt remains in the student's payment history.

**This survived a review challenge and is deliberate.** The step-0 review proposed removing write-offs on the reading that the owner had ruled out forgiveness. That conflated two decisions: no *refunds* and no automatic forgiveness on withdrawal, which are settled, with the separate question of retiring a debt the centre has accepted it will never collect, which the owner answered directly and affirmatively. The finding was retracted. Do not re-derive it.

Withdrawal is **not** a financial event. Withdrawing an enrolment leaves the bill exactly as it stands.

### Permissions deliberately not created

`create_charge`, `update_charge` and `delete_charge` are **not seeded**, following the reasoning recorded for the activity log in `RolePermissionSeeder`: seeding an ability nothing honours invites someone to wire it up later. `ChargePolicy::create()`, `update()` and `delete()` return **false unconditionally**, and a test grants the permission anyway and proves the policy still refuses.

**One exception, stated here so it is not read as a contradiction.** §12 requires that an enrolment created in error stays deletable, which means deleting its unpaid bill with it. That happens through `DeleteUncommittedChargeAction` (§11), an internal Finance Action callable only from `DeleteEnrollmentAction`. It does not consult `ChargePolicy`, because the policy answers "may this actor delete a bill on its own", and that answer is no for everyone.

---

## 5. Payments, tenders and allocations

`RecordPaymentAction` is the **receipt-confirmation boundary**, used only after cash has been physically counted or the card terminal has shown Approved. The application records an event that already happened; it does not authorise one.

`RecordPaymentAction` and `ReversePaymentAction` are ordinary request-path Actions: **actor first, self-authorizing via `Gate::forUser($actor)`**, exactly as `docs/ENGINEERING.md` requires and as every phase 1 Action already does. They are not internal collaborators — that exemption belongs only to `IssueChargeAction` and `DeleteUncommittedChargeAction` (§10). Each is tested by invoking the Action directly with an unauthorized actor and asserting the denial, not merely by checking that a Filament button is hidden.

### One receipt, one or more tenders

A single customer payment is **one parent `payments` row** — student, `received_at`, `recorded_by`, `RCT-…`, notes — carrying **one or more `payment_tenders`**, each with a method, an amount, and an optional external reference.

A 1,000 bill settled with 300 on card and 700 in cash is one payment, one receipt, two tenders. This replaces the original design's single `payments.method` column, which cannot represent the split-tender checkout every retail counter performs.

**A card tender requires a non-blank terminal reference**, enforced by a MySQL `CHECK` that also rejects whitespace — `method <> 'card' OR (external_reference IS NOT NULL AND TRIM(external_reference) <> '')`. A space is not a reference, and a nullability check alone accepts one.

**Card numbers, PINs and CVVs are never stored.** A validation rule rejects PAN-shaped input — 13 to 19 digits, spaces and dashes ignored. The field is free text, sits next to a card machine, and is filled in by whoever is at the desk.

### Nothing derived is stored

**`payments` has no `amount`, `method` or `status` column.** The total is `SUM(tenders)`. Charge balances are sums of allocations. Payment state derives from `reversed_at`. **Because no draft state exists, every payment row is by definition finalized** — reports filter on `reversed_at` alone, and no code should look for a finalized flag that does not exist.

### The student is derived, never supplied

`RecordPaymentAction` takes the **bill**, not a student. It locks the charge and resolves the owning student **through `EnrollmentQueryService`** — not by walking `$charge->enrollment`, which would be Finance querying an Enrolment model directly and is what the domain-boundary architecture test forbids. The DTO has no student field at all, so a crafted request cannot attach a payment to one student while settling another's bill; there is nothing to attach.

A test drives exactly that: a crafted submission targeting student A's bill while claiming student B, asserting the stored payment belongs to A. Accepting an independently supplied identifier and then validating it is a weaker construction than never accepting it.

### Retry protection

Atomic finalization plus a double-clicked button equals two payments and two receipts for one handover of cash. Nothing else in the design catches it: both submissions are individually valid.

**`payments.idempotency_key`** is a client-generated UUID, minted when the collection form is first rendered and submitted with the payment, under a unique index. A replayed submission raises the unique violation, which the Action converts — **matching on the index name, as `EnrollStudentAction` already does** — rather than surfacing a raw driver error.

**The key alone is not enough, and returning whatever payment owns it is wrong.** A key reused with a different bill or a different tender breakdown would hand back an unrelated receipt for money that was never recorded — a worse failure than the duplicate it was meant to prevent.

Each payment therefore stores a **canonical request fingerprint** alongside the key: a hash over the charge id, the allocation amount, and the tender lines normalized to a stable order and to integer dirham. On a key collision the Action compares fingerprints:

- **Identical** — a genuine replay. Return the existing payment. The second click gets the first receipt.
- **Different** — raise `IdempotencyConflictException`. The same key is being used for a different request, and the only safe answer is to refuse both silently succeeding and silently returning the wrong thing.

Tested sequentially (the same key and request twice yields one row), concurrently (two simultaneous identical submissions yield one row and no error), and on conflict (the same key with a different bill or tender split raises, and creates nothing).

### Finalization is atomic

There is no draft. Payment, tenders and allocations are written in **one transaction** that checks every invariant under lock, then generates the receipt after commit. A half-finished payment row cannot exist to be misread later.

Two invariants are checked inside that transaction, with the charge row locked:

1. **Tender total equals allocation total.** A payment is self-consistent or it does not exist. Tested from the unhappy side explicitly — a submission whose tenders and allocations disagree is refused with a typed exception, and no partial row survives.
2. **Allocation never exceeds the bill's outstanding balance**, where outstanding is derived *under the same lock*, so a bill settled by someone else between form load and submit produces a clean refusal.

Never accept more than is outstanding. For cash, return change and record only the amount accepted. For card, enter the exact amount charged.

### Reversal

Super admin only, via `reverse_payment`. A **set-once lifecycle transition on an immutable row**: `reversed_at`, `reversed_by` and `reversal_reason` are written once and never unset. The payment, its tenders and its allocations are never rewritten and never deleted.

This reuses the shape the system design blessed for phase 3 certificates rather than inventing a second answer.

**Only non-reversed payments count as collected revenue**, asserted per report rather than assumed.

`update_payment` and `delete_payment` are **not seeded**, and `PaymentPolicy::update()`, `delete()` and `deleteAny()` return false unconditionally, with tests that grant the permission and prove refusal anyway. A payment row has no delete path at all.

### Allocations remain load-bearing

Every balance is derived by summing them. The phase 2 UI always targets exactly one bill, so the allocation is decided by context and never by the operator. The many-charge capability stays in the schema so a future "pay both my courses at once" flow needs no migration.

---

## 6. Money arithmetic

The column is `decimal(12,3)`; LYD subdivides into 1000 dirham. Laravel's `decimal:3` cast hands PHP a **string**, and adding two of those with `+` converts to float. A float cannot represent 0.001 exactly, so the dirham is precisely the digit lost.

- A **`Money` value object over integer dirham**. All arithmetic integer.
- **Aggregation happens in SQL**, where MySQL's `DECIMAL` sums are exact, and hydrates into `Money`.
- The discount rounding rule (§3) is the only rounding in the system.
- An architecture test forbids float casts on money attributes anywhere in `app/Domain/Finance`.

---

## 7. Compensation and payroll

### Compensation

Effective-dated: **a raise inserts a row and closes the previous one**, in one transaction, through `ChangeCompensationAction`. A rate is never overwritten.

**`per_student` is dropped.** Types are `salary` (a monthly amount) and `hourly` (a per-hour rate). One person may hold both.

`update_staff_compensation` is not seeded and the policy refuses: the only legitimate change to a rate is a new row.

**No overlapping periods per person per type**, and the lock that guarantees it is taken on the **`users` row**, not on the compensation rows. Revision 1 said "lock + check" without saying what was locked, which does not work in the case that matters: when a person has no compensation rows yet there is nothing to lock, so two concurrent first-row writes both see an empty table and both pass. Locking the stable parent row serializes them.

**The concurrency test starts with zero existing compensation rows**, because that is the state in which the naive implementation passes.

### Payroll runs

Three run types, all **draft → finalized → immutable**.

**`monthly_salary`** — a period run over salaried staff.

**`instructor_batch`** — an **on-demand** run. The draft lists every instructor-hour assignment not already paid in a finalized run, whatever the batch's status, and the operator ticks which to include. A batch is paid as one lump, either before it starts or after it finishes.

**An instructor line freezes the hours as well as the rate.** `frozen_hours` copies `batch_instructor.assigned_hours` at finalization, and `computed_amount = frozen_rate × frozen_hours`. The system design requires a run to freeze "the rate and hours used at the moment of calculation", and revision 2 lost the hours when it replaced the generic quantity column with salary-specific day columns. Without them, an allocation edited after payment leaves a finalized amount that nothing in the database can explain — the figure would be right and unjustifiable, which for a wage record is the same as being wrong.

This resolves a gap the original design did not notice: `batch_instructor.assigned_hours` is per *batch* while a payroll run is per *period*, so a 30-hour batch running January to March had no defined January figure. **There is no calendar slicing and no pay-on-start / pay-on-completion setting.**

**`adjustment`** — see "Correcting a finalized run" below.

### Salary lines are segments, not periods

A run may legitimately cover a partial previous month plus a full current month — the centre pays when it pays, and a period is not always a calendar month.

A salary line is therefore a **segment**, produced by intersecting three things: the run's period, each calendar month inside it, and each compensation row's validity range. Each segment freezes its own `segment_start`, `segment_end`, `frozen_rate`, `frozen_days` (days in the segment) and `frozen_days_in_month` (the denominator), and computes `round(rate × days ÷ days_in_month)` in integer dirham.

Splitting by calendar month is not cosmetic: a monthly salary pro-rated across a boundary has **two different denominators**, and a single line spanning 20 March to 15 April cannot express both. Splitting by rate is what makes a mid-period raise produce two correctly-priced lines instead of one averaged wrong one.

Revision 1 keyed uniqueness on `(user_id, period_start, staff_compensation_id)`, which describes a model where a line covers a whole period. That model cannot represent the owner's actual case.

### What actually prevents paying twice

| Guarantee | Mechanism |
|---|---|
| An instructor assignment is paid at most once | **Stored generated column + unique index** on `batch_instructor_id`, carried only while `finalized_at` is set |
| The identical salary segment is not finalized twice | **Stored generated column + unique index** on `(user_id, segment_start)`, carried only while finalized |
| **Overlapping** salary segments are not finalized | **Lock + check** — the `users` row is locked and existing finalized segments intersecting the new range are queried inside the finalizing transaction |

The third row is stated separately and honestly. A unique index cannot express range overlap: two runs covering 1–15 January and 10–31 January produce segments with different start dates, and no index refuses them. The database catches exact duplicates; **only the lock catches overlaps**, and it protects the application path alone.

`finalized_at` is denormalized onto the line at finalization — frozen data, like every other payroll figure — because a generated column cannot reference another table.

### Correcting a finalized run

A finalized run is immutable: no edits, no deletions, no new adjustments. Revision 1 offered only draft-time line adjustments, which does not solve a mistake discovered next month.

A mistake found after finalization is corrected by an **`adjustment` run**: its lines carry a signed amount, a mandatory reason, and `corrects_payroll_line_id` pointing at the line being corrected. Nothing about the original moves.

`AdjustPayrollLineAction` enforces four rules, none of which are inferable from the schema:

1. **The employee is derived from the locked target line**, never supplied. Same construction as the payment's student in §5, for the same reason: an identifier the caller provides is an identifier the caller can get wrong or forge.
2. **The target must be finalized.** A draft line is corrected by editing the draft.
3. **The signed amount must be non-zero.** A correction of nothing is a reason with no effect, and it would still appear in the audit trail as though something had happened.
4. **A correction may not target another correction.** Corrections point only at original salary or instructor lines. Multiple corrections against the same original are allowed and sum, so a wrong correction is fixed by issuing another against the same original — flat, not chained. Chains would make "what was this person actually paid for March" depend on walking a linked list of unknown depth.

**A correction posts to the period of the line it corrects, not to the period the adjustment run was finalized in.** Correcting March in June makes March's wage cost right. This is the same rule the system design already states for effective-dated compensation — that March's payroll must recompute correctly after a June rate change — and without it the wage-cost and profit reports would disagree with each other while reading the same rows. Instructor corrections post to the period of the original run's finalization date, since an instructor line has no segment of its own.

Draft-time line adjustments remain, for bonuses and deductions known before the run is posted. The two mechanisms differ in *when*, not in kind.

### Lock ordering

Finalizing a run locks one `users` row per person on it. **Those locks are acquired in ascending user id order**, always. Two runs finalizing overlapping staff in opposite orders deadlock, and MySQL resolves a deadlock by killing one transaction — turning a correct refusal into an intermittent, unreproducible failure. The project already relies on this reasoning in `EnrollmentMutex`, where a consistent global lock order is what makes concurrent enrolment safe.

### Finalized means posted, not paid

**Finalizing approves a run and posts it to the books. It does not disburse money.** Payroll disbursement is out of scope for the whole project (system design §12), there is no paid/unpaid flag on a run, and wage-cost reports count finalized lines as cost incurred. Anyone reading "finalized" as "the staff have their money" is reading it wrong, and the word appears in enough places to be worth saying once here.

---

## 8. Reports and export

**Cash basis.** A dinar is revenue in the month it arrived, not the month it was billed.

| Report | Notes |
|---|---|
| Revenue by course, batch and month | From allocations of non-reversed payments, joined through charge → enrolment → batch → course |
| Outstanding balances, aged | Buckets 0–30 / 31–60 / 61–90 / 90+ from `due_date`; excludes written-off |
| Payment method breakdown | By tender, not by payment — a split payment contributes to two methods |
| **Daily tender report** | Non-reversed cash and card tender totals for a date |
| Wage cost per period | Per person and in total, from finalized payroll lines of all three run types |
| Profit | Collected revenue minus finalized wage cost for a period |
| Per-student payment history | Every bill and every receipt |

### Calendar boundaries are local, queries are UTC

Every report period is a **local calendar period in `Africa/Tripoli`** — "March" means March as the centre experienced it, not March in UTC.

Each local period is converted to a **half-open UTC range**, `received_at >= start AND received_at < end`, before it reaches the database. Half-open rather than `BETWEEN`, so a payment recorded at the final instant of a month lands in exactly one period rather than in two or neither. A range comparison rather than date extraction, so the index on `received_at` is used — wrapping the column in a conversion function makes it unusable and turns every report into a table scan.

**The conversion is done through the timezone database, never by adding a fixed offset**, so the code stays correct if Libya's offset ever changes.

Tested at the boundaries that break naive implementations: a payment at 00:00:00 local on the first of a month, one at 23:59:59 local on the last day, and one either side of midnight UTC.

**The daily tender report is live, not a persisted reconciliation.** There is no counted-drawer workflow, no stored expected figure, no attested difference. Staff confirmation at the moment of recording is the source of truth. A formal reconciliation may be added later if operational experience calls for it; this paragraph exists so its absence reads as a decision rather than an oversight.

Because no overpayment can exist, collected revenue reconciles exactly against tenders with no unallocated bucket to explain.

### Export

**Excel costs no new dependency.** Filament v5 ships queued XLSX export — `Filament\Actions\Exports\Jobs\CreateXlsxFile` over `openspout/openspout`, already installed as a Filament dependency. Maatwebsite Excel is **not** added unless a later requirement genuinely needs formulas, charts or multi-sheet workbooks. The `exports` and `failed_import_rows` migrations are not published in this project yet and must be.

**PDF is mPDF.** Native Arabic letter shaping and RTL, pure PHP, no system dependency, works inside a queue worker. Chosen over Chromium via Browsershot, which renders better but adds a runtime dependency whose failure takes every receipt and export with it; and over dompdf, whose Arabic support is effectively broken and which phase 4 would have to replace. Decided now, for phase 4's benefit.

Every export runs as a queued job and notifies in-app when ready. **Export queries are scoped by the requesting user's permissions** — an export is a read path and gets the same authorization as the screen it came from. **Formula-like user input is neutralized**: any exported cell beginning with `=`, `+`, `-`, `@`, tab or carriage return is prefixed so a spreadsheet treats it as text.

---

## 9. Data model

Nine new tables, one altered.

**Altered:** `enrollments` gains `reference` (unique, `ENR-…`), backfilled as described in §2.

### `discounts`
`id, name (unique), percentage decimal(5,2), is_active, timestamps`
`CHECK (percentage > 0 AND percentage <= 100)`

### `charges`
`id, enrollment_id (unique FK restrict), reference (unique), list_price, discount_id (nullable FK restrict), discount_percentage (nullable), amount, due_date (indexed), written_off_at, written_off_by (nullable FK restrict), written_off_reason, timestamps`

`CHECK (amount >= 0)` · `CHECK (list_price >= 0)` · `CHECK` that `discount_id` and `discount_percentage` are both null or both present · `CHECK` that the three write-off columns are all null or all present.

### `payments`
`id, student_id (FK restrict, indexed), reference (unique), idempotency_key (unique), received_at (indexed), recorded_by (FK restrict), notes, reversed_at, reversed_by (nullable FK restrict), reversal_reason, receipt_disk, receipt_path, timestamps`

`CHECK` that the three reversal columns are all null or all present. **No amount, no method, no status.**

### `payment_tenders`
`id, payment_id (FK restrict), method (indexed), amount, external_reference (nullable), timestamps`
`CHECK (amount > 0)` · `CHECK (method <> 'card' OR (external_reference IS NOT NULL AND TRIM(external_reference) <> ''))`

The foreign key **restricts** rather than cascades. A tender is a genuine child record and the cascade rule would normally apply, but restricting makes the parent payment undeletable at the database level while any tender exists — the immutability this design claims, expressed where it cannot be argued with.

### `payment_allocations`
`id, payment_id (FK restrict), charge_id (FK restrict), amount, unique(payment_id, charge_id), timestamps`
`CHECK (amount > 0)`

### `staff_compensation`
`id, user_id (FK restrict), type (indexed), amount, effective_from, effective_to (nullable), timestamps`
`CHECK (amount > 0)` · `CHECK (effective_to IS NULL OR effective_to >= effective_from)` · index `(user_id, type, effective_from)`

### `payroll_runs`
`id, type (indexed), period_start (nullable), period_end (nullable), created_by (FK restrict), finalized_at, finalized_by (nullable FK restrict), notes, timestamps`
`CHECK` that a `monthly_salary` run has both period dates and that `instructor_batch` and `adjustment` runs have neither · `CHECK (finalized_at IS NULL) = (finalized_by IS NULL)` — an approval with no approver, or an approver with no time, is not a state the table should be able to hold.

### `payroll_lines`
`id, payroll_run_id (FK cascade), user_id (FK restrict), staff_compensation_id (nullable FK restrict), batch_instructor_id (nullable FK restrict), corrects_payroll_line_id (nullable self-FK restrict), segment_start (nullable), segment_end (nullable), frozen_rate (nullable), frozen_hours (nullable), frozen_days (nullable), frozen_days_in_month (nullable), computed_amount, posting_period_start, reason (nullable), finalized_at (nullable), timestamps`

Line shape by run type, with a `CHECK` enforcing that exactly one shape is present:

| Shape | Carries | Null |
|---|---|---|
| **salary** | `staff_compensation_id`, `segment_start`, `segment_end`, `frozen_rate`, `frozen_days`, `frozen_days_in_month` | `batch_instructor_id`, `corrects_payroll_line_id`, `frozen_hours` |
| **instructor** | `staff_compensation_id`, `batch_instructor_id`, `frozen_rate`, `frozen_hours` | segment columns, `corrects_payroll_line_id`, `frozen_days*` |
| **adjustment** | `corrects_payroll_line_id`, signed `computed_amount`, `reason` | every frozen column including `frozen_rate` |

`frozen_rate` is **nullable**, because an adjustment line has no rate — it is a signed correction, not a calculation. Forcing a value there would mean storing a meaningless number in a column whose whole purpose is to explain how an amount was reached.

`posting_period_start` is the period the line's cost belongs to, copied at finalization. For salary lines it is the segment's month; for instructor lines the run's finalization month; **for adjustment lines it is copied from the line being corrected**, which is what makes a June correction land in March's wage cost (§7).

Plus the generated columns and unique indexes in §7. The cascade is correct — lines are genuine children and only a draft run is ever deletable.

### `payroll_line_adjustments`
`id, payroll_line_id (FK cascade), amount (signed), reason, created_by (FK restrict), timestamps`
`CHECK (amount <> 0)`

---

## 10. Authorization

Permission names follow Shield's `{action}_{model}`; custom abilities are bare verbs. `RolePermissionSeeder` remains the authority.

**Resources seeded with read permissions:** `charge`, `payment`, `discount`, `staff_compensation`, `payroll_run`.

**Write abilities deliberately not seeded**, with policies that refuse unconditionally and tests that grant the permission anyway: `create_charge`, `update_charge`, `delete_charge`, `update_payment`, `delete_payment`, `update_staff_compensation`.

**Custom abilities:** `manage_pricing` · `apply_discount` · `adjust_charge` · `write_off_charge` · `reverse_payment` · `run_payroll` · `finalize_payroll` · `view_financial_report` · `export_financial_report`.

| Role | Holds |
|---|---|
| super_admin | everything |
| admin | read on all five finance resources · `create_payment` · `apply_discount` · `view_financial_report` · `export_financial_report` |
| staff | nothing financial |
| student | nothing until phase 3 |

Admins record money but cannot set a price, manage a discount definition, adjust a charge, write off a debt, reverse a payment, or touch compensation or payroll. Every one of those denials is a negative test.

### The one place authorization is not what it looks like

Staff hold `create_enrollment` but no charge permission. If charge issuance demanded its own ability, **a staff member could not complete an enrolment** — the walk-in scenario the system exists for (system design §1).

`EnrollAndBillAction` authorizes `create` on `Enrollment`, plus `apply_discount` when a discount was selected, and issues the bill as a system consequence of a permitted act. `IssueChargeAction` and `DeleteUncommittedChargeAction` are **internal collaborators, not request-path Actions**: they perform no ability check of their own, and architecture tests assert each has exactly one caller.

This is stated at length because it is the one deviation from "every Action authorizes itself", and an unexplained deviation is indistinguishable from a bug.

---

## 11. The write boundary

Per `docs/ENGINEERING.md`, any design section touching money must name its write boundary explicitly.

| Concern | Owner |
|---|---|
| Enrol and bill | `EnrollAndBillAction` (wraps `EnrollStudentAction` + `IssueChargeAction`, one transaction) |
| Delete an uncommitted bill | `DeleteUncommittedChargeAction` — internal, callable only from `DeleteEnrollmentAction` |
| Correct a charge | `AdjustChargeAction` |
| Write off a debt | `WriteOffChargeAction` |
| Record money in | `RecordPaymentAction` — tenders, allocation and finalization in one atomic call |
| Undo a payment | `ReversePaymentAction` |
| Change a rate | `ChangeCompensationAction` |
| Payroll | `CreatePayrollRunAction`, `FinalizePayrollRunAction`, `AdjustPayrollLineAction` |
| Change a price | `UpdateCoursePriceAction`, `UpdateBatchPriceAction` — the only writers of either price column |
| Discounts | `CreateDiscountAction`, `DeactivateDiscountAction`, `DeleteDiscountAction` — all gated on `manage_pricing` |
| **Payment self-consistency** | `PaymentInvariantService` |
| **Compensation overlap** | `CompensationPeriodInvariantService` — locks the `users` row |

`ChargeQueryService` is **read-only** and performs no writes. Deleting a bill alongside its enrolment goes through `DeleteUncommittedChargeAction`, which locks the charge and checks every disqualifying condition — any allocation, any adjustment, any write-off — **inside the transaction that deletes it**. A query service that also deletes is a write path wearing a reader's name.

**Prohibited, and enforced by extending `tests/Feature/Staff/ActionBoundaryArchTest.php`:** any write to a Finance table outside these Actions · `->relationship()` on a Filament field an Action owns · a `paid_amount`-style cached column · any status string column in Finance · float casts on money · `IssueChargeAction` or `DeleteUncommittedChargeAction` called from more than their one permitted caller · **`EnrollStudentAction` called from application code outside `EnrollAndBillAction`** (§12) · any write to a price column outside the two pricing Actions.

**What those tests are worth is unchanged.** They scan for known-bad code shapes and are a fast early warning, not proof. Where a protection matters, the proof is behavioural: drive the real Filament component and assert the outcome.

### Invariants and their actual mechanism

| Invariant | Mechanism |
|---|---|
| Tender total equals allocation total | Lock + check in the finalizing transaction |
| A payment never exceeds outstanding | Charge locked, outstanding derived under that lock |
| A payment is not duplicated by a retry | **Unique index** on `idempotency_key` |
| A replayed key returns the same request, not a different one | Fingerprint comparison on collision, refusing on mismatch |
| A reference is never absent | **`NOT NULL UNIQUE`**, satisfied by a placeholder replaced in the same transaction |
| Payroll locks do not deadlock | Deterministic ascending user-id lock order |
| Amounts are positive | **MySQL `CHECK`** |
| A card tender carries a non-blank reference | **MySQL `CHECK`** with `TRIM` |
| Finalization actor and time are paired | **MySQL `CHECK`** |
| One charge per enrolment | **Unique index** |
| References are unique | **Unique index** |
| An instructor assignment is paid at most once | **Stored generated column + unique index** |
| An identical salary segment is not paid twice | **Stored generated column + unique index** |
| Overlapping salary segments | Lock + check — no index can express range overlap |
| No overlapping compensation periods | Lock on the `users` row + check |
| Adjustments only while draft | Lock + check |

The trust boundary is unchanged from phase 1: locks and Actions protect every write reachable through application code; raw SQL and manual `tinker` are trusted administrative operations. The rows marked **CHECK** and **unique index** hold regardless.

---

## 12. Impact on existing code

### The unbilled-enrolment path — found by the step-0 review

`EnrollmentsRelationManager` calls `EnrollStudentAction` directly (line 266). Phase 1 was right to do so; phase 2 makes it **a UI path that creates an enrolment with no bill**, silently, on the batch screen staff already use.

**Resolution:** `EnrollAndBillAction` becomes the only application entry point. The relation manager calls it instead. An architecture test asserts that no code under `app/` calls `EnrollStudentAction` except `EnrollAndBillAction`, and the rule is mutation-tested by injecting a violation.

`EnrollmentTest` continues to call `EnrollStudentAction` directly — it is that Action's own unit-level test and the architecture rule scans `app/`, not `tests/`. But **`EnrollmentsRelationManagerTest` and the other existing enrolment tests must be updated**, because enrolling now also raises a bill. A phase 2 test suite that passes while the phase 1 suite still asserts the old behaviour means the surface was not actually migrated.

### `DeleteEnrollmentAction` breaks the day this ships

Every enrolment now has a charge, and financial foreign keys restrict on delete. The system design requires that a student recorded in error stays removable — "or the mistake is permanent" (§6) — and P1-T11 shipped deletion as a separate grant from withdrawal for exactly that case.

**Resolution:** `DeleteEnrollmentAction` calls `DeleteUncommittedChargeAction`, which locks the charge and refuses with a typed exception if it carries any allocation, adjustment or write-off, then deletes bill and enrolment together in one transaction.

### Cross-domain boundaries

`EnrollmentQueryService` is named in the system design and `docs/ENGINEERING.md` as the way Finance reads enrolment data — **and it does not exist.** Phase 2 builds it, in task 3, **with the full surface every later task needs**, so that no two tasks extend it in parallel. If a genuine gap appears later, it is raised rather than patched twice.

Its task 3 contract must therefore cover four consumers, not two:

| Task | Needs |
|---|---|
| 4 Payments | the student owning a charge's enrolment |
| 6 Receipts | enrolment reference, course and batch codes for the printed document |
| 8 Payroll | instructor assignments and their hours |
| 10 Reports | the enrolment → batch → course path every revenue grouping walks |

Revision 2 listed only tasks 8 and 10, which would have left task 4 walking `$charge->enrollment` — the exact cross-domain query the architecture test forbids, discovered at implementation time rather than here.

`ChargeQueryService` is the read-only reverse-direction interface Finance publishes for Enrolment.

The architecture test forbids Finance code from *querying* Enrolment models outside the service. Declaring a `belongsTo` where a real foreign key exists stays allowed — an over-strict rule here would be fought and then weakened.

### A phase 1 test inverts

`BatchResourceTest` asserts the price field is **absent** from `BatchResource`, pinned deliberately. That assertion inverts, in the task that adds the price gate and nowhere else.

### Activity log

From this phase the log records **all financial mutations** (system design §7): charge issuance, adjustment and write-off with their reasons, payment recording with its tender breakdown, reversal with its reason, compensation changes, payroll creation and finalization, line and run adjustments, price changes, and discount creation and deactivation.

The log remains **append-only**.

### Internationalization

All strings go through `lang/`, and **phase 2 uses one translation file per task rather than a single `finance.php`** — `pricing`, `billing`, `payments`, `charges`, `receipt`, `payroll`, `collect`, `reports`. Nine tasks editing one array file is a guaranteed merge conflict, and the split costs nothing. Arabic counterparts ship **empty** until phase 4.

Composite strings — anything built by joining fragments, including money formatting and the receipt's field labels — get their own keys rather than being assembled in code, because separators and ordering are localisable.

---

## 13. Open items

- **Aging buckets** are 0–30 / 31–60 / 61–90 / 90+ from `due_date`. Conventional, changeable at review.
- **One receipt cannot span two bills.** A student enrolling on two courses the same day gets two bills and two receipts. The allocation table supports the many-charge case so nothing needs re-migrating if this changes; the phase 2 UI will not offer it. Confirmed acceptable at step-0 review.

---

## 14. Testing requirements

Beyond `composer verify`:

- **Every permission test asserts the negative** — that an admin cannot reach `manage_pricing`, `adjust_charge`, `write_off_charge`, `reverse_payment`, `run_payroll` or `finalize_payroll`, and that staff reach no finance surface and cannot apply a discount.
- **The refusing policies are tested by granting the permission first.**
- **Concurrency is driven from both directions**: two payments against one bill; two first compensation rows **starting from an empty table**; two finalizations of overlapping salary segments; two duplicate payment submissions sharing an idempotency key.
- **Database-level guarantees are proven at the database.** Insert a second finalized line for an already-paid assignment; insert a card tender with a whitespace reference; insert a payroll run with a finalized time and no actor. Not by asserting a button is hidden.
- **Overpayment is tested at the boundary**, including when outstanding drops between form load and submit.
- **The tender-total-versus-allocation-total mismatch has its own unhappy-path test**, asserting the typed refusal and that no partial row survives.
- **A crafted cross-student allocation is tested.**
- **Every request-path Action is tested by direct invocation with an unauthorized actor**, not only through the UI that normally calls it.
- **An idempotency key reused with a different bill or tender split raises and creates nothing.**
- **An admin can edit a course or batch's non-price fields** while the price sits untouched in form state.
- **No row survives a transaction carrying a placeholder reference.**
- **A finalized instructor line still explains its amount** after `batch_instructor.assigned_hours` is changed underneath it.
- **A correction posts to the corrected line's period**, asserted by correcting March in June and reading March's wage cost.
- **A reversed payment is asserted absent from every report individually.**
- **Report boundaries are tested at local midnight and month edges** in `Africa/Tripoli`.
- **Rounding is tested on a case that rounds.**
- **The pricing boundary is tested from both sides** — super admin persists, admin's crafted write does not.
- **Every security claim in this document has a test named after it.**
- **Watch each new test fail before trusting it.** Break the thing it protects, confirm the failure names the right cause, restore from a file copy — never `git checkout`.

---

## 15. Decisions superseded during design

| Superseded | By |
|---|---|
| Auto oldest-first allocation with operator override | Allocation is decided by which bill was opened |
| Leftover money held as student credit | No overpayment is possible |
| A persisted drawer reconciliation | A live daily tender report |
| Draft payments finalized as a second step | One atomic create-and-finalize |
| `payments.method` as a single column | `payment_tenders` |
| `charges.status` as a stored enum | Fact columns plus derived state |
| `per_student` compensation | Removed — the centre does not pay per head |
| Pro-rating instructor hours across payroll periods | On-demand runs paying a batch as one lump |
| Salary lines keyed to a whole period | Month-and-rate segments (revision 2) |
| Draft-only payroll corrections | Adjustment runs for anything found after finalization (revision 2) |
| `apply_discount` held by staff | Admin and above (revision 2) |
| Price fields merely disabled | Never dehydrated; both prices written only by their Actions (revision 2) |
| `ChargeQueryService` deleting a charge | `DeleteUncommittedChargeAction` (revision 2) |
| A non-nullable reference written after insert | Placeholder replaced in the same transaction (revision 3) |
| One migration adding, backfilling and indexing | Three recoverable migrations (revision 3) |
| Calling the pricing Action on every save | Calling it only when the price changed (revision 3) |
| An idempotency key returning whatever owns it | Fingerprint comparison, refusing on mismatch (revision 3) |
| A generic `frozen_quantity` on payroll lines | Shape-specific frozen columns, with instructor hours restored (revision 3) |

---

## 16. Step-0 review findings declined

Recorded with reasoning, per the review contract's position that a reviewer can be wrong and the author should say so.

**"Remove write-offs throughout the design."** Declined, and retracted by the reviewer on 2026-08-09. The finding read the owner's no-refunds decision as covering debt write-off. Those are different questions and the owner answered them separately: no money is ever returned, *and* a super admin may retire a debt the centre has accepted it will never collect. §4 stands as written.

**"Remove `apply_discount` from staff."** Raised as a recommendation, then retracted by the reviewer, then **adopted anyway by the owner** on its merits — the grant is now admin and above (§3). Recorded because the path matters: it changed on the owner's decision, not on the review's.
