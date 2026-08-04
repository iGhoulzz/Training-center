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
- Anyone holding a physical student certificate can verify its reference without seeing unrelated student data.
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
| Hosting | VPS (Hetzner/DigitalOcean) managed via Ploi or Forge | Provides queue workers, Redis, and scheduled tasks — including the nightly backup, which writes to a destination outside the server's own failure domain. Roughly $15–25/month. |
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
- **Phase 3 — Student portal and certificates.** Read-only student login, completion marking, physical-certificate issuance records, and reference verification. Certificate design and printing happen outside the system.
- **Phase 4 — Public site and Arabic.** Landing pages, course listings, contact, and the full bilingual pass.

Internationalization structure is built from the first commit even though translations arrive in phase 4. Every user-facing string goes through `lang/` files, and all layout uses logical CSS properties (`margin-inline-start`, not `margin-left`). Retrofitting RTL onto a completed application is substantially more expensive than accommodating it from the start.

---

## 4. Authentication

Filament's native authentication. No Breeze, Fortify, or Jetstream — Filament already provides login, session handling, and password hashing, and a second auth package would create two competing sources of truth.

- **No public registration.** Registration routes are not enabled on either panel.
- **Identity:** email and password.
- **Account creation:** super admins and admins create staff accounts, and both may assign roles — but **only a super admin may grant or revoke `super_admin`**, or create an account holding it. Staff create student records and, from phase 3, issue portal credentials.

  Creating an account requires `create_user` **and** `reset_user_password`: the form has no password field (an administrator typing someone else's password is a credential they then know), so creation issues a temporary one. Being able to create an account whose password you can see is the same capability as resetting one. At least one role is required, since panel access comes from a role and a roleless account is one nobody can sign in to.
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
| Students | full | full | view all, create | own record (P3) |
| Courses and batches | full | full | view | own (P3) |
| Enrollments | full | full | create/edit in own batches | own (P3) |
| Staff accounts | full | all except super admins | none | none |
| Roles and permissions | full | assign roles below super_admin | none | none |
| Activity log | full | view | none | none |
| System settings | full | none | none | none |
| Course pricing (P2) | full | view | none | none |
| Record payments (P2) | full | create only | none | none |
| Edit/reverse payments (P2) | full | none | none | none |
| Compensation and payroll (P2) | full | view | none | none |
| Financial reports (P2) | full | view and export | none | own balance (P3) |
| Student certificates (P3) | full | view, issue, replace, revoke | view | own valid certificate |

The students row previously read "view all, edit own batches", which conflated two different things: a student **record**, and the **enrolments** that place a student in a batch. Staff scope applies to the latter. On student records staff hold **view and create** — they register walk-ins and see the whole register — but not update or delete, because correcting or removing an existing record is an administrative act. Create without update is deliberate; the two are separate grants and are tested as such.

**How the enrolment scope is expressed (P1-T11).** "create/edit in own batches" is carried by two permissions, never by a role check. `update_enrollment` is unrestricted and is held by super admins and admins. `update_assigned_batch_enrollment` is the staff grant and is restricted to batches the actor is assigned to teach. Staff deliberately do **not** hold `update_enrollment`: holding it would satisfy the unrestricted branch first and the scoping would never run, while every scoped test still passed.

"Own batches" is answered by the `batch_instructor` pivot and never by `staff_profiles.employment_type`. Employment type says what somebody *is*; the pivot says what they were actually put on. An administrative profile assigned to teach one batch may amend that batch's enrolments, and an instructor assigned to nothing may amend none — both correct, and neither expressible through employment type.

**Creation is unscoped.** `create_enrollment` alone. A front-desk staffer must be able to enrol a walk-in (goal, section 1) and they teach nothing at all; scoping creation the way editing is scoped would make the system's stated purpose unreachable for the people it was written for. The asymmetry matches the reasoning applied to student records above.

The "roles and permissions" row originally read `none` for admins while the row above granted them staff-account management. Those are incompatible once creating an account requires giving it a role: an admin could only ever produce an account that rolled back or could reach no panel. Admins therefore hold `assign_role`, and **guard 1 — not the absence of the permission — is the boundary**. Admins cannot manage the roles themselves (creating, renaming, deleting a role, or changing its permissions remains super-admin-only via `RolePolicy`); they can only assign existing roles below `super_admin` to users.

The payment split is deliberate. Front-desk administrators must be able to record incoming money without a super admin present, but corrections and reversals are a separate, restricted capability — that boundary is what makes the audit trail meaningful.

### Escalation guards

These are the failure modes that break role systems in practice, and each is enforced and tested:

1. A non-super-admin cannot create, edit, or delete a super admin, nor add or remove the `super_admin` role.
2. No user can modify their own roles or permissions.
3. The system refuses to delete, deactivate, or strip the role from the last active super admin.
4. An authenticated role or permission write requires the `assign_role` ability.

Guard 3 was extended during implementation to cover role *removal* as well as deletion and deactivation: stripping `super_admin` from the last super admin reaches the identical end state — nobody able to manage roles — and the guard as originally specified said nothing about it. Guard 4 was added after a review found a staff account could assign `admin` to another user.

**Enforcement architecture.** Security-sensitive writes go through explicit domain Actions that receive the acting user and authorize themselves via `Gate::forUser($actor)`. A `SuperAdminInvariantService` owns guard 3, holding a row lock and running the check and the mutation in one transaction. Architecture tests prevent application code from reaching around the Actions.

This replaced an earlier design that enforced the guards by overriding Spatie's write methods on the models. Two review rounds kept finding fresh bypass methods, and the approach contradicted the project's own "no business logic in models" rule. The lesson is recorded because it is easy to re-derive the wrong answer: **a policy only runs when something consults it, and patching each write method is an unbounded game — so the fix is to make one narrow write path and enforce that nothing else exists.**

**Trust boundary, stated plainly.** Model events do not protect against raw SQL, manual `tinker`, or query-builder writes, and the system does not claim they do. Those are trusted administrative operations; database-wide enforcement would require MySQL triggers and is out of scope. What is guaranteed is that every write reachable through application code is authorized.

Guards 1, 2, and 4 are actor-relative and do not apply where there is no actor — seeders and setup use a separately named trusted path (`SystemRoleWriter`). Guard 3 has no exemption and applies to system writes too. `docs/ENGINEERING.md` holds the full detail.

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
`id, user_id (unique FK), phone, job_title, hire_date, employment_type, qualifications, profile_photo_path, timestamps`

Employment attributes describe a person's job, not their login. A super admin may have no employment record; a departed instructor retains theirs after deactivation.

`employment_type` distinguishes the two kinds of staff the centre actually has: **instructors**, who teach courses, and **administrative** or **support** staff, who work shifts at the centre. It drives who can be assigned to a batch.

`qualifications` is a single free-text field — e.g. "PhD in Applied Linguistics, University of Tripoli". Deliberately not an enum: the centre records credentials for reference, not for filtering or reporting, and prose accommodates the range of real qualifications without a migration each time one does not fit.

`profile_photo_path` stores a **path only**. There is no default image row per user; the UI renders initials when the column is null.

**`staff_certificates`** — one row per uploaded credential
`id, staff_profile_id (FK), title, issued_on (nullable), expires_on (nullable), original_filename, disk, path, timestamps`

A separate table rather than columns on the profile, because a certificate carries business metadata of its own — what it is, when it was issued, and when it expires — and one person holds several. `expires_on` matters operationally: some teaching accreditations lapse.

### File storage

Applies to every file the system stores, now and later.

- **Binary content never goes in the database.** Rows hold metadata and a path; the bytes live on a filesystem disk.
- **Uploads go to a private disk**, not a web-served one. Staff certificates carry personal data — full names, national ID numbers, dates of birth — and a public disk gives every file a permanent URL that needs no login and cannot be recalled once it leaks.
- **Files are served only through policy-authorized downloads or temporary signed URLs.** Authorization is checked per request, at the point of serving.
- **The private disk is included in backups** (see section 11). A database dump alone would restore rows pointing at files that no longer exist.

Phase 2's payment receipts and generated report PDFs reuse this storage infrastructure. Phase 3 student certificates do **not** create or store a certificate PDF: the physical template, visual design, and printing are handled outside the system. If scanned student-certificate copies are added later, that is a separate feature with its own model, policy, retention rule, and private storage path; it must not reuse `staff_certificates`.

**`students`** — a record, not necessarily a user
`id, user_id (nullable unique FK), student_code (unique), first_name, last_name, email (nullable), phone, national_id, date_of_birth, gender, address, status, notes, timestamps, softDeletes`

The nullable `user_id` is deliberate: a student exists whether or not they ever receive a portal login. Phase 3 populates this column without altering anything else.

**`courses`** — catalog template
`id, code (unique), name_en, name_ar, description_en, description_ar, total_hours, default_price, is_active, timestamps`

**`batches`** — a course actually running
`id, course_id (FK), code (unique), start_date, end_date, capacity, total_hours (nullable), price (nullable), status, timestamps`

`total_hours` inherits from the parent course when null, rather than being copied at creation. Copying would leave stale duplicates the moment someone edits the course. **`price` does not inherit — nothing reads it in phase 1**; the column is nullable only so phase 2 need not alter a populated table.

**`price` inheritance is phase 2 work.** The column is nullable now so phase 2 never has to alter a table holding production data, and `total_hours` proves the inheritance pattern works, but no price is read, displayed, or inherited anywhere in phase 1. Phase 2 decides what a null price means once the charge model exists — in particular whether an already-issued charge keeps the price it was raised at when the course price later changes. Do not implement price inheritance before that decision.

**`course_id` is immutable after creation.** Re-parenting a batch silently rewrites what it inherits and, once instructor hours and enrolments exist, strands them against a course those people never taught or enrolled on. The form disables and de-hydrates the field on edit. If the centre ever needs to re-parent a batch, that is a deliberate Action with its own authorization and its own handling of the dependent rows, not an ordinary edit.

**`batch_instructor`** — many-to-many with an attribute
`id, batch_id (FK), user_id (FK), assigned_hours, unique(batch_id, user_id)`

Where two instructors share a course, the hours belong to the *relationship*, not to either side. One instructor assigned to a 30-hour batch receives all 30; two may split 18/12 or any other distribution. The system warns when assigned hours do not sum to the batch total but does not block it, because genuine co-teaching means both instructors are present for all hours.

**Both foreign keys restrict on delete (corrected in P1-T11).** `user_id` always did, because phase 2 pays wages from these rows and an instructor holding allocations must not be destroyable. `batch_id` originally cascaded, on the reasoning that "an allocation to a batch that no longer exists is not a dangling fact" — which was wrong, and never squared with the line beside it: the same row was protected from the instructor side and destroyable from the batch side, so tidying up a batch destroyed the record of work somebody may still be owed for. A batch carrying either enrolments or instructor hours is now undeletable, and the refusal comes from the database where it cannot be raced.

**Deleting a batch goes through `DeleteBatchAction`**, which authorizes the actor, pre-checks both relations for a readable message, and converts **only** MySQL 1451 into a typed refusal. The pre-check is not race-safe and is not meant to be — the foreign keys are the guarantee, and converting any other driver error would report a deadlock or a lost connection as "this batch is still in use".

**`enrollments`** — associative entity
`id, student_id (FK), batch_id (FK), enrolled_at, status, completed_at, unique(student_id, batch_id)`

Enrolling beyond a batch's capacity produces a warning, not a hard block.

**What phase 1 actually ships (P1-T11):** enrolment, withdrawal and deletion. There is no completion path, because completion marking belongs to phase 3 (section 3) — `EnrollmentStatus::Completed` is therefore unreachable from the application in phase 1, and `completed_at` is a column nothing writes yet. Withdrawal is `active → withdrawn`, idempotent, and terminal; there is no generic status editing anywhere in the UI and deliberately no `withdrawn_at` column, since withdrawal is unambiguous from the status alone and a second timestamp would have to be kept consistent with it forever.

Withdrawal and deletion are **not** gated on the batch's status — see the status rules below. Deleting an enrolment is a separate grant from withdrawing one: withdrawal keeps the record that the student was once on the batch, which is what phase 2 bills from, and deletion destroys it.

Certificate issuance does not live as a boolean or timestamp on an enrollment. Phase 3 needs to preserve revocation and replacement history, so each issued physical certificate gets its own immutable issuance record.

**`activity_log`** — Spatie's table, polymorphic

### Status enums

Defined here so they are not invented inconsistently during implementation:

| Column | Values |
|---|---|
| `students.status` | `prospective`, `active`, `graduated`, `inactive` |
| `batches.status` | `planned`, `active`, `completed`, `cancelled` |
| `enrollments.status` | `active`, `completed`, `withdrawn` |
| `student_certificates.status` (P3) | `valid`, `revoked`, `replaced` |
| `charges.status` (P2) | `unpaid`, `partial`, `paid`, `waived` |
| `payments.method` (P2) | `cash`, `bank_transfer`, `card`, `other` |
| `staff_compensation.type` (P2) | `salary`, `hourly`, `per_student` |

A batch's status gates two specific operations, and only those two: `completed` and `cancelled` batches reject **new enrollments** and **instructor changes**.

It is deliberately not a general edit freeze. Blocking all updates on a closed batch would make its own `status` column uneditable, so a mis-clicked "completed" could never be undone through the application and a typo in a finished batch's dates would need raw SQL. `BatchPolicy::update()` therefore checks the permission alone; the status gate lives on `assignInstructor()` and, since P1-T11, on `EnrollStudentAction`.

The enumeration is exhaustive, and that matters in the other direction too: **withdrawing or deleting an enrolment on a closed batch is allowed**, because neither is a new enrolment nor an instructor change. A student recorded in error on a finished batch must stay removable, or the mistake is permanent.

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

### Phase 3 tables (designed now, built in phase 3)

**`student_certificates`** — immutable issuance record for an externally printed certificate
`id, enrollment_id (FK restrictOnDelete), reference_number (unique), student_name, course_name, completed_on, issued_at, issued_by (FK users restrictOnDelete), status, replaces_certificate_id (nullable unique self-FK restrictOnDelete), revoked_at (nullable), revoked_by (nullable FK users restrictOnDelete), revocation_reason (nullable), timestamps`

The row records the certificate the center actually issued; it is not an uploaded instructor credential and it contains no PDF, template, image, disk, or path. `student_name`, `course_name`, and `completed_on` are an issuance snapshot matching the physical certificate. They deliberately do not change if a student record is corrected or a course is renamed later.

Issuance requires a completed enrollment. The reference is generated server-side, normalized to uppercase, protected by a unique database index, and includes at least eight non-sequential random characters — for example `TC-2026-7K4M9Q2R`. It is never the database ID and never a predictable counter, because the public verifier must not make neighboring certificates enumerable.

Issued rows are never deleted. Their enrollment, reference, printed snapshot, issue time, and issuing actor are immutable; only lifecycle fields (`status`, revocation metadata, and the replacement link created by a new row) may transition. Reprinting the same unchanged physical certificate may keep the same reference. Correcting or reissuing it creates a new row with a new reference, marks the old row `replaced`, and sets the **new row's** `replaces_certificate_id` to the immediately previous certificate. Revocation marks a row `revoked` with actor, time, and reason. At most one certificate for an enrollment may be `valid`. Every issuance, revocation, and replacement is activity-logged.

`IssueStudentCertificateAction`, `ReplaceStudentCertificateAction`, and `RevokeStudentCertificateAction` are actor-first and self-authorizing. Each transition runs in one transaction that locks the enrollment and its current certificate rows before checking status and writing. That shared lock is the anti-race boundary: two concurrent issue or replacement requests cannot both decide that no valid certificate exists. The Action tests must exercise the invariant from both directions, not merely hide unavailable buttons.

**"At most one valid certificate per enrollment" is also a database constraint, not only a lock.** `docs/ENGINEERING.md` requires that validation which matters is mirrored in the database, because a lock protects the application path and nothing else — a seeder, a console command, or a repair script writing a second `valid` row would corrupt the register silently, and the verifier would then have two answers for one enrollment.

MySQL has no partial unique index, but the rule is expressible as a stored generated column carrying the `enrollment_id` only while the row is `valid`, and NULL otherwise, with a unique index over it. Unique indexes do not collide on NULL, so any number of `replaced` and `revoked` rows coexist while a second `valid` row is refused:

```sql
valid_enrollment_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'valid' THEN enrollment_id ELSE NULL END) STORED,
UNIQUE KEY uniq_valid_certificate_per_enrollment (valid_enrollment_id)
```

Verified against this project's MySQL 8.4 during the phase 1 review: one `valid` plus several non-`valid` rows for the same enrollment insert cleanly, and a second `valid` row raises a unique-constraint violation. The lock stays — it is what turns a race into a clean refusal rather than a database error — but the constraint is what makes the invariant true.

The public verifier is an exact-reference lookup such as `/verify/certificates/{reference}`; there is no browsable certificate register, partial search, or autocomplete. Public exact-reference verification does not grant access to the internal register and is separate from the permission matrix above. It is rate-limited and displays only the certificate status, printed student name, course name, completion date, issue date, and center confirmation. It never exposes date of birth, national ID, contact details, portal account data, or financial information.

Because the reference appears in the URL and unlocks the printed name and course, verification responses send `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, and `X-Robots-Tag: noindex, nofollow, noarchive`. The page loads no third-party scripts, fonts, analytics, images, or styles that could receive the reference through a request or referrer; required assets are self-hosted. A QR code may encode this same verification URL for the external printer to place on the physical certificate, but generating the certificate layout remains outside the application.

### Relationships

```
users ──1:1── staff_profiles ──1:N── staff_certificates
users ──M:N── batches                (via batch_instructor, with assigned_hours)
users ──1:N── staff_compensation     (effective-dated)
courses ──1:N── batches ──1:N── enrollments ──N:1── students
enrollments ──1:N── student_certificates
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

- **Backups from day one.** `spatie/laravel-backup`, dumping the database **and the private uploads disk** daily to storage in a **separate failure domain** — a rotated removable drive or off-site object storage, selected by `BACKUP_DISK` (P1-T17). The invariant is the failure domain, not the technology: an archive on the application's own disk dies with that disk, so the application refuses a backup path inside itself and refuses at backup time to write to its own filesystem, which is what an unmounted drive looks like. A drive that never leaves the building survives a dead server and not a fire. Both are required: restoring rows whose files are missing leaves staff certificates permanently unrecoverable, and the database records only their paths. This is set up in phase 1, before there is anything valuable to lose, because that is the only point at which anyone reliably remembers to do it. A training center's payment history is not reconstructible.
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
- Student certificate template design, PDF generation, physical printing, or printer integration
