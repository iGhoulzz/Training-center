# Changelog

## Phase 3.5 — Hardening and owner feedback (completed 2026-10-08)

Nothing in this phase adds a feature anyone asked for. It exists because the
system was about to be trusted with a centre's money and documents, and nobody
had yet measured what it does when twenty people use it at once. Three of the
eighteen tasks were written only after that measurement, to fix what it found.

### What the measurement found, and what it cost to fix

The load baseline ran four staff flows at twenty concurrent sessions for five
minutes each, against a four-thousand-student fixture. Three things broke.

- **One collection in fifty-five failed.** 51 of 2,821 enrol-and-collect flows
  returned an error, every one a database deadlock while recording the payment.
  On that screen, cash has usually changed hands before the request is sent, so
  a failure leaves a person holding money the system has no record of. The cause
  was two tills billing *different* students colliding on a lock neither needed.
  The rerun after the fix recorded **zero failures in 2,764 collections**.
- **Receipt downloads failed about one time in a hundred and twenty**, for an
  unrelated reason: the counter that throttles downloads was kept in the
  database, and two requests arriving together could deadlock while writing it.
  The counter now lives in Redis, where incrementing is atomic. The application's
  own cache stays where it was.
- **Receipt PDFs exhausted their worker's memory repeatedly.** Every receipt
  still arrived, because the job retries, so this cost wasted work and a noisy
  log rather than lost documents. The cause was two limits set to the same
  number: the worker was supposed to be recycled at 128 MB, but PHP's own ceiling
  was also 128 MB, so the worker always died before the recycle could happen.
  Rendering accumulates about 2 MB per receipt that is never released, so the
  recycle is what keeps a long run alive. The drain after the fix rendered
  **2,358 receipts with no exhaustion at all**.

### What else changed that you can see

- **Receipts are now downloadable from the payment screen.** They were being
  generated and stored, and nothing in the interface linked to them.
- **Student and batch codes are generated** in a fixed readable format instead of
  being typed. Course codes stay manual, with suggestions.
- **Reports cover a range of months**, and the period filters behave the same way
  across all of them.
- **The one refusal that can explain itself now does.** Trying to pay more than a
  bill is owed says how much was attempted and how much remained, instead of a
  bare refusal.

### What changed underneath

Queues moved to Redis with Horizon supervising them, and Pulse records slow
queries and runtime health. Service level objectives now exist for the four
measured flows, written as regression detectors against a measured baseline
rather than as promises — and recorded as describing the development harness,
not a production host, because no production host has been chosen. Queue delay
deliberately has no target: nothing has measured it.

Three findings from the final stress cycle are recorded as deferred measurements
rather than quietly dropped: student search and the reports both drifted past
their new latency targets, and the receipt queue's drain profile is unmeasured.

## Phase 3 — Student portal and certificates (completed 2026-09-06)

Students can sign in and see their own enrolments, bills and payments, and the
centre can record the physical certificates it issues and let anyone verify one
from a printed reference.

### What works

- **A student portal**, separate from the staff dashboard and with its own login.
  A student sees their enrolments, what each cost, what they have paid and what
  is outstanding — and nothing belonging to anybody else.
- **Portal credentials are issued by staff**, not self-registered. There is no
  public sign-up, deliberately.
- **Completion marking**: an enrolment can be marked complete, which is what makes
  a certificate issuable.
- **Certificate records.** The centre prints certificates outside the system; what
  this records is that one was issued, to whom, for which enrolment, and under
  which reference. Deleting an enrolment that carries a certificate is refused.
- **A public verifier.** Anyone holding a printed reference can check it on a page
  that needs no account. A reference the centre issued is answered honestly,
  including one that has been revoked — that says so, with the date it was
  revoked, because somebody holding a worthless certificate needs to know which.
  What the page will not do is let anyone *probe* it: a blank box, a malformed
  reference and one that was never issued all produce the identical not-found
  result, so no sequence of guesses reveals which references exist. The answer
  also carries only what is printed on the certificate — no contact details, no
  national ID, no balance.
- **Export retention**: generated exports are cleaned up on a schedule rather than
  accumulating for ever.


## Phase 2 — Finances (completed 2026-08-26)

The centre can now price a course, enrol a student and raise the bill in one
step, take the money at the desk in cash, card or a split of both, hand over a
receipt, and see what it earned and what it is still owed. Staff wages are
computed and frozen into payroll runs. Nothing about the money is stored twice:
every balance is added up from the payments themselves, every time it is asked
for.

**Currency is the Libyan dinar, carried to three decimal places** — a dinar is
1000 dirham. A float cannot hold 0.001 exactly, and the dirham is precisely the
digit that would be lost, so no amount that gets stored is ever allowed to pass
through one: the financial code works in whole dirham as integers, and a
check in the test suite fails the build if a float conversion appears anywhere
in it.

### What works

- **Prices and discounts.** A course carries a default price, a batch may
  override it, and a discount is a named percentage an authorised person applies
  when the bill is raised. The percentage that was actually used is copied onto
  the bill, so changing or retiring a discount later never rewrites what a
  student was charged.
- **Enrol and collect**, the front-desk flow: find or create the student, pick
  the batch, apply a discount if permitted, see the total before committing,
  then take payment and print the receipt. Enrolling and billing happen in one
  transaction — there is no way to end up with an enrolment that carries no bill.
- **Payments** in cash, card, or several tenders at once, allocated against the
  bill they settle. A payment can be reversed with a reason; it is never edited
  and never deleted. Collecting more than is owed is refused.
- **Receipts** as PDFs, generated from a snapshot taken at the moment of
  payment. Reprinting a receipt years later reproduces the document the student
  was given, not today's data.
- **Staff compensation and payroll.** Rates are effective-dated: a raise inserts
  a new row rather than overwriting the old one, so a past month can still be
  recomputed correctly. A payroll run freezes the rates and hours it used.
- **Seven financial reports** — revenue, profit, outstanding by age, daily
  takings, tender breakdown, wage cost, and a per-student payment history —
  each viewable in the panel and exportable to spreadsheet or PDF. Exports are
  queued, and capture their data when they are requested rather than when the
  queue gets to them.

### Decisions worth knowing

- **Balances are always calculated, documents are always frozen.** These pull in
  opposite directions on purpose. What a student owes is worked out from the
  payment rows every time it is asked. What a receipt or an exported report says
  is fixed at the moment it was issued.
- **A spreadsheet export cannot be turned into an attack.** A student named
  `=cmd(...)` is written so the spreadsheet treats it as text, while genuine
  negative amounts stay numbers.
- **Recording money and correcting money are separate permissions.** Front-desk
  staff can take a payment without a super admin present; reversing one is a
  restricted capability, which is what makes the audit trail mean anything.
- **The activity log records every financial change and cannot be edited or
  deleted by anyone**, including a super admin. It is evidence, not a working
  record — no financial figure is ever read back out of it.

### Not built, deliberately

- **Collecting a later instalment through the screens.** The data model has
  always supported a bill paid over several payments, and the system will record
  one; what does not exist is a screen to start it. A student returning a week
  later to pay the rest cannot be served by the software today. Recorded in
  section 12 of the system design.
- **Automatic clean-up of generated export files.** They accumulate on the
  private disk. The existing file-deletion mechanism cannot express "delete this
  in 48 hours", so this needs its own piece of work rather than a workaround.
- Online card processing, and paying wages out — payroll computes and freezes
  what is owed; the paying happens outside the system.

### How it was verified

Twelve planned tasks plus five corrective ones, each on its own branch, each
implemented by one agent and reviewed by the other before merge. Several took
multiple rounds; the review that found nothing did not happen.

The habit that repeatedly earned its keep was **changing the code to prove a test
would notice**. A test that passes tells you very little until you have watched
it fail for the reason you expect. It caught a report that behaved correctly but
had no guard at all, a payment test that agreed with itself, an authorisation
check that had been quietly bypassed everywhere it mattered, and — twice — a
written explanation that confidently described behaviour the code did not have.

Two defects found this way are worth recording because neither was visible by
reading. Two people at two tills could each collect the full balance of the same
bill, because the second read an out-of-date figure while holding a lock that
looked sufficient. And a test asserting a receipt showed the right balance was
passing partly by luck, because the amount it checked for could also be produced
at random by the test data itself.


## Phase 1 — Foundation (completed 2026-08-05)

Staff sign in under four roles and manage students, courses, batches and
enrolments. Every change is audited. The database and uploads back up nightly to
a destination outside the server's own failure domain.

**Stack as shipped:** PHP 8.4.1 (hard floor), Laravel 13.20, Filament v5.7,
MySQL 8, Pest 4, Larastan/PHPStan, Pint.

### What works

- **Admin panel** at `/admin`, organised by domain (`app/Domain/Staff`,
  `app/Domain/Enrollment`) rather than by Laravel file type.
- **Four roles** — super admin, admin, staff, student — via
  `spatie/laravel-permission` and Filament Shield. Authorization is by
  permission everywhere; there is no `role` column and no role check in
  application logic, save one documented exception for rank.
- **Four privilege-escalation guards**, enforced in policies and in Actions that
  receive the actor explicitly, with architecture tests preventing anything from
  reaching around them.
- **Staff accounts** with admin-issued temporary passwords and a forced change at
  next sign-in.
- **Staff profiles** with certificate and photo uploads, served through
  authenticated routes rather than a public disk, and a durable deletion
  lifecycle: a receipt is committed with the row that owns the file, the bytes go
  afterwards, and a scheduled sweep re-dispatches anything the queue dropped.
- **Students, course catalogue, batches** inheriting course defaults, and
  **enrolments**.
- **Instructor hour allocation** on the batch↔instructor relationship, with a
  mismatch warning.
- **Append-only activity log** covering data changes and authentication events.
  No delete path exists for any role, including super admin — including through
  the console.
- **Nightly backups** of the database and both upload roots, encrypted, to a
  removable drive (default) or S3-compatible storage. The commands refuse to
  write to a destination that shares a filesystem with the application, so an
  unplugged drive fails the backup and alerts rather than silently filling a
  mount point on the server.
- **Bilingual scaffolding** with RTL support and a key-parity test. No
  user-facing string is hardcoded and no physical CSS property is used, though
  the Arabic catalogue is deliberately empty until phase 4.

### Not included

No financial feature of any kind, no student portal, no certificates, no public
marketing site. Those are phases 2 to 4.

### Known limitation carried forward

Audited field names render untranslated — see section 13 of the design spec.
Phase 4 inherits it.

### How it was verified

Seventeen tasks, each implemented on its own branch and reviewed before merge.
Phase 1 closed with an end-of-phase review by fresh reviewers with no
implementation context, split into three groups covering security, data
integrity and cross-cutting operations: **28 findings, 27 fixed and 1 deferred**,
recorded with their dispositions in
`docs/reviews/2026-07-29-phase-1-review.md`.

One question the review left open, G1-U3, was run at the close of phase 1 and
returned a finding rather than an all-clear: a Livewire component rendered
alongside the forced-password-change form can still be driven while an account is
locked to that form. It grants no privilege the actor lacks, but it defeats the
containment the flag exists to provide. Fixed in PR #11 — the page drops the
panel chrome, and the exemption now resolves every component in the Livewire
payload instead of trusting the page it was rendered on.

Seven runtime experiments settled claims a static reading could not. Two of them
cleared code that looked wrong, which is the argument for running the experiment
before writing the fix.
