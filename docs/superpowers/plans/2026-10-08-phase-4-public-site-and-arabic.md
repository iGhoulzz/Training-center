# Phase 4 — Public site, Publications, the design revamp and Arabic/RTL

**Status: DRAFT, round 1. No task may begin until this merges green.** The rule
that has held since phase 2: a plan-level architecture error cost five
remediation rounds, so Codex reviews the plan before any implementation starts.

## What this phase is, and why it is one phase rather than two

The owner prepared a design handoff — a Filament revamp of roughly twenty admin
screens, and a new public marketing site — while phase 4 was already specified as
"public site, landing pages, course listings, contact, and the full bilingual
pass". **The design's public site *is* phase 4's public site**, so running them
separately means building that surface twice or coordinating two plans over one
set of files.

The decisive argument is ordering rather than overlap. **The bilingual and RTL
pass must run last, over whatever the final design is.** Do it before the revamp
and every screen is translated and direction-checked twice, the second time
against markup that has changed underneath it. That single constraint sets the
shape of the whole phase: design first, language last.

**Internal order: public site → admin revamp → bilingual/RTL over both.**

---

## Decisions taken before planning

Recorded with their dates so a reviewer sees what was chosen rather than
inferring it from the tasks, and so a later reader can tell a decision from an
assumption.

| Decision | Date | What it means here |
|---|---|---|
| **Phase 4 is unified** — design revamp, public site and bilingual pass in one phase | 2026-10-08 | Requires a §3 amendment; §3 as written contains no admin redesign |
| **Expenses: a managed list** admins maintain | 2026-10-08 | A categories table and a small admin screen, so adding "Insurance" needs no developer. Requires a §12 amendment: expense tracking is currently listed out of scope |
| **Publications: in** | 2026-10-08 | A public PDF library. Genuinely new domain scope — a model, uploads, public file serving, a download counter. Requires a §12 amendment |
| **The public programme cards show price, start date, hours and description** | 2026-10-08 | **Not seats remaining.** The owner confirmed all four fields and then reversed on seats the same day: a quiet batch showing 2 of 20 taken broadcasts how full the centre is. The prototype's "seats left" pill is not built |
| **All four new admin surfaces are in** | 2026-10-08 | Finance hub, export history, report charts, certificate work queues |
| **The verifier keeps its identical not-found response** | 2026-10-08 | The design's distinct "enter a reference" message is handled **client-side**, before anything is submitted |
| **Contact hours come off the certificate result** | 2026-10-08 | The design shows them; the closed public projection does not carry them, and widening what a public page reveals was not worth a detail nobody asked for |

### One precision this plan must not lose

**A revoked certificate is not a miss, and the two must never be described as
alike.** `VerifyCertificateController::show()` renders a known certificate's real
status, and design §7.3 *requires* a revoked one to say so **with its revocation
date** — somebody holding a worthless certificate needs to know which. What is
uniform is the miss: a blank box, a malformed reference and one that was never
issued all return the identical not-found result, so no sequence of guesses
reveals which references exist.

This is written out because the phase 3.5 close-out shipped the wrong version of
it and Codex caught it in review (#81). Anyone restyling the verifier inherits
both properties, not one.

---

## Prerequisites — amendments before any task

These are not tasks that can run in parallel with the work they authorise. §12
exists precisely so that out-of-scope items do not reappear as assumptions, and
widening it quietly would be the defect that rule was written against.

- **System design §3, Phasing** — phase 4 gains the admin revamp. As written it
  is public site, landing pages, course listings, contact and the bilingual pass.
- **System design §12, Explicitly out of scope** — expense tracking beyond staff
  wages comes out of the list, bounded to the managed-list shape the owner chose.
  Publications is added to the design as new scope rather than assumed in.

---

## What the backend already supports, and what it does not

The handoff ships `BACKEND_CONTRACT.md`, which was checked against this
repository before it was written. **Its central claim holds: nothing in the
bundle needs new business logic.** The only new write in the whole design is the
Publications download counter.

Two of its entries are **already obsolete** and must not be scoped as work — it
predates P35-T07 and T09:

- "Creating a new student inside the flow" exists. `EnrollAndCollect` quick-creates
  through `CreateWithIdentifierCodeAction`.
- "Suggesting the next free student code" is moot. Codes are generated.

The remaining gaps are read queries and N+1 fixes, and they are scoped as tasks
below rather than left for whoever notices them first.

---

## Declared seams

| File or surface | Tasks | Rule |
|---|---|---|
| `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` | T01, and **conditionally T03 and T04** | **T01 first, and alone, for §3 and §12.** T03 may touch §4's data model for the article, T04 for expenses — each only in the section its own entry names, and neither reopens §3 or §12 |
| `routes/web.php` | T11, then T13, T14, T15 | **Sequenced: T11 establishes the public group and its middleware**; the other three add routes inside it and may not alter the group. The existing verifier routes and their named rate limiter are T14's and nobody else's |
| `lang/en/*`, `lang/ar/*` | **Every task adds English keys; T17 writes Arabic** | No task ships a user-facing string without an English key, as from commit one. `lang/ar/` counterparts stay empty until T17, which is the only task that writes them |
| The Filament theme and shared layout | T16, then T18 | **Sequenced: T16 → T18.** T16 restyles; T18 makes it directional. A screen restyled after T18 has not been RTL-checked, which is the whole reason the bilingual pass runs last |
| `app/Domain/Finance/Reports/ProfitReport.php` | T04, then T10 | **Sequenced.** T04 teaches it expenses; T10 charts what it returns. T10 must not change what the figure means |
| `config/filesystems.php` and the private disk | T03 | Publications introduce a **public** document surface for the first time. Receipts and staff certificates are private and stay private; the seam is that one disk configuration now serves both |

---

## Task 0 — Scope inventory

Before implementation, each task records the exact files it will touch in its own
File scope, and any file shared with another task appears in the seams table
above. A scope expansion is approved by the owner and recorded in **both** the
task's File scope and the seam row, in the same pass — the phase 3.5 lesson, where
four approved expansions each missed the seam table on the first attempt.

---

## Wave 1 — Amendments and carried debt

### Task 1 — The spec amendments
**Owner: Claude · `p4/t01-spec-amendments` · blocks every other task**

**File scope**
- `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` — §3 and §12 only

**Does**

§3 records that phase 4 includes the admin revamp, and why the bilingual pass runs
last. §12 loses expense tracking, bounded to the managed-list shape, and gains
nothing silently — Publications is written in as scope with its own sentence.

**Done when**

§3 describes the phase that is actually being built · §12 no longer lists what
this phase ships, and records who decided it and when · no other section is
touched · `composer verify` green.

### Task 2 — Relax the two carried pins
**Owner: Claude · `p4/t02-dependency-pins` · depends on T01 only for sequencing**

**File scope**
- `composer.json`, `composer.lock` — the Filament constraint
- `package.json`, `package-lock.json` — the `shell-quote` override
- `tests/Load/README.md` — the smoke-run note, if the harness needs one

**Does**

Phase 3.5 left two deliberate holds: Filament pinned to `~5.8.4` rather than
taking 5.9, and `shell-quote` overridden to `^1.12.0` because no `concurrently`
release depended on a patched version. Both were risk decisions taken while a
measurement harness was mid-flight.

**The harness is the reason this is a task rather than a chore.**
`tests/Load/README.md` requires every k6 script to be smoke-run after a Filament
or Livewire change, because the scripts drive Livewire's update endpoint. Phase
3.5's figures were taken on 5.7.8 and 5.8.4.

**Done when**

The Filament constraint is a caret again, or the plan records why it is not ·
the `shell-quote` override is removed if `concurrently` has shipped a patched
dependency, and kept with a dated reason if not · all four k6 scripts are
smoke-run on the new version and the result recorded · `composer audit --locked`
and `npm audit --audit-level=high` both clean · `composer verify` green.

---

## Wave 2 — Data foundations

### Task 3 — Publications: the domain and the admin side
**Owner: Claude · `p4/t03-publications-domain` · depends on T01**

**File scope**
- A migration for `articles`
- `app/Domain/Publications/` — the model, its Action for the one new write, and the Filament resource
- `database/factories/`, `database/seeders/RolePermissionSeeder.php` — the permissions
- `config/filesystems.php` — the public document disk
- `lang/en/publications.php`
- `tests/Feature/Publications/`

**Does**

An `Article`: title in English and Arabic, slug, description, topic, PDF file,
issue date, authors, download counter, published flag. A Filament resource for
the administration to upload and publish.

**The download counter is the only new write in the whole design bundle**, and it
goes through an Action like every other write — thin model, actor-aware Action,
the architecture test already enforces it.

**This introduces the first public document surface.** Receipts and staff
certificates are private and served through signed, authorised routes; a
published article is deliberately world-readable. The seam is the disk
configuration, and the rule is that nothing already private moves to satisfy it.

**Done when**

Uploading, publishing and unpublishing are Actions with permissions · an
unpublished article is invisible to the public surface and a test proves it ·
the counter increments through an Action and cannot be driven negative · the
public disk serves only what was deliberately put on it · `composer verify` green.

### Task 4 — Expenses, and what Profit means
**Owner: Claude · `p4/t04-expenses` · depends on T01 · blocks T10's profit chart**

**File scope**
- Migrations for `expense_categories` and `expenses`
- `app/Domain/Finance/` — models, Actions, and `ProfitReport`
- `database/seeders/RolePermissionSeeder.php`
- `lang/en/finance.php` or a new catalogue
- `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` — **§4 only**, the data model
- `tests/Feature/Finance/`

**Does**

A managed list of categories an admin maintains, and expenses recorded against
them. `ProfitReport` today is literally revenue minus wages; it becomes revenue
minus wages minus expenses.

**This changes what a published figure means, which is the risk.** The owner's
standing objection to the finance reports is that figures are not traceable to
the rows behind them and the terms are undefined on screen. A Profit number that
silently changes definition between two months is exactly that complaint made
worse. The report states its own definition on screen, and the period boundary
for an expense is decided and written down rather than assumed.

Money stays `decimal(12,3)`, through the existing `Money` cast. No derived total
is stored.

**Done when**

Categories are managed without a developer · an expense cannot exist without a
category · `ProfitReport` states its definition where it is read · the period
rule is explicit and tested at both boundaries · no float appears in the path,
and the existing architecture test proves it · `composer verify` green.

### Task 5 — The read queries the design needs
**Owner: Claude · `p4/t05-read-queries` · depends on T01**

**File scope**
- `app/Domain/Enrollment/Models/Batch.php` — scopes only, beside the existing predicates
- `app/Domain/Finance/` and `app/Domain/Enrollment/` query services — new read methods
- `tests/Feature/` beside the existing cases for each

**Does**

Seven read queries the design's screens need and the backend does not have. None
introduces business logic; the rule already lives in a model or an enum, and only
the selection is new.

- `Batch::isOverCapacity()` and `hasHourMismatch()` are **PHP predicates evaluated
  after rows load**, so they cannot filter in SQL. Add scopes expressing the same
  comparison in the query. **The predicate stays the single definition of the
  rule** — a scope that drifts from it is two sources of truth, and this codebase
  has already paid for that shape once.
- Batch-level outstanding total; an "owes money" filter for the students list; the
  ageing chip per charge row; revenue per course on the catalogue; assigned
  batches and hours per user; course and batch names on portal balance rows.

**Done when**

Every scope is tested against its predicate on the same fixture, so a drift
fails · no new business rule is introduced, and the review can see that · each
query is exercised by the screen that needs it · `composer verify` green.

### Task 6 — The N+1s the design would otherwise ship
**Owner: Claude · `p4/t06-batching` · depends on T01**

**File scope**
- The Filament resources and relation managers named by the contract
- `tests/Feature/Tooling/LazyLoadingGuardTest.php` if the guard needs a case

**Does**

Four per-row calls the design's lists would make once per row: per-student
balance in the students list and the batch roster, tender chips per payment,
enrolled-student and batch counts per course.

P3.5-T04 installed a lazy-loading guard, and P3.5-T05 established that the query
plan is measured rather than assumed. **The guard only arms on multi-row
hydrations**, so a test that loads one record proves nothing here.

**Done when**

Each list's query count is asserted and does not grow with row count · the
measurement is recorded, not estimated · `composer verify` green.

---

## Wave 3 — The new admin surfaces

### Task 7 — Finance hub
**Owner: Claude · `p4/t07-finance-hub` · depends on T05**

A landing page tiling finance screens and reports that already exist. Every tile
links to something built. **No export is an instant download** — the hub shows
queued, running, ready and failed, and never offers a partial file.

### Task 8 — Export history
**Owner: Claude · `p4/t08-export-history` · depends on T07**

Filament already stores `Export` rows and nothing surfaces them. Staff see what
was queued, what is ready and what failed, instead of relying on a notification
they may have missed. **Export permission is re-checked at download time**, which
this list must not imply otherwise.

### Task 9 — Certificate work queues
**Owner: Claude · `p4/t09-certificate-queues` · depends on T05**

Two lists: completed but not yet issued, and due this month. The eligible records
exist and nothing surfaces them, so today it is a manual check. **An outstanding
balance never blocks issuing**, and the queue must not imply it does.

### Task 10 — Charts on the reports
**Owner: Claude · `p4/t10-report-charts` · depends on T04 and T05**

The report data exists; no chart widget is registered. **The largest of the four**,
because each report needs a decision about what its chart actually says — a chart
that implies a trend the rows do not support is worse than no chart. The profit
chart depends on T04, and must not restate the definition differently from the
report it sits on.

---

## Wave 4 — The public site

### Task 11 — Public site foundation
**Owner: Claude · `p4/t11-public-foundation` · depends on T01**

Layout, navigation, theme, the route group and its middleware, and the caching
strategy. **The programme cards carry no derived count** — the owner's reversal on
seats removed the only one — so this surface is near-static and should be served
that way.

A public surface is the first thing in this system that serves the open internet
other than the verifier. Rate limiting, no session for anonymous visitors, and
nothing that reads a student record.

### Task 12 — Home, about, programmes, contact
**Owner: Claude · `p4/t12-public-pages` · depends on T11**

Reads `Course` and `Batch` for name, hours, price and start date. **Price, start
date, hours and description are public; seats are not.**

### Task 13 — Publications, public
**Owner: Claude · `p4/t13-publications-public` · depends on T03 and T11**

The library index with search by title, topic, author and description; topic
chips; sort by newest or most downloaded; the article page; the download route
that increments the counter through T03's Action.

**Only published articles are reachable**, and the download route is rate-limited
like every other public one.

### Task 14 — The verifier, restyled
**Owner: Claude · `p4/t14-verifier-restyle` · depends on T11**

**Two properties are inherited, not re-decided.** A revoked certificate answers
honestly with its revocation date (§7.3). A blank box, a malformed reference and
an unknown one return the identical not-found result. The design's "enter a
reference" helpfulness is **client-side only**, before submission.

Contact hours come off the result. The projection is named field by field and
stays that way: whatever the certificates table gains later must not appear here
by default.

### Task 15 — Portal sign-in from the public site
**Owner: Claude · `p4/t15-portal-entry` · depends on T11**

A link or form posting to the existing student guard. **A student with no email
cannot be issued a login**, and the copy says so. No new auth path.

---

## Wave 5 — The admin revamp

### Task 16 — The Filament revamp
**Owner: Claude · `p4/t16-admin-revamp` · depends on every Wave 3 task**

Roughly twenty screens, visual only. **The contract's rules to preserve are the
acceptance criteria**: no export is instant; export permission is re-checked at
download; over-capacity enrolment is flagged, never blocked; an outstanding
balance never blocks a certificate; a second installment against one bill is
refused; tender totals must equal the amount collected; the last super admin
cannot be deactivated, demoted or deleted; the activity log is append-only; a
student with no email gets no portal login; pricing is written only through its
Actions.

**A revamp that makes any of those read differently has broken the system while
passing its own tests.** Each one gets an assertion that the UI still says what
the code enforces.

This task is a candidate for splitting at review. Twenty screens in one PR is not
reviewable, and the phase 3.5 lesson is that a large pass needs its own review
per revision.

---

## Wave 6 — Arabic and RTL, last

### Task 17 — The Arabic catalogues
**Owner: Claude · `p4/t17-arabic-catalogues` · depends on T16**

Every `lang/ar/` counterpart, which has shipped empty since commit one precisely
for this. Composite strings go through keys with their own separators and
ordering — a lesson this project already paid for.

### Task 18 — RTL and locale switching
**Owner: Claude · `p4/t18-rtl` · depends on T17**

Direction, the locale switch, and the conformance check that matters: **logical
CSS properties have been enforced since commit one, but the handoff's own CSS is
not this repository's** and must be checked before it is adopted. A `margin-left`
inherited from the prototype defeats the discipline the whole codebase has kept.

**Done when**

Both directions render every screen · no physical property survives from the
handoff · the Arabic catalogue has no missing key, and a test proves it rather
than a reviewer reading them · `composer verify` green.

---

## Deferred

| Item | Why not now |
|---|---|
| **Search latency attribution** | P3.5-T13's finding, measurement only. Carried forward unscheduled |
| **Report latency attribution** | P3.5-T13's finding, measurement only |
| **Receipt queue-drain profile** | P3.5-T13's finding, measurement only |
| **Online payment from the portal** | `RecordPaymentAction` is staff-only. The design does not offer it and neither should the build. §12 keeps card payments out |
| **Production SLO targets** | T12's objectives describe the development harness. Real targets need a chosen host and a measurement on it |

---

## What this plan does not cover

From §12, recorded so they do not reappear as assumptions: multi-tenancy,
attendance tracking, grades, timetabling, online card payments, a mobile
application, public student self-registration, and certificate template design or
printing.

**Hosting is still undecided.** A public site means real traffic, TLS and search
engines, and none of this phase's work chooses a host. That decision gates
deployment, not development.
