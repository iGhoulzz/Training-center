# Backend contract — read before writing any query

Repo `iGhoulzz/Training-center`, branch `main`, Laravel 13 / PHP 8.4 / Filament 5.7.

The client's standing instruction: **check what the backend supports so we do not find ourselves adding endpoints or business logic.** Every design in this bundle was checked against the repo. Nothing needs new business logic. This file lists, per screen, what already exists, what exists but must be batched, and the few genuinely new read queries.

Tags:

- **Exists** — the service, action, aggregate or report is already there. Wire the UI to it.
- **Reuse + batch** — the method exists but is per-record. Calling it per row N+1s a list. Batch or aggregate it; do not write a second source of truth.
- **New query** — a read query that does not exist. Still no new business logic: the rule lives in a model or enum already; only the *selection* is new.

---

## Dashboard / Front desk

| | |
|---|---|
| Exists | Collected today and the cash / card / transfer split — `Domain\Finance\Reports\DailyTenderReport`, `TenderBreakdownReport` |
| Exists | Outstanding total — `Domain\Finance\Reports\OutstandingAgedReport` |
| Exists | Active enrolments count — `Enrollment::scopeActive()`, `Batch::ACTIVE_ENROLLMENTS_COUNT` |
| New query | The "over capacity" and "hours unallocated" alert list. `Batch::isOverCapacity()` and `Batch::hasHourMismatch()` are **PHP predicates**, evaluated after rows load — they cannot filter in SQL as written. Add scopes that express the same comparison in the query, and keep the predicates as the single definition of the rule. |
| New query | "Certificates due this month". `StudentCertificate` has no due/overdue query. |

## Batches list

| | |
|---|---|
| Exists | Seats taken / capacity in one query — `BatchResource::getEloquentQuery()` `withCount()` |
| Exists | Assigned hours vs effective total hours — `Batch::ASSIGNED_HOURS_SUM`, `effective_total_hours` |
| Exists | Status, dates, course code, default sort — `BatchResource::table()` |
| New query | The "Over capacity" / "Hour mismatch" filter chips — same predicate-in-PHP problem as above. |

## Batch view

| | |
|---|---|
| Exists | Roster with enrolment status and enrolled-on date — `EnrollmentsRelationManager` |
| Exists | Instructors with assigned hours, departed staff included — `InstructorsRelationManager`, `Batch::instructors()` `withTrashed()` |
| Exists | Enrol / withdraw / complete — `EnrollStudentAction`, `WithdrawEnrollmentAction`, `CompleteEnrollmentAction` |
| Reuse + batch | Per-student balance column — `ChargeQueryService::outstandingForEnrollment()` is per enrolment. Batch it for the roster. |
| New query | Batch-level outstanding total. No aggregate across a batch today. |

## Students list

| | |
|---|---|
| Exists | Code, name, phone, status; sorted by surname — `StudentResource::table()`, `defaultSort(last_name)` |
| Exists | Issue / reset portal login, including the disabled "no email" state — `IssuePortalCredentialAction`, `ResetPortalCredentialAction`, `credentials.student_has_no_email` |
| Exists | "No portal login" filter — `students.user_id IS NULL` |
| Reuse + batch | Balance in the row and in the detail panel — `StudentBalanceQuery::forStudent()` is one call per student. |
| New query | "Owes money" filter — needs a join from students to outstanding charges. |

## Enrol & Collect

| | |
|---|---|
| Exists | Student → batch → discount → preview, and the previewed price — `EnrollAndCollect` wizard, `PricingService::priceForBatch()` |
| Exists | Confirm raises the bill; collect records cash / card / split tenders and the receipt — `EnrollAndBillAction`, `RecordPaymentAction`, `GenerateReceiptJob` |
| Exists | Tender totals must equal the amount collected — `PaymentInvariantService::assertRecordable()` |
| New query | Creating a new student inside the flow. The step only selects existing records — `EnrollAndCollect::studentStep()`. |
| Behaviour to respect | A second installment against the same bill is **refused by design** (`collect.installment_conflict`). The persistent Bill panel must not imply otherwise. |

## Finance hub *(new page)*

| | |
|---|---|
| Exists | Every tile and link is an existing page, report or aggregate — `ChargeResource`, `PaymentResource`, `PayrollRunResource`, `Reports\*` |
| Exists | Excel export: a **queued** Filament `ExportAction`, **Xlsx only**, served on a signed throttled route once every row succeeds — `PrepareReportCsvExport`, `ReportXlsxDownload` |
| Exists | PDF export: dispatches a job and notifies; served per-requester from the private disk — `GenerateReportPdfJob`, `ReportPdfDownload` |
| Exists | Both exports **re-check authorization at download time**, not only at click time — `ReportExportAuthorization`, `EnsureReportExportAuthorized` |
| Exists | Per-payment receipt PDF, generated asynchronously, stored privately — `GenerateReceiptJob`, `AttachReceiptAction`, `ReceiptFileOwnershipService` |
| New query | The hub page itself. Every part exists; the landing page does not. |
| New query | The export history list. Filament stores `Filament\Actions\Exports\Models\Export` rows; nothing surfaces them. |

**This shapes the UI, not just the plumbing: no export is an instant download.** Buttons must show queued / running / ready / failed, and a partial export is never served. The prototype models this exactly — copy that behaviour.

## Charges

| | |
|---|---|
| Exists | Bill reference, enrolment, course, batch, billed amount, discount, due date — `ChargeResource::table()` |
| Exists | Outstanding per bill as an aggregate, not a per-row query — `ChargeBalance::OUTSTANDING_ALIAS` |
| Exists | "Outstanding only" and written-off filters — `Filter::make(outstanding_only)`, `TernaryFilter` |
| Exists | Adjust and write off, with their reasons and refusals — `AdjustChargeAction`, `WriteOffChargeAction` |
| New query | The "31 days late" ageing chip on a row. Ageing exists in `OutstandingAgedReport` only. |

## Payments

| | |
|---|---|
| Exists | Receipt ref, student, received at, amount, bill, recorded by, reversed flag — `PaymentResource::table()`, `tenders_sum_amount` |
| Exists | Reverse a payment, with the mandatory reason and the no-refunds hint — `ReversePaymentAction`, `payments.reverse_reason_hint` |
| Exists | Receipt PDF download — `GenerateReceiptJob`, `ReceiptDownloadController` |
| Reuse + batch | The tender chips per row — `Payment::tenders()` is a relation; eager-load it for the list. |

## Certificates

| | |
|---|---|
| Exists | Register columns, the three statuses, issue / replace / revoke — `StudentCertificateResource`, `Issue`/`Replace`/`RevokeStudentCertificateAction` |
| Exists | Outstanding balance shown on the issue modal, **never blocking** — `certificates.outstanding_balance_hint` |
| New query | The "completed but not yet issued" queue. The eligible enrolments exist; the list does not. |

## Reports

| | |
|---|---|
| Exists | All seven reports, their filters and row data — `Domain\Finance\Reports\*`, `ReportDataBuilder` |
| Exists | Excel (Xlsx only) and PDF exports, both queued, both on signed per-requester routes |
| Exists | Export buttons hide entirely without the permission — `->authorize(ReportExportAuthorization::allows)` |
| New query | Charting the rows. The data is there; no `ChartWidget` is registered. |

## Payroll runs

| | |
|---|---|
| Exists | Runs, type, period, lines with frozen rate and hours, finalize — `PayrollRunResource`, `PayrollCalculator`, `FinalizePayrollRunAction` |
| Exists | Bonuses, deductions, corrections against a finalized line — `AddPayrollLineAdjustmentAction`, `AdjustPayrollLineAction` |
| Exists | Run total, computed from lines already loaded on the review page — `PayrollLine::computed_amount` |

## Activity log

| | |
|---|---|
| Exists | When / who / action / record / IP, with translated event and record-type labels — `ActivityResource`, `lang/en/activity.php` |
| Exists | The field-level diff per entry — `activity.change_line`, spatie/laravel-activitylog properties |
| Exists | Append-only; cleaning is refused — `RefuseActivityLogCleaning` |

## Courses

| | |
|---|---|
| Exists | Code, name, total hours, active flag, default price behind `manage_pricing` — `CourseResource::table()`, `form()` |
| Exists | Batches of a course, and the hours a batch inherits when empty — `Course::batches()`, `Batch::effective_total_hours` |
| Reuse + batch | Enrolled-student and batch counts per row — `withCount()` is not on `CourseResource` yet; one line. |
| New query | Revenue per course on the catalogue. It exists in `RevenueReport` only. |

## Users & roles

| | |
|---|---|
| Exists | Name, email, roles, active flag, last login, job title, employment type, hire date, certificate count — `UserResource`, `StaffProfileResource` |
| Exists | New user with a temporary password, reset password, and every escalation refusal — `IssueTemporaryPasswordAction`, `staff.escalation.*` |
| Exists | The last-super-admin guard on deactivate, role removal and delete — `staff.save_refused_last_super_admin` |
| Exists | Staff certificates with expiry and private download — `CertificatesRelationManager`, `staff.expired` |
| New query | "Assigned batches / hours this month" on a user row — instructor hours live on the `Batch::instructors()` pivot (`assigned_hours`). |

## Create pages

**Student** — every field, rule and help text exists in `StudentResource::form()`; status defaults to `StudentStatus::Prospective`; create is a real page (`CreateStudent::route('/create')`) because a modal action skips page save hooks. *New:* suggesting the next free student code (the field is free text with a unique rule), and redirecting from create into Enrol & Collect.

**Course** — `CourseResource::form()`; the default price is hidden without `manage_pricing` and always written through `UpdateCoursePriceAction` (`WritesPricingThroughActions`); batches inherit course hours when their own is empty.

**Batch** — `BatchResource::form()`; end date cannot precede start; over-capacity enrolment is permitted and flagged, not blocked; the inherit-when-empty hints are `enrollment.total_hours_batch_hint`.

## Student portal

| | |
|---|---|
| Exists | Overview, my enrolments (certificate columns when permitted), my balance — `Portal\Pages\Overview`, `MyEnrollments`, `MyBalance` |
| Exists | Per-enrolment outstanding and the total — `StudentBalanceQuery::forStudent()` |
| New query | Naming the course and batch on balance rows. `MyBalance` identifies an enrolment by id today (`portal.balance_enrollment_row`). |
| Not supported | Paying online from the portal. `RecordPaymentAction` is staff-only — the design does not offer it, and neither should the build. |

---

## Public site — entirely new surface

| | |
|---|---|
| **Certificate verifier** | **No new business logic.** `StudentCertificate` already holds holder, programme, issue date and the three statuses. Needs: a public read-only route, a lookup by reference, and a response that exposes **only** what is printed on the certificate. Add rate limiting and do not leak student contact data, national ID or balance. `CertificateStatus` already distinguishes Valid / Replaced / Revoked — surface all three honestly rather than a boolean. |
| **Publications** | Genuinely new. An `Article` model: title (EN + AR), slug, description, topic, PDF file, issue date, authors, download counter, published flag. A Filament resource for the admin to upload; a public index, show page and download route. The download counter increment is **the only new write in the whole bundle**. |
| **Programmes / centre info** | Can read `Course` and `Batch` (name, hours, price, start date, seats) — but decide deliberately what is public. Seat counts and prices are business-sensitive; the prototype shows them, so confirm with the client. |
| **Student portal sign-in** | The portal panel and its student guard exist. The public site only needs a link or a sign-in form posting to the existing guard. Logins are issued by the registrar and require an email on the student record — the modal copy says so; keep it. |

## Rules to preserve

These are behaviours the code enforces. The UI must not imply otherwise.

1. No export or receipt is an instant download — everything is queued, and a partial file is never served.
2. Export permission is re-checked at download time.
3. Over-capacity enrolment is allowed and flagged, never blocked.
4. An outstanding balance does not block issuing a certificate.
5. A second installment against the same bill is refused.
6. Tender totals must equal the amount collected.
7. The last super admin cannot be deactivated, demoted or deleted.
8. The activity log is append-only.
9. A student with no email cannot be issued a portal login.
10. Pricing is only ever written through its actions, never mass-assigned.
