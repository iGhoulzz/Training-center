# Phase 3 — Student Portal and Certificates: Design

**Status:** approved, not yet planned.
**Date:** 2026-08-27.
**Supersedes nothing.** Amends four lines in earlier documents; see section 13.

Authoritative for phase 3, in the same way
`2026-08-09-phase-2-financials-design.md` is authoritative for phase 2. Where
this document and the system design disagree on a phase 3 detail, this one wins
and section 13 records the correction. Where they disagree on anything else, the
system design wins.

---

## 1. Purpose and scope

Phase 3 delivers four things and a tail of carried phase-2 work:

1. A **student portal** at `/portal` — read-only, a second Filament panel on its
   own auth guard.
2. **Completion marking** on enrolments, which is what makes a certificate
   possible.
3. **`student_certificates`** — an immutable issuance record for a certificate
   printed outside this system.
4. A **public, unauthenticated verifier** for a certificate reference.

Out of scope, from system design §12 and unchanged: certificate template design,
PDF generation, printing, printer integration, student self-registration,
collecting a later instalment through the interface.

**This phase is smaller than phase 2 and not simpler in kind.** It contains the
first non-staff actor that reads Finance, and the first thing in the system that
serves the open internet. Both deserve the scrutiny payments got.

---

## 2. The student panel

### 2.1 A Filament panel, not Blade

System design §3's table calls `/portal` "Blade + Tailwind" while the prose
immediately below it says "separate panels with separate auth guards". Both
cannot be literal. **Resolved in favour of a Filament panel** —
`StudentPanelProvider`, `id('student')`, `path('portal')`.

The reason is that §4 already refused a second auth package to avoid two
competing sources of truth, and hand-rolling login, throttling, session
invalidation and forced password change for the portal would reintroduce exactly
that — a second auth implementation, on the surface where a mistake is most
expensive. Filament's login page self-throttles at `rateLimit(5)`, the
`must_change_password` containment gate already exists and is component-aware,
and the translation and logical-CSS wiring are already in place.

Filament 5.7.1 supports `->authGuard('student')` directly.

### 2.2 The guard, and pinning Spatie to `web`

`config/auth.php` gains a `student` guard: `session` driver, the **existing
`users` provider**. A portal user is a `users` row; `students.user_id` has
pointed at one since phase 1.

**`User` gains `protected $guard_name = 'web';`, and this is load-bearing.**

Filament's `Authenticate` middleware calls
`$this->auth->shouldUse(Filament::getAuthGuard())`
(`vendor/filament/filament/src/Http/Middleware/Authenticate.php:25`).
`AuthManager::shouldUse()` delegates to `setDefaultDriver()`, which **writes
`config('auth.defaults.guard')`** — it does not merely set a property on the
manager. Spatie's `Guard::getDefaultName()` reads that config value and returns
it whenever it is among the guards whose provider matches the model. With `web`
and `student` both on the `users` provider, `student` qualifies, so every
permission lookup inside a portal request would resolve against
`guard_name = 'student'` — for which no permission rows exist. Every check would
return false, silently, and the portal would look like a permissions bug.

Pinning `$guard_name` short-circuits `Guard::getNames()` before it consults
config at all. **Authentication happens on `student`; authorization stays on
`web`.** One permission set, one Shield generation, no duplication per guard.

An earlier draft of this design claimed the opposite — that the default would
conveniently remain `web`. It was wrong, and it was wrong in the direction that
fails closed and looks like something else, which is why the correction is
recorded rather than quietly applied.

**This is pinned by a test that drives a real student-panel request** and asserts
a granted permission answers true. Reading `config/auth.php` back proves nothing
about what Filament does to it mid-request.

### 2.3 Sessions and logout, stated accurately

The two guards **share Laravel's session cookie** and hold separate
authentication state within it (`login_student_…` beside `login_web_…`). They are
not separate browser sessions.

`Filament\Auth\Http\Controllers\LogoutController` calls
`Filament::auth()->logout()` and then `session()->invalidate()`. The invalidate
flushes the whole session, so **logging out of `/portal` also ends an `/admin`
login in the same browser, and vice versa.**

**Accepted as-is.** It errs toward logging out too much rather than too little,
and a per-guard logout would be custom auth code on the surface §4 refused to
write custom auth code for. A test pins the behaviour so that a later reader does
not "fix" it.

### 2.4 Panel access fails closed

`User::canAccessPanel()` today reads
`return $this->is_active && $this->can('access_admin_panel');` and **ignores
`$panel` entirely** — which would admit every staff account to `/portal` the
moment the panel exists.

It becomes a `match` on `$panel->getId()` with `default => false`:

| Panel | Requires |
|---|---|
| `admin` | `is_active` and `access_admin_panel` |
| `student` | `is_active`, `access_student_portal`, **and a linked, non-trashed student record** |
| anything else | refused |

Five tests, each a real request: a student refused at `/admin`; a staff account
refused at `/portal`; an inactive account refused at both; a `student`-role
account with no linked student refused; an unknown panel id refused.

### 2.5 Middleware

The student panel carries the admin panel's middleware stack and, critically, the
same `persistentMiddleware` list — `SetLocale`, `AuthenticateSession`,
`ForcePasswordChange`. P1-T15 finding 5 established why: Livewire updates arrive
on Livewire's own route, so non-persistent middleware does not run for them, and
a guard that does not run on Livewire's route is skippable. The portal inherits
that reasoning rather than rediscovering it.

`ForcePasswordChange` is ported with its G1-U3 component-awareness and tested with
a student holding a temporary password.

---

## 3. Portal credentials

### 3.1 Identity is email

§4 says identity is email and password. `students.email` is nullable and
**not unique** — the centre enrols walk-ins who leave only a phone number.

**A portal account requires an email.** Staff cannot issue credentials to a
student without one, and the UI says so rather than failing at submit. The portal
is opt-in for a minority of students; excluding the phone-number-only walk-in
costs little, and the alternative — a second identity rule, a custom login field,
and a synthesized `users.email` — buys a case the centre has not asked for.

### 3.2 Authorization

§4 says staff issue portal credentials. The permission matrix gives staff `none`
on staff accounts, and §4 also says creating an account requires `create_user`
**and** `reset_user_password`. Granting staff those two would hand the front desk
staff-account creation.

**Resolved with two narrow custom abilities:** `issue_portal_credential` and
`reset_portal_credential`, held by super admin, admin and staff. They authorize
one operation on one kind of subject, and nothing else.

### 3.3 `IssuePortalCredentialAction`

Actor-first, self-authorising, one transaction, locking the student row so a
concurrent double-issue is a clean refusal rather than a unique-index
`QueryException` on `students.user_id`.

It refuses when:

- The student already has a `user_id`.
- The student has no email.
- **Any `users` row holds that email, `withTrashed()` included.** No reuse, no
  automatic relink, no inspection of what roles that account holds. The email is
  the whole test.

  *Consequence, named rather than discovered later:* a student whose account was
  soft-deleted cannot be given a new one until an administrator resolves the
  existing row. The refusal message says exactly that — **an administrator must
  resolve the existing account holding this email.** It does not say "restore
  it": restoring is right only when the archived row is that student's own former
  account, and an unrelated archived account using the address is a different
  problem the message must not mis-describe.

**Two students may share an email**, since `students.email` is indexed and not
unique. Two concurrent issuances therefore both pass the `users` lookup — the
student-row lock cannot see across student rows — and one dies on
`users_email_unique`. The Action translates **that specific duplicate-key
failure, identified by index name**, into the same
"email already belongs to an account" refusal. Proven with a two-connection test,
not asserted.

On success, inside the one transaction: create the `users` row, assign **exactly
the literal `student` role**, link `students.user_id`, and issue a one-time
temporary password with `must_change_password` set.

### 3.4 `ResetPortalCredentialAction`

Specified as carefully as issuance, because it is the same capability pointed at
an existing account.

- Takes `(User $actor, Student $student)` — **never an arbitrary `User`.** A
  reset Action that accepts any user is a staff-account reset waiting for a
  caller.
- Locks the student, derives the linked account from the locked row.
- Refuses a missing or trashed account.
- **Refuses any linked account that can reach `/admin`.**
- Issues through the shared temporary-credential collaborator.
- An already-logged-in student is rejected on their next request by the
  persistent `AuthenticateSession` middleware, which detects the password-hash
  change. That is the existing mechanism from §2.5, not a new one.

### 3.5 The temporary-password mechanism is shared, not reimplemented

`ResetUserPasswordAction` already generates, hashes and surfaces a one-time
temporary password. It is **extracted into a shared collaborator** and used by
both portal Actions. A second implementation would be a second place for the
generation rule to drift.

### 3.6 The role-writing boundary

`ActionBoundaryArchTest:149-156` allowlists `assignRole|removeRole|syncRoles` to
exactly `SyncUserRolesAction` and `SystemRoleWriter`, matched by file basename.

Neither is the right tool here. `SystemRoleWriter` is the actorless
seeder/setup path and must not be reachable from a request.
`SyncUserRolesAction` requires `assign_role`, which staff must not need merely to
hand a student a login.

**`IssuePortalCredentialAction` is added to that allowlist as a third narrow
path** — and the design states plainly what that buys:

> The arch test is basename-matched and cannot see arguments. It proves only
> *"this file may call `assignRole`"*. It does **not** prove *"it assigns the
> `student` role"*.

The second claim is a **behavioural** test: the Action assigns `student` and
nothing else, and no caller-supplied value reaches the role argument. Two claims,
two mechanisms, neither pretending to be the other.

---

## 4. The portal's pages

Four pages, all read-only. The only write a student can perform anywhere in
phase 3 is changing their own password.

| Page | Shows | Requires |
|---|---|---|
| Overview | Name, student code, status | `view_own_student_record` |
| My enrolments | Course, batch, dates, enrolment status | `view_own_enrollment` |
| — its certificate fields | Reference and status, when one is `valid` | **additionally** `view_own_certificate` |
| My balance | Per-enrolment outstanding and a total | `view_own_balance` |
| Password | Change own password | portal access and the existing own-password rules — **no `view_own_*` ability exists for it** |

The certificate fields are a **separate grant on a shared page**, not a page of
their own. A student holding `view_own_enrollment` but not `view_own_certificate`
sees the enrolment list with those columns absent — so the two abilities are
tested independently, and the certificate columns are asserted missing rather
than assumed hidden.

### 4.1 Scoping is two mechanisms, not one

**Every page resolves the viewing student through a single
`AuthenticatedStudent` resolver.** No page derives a `student_id` for itself. An
architecture test proves no portal page queries without it.

**That is necessary and not sufficient.** A source-scanning test cannot prove row
isolation — it proves a call was made, not that the resulting query was
constrained. So, in addition:

1. **Every page authorizes the ability named against it in the table above.** The
   resolver answers *who*; the permission answers *whether*. The password page is
   the one that carries no `view_own_*` ability — it is gated by portal access and
   the existing own-password rules, and stating that here stops a later reader
   inventing a fifth ability to make the pattern look uniform.
2. **Two-student behavioural tests.** Log in as student A; create distinctive
   enrolments, balances and certificates for student B; assert none of B's data
   appears anywhere in A's rendered pages. Run for each page.

This follows the project's standing lesson that a source scan cannot prove an
absence. The arch test is partial defence in depth; the two-student tests are
what the correctness claim actually rests on.

### 4.2 The balance page must not loop

`ChargeQueryService::outstandingForEnrollment()` performs a `charges` lookup plus
`ChargeBalance::outstandingFor()` — roughly two queries per enrolment. Calling it
once per row is an N+1 on the page most likely to be opened by every student at
once.

**Finance publishes a bulk query** returning per-enrolment outstanding figures
and the total in a fixed number of statements, using the canonical
`ChargeBalance` calculation. Nothing derived is stored — that non-negotiable is
untouched; this is about how many statements produce the same derived answer.

`outstandingForEnrollment()` stays for the single-row callers, including the
certificate issue form's warning.

---

## 5. Completion marking

### 5.1 Authorization

Two abilities, mirroring the `update_enrollment` /
`update_assigned_batch_enrollment` split P1-T11 built and tested:

- `complete_enrollment` — unrestricted; super admin and admin.
- `complete_assigned_batch_enrollment` — staff, restricted to batches they are
  assigned to teach via the `batch_instructor` pivot.

**Staff must not hold the unrestricted permission.** P1-T11's lesson: holding it
satisfies the unrestricted branch first, the scoping never runs, and every scoped
test still passes.

`CompletionRule` is modelled on `EnrollmentUpdateRule` but is a **separate
class**, because that one asks about `update_enrollment`. Same three-step shape:
unrestricted check, scoped check, then a **locking** pivot read taken *after*
`EnrollmentMutex` is acquired.

The locking read is not optional. Inside a transaction, an ordinary read is
served from the REPEATABLE READ snapshot, which may already have been opened by
any earlier ordinary query in that transaction. A snapshot read of the pivot can
therefore be stale even while the row is locked, which is how two tills once
double-collected. Only a locking read is current.

Reversal uses the identical pair, plus a mandatory reason — so the assigned
instructor who mis-marked a row can correct their own mistake without escalating.

### 5.2 Transitions, stated exactly

| Action | From | To | Also |
|---|---|---|---|
| `CompleteEnrollmentAction` | `active` | `completed` | sets server-generated `completed_at` |
| `ReverseEnrollmentCompletionAction` | `completed` | `active` | clears `completed_at`; mandatory reason |

`CompleteEnrollmentAction` refuses a `withdrawn` enrolment and refuses a second
completion. `ReverseEnrollmentCompletionAction` refuses an `active` or
`withdrawn` enrolment, and **locks the certificate rows after the enrolment**,
refusing while any certificate for it is `valid`. The certificate must be revoked
first.

Both Actions take locks through `EnrollmentMutex`, preserving the global
**batch → enrolment** order that `EnrollStudentAction` established. A consistent
global lock order is what stops two Actions deadlocking on the same pair.

Both are activity-logged.

### 5.3 Bulk marking

A Filament bulk action on the batch's enrolment list: tick the students who
finished, confirm once. **Each row runs the Action individually** — its own lock,
its own authorization check, its own activity-log entry. Nothing is decided in
aggregate, and there is no "complete the whole batch" button, because the student
who dropped out in week three and was never withdrawn would be certified by
default.

### 5.4 An unpaid balance never blocks anything

Completion and issuance both proceed regardless of what is owed. The issue form
**displays** the outstanding figure prominently; the Action does not consult it.

This is a decision about the system as built, not a preference. Enrolling always
raises a bill and collecting at the desk is optional — the enrol-and-collect flow
has a skip. Partial payment is explicit ("one bill, many receipts"). And system
design §12 records that **no screen exists to collect a later instalment.** So
blocking issuance on an outstanding balance would permanently strand every
partial payer in a state the software offers no way out of, short of a
super-admin write-off. A 0.001 rounding remnant would do the same.

The centre's leverage is where it already was: physically handing over the
printed certificate.

---

## 6. Student certificates

### 6.1 Schema

`student_certificates`, exactly as system design §6 specifies:

```
id, enrollment_id (FK restrictOnDelete), reference_number (unique),
student_name, course_name, completed_on, issued_at,
issued_by (FK users restrictOnDelete), status,
replaces_certificate_id (nullable unique self-FK restrictOnDelete),
revoked_at (nullable), revoked_by (nullable FK users restrictOnDelete),
revocation_reason (nullable), timestamps
```

No PDF, template, image, disk or path. `student_name`, `course_name` and
`completed_on` are an **issuance snapshot** matching the physical certificate and
deliberately do not follow a later correction to the student or course record.

### 6.2 Three database constraints

**One valid certificate per enrolment**, as a stored generated column with a
unique index — verified against this project's MySQL 8.4 during the phase 1
review:

```sql
valid_enrollment_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'valid' THEN enrollment_id ELSE NULL END) STORED,
UNIQUE KEY uniq_valid_certificate_per_enrollment (valid_enrollment_id)
```

Unique indexes do not collide on NULL, so any number of `replaced` and `revoked`
rows coexist while a second `valid` row is refused.

**Revocation fields arrive together**, as a named `CHECK`:

```sql
CONSTRAINT chk_student_certificates_revocation CHECK (
    (status = 'revoked'
        AND revoked_at IS NOT NULL
        AND revoked_by IS NOT NULL
        AND CHAR_LENGTH(TRIM(revocation_reason)) > 0)
    OR (status <> 'revoked'
        AND revoked_at IS NULL
        AND revoked_by IS NULL
        AND revocation_reason IS NULL)
)
```

`CHAR_LENGTH(TRIM(...)) > 0` rather than `NOT NULL`: `NOT NULL` admits `''`,
which would make "mandatory reason" true in the form and false in the database.

**`status` is constrained to its value set:**

```sql
CONSTRAINT chk_student_certificates_status CHECK (
    status IN ('valid', 'revoked', 'replaced')
)
```

This one is not decoration. The generated column reads
`CASE WHEN status = 'valid' THEN …`, so a row inserted with a mistyped status —
`'Valid'`, `'vaild'` — yields NULL in `valid_enrollment_id` and **slips past the
unique index entirely**. The register would then hold a certificate that is
neither counted as valid nor visibly wrong, while Eloquent's enum cast throws on
hydration and the public verifier fails on a reference that resolves to a real
row. The two other constraints both assume `status` means something; this is what
makes that assumption true.

All three constraints exist for the reason `docs/ENGINEERING.md` gives — a lock
protects the application path and nothing else. A seeder, a console command or a
repair script writing a second `valid` row would corrupt the register silently,
and the verifier would then have two answers for one enrolment.

### 6.3 A `status` column, and why it is not a Finance violation

Phase 2's rule is that no status string column exists on any table in the Finance
domain; lifecycle is nullable fact columns and the label is derived.

`student_certificates` lives in **Enrollment**, and its `status` records a
*decision a human made* — somebody revoked this — not a value derivable from other
rows. It is in scope for the system design and stays.

See §11.6: the arch test that phase 2's design claims enforces the Finance rule
**does not exist**, so there is nothing here to widen. When it is written it must
be scoped to Finance-owned tables, which puts `student_certificates` naturally
outside it.

### 6.4 The state machine

| Action | Precondition | Effect |
|---|---|---|
| `IssueStudentCertificateAction` | enrolment `completed`; **no** `valid` certificate | inserts a `valid` row |
| `ReplaceStudentCertificateAction` | a `valid` certificate exists | marks it `replaced`, then inserts a new `valid` row whose `replaces_certificate_id` points at it |
| `RevokeStudentCertificateAction` | a `valid` certificate exists | marks it `revoked` with actor, time and non-blank reason |

All three are actor-first and self-authorising. Each locks the enrolment and its
current certificate rows **before** checking status and writing. That shared lock
is the anti-race boundary: two concurrent issue or replacement requests cannot
both conclude that no valid certificate exists.

**Replacement is one transaction.** The old row is marked `replaced` before the
new row is inserted, so the generated column frees the unique slot; a failed
insert rolls the whole thing back rather than leaving an enrolment with no valid
certificate. Reprinting the same unchanged physical certificate keeps the same
reference and creates no row at all.

Issued rows are **never deleted**. Enrolment, reference, snapshot, issue time and
issuing actor are immutable; only lifecycle fields transition. Every issuance,
replacement and revocation is activity-logged.

**The invariant is tested from both directions**, and neither test stands in for
the other: the lock turns a concurrent race into a clean refusal, and a direct
second `valid` insert bypassing the Action raises the database constraint.

### 6.5 Reference, clock and retry

`TC-{year}-{8 random non-sequential characters}`, normalized to uppercase,
generated server-side, unique index. Never the database id, never a counter —
the public verifier must not make neighbouring certificates enumerable.

Deliberately **unlike** the `ENR-` / `CHG-` / `RCT-` series, which are sequential
and phone-dictatable on purpose. Phase 2's placeholder-UUID trick is not needed
here, because a random reference does not depend on the row id.

`issued_at` and the reference's year derive from **one `Africa/Tripoli` reading,
taken once**. The application runs in UTC (`config/app.php`), so a January
issuance could otherwise carry the previous year's number while its `issued_at`
says January.

**Retry is discriminated by index name.** A collision on the reference's unique
index retries the whole transaction with a fresh random suffix, bounded. A
collision on `uniq_valid_certificate_per_enrollment` is the
"a valid certificate already exists" refusal and **must never retry** — retrying
it would turn a correct refusal into a loop. No raw driver error reaches a user.

---

## 7. The public verifier

### 7.1 Three routes

| Route | Purpose |
|---|---|
| `GET /verify/certificates` | render the form |
| `POST /verify/certificates` | validate and normalize a complete reference, then redirect |
| `GET /verify/certificates/{reference}` | exact lookup |

A form taking a complete reference is still exact-match lookup, not the browsable
register, partial search or autocomplete §6 forbids. Someone holding a printed
certificate needs somewhere to type its reference; a QR code encoding the lookup
URL serves the same purpose without one.

The `{reference}` parameter is **constrained to the canonical
`TC-{year}-{suffix}` shape** at the route.

**A malformed reference, an unknown reference and a missing one all render the
same not-found result.** Nothing distinguishes "you typed it wrong" from "no such
certificate", and nothing reveals whether a shape-valid reference exists. Returns
404 with a rendered page — correct semantics, and a page a human can read.

### 7.2 Limiting and headers

The limiter and the security headers apply to **both the submission and the
lookup**, in one middleware group — the same reasoning `routes/web.php` uses for
the private-file routes, so they cannot be applied to one route and forgotten on
the other.

- `Cache-Control: private, no-store`
- `Referrer-Policy: no-referrer`
- `X-Robots-Tag: noindex, nofollow, noarchive`

**A named limiter returns in phase 3 after all** — `certificate-verification`,
per-IP, two windows on one limiter: 10 per minute and 100 per hour. It is
referenced by real routes, which is precisely the property whose absence got
P1-T03's `login` limiter deleted.

§4's line promising the *login* limiter's return is corrected in §13: with a
Filament portal there are still no Laravel auth routes, the panel self-throttles
at `rateLimit(5)`, and a registered-but-unreferenced limiter reads as a control
while protecting nothing. We are not making that mistake a second time.

### 7.3 What it shows, and how that is guaranteed

Status, printed student name, course name, completion date, issue date, centre
confirmation. Never date of birth, national ID, contact details, portal account
data, or anything financial.

**The response is built from a dedicated six-field projection, never from the
model.** A column added to `student_certificates` in a later phase therefore
cannot leak through the public surface by default — the projection would have to
be edited deliberately.

For a `revoked` or `replaced` certificate the page **states the status** —
"revoked on 4 March 2026", "superseded by a later certificate" — and withholds
the detail. No revocation reason, no replacement reference. Telling the holder of
a bad certificate that it is bad is the entire purpose of verification; the
reason is internal and frequently about a person.

### 7.4 Assets

Standalone Blade with self-hosted assets. No third-party scripts, fonts,
analytics, images or styles that could receive the reference through a request or
a referrer. It does not load the Filament asset pipeline. Phase 4's public site
restyles this page; phase 3 ships it plain.

---

## 8. Authorization

### 8.1 The student role gets exactly five abilities

**One portal-access ability and four `view_own_*` abilities.** The counts are
written out because "five abilities" and "five `view_own_*`" are easy to conflate
and the seeder is where that mistake would land.

| Ability | Grants |
|---|---|
| `access_student_portal` | reach `/portal` at all |
| `view_own_student_record` | the overview page |
| `view_own_enrollment` | the enrolments page |
| `view_own_balance` | the balance page |
| `view_own_certificate` | the certificate fields on the enrolments page |

Bare-verb custom abilities, per the project's convention for non-Shield
abilities. Seeded onto the `student` role by `RolePermissionSeeder`.

**The student role receives none of Shield's generated
`{action}_student_certificate` permissions** — not `view_any_student_certificate`,
not any other. Those are staff certificate-management permissions over the whole
register. A student's access to their own certificate is
`view_own_certificate` and nothing else. This is stated explicitly because
Shield's generator produces the generic set automatically and attaching it to the
student role would be a one-line mistake with a register-wide blast radius.

### 8.2 New abilities in full

| Ability | Super admin | Admin | Staff | Student |
|---|---|---|---|---|
| `access_student_portal` | — | — | — | yes |
| `view_own_*` (four) | — | — | — | yes |
| `complete_enrollment` | yes | yes | — | — |
| `complete_assigned_batch_enrollment` | — | — | yes | — |
| `issue_portal_credential` | yes | yes | yes | — |
| `reset_portal_credential` | yes | yes | yes | — |
| `view_any_student_certificate`, `view_student_certificate` | yes | yes | yes | — |
| `issue_student_certificate` | yes | yes | — | — |
| `replace_student_certificate` | yes | yes | — | — |
| `revoke_student_certificate` | yes | yes | — | — |

Matching system design §5's certificate row: super admin full; admin view, issue,
replace, revoke; staff view.

**The seeded roles hide permission gaps.** Each of these is additionally tested
against a bespoke role built in the test holding exactly one ability, because a
real gap often surfaces only that way.

---

## 9. Data model changes

| Table | Change |
|---|---|
| `enrollments` | add `completed_at` (nullable timestamp) |
| `student_certificates` | new; §6.1, plus the generated column and the `CHECK` |
| `pending_file_deletions` | add nullable `delete_after`; add a path-kind discriminator (file / directory) |
| `config/auth.php` | add the `student` guard on the `users` provider |

One schema statement per migration. MySQL does not roll back DDL, so a migration
carrying two schema statements can fail on the second having committed the first,
and the retry then dies on the first.

---

## 10. Impact on existing code

Found by reading the code, not assumed:

- **`DeleteEnrollmentAction` must become certificate-aware.**
  `student_certificates.enrollment_id` is `restrictOnDelete`, so the database
  will refuse — but a foreign-key error is not a business refusal. The Action
  locks through `EnrollmentMutex`, checks explicitly for **any** certificate
  including `revoked` and `replaced` ones, and raises a translated business
  exception. The restrictive foreign key stays, as protection against bypasses
  and races.

  §12 keeps an enrolment created in error deletable; a certified enrolment is not
  a mistake, and issued rows are never deleted.

- **`WithdrawEnrollmentAction` already refuses `completed` rows** — a branch that
  has been unreachable since phase 1, because nothing could produce a completed
  enrolment. Phase 3 makes it reachable for the first time. Its existing test
  must be re-verified against the real path rather than trusted.

- **`User::canAccessPanel()`** — §2.4.

- **`User`** gains `$guard_name` — §2.2.

- **`RolePermissionSeeder`** gains everything in §8.

- **`ResetUserPasswordAction`** — its temporary-password mechanism is extracted
  to a shared collaborator; §3.5.

- **`ActionBoundaryArchTest`** — one allowlist entry; §3.6.

---

## 11. Carried and adjacent work

**Six tasks, each its own PR.** Four are the phase-2 items carried forward
(§§11.1–11.4); two arose during this design — the bulk balance query the portal
needs (§11.5) and a phase-2 control that was documented but never built (§11.6).
Branch cleanup is **not** a task; see §11.7.

### 11.1 Export retention

Generated XLSX and PDF exports accumulate on the private disk indefinitely and
land in every nightly backup. **Retention is 7 days.** An export is regenerable
at any time by re-running the report, so retention only has to cover the gap
between generating and fetching.

This is an enhancement to the **generic file lifecycle**, not a second scheduled
command — `routes/console.php` stays a single seam. `pending_file_deletions`
gains a nullable `delete_after` and a path-kind discriminator, because Filament's
XLSX pipeline produces a *directory* while `path` today names a single file.
Exports write a receipt at generation time with `delete_after` at +7 days, and
the existing hourly sweep honours it.

**Recursive directory deletion is a materially more dangerous capability than
the unlink the purge job performs today, and is fenced accordingly:**

- Permitted **only** for the configured export disk and the canonical export
  path prefix. Every other disk and prefix is refused.
- Traversal segments, the disk root, and unrelated directories are rejected.
- `delete_after` is an **eligibility filter**, not a replacement for the sweep's
  ordering. `SweepPendingFileDeletionsCommand` orders by `last_swept_at` with
  never-attempted rows first — its docblock records that ordering by `created_at`
  was the original bug, because a page that never drained blocked everything
  behind it forever. That ordering is preserved and **proven across two
  consecutive sweep runs**, not one.
- Ordinary immediate file-deletion receipts behave **exactly** as before. The
  existing ownership check that refuses to unlink bytes a committed row owns is
  untouched.

### 11.2 `AdjustChargeConcurrencyTest` timing

It spawns a real worker and waits a hard-coded 10s for readiness; the single test
takes 11.6s uncontended and fails whenever a second suite runs concurrently.
Either give it real headroom or exclude it explicitly from concurrent runs.

### 11.3 `failOnNotice` in the gate

Its own PR with its own evidence. **A gate change never rides on another
change's review.**

### 11.4 Finance query scoping for the portal

The carried item was recorded as `EnrollmentQueryService::joinStudentTo()`.
**Reshaped**: a method that merely joins the student table leaves every caller
responsible for remembering the `WHERE` clause — which is the exact part that must
never be forgotten on the portal.

It becomes a **security-shaped operation**:
`scopeToStudent($query, $enrollmentColumn, $studentId)`, which joins *and*
constrains in one call. A caller cannot take the join without the restriction.

It gives portal-shaped queries a path neither existing method offers: today the
choice is a per-row `studentIdFor()`, which is an N+1, or `joinCatalogueTo()`,
which throws on empty dimensions and answers a different question. Both stay for
their existing callers; nothing is removed.

The signature deliberately mirrors `joinCatalogueTo(Builder $query, string
$enrollmentIdColumn, …)` so the two read as siblings.

### 11.5 The bulk balance query

§4.2. Belongs to Finance, consumed by the portal.

### 11.6 The Finance status arch test — a documented control that was never built

Phase-2 design §4 states: *"The same rule holds throughout Finance: no status
string column exists on any table in this domain. An architecture test enforces
it."*

**It does not.** The repository contains two architecture tests —
`tests/Feature/Finance/MoneyCastArchTest.php` and
`tests/Feature/Staff/ActionBoundaryArchTest.php` — and neither enforces this.

This is the project's most frequent defect class: a document asserting a property
the code lacks. It is recorded here as a **gap**, not a wording fix. Closing it
means writing the test, scoped to Finance-owned tables only, and correcting the
line. It gets its own task and its own PR, because it is a phase-2 control and
should not be closed inside an unrelated change.

### 11.7 Branch cleanup is an operational step

Merged phase-1 and phase-2 branches can be deleted now that every `task/P1-*` and
`task/P2-*` tag is on origin. This is a workflow action, **not a task and not a
PR**: confirm the tag exists, then delete through
`gh api -X DELETE repos/iGhoulzz/Training-center/git/refs/heads/<branch>`, which
skips the pre-push hook entirely. `git push --delete` pays the full ~15–20 minute
gate for nothing.

---

## 12. Testing requirements

Beyond the per-feature tests already named:

- **Panel isolation**, five real requests — §2.4.
- **Guard pinning**, through a real student-panel request — §2.2.
- **Logout crossing guards**, pinned — §2.3.
- **Two-student row isolation**, per portal page — §4.1.
- **Credential issuance from the attacking direction**: the refusals in §3.3 and
  §3.4 are tested as refusals, including the two-connection duplicate-email race.
- **The certificate invariant from both directions** — §6.4.
- **Retry discrimination**: a reference collision retries; a valid-certificate
  collision refuses. Distinct tests, because a single test cannot tell them
  apart.
- **The verifier reveals nothing**: malformed, unknown and revoked references each
  produce the specified output and no more. The six-field projection is asserted
  as a projection — adding a column to the table must not change the response.
- **Bespoke single-ability roles** for every new permission — §8.2.

**A passing test proves nothing until it has been seen to fail for the right
reason.** Every guard in this phase carries a probe that must fail when the guard
is removed.

---

## 13. Documents corrected by this phase

Four lines, all of them corrected in the phase's reconciliation task rather than
left as folklore:

1. **System design §3**, the surfaces table — `/portal` is a **Filament panel**,
   not Blade + Tailwind. §2.1.
2. **System design §4**, the login limiter — it does **not** return. Filament
   self-throttles at `rateLimit(5)` and the portal introduces no Laravel auth
   routes. The named limiter phase 3 does introduce is
   `certificate-verification`. §7.2.
3. **Phase-2 design §2, line 54** — "later installments: search the student, open
   the unpaid bill, record another payment" describes a screen system design §12
   records as deliberately scrapped, after it was raised twice in review of
   P2-T09.
4. **Phase-2 design §4** — "An architecture test enforces it" is false. §11.6.

---

## 14. Decisions taken during design

Recorded so they are not re-derived:

- **The portal is a Filament panel.** §2.1. The alternative was hand-rolled
  Blade, which meant a second auth implementation on the system's most sensitive
  new surface.
- **Spatie is pinned to `web` while authentication runs on `student`.** §2.2.
  An earlier draft claimed the default guard would remain `web` on its own. It
  would not, and the failure would have been silent.
- **Cross-guard logout is accepted, not worked around.** §2.3.
- **A portal account requires an email.** §3.1.
- **Credential issuance is two narrow custom abilities**, not `create_user` +
  `reset_user_password`. §3.2.
- **An outstanding balance never blocks completion or issuance.** §5.4. This
  follows from the system as built, not from a preference.
- **Completion is reversible, but not past a live certificate.** §5.2.
- **Bulk completion requires explicit row selection.** §5.3.
- **The verifier states a revoked certificate's status and withholds the
  reason.** §7.3.
- **Export retention is 7 days.** §11.1.
- **Portal code lives in the owning domains**, with one shared
  `AuthenticatedStudent` resolver — not in a fifth `Domain/Portal`, which system
  design §3 does not list, and not flat under `app/Filament/`.

---

## 15. Open items

None. Every question raised during design was answered before this document was
written.
