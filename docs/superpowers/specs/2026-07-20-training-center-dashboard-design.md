# Training Center Management System — System Design

**Date:** 2026-07-20
**Status:** Approved
**Scope:** Full system design. Phase 1 at implementation detail; phases 2–4 at architectural detail.

---

## 1. Purpose

A web-based management system for a single training center. It replaces nothing — the center is new and has no existing system or legacy data. The system manages students, course offerings, enrollments, staff accounts, and the center's finances, and presents a public marketing site to prospective students.

### Success criteria

- Staff can enroll a student in a running course batch without touching a spreadsheet.
- A super admin can answer "how much did we make in March, and what did we pay out in wages?" from the dashboard, and export the answer to Excel or PDF.
- Every change to student or financial data is attributable to a named user with a timestamp.
- No user can see or do anything their role does not permit.

---

## 2. Constraints and decisions

| Decision | Choice | Rationale |
|---|---|---|
| Organization scope | Single center, single location | No tenant or branch scoping anywhere in the data model. |
| Legacy data | None | No migration or import work. |
| Stack | Laravel + Filament | The dashboard is CRUD- and report-heavy, which is precisely Filament's domain. Effort goes into financial logic, not rebuilding tables and forms. |
| Database | MySQL | Nothing here requires PostgreSQL-specific features. MySQL runs everywhere and is more portable for this team. Migrations use Laravel's query builder, keeping a later switch possible. |
| Hosting | VPS (Hetzner/DigitalOcean) managed via Ploi or Forge | Provides queue workers, Redis, scheduled tasks, and automated off-server backups. Roughly $15–25/month. |
| Languages | Arabic and English, RTL | Translation structure and logical CSS properties from the first commit; Arabic strings land in phase 4. |
| Currency | Libyan Dinar (LYD), single currency | Stored as `decimal(12,3)` per ISO 4217 — the dinar subdivides into 1000 dirham. Display precision is a UI setting. Multi-currency is out of scope. |
| Public registration | Disabled | All accounts are created by an administrator. |
| Email | None at launch | Notification classes exist; mail driver set to `log`. Enabling email later is a config change. |

---

## 3. Architecture

One Laravel application serving three surfaces:

| Surface | Route | Built with | Audience |
|---|---|---|---|
| Public site | `/` | Blade + Tailwind | Anyone, unauthenticated |
| Staff dashboard | `/admin` | Filament panel | Super admin, admin, staff |
| Student portal | `/portal` | Blade + Tailwind | Students (phase 3) |

Staff and students use **separate panels with separate auth guards**. They never share a screen or a session scope, which eliminates the class of bugs where a student reaches an admin view because a menu item was hidden but its route was not guarded.

### Internal structure

Code is organized by domain rather than by file type:

```
app/Domain/
  Enrollment/    students, courses, batches, enrollments, certificates
  Finance/       charges, payments, wages, payroll, reports
  Staff/         accounts, roles, activity log
  Content/       public site pages, course listings
```

Each domain owns its models, actions, services, and policies. Cross-domain access goes through a published service interface — the Finance domain reads enrollment data through `EnrollmentQueryService`, never by querying `enrollments` directly. This keeps wage and revenue calculation independently testable and prevents the financial logic from becoming entangled with enrollment internals.

### Phasing

Each phase produces a usable system and gets its own spec and implementation plan.

- **Phase 1 — Foundation.** Auth, four roles, staff account management, activity log, student profiles, course templates, batches with instructor hour allocation, enrollments, automated backups. No money.
- **Phase 2 — Financials.** Pricing, charges, payments, balances, compensation configuration, payroll, reports, Excel and PDF export.
- **Phase 3 — Student portal and certificates.** Read-only student login, completion marking, certificate generation.
- **Phase 4 — Public site and Arabic.** Landing pages, course listings, contact, and the full bilingual pass.

Internationalization structure is built from the first commit even though translations arrive in phase 4. Every user-facing string goes through `lang/` files, and all layout uses logical CSS properties (`margin-inline-start`, not `margin-left`). Retrofitting RTL onto a completed application is substantially more expensive than accommodating it from the start.

---

## 4. Authentication

Filament's native authentication. No Breeze, Fortify, or Jetstream — Filament already provides login, session handling, and password hashing, and a second auth package would create two competing sources of truth.

- **No public registration.** Registration routes are not enabled on either panel.
- **Identity:** email and password.
- **Account creation:** super admin creates staff accounts; staff create student records and, from phase 3, issue portal credentials.
- **Password reset (phase 1):** an administrator opens the user record and generates a temporary password, displayed once. The `must_change_password` flag forces a change at next login.
- **Notifications** are implemented as Laravel Notification classes with the mail driver set to `log`. Enabling welcome and password-reset emails later requires configuring a provider, not rewriting code.
- **Sessions:** database-backed, 8-hour idle timeout.
- **Throttling:** provided by Filament's own login page, which calls `rateLimit(5)` — 5 attempts per minute, keyed per component and IP. This spec originally called for email+IP keying via a Laravel named limiter; that was written before it was known Filament self-throttles. A named `login` limiter was implemented in P1-T03 and then **removed**, because nothing referenced `throttle:login` — there are no Laravel auth routes in this app — and a registered-but-unreferenced limiter reads as an active control while protecting nothing. Phase 3 adds one when the student portal introduces real auth routes.
- **Forced password change is a UX gate, not an authorization boundary.** The `ForcePasswordChange` middleware is registered non-persistently, so it runs on page loads but not on Livewire update requests. This is required for the change-password form to submit at all — a persistent middleware would redirect the save request itself and make the flow an inescapable trap. The consequence is that a flagged user could in principle drive other Livewire components before changing their password. Anything that must be a genuine boundary belongs in a policy, not here.
- **Two-factor authentication:** out of scope for phase 1. Filament supports it natively when wanted.

---

## 5. Roles and permissions

Four roles, implemented with `spatie/laravel-permission` and Filament Shield.

Authorization is **permission-based, never role-based, in code**. Always `$user->can('students.delete')`; never `$user->hasRole('admin')`. Role membership is data; adding a fifth role must not require a code change.

**No `role` column exists on the `users` table.** Role assignment lives entirely in Spatie's pivot tables.

### Permission matrix

| Capability | Super Admin | Admin | Staff | Student |
|---|---|---|---|---|
| Students | full | full | view all, edit own batches | own record (P3) |
| Courses and batches | full | full | view | own (P3) |
| Enrollments | full | full | create/edit in own batches | own (P3) |
| Staff accounts | full | all except super admins | none | none |
| Roles and permissions | full | none | none | none |
| Activity log | full | view | none | none |
| System settings | full | none | none | none |
| Course pricing (P2) | full | view | none | none |
| Record payments (P2) | full | create only | none | none |
| Edit/reverse payments (P2) | full | none | none | none |
| Compensation and payroll (P2) | full | view | none | none |
| Financial reports (P2) | full | view and export | none | own balance (P3) |

The payment split is deliberate. Front-desk administrators must be able to record incoming money without a super admin present, but corrections and reversals are a separate, restricted capability — that boundary is what makes the audit trail meaningful.

### Escalation guards

These are the three failure modes that break role systems in practice, and each is enforced and tested:

1. An admin cannot create, edit, or delete a super admin.
2. No user can modify their own roles or permissions.
3. The system refuses to delete or deactivate the last active super admin.

Enforcement lives in `UserPolicy` **and** at the model layer, because a policy only runs when something chooses to consult it — Spatie's `assignRole()` answers to no gate, so a Filament form could otherwise grant `super_admin` without the policy ever executing.

Guard 3 was extended during implementation to cover role *removal* as well as deletion and deactivation: `removeRole('super_admin')` or `syncRoles([])` on the last super admin reaches the identical end state — nobody able to manage roles — and the guard as originally specified said nothing about it.

Guards 1 and 2 skip when no user is authenticated, since seeders and console commands legitimately assign roles with no principal; they protect the request path, not the CLI path. Guard 3 has no exemption and holds everywhere. `docs/ENGINEERING.md` records the writes that bypass model events and must not be used on `User`.

---

## 6. Data model

### Normalization rules applied throughout

- Every foreign key carries a real database constraint with explicit `onDelete` behavior: `restrict` for anything financial, `cascade` only for genuine child records.
- Money is `DECIMAL(12,3)` — LYD subdivides into 1000 dirham, per ISO 4217. Never float. Never `DECIMAL(12,2)`, which would silently round any dirham-precision amount and break reconciliation.
- Statuses are string columns backed by PHP enums — validated in the application, indexed in the database.
- **No derived values are stored.** Balances, revenue, and wage totals are always computed from source rows.
- Soft deletes on students and users; hard constraints on financial records. A payment is never deleted, only reversed.
- Indexes on every foreign key and on every column used in `WHERE` or `ORDER BY`.

### Phase 1 tables

**`users`** — login identity only
`id, name, email (unique), password, locale, is_active, must_change_password, last_login_at, timestamps, softDeletes`

**`staff_profiles`** — optional 1:1 with `users`
`id, user_id (unique FK), phone, job_title, hire_date, employment_type, timestamps`

Employment attributes describe a person's job, not their login. A super admin may have no employment record; a departed instructor retains theirs after deactivation.

**`students`** — a record, not necessarily a user
`id, user_id (nullable unique FK), student_code (unique), first_name, last_name, email (nullable), phone, national_id, date_of_birth, gender, address, status, notes, timestamps, softDeletes`

The nullable `user_id` is deliberate: a student exists whether or not they ever receive a portal login. Phase 3 populates this column without altering anything else.

**`courses`** — catalog template
`id, code (unique), name_en, name_ar, description_en, description_ar, total_hours, default_price, is_active, timestamps`

**`batches`** — a course actually running
`id, course_id (FK), code (unique), start_date, end_date, capacity, total_hours (nullable), price (nullable), status, timestamps`

Nullable fields inherit from the parent course when null, rather than being copied at creation. Copying would leave stale duplicates the moment someone edits the course.

**`batch_instructor`** — many-to-many with an attribute
`id, batch_id (FK), user_id (FK), assigned_hours, unique(batch_id, user_id)`

Where two instructors share a course, the hours belong to the *relationship*, not to either side. One instructor assigned to a 30-hour batch receives all 30; two may split 18/12 or any other distribution. The system warns when assigned hours do not sum to the batch total but does not block it, because genuine co-teaching means both instructors are present for all hours.

**`enrollments`** — associative entity
`id, student_id (FK), batch_id (FK), enrolled_at, status, completed_at, certificate_issued_at, unique(student_id, batch_id)`

Enrolling beyond a batch's capacity produces a warning, not a hard block.

**`activity_log`** — Spatie's table, polymorphic

### Status enums

Defined here so they are not invented inconsistently during implementation:

| Column | Values |
|---|---|
| `students.status` | `prospective`, `active`, `graduated`, `inactive` |
| `batches.status` | `planned`, `active`, `completed`, `cancelled` |
| `enrollments.status` | `active`, `completed`, `withdrawn` |
| `charges.status` (P2) | `unpaid`, `partial`, `paid`, `waived` |
| `payments.method` (P2) | `cash`, `bank_transfer`, `card`, `other` |
| `staff_compensation.type` (P2) | `salary`, `hourly`, `per_student` |

A batch's status gates what may be edited: `completed` and `cancelled` batches reject new enrollments and instructor changes.

### Note on price columns in phase 1

`courses.default_price` and `batches.price` appear in the phase 1 schema but are **not used, displayed, or editable in phase 1**. They are created by the initial migrations so that phase 2 does not require altering tables that already hold production data. Phase 1 has no financial features of any kind.

### Phase 2 tables (designed now, built in phase 2)

**`charges`** — what a student owes
`id, enrollment_id (FK), amount, due_date, status`

**`payments`** — money received
`id, student_id (FK), amount, paid_at, method, reference, recorded_by (FK users), notes`

**`payment_allocations`** — which payment settles which charge
`id, payment_id (FK), charge_id (FK), amount`

The allocation table is load-bearing. A student paying one lump sum covering three courses is normal, and so is paying one course in four installments. Without it, a `paid_amount` column on `charges` inevitably drifts out of sync with the payment records. With it, every balance is derived by summing allocations — one source of truth, permanently reconcilable.

**`staff_compensation`** — effective-dated
`id, user_id (FK), type (salary|hourly|per_student), amount, effective_from, effective_to (nullable)`

Compensation rates are never updated in place. A raise inserts a new row and closes the previous one. This is what allows March's payroll to be recomputed correctly after a rate change in June; overwriting the rate would silently corrupt every historical report.

Staff compensation is per person and may combine types — the center employs both salaried shift staff and hourly instructors, and one person may have both a base salary and an hourly teaching rate.

**`payroll_runs`** and **`payroll_lines`** — a payroll run freezes its computed lines, storing the rate and hours used at the moment of calculation. Historical payroll must not change because upstream data changed.

### Relationships

```
users ──1:1── staff_profiles
users ──M:N── batches                (via batch_instructor, with assigned_hours)
users ──1:N── staff_compensation     (effective-dated)
courses ──1:N── batches ──1:N── enrollments ──N:1── students
enrollments ──1:N── charges ──M:N── payments  (via payment_allocations)
activity_log ──polymorphic── everything auditable
```

---

## 7. Activity log

Implemented with `spatie/laravel-activitylog`.

**Recorded:** every create, update, and delete on students, enrollments, batches, courses, and user accounts, with actor, timestamp, IP address, and a field-level before/after diff. Role and permission changes. Successful logins, logouts, and failed login attempts. From phase 2, all financial mutations.

**The log is append-only.** No delete action exists in the UI for any role, including super admin. An audit trail that someone can edit is not an audit trail.

Viewable by super admin and admin, filterable by actor, date range, and record type.

---

## 8. Financial reporting (phase 2)

Reports available to super admin (full) and admin (view and export):

- Revenue by course, by batch, and by month
- Outstanding balances — who owes what, aged
- Payment method breakdown
- Wage cost per period, per person and in total
- Profit: revenue minus wages for a period
- Per-student payment history

Every report exports to both Excel and PDF. Export runs as a queued job so large reports do not block the browser; the user is notified in-app when the file is ready. Library selection for the Excel writer and PDF renderer is deferred to the phase 2 spec, since the right choice depends on the final report layouts.

---

## 9. Error handling

- Validation lives in Form Request classes (for Blade surfaces) and Filament's schema-level rules (for the admin panel), and is mirrored by database constraints. Invalid data cannot enter through a route that was overlooked.
- Any operation touching multiple tables — enrolling a student, creating a user with a role, recording a payment with allocations — runs inside a database transaction.
- Domain failures raise typed custom exceptions carrying context (`InsufficientCapacityException`, `LastSuperAdminException`), rendered as readable messages in the UI.
- Stack traces are never shown to users. Errors are logged with structured context.

---

## 10. Testing

Feature tests are the priority, because the risk in this system is in how the pieces connect rather than in isolated units.

**Phase 1 required coverage:**

- One permission test per role per resource, asserting both what the role *can* and *cannot* do. Negative assertions are as important as positive ones.
- All three escalation guards.
- Enrollment constraints: duplicate enrollment rejected, capacity overflow warns without blocking.
- Instructor hour allocation, including the two-instructor split and the sum mismatch warning.
- Activity log completeness — that a change produces a log entry with the correct actor and diff.
- Authentication: throttling, forced password change, session scoping between panels.

`RefreshDatabase` on every feature test. Factories with states for test data. `Mail::fake()`, `Queue::fake()`, and `Event::fake()` — never hit real external services.

---

## 11. Operations

- **Backups from day one.** `spatie/laravel-backup`, dumping the database daily to off-server storage. This is set up in phase 1, before there is anything valuable to lose, because that is the only point at which anyone reliably remembers to do it. A training center's payment history is not reconstructible.
- Queue workers via Redis, managed by the hosting panel.
- Laravel's scheduler on a one-minute cron entry.
- Deployment on git push via Ploi or Forge.

---

## 12. Explicitly out of scope

Recorded so these do not reappear as assumptions:

- Multi-tenancy, multi-branch, or multi-currency
- Student attendance tracking per session
- Grades and assessment results — completion is marked manually by staff
- Class scheduling and timetables
- Staff clock-in, leave management, and payroll disbursement
- Online card payment processing
- Expense tracking beyond staff wages
- A mobile application
- Public student self-registration
