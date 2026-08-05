# Changelog

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
