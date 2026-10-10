# Phase 4 — Public site, Publications, the design revamp and Arabic/RTL

**Status: DRAFT, round 4. No task may begin until this merges green.** The rule
that has held since phase 2: a plan-level architecture error cost five
remediation rounds, so Codex reviews the plan before any implementation starts.

## Round 2 — what changed, and where

Codex's round-1 review of `0511fe6` requested changes before Wave 1. Each finding
is answered in the body; this table only says where.

| Finding | Answer |
|---|---|
| P1 — T01 amends the wrong sections; the expense period basis is undecided | T01 now amends §3, §5, §6, §8 and §12, and is the **only** task that edits the spec. The owner decided the basis: **an expense counts in the month it was paid**. T03 and T04 no longer touch the spec |
| P1 — a public-disk PDF cannot honour unpublish; the anonymous counter has no actor | Articles live on the existing **private** disk. Every download goes through a route that re-reads the published state per request (T13), and the counter is an explicitly actor-less Action with its own guard (T03) |
| P1 — T07–T18 lack scope and Done-when; T16 is unreviewable; undeclared seams | Every task now names its files and tests. T16 is split into **T16a–T16e**. `RolePermissionSeeder.php`, `AdminPanelProvider.php`, `AppServiceProvider.php`, `resources/css/` and `vite.config.js` are declared seams with an order |
| P1 — the design handoff is untracked, so no worktree or reviewer can see it | New **T19**, in Wave 1: a cleaned copy is committed, reviewed by the owner before it is pushed, because this repository is public |
| P2 — "certificates due this month" has no rule | **Dropped from phase 4** by the owner. T09 builds the one queue the data can define |
| P2 — "no session for anonymous visitors" against the `web` group | T11 specifies which routes are sessionless and how, and leaves the verifier and every sign-in path on the full `web` stack |
| P2 — which Batch a programme card shows | The owner decided: **the next upcoming batch**, with a defined fallback (T05, T12) |
| Wording — the verifier cannot make enumeration impossible | Corrected below |

Task numbers are kept from round 1 so the review's references stay valid; the new
task is T19 for that reason, not because it runs last.

## Round 3 — what changed, and where

Codex's re-review of `2028d82` found five issues that only appear when the plan
is checked against the code. Three needed the owner, who decided them on
2026-10-08.

| Finding | Answer |
|---|---|
| P1 — an actor-less counter contradicts ENGINEERING's write boundary | **The owner approved a narrow exception**, which T01 writes into `docs/ENGINEERING.md` itself rather than letting the plan assert it. It is not called an Action: `ArticleDownloadCounter` (T03) |
| P1 — T04 omits `ReportDataBuilder::profit()`, and in-place correction would silently move a past month | `ReportDataBuilder.php` is in T04's scope and a seam. **The owner chose reversal, like payments**: an expense is reversed once and re-recorded, never edited, and a reversed expense leaves its original month traceably |
| P1 — T17's keys have no code using them; new audited models need record-type labels | `ActivityResource.php` is in T17's scope, with Arabic behavioural assertions. `lang/en/activity.php` is in T03's and T04's scopes and is a seam |
| P2 — T14's optional script breaks `VerificationDisclosureTest` | **No script.** The empty-box prompt is the input's native `required` attribute. The verifier pages stay standalone and do not adopt the public layout, and the security test is unchanged |
| P2 — the locale boundary and the routes seam stop short | **The owner chose full coverage**: `/ar/` verifier routes inside the existing verifier group, and a guest locale on both panels' login pages. T18 is in the routes seam, and its Done-when names every surface |

## Round 4 — what changed, and where

Codex's re-review of `52cfa4a` cleared round 3 and found one remaining P1.

| Finding | Answer |
|---|---|
| P1 — registering `/ar` routes cannot keep an Arabic journey in Arabic. The form action, the controller's redirect and both "verify another" links resolve route names that point at the English URLs, and reusing those names would overwrite them | **Arabic routes get their own names, and every public and verifier link goes through one resolver.** Arabic routes are named `ar.` plus the English name. `PublicRoute` (created by T11) picks the name for the request's locale. T18 converts the controller's redirect and all three verifier call sites, and its test walks the full Arabic flow and the full English flow. **The same defect would have hit every public-site link**, so T11's guard forbids a bare `route()` in public views from the start |

---

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
| **An expense counts in the month it was paid** | 2026-10-08 | One local calendar date per expense, `paid_on`. Rent paid on 1 October for the quarter counts wholly in October. No incurred date, no spreading across months. Matches revenue, which is already cash basis (§8) |
| **Publications: in** | 2026-10-08 | A public PDF library. Genuinely new domain scope — a model, uploads, public file serving, a download counter. Requires amendments to §5, §6 and §12 |
| **The public programme cards show price, start date, hours and description** | 2026-10-08 | **Not seats remaining.** The owner confirmed all four fields and then reversed on seats the same day: a quiet batch showing 2 of 20 taken broadcasts how full the centre is. The prototype's "seats left" pill is not built |
| **A programme card shows the course's next upcoming batch** | 2026-10-08 | Rule in T05. With no upcoming batch the card shows the course without a date or price, and a contact call to action |
| **Three new admin surfaces are in** | 2026-10-08 | Finance hub, export history, report charts, and **one** certificate queue — completed but not yet issued |
| **"Certificates due this month" is out of phase 4** | 2026-10-08 | Nothing in the data defines when a certificate falls due, and inventing a rule for a queue was not worth it. Recorded so it does not return as an assumption |
| **The verifier keeps its identical not-found response** | 2026-10-08 | The design's distinct "enter a reference" message is handled **client-side**, before anything is submitted |
| **Contact hours come off the certificate result** | 2026-10-08 | The design shows them; the closed public projection does not carry them, and widening what a public page reveals was not worth a detail nobody asked for |
| **The design handoff is committed as a cleaned copy** | 2026-10-08 | The repository is public. Real logo, contact details and third-party material come out, and the owner reviews the copy before it is pushed (T19) |
| **The download counter is an approved exception to the write boundary** | 2026-10-08 | One column, one class, increment-only, not security-sensitive, no activity-log row, enforced by an architecture test. T01 writes it into `docs/ENGINEERING.md` |
| **An expense is corrected by reversal, like a payment** | 2026-10-08 | Set-once `reversed_at`, `reversed_by`, `reversal_reason`; never edited or deleted; the correct figure is a new row. A reversed expense leaves every report, including its original month's profit — exactly as a reversed payment already leaves revenue. The month moves, and the reversal is what explains it |
| **The bilingual pass reaches the verifier and both login pages** | 2026-10-08 | `/ar/` verifier routes in the existing verifier group; the Arabic site's portal link carries the locale, and both panels' login pages honour it for a guest |

### One precision this plan must not lose

**A revoked certificate is not a miss, and the two must never be described as
alike.** `VerifyCertificateController::show()` renders a known certificate's real
status, and design §7.3 *requires* a revoked one to say so **with its revocation
date** — somebody holding a worthless certificate needs to know which.

What is uniform is the miss: a blank box, a malformed reference and one that was
never issued all return the identical not-found result. That does not make
enumeration impossible — a correctly guessed reference necessarily returns a
result. The uniform miss, the random reference alphabet and the named rate
limiter make guessing expensive and uninformative; none of them makes it
impossible, and no document in this repository should say otherwise.

The phase 3.5 close-out shipped the wrong version of the first point and Codex
caught it (#81); round 1 of this plan overclaimed the second. Anyone restyling
the verifier inherits both properties, stated this precisely.

---

## What the backend already supports, and what it does not

The handoff ships `BACKEND_CONTRACT.md`, which was checked against this
repository before it was written. **Its central claim holds: nothing in the
bundle needs new business logic** beyond the two pieces of new scope the owner
added — Publications and expenses.

Two of its entries are **already obsolete** and must not be scoped as work — it
predates P35-T07 and T09:

- "Creating a new student inside the flow" exists. `EnrollAndCollect` quick-creates
  through `CreateWithIdentifierCodeAction`.
- "Suggesting the next free student code" is moot. Codes are generated.

The remaining gaps are read queries and N+1 fixes, scoped as tasks below rather
than left for whoever notices them first.

---

## Declared seams

A file shared by two tasks appears here with an order. A task that finds it needs
a file outside its own scope stops and raises it; an approved expansion is
recorded in **both** the task's File scope and this table in the same pass — the
phase 3.5 lesson, where four approved expansions each missed this table on the
first attempt.

| File or surface | Tasks, in order | Rule |
|---|---|---|
| `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` | **T01 only** | No other task edits the spec. A task that finds the spec wrong stops and raises it; the fix is a spec amendment, not a quiet edit inside a feature PR |
| `docs/ENGINEERING.md` | **T01 only** | The download-counter exception, in the write-boundary section. Nothing else |
| `lang/en/activity.php` | **T03 → T04 → T17** | T03 adds the `Article` record-type label, T04 `Expense` and `ExpenseCategory`, and T17 the `activity.field.*` group. `LocalizationTest` fails without the labels, so each lands with its model |
| `app/Domain/Staff/Filament/Resources/ActivityResource.php` | **T16e → T17** | T16e restyles; T17 routes `describeProperties()` and `describeChanges()` through `activity.field.*` |
| `app/Domain/Finance/Exports/ReportDataBuilder.php` | **T04 only** | `profit()` gains the expenses column |
| `app/Providers/Filament/StudentPanelProvider.php` | **T18 only** | The guest-locale middleware |
| `docs/design/design_handoff_training_centre/` | **T19 only** | Read-only reference for every other task |
| `database/seeders/RolePermissionSeeder.php` | **T03 → T04** | T03 adds the article permissions, T04 the expense and category permissions. T04 rebases on T03 and must not reorder T03's rows |
| `app/Providers/Filament/AdminPanelProvider.php` | **T03 → T09 → T16a → T18** | T03 adds `discoverResources` for `Domain/Publications`; T09 adds `discoverPages` for `Domain/Enrollment/Filament/Pages`; T16a owns the theme, colours and navigation; T18 adds the guest-locale middleware. Each adds its own lines and edits no other task's |
| `app/Providers/AppServiceProvider.php` | **T11 → T13** | T11 registers the `public-site` rate limiter, T13 the `publication-download` limiter. Nothing else in the file is touched |
| `routes/web.php` | **T11 → T12 → T13 → T15 → T18** | T11 creates the sessionless public group; T12, T13 and T15 add routes inside it and may not alter the group or its middleware. T18 adds the `/ar` copies of the public group and the `/ar/` verifier routes **inside the existing verifier group**, under the same middleware and the same named limiter, **named `ar.` plus the English name** so the English names never move. **No task touches the private-file group** |
| `resources/css/app.css`, `vite.config.js` | **T11 → T16a → T18** | T11 adds the public site's stylesheet entry; T16a the Filament theme entry; T18 the direction work over both |
| `resources/views/layouts/public.blade.php` | **T11 → T18** | T11 creates it; T18 makes it directional. The verifier does **not** use it — see T14 |
| `resources/views/public/partials/header.blade.php` | **T11 → T15 → T18** | T11 creates it; T15 adds the portal link; T18 makes that link carry the locale |
| `resources/views/verify/*` | **T14 → T18** | T14 restyles; T18 makes them directional and converts their three `route()` call sites to `PublicRoute`. Both keep them standalone and scriptless |
| `app/Http/Controllers/VerifyCertificateController.php` | **T18 only** | One line: the `submit()` redirect goes through `PublicRoute`. The lookup, the normalisation and the uniform miss are untouched |
| `app/Support/PublicRoute.php` | **T11 only** | Complete from the start, so T18 adds routes and a locale without editing it |
| `app/Domain/Enrollment/Models/Batch.php` | **T05 only** | Scopes beside the existing predicates |
| `app/Domain/Enrollment/Services/EnrollmentQueryService.php`, `app/Domain/Finance/Services/StudentBalanceQuery.php`, `app/Domain/Finance/Data/EnrollmentBalance.php`, `app/Domain/Finance/Filament/Portal/Pages/MyBalance.php`, `resources/views/portal/my-balance.blade.php`, `lang/en/portal.php`, `tests/Feature/Finance/StudentBalanceQueryTest.php`, `tests/Feature/Portal/MyBalancePageTest.php`, `tests/Feature/Portal/PortalQueryCountTest.php` | **T05 only** | Owner-approved scope expansion (2026-10-10): show localized course names and batch codes on balance rows now. Ownership, existing unbilled-row handling and fixed query counts are preserved regression behavior |
| `app/Domain/Finance/Reports/ProfitReport.php` | **T04 → T10** | T04 teaches it expenses; T10 charts what it returns and must not change what the figure means |
| `app/Domain/Finance/Filament/Pages/Reports/*` | **T10 → T16d** | T10 adds the charts; T16d restyles. T16d must not change what a chart plots |
| `lang/en/*` other than `activity.php` | Every task, each in its own catalogue | No task ships a user-facing string without an English key. A task adding to an existing catalogue appends its own keys and edits nobody else's |
| `lang/ar/*` | **T17 only** | Stay empty until T17, so an untranslated string stays visible |

---

## Wave 1 — Amendments, the design contract, carried debt

### Task 1 — The spec amendments
**Owner: Claude · `p4/t01-spec-amendments` · blocks every other task except T19**

**File scope**
- `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` — §3, §5, §6, §8, §12
- `docs/ENGINEERING.md` — the write-boundary section only

**Does**

- **§3 Phasing.** Phase 4 includes the admin revamp, and says why the bilingual
  pass runs last. The bilingual pass covers the public site, **the certificate
  verifier and both panels' login pages**, as well as every panel screen.
- **§5 Roles and permissions.** The permission matrix gains rows for articles,
  expenses and expense categories, with Shield's `{action}_{model}` names and the
  role that holds each. Proposed: super admin and admin manage articles and
  categories and record expenses, staff do none of it, and **reversing an expense
  is super admin only**, mirroring `reverse_payment`. **The owner confirms the
  matrix rows in review** — a permission is not something this plan guesses.
- **`docs/ENGINEERING.md`, the write boundary.** Records the owner-approved
  exception in the section that states the rule (the Actions table and "What the
  boundary does guarantee"), so the standard and the plan cannot disagree. The
  wording is deliberately narrow:
  - **one** column, `articles.download_count`, written by **one** class,
    `ArticleDownloadCounter`;
  - increment-only, by a single conditional `UPDATE`, reachable anonymously;
  - not security-sensitive: it moves no money, grants nothing and exposes
    nothing;
  - no activity-log row, because an anonymous count has no actor to attribute;
  - enforced by an architecture test that fails on a second writer.

  The exception is named as one, so it cannot be cited as precedent for another.
- **§6 Data model.** Three tables, written as the existing ones are:
  - `articles` — `id, title_en, title_ar, slug (unique), description_en,
    description_ar, topic, authors, issued_on, original_filename, disk, path,
    download_count (unsigned, default 0), published_at (nullable), timestamps`.
    `published_at` null is unpublished; there is no separate flag to disagree
    with it.
  - `expense_categories` — `id, name_en, name_ar, is_active, timestamps`. A
    category with expenses is deactivated, never deleted.
  - `expenses` — `id, expense_category_id (FK restrictOnDelete), amount
    decimal(12,3), paid_on (date), description, recorded_by (FK users
    restrictOnDelete), reversed_at (nullable), reversed_by (nullable FK users
    restrictOnDelete), reversal_reason (nullable), timestamps`, with a `CHECK`
    that the three reversal columns are all null or all present — the shape
    `payments` already has.
  - **§6 File storage gains one sentence**: a published article is the first file
    served to anonymous visitors, and it is still stored on the private disk.
    Its route checks publication state on every request, which is the
    per-request check the rule already demands, applied to a public reader.
- **§8 Financial reporting.** Profit becomes *collected revenue minus finalized
  wage cost minus expenses paid in the period*. `paid_on` is a **centre-local
  calendar date**, matched to a month by equality on its year and month like
  `payroll_lines.posting_period_start`, never through `ReportPeriod`'s UTC
  instants. The section records that the definition changed, when, and that a
  month before the first expense row reads exactly as it did.

  **Corrections are reversals.** An expense is never edited or deleted. A wrong
  one is reversed once — `reversed_at`, `reversed_by` and `reversal_reason` are
  written together and never unset — and the right figure is recorded as a new
  row. **Only non-reversed expenses count**, in every report. A reversal
  therefore changes the profit of the month the expense was paid in, which is
  the same behaviour a reversed payment already has on revenue. §8 says so
  plainly rather than claiming that closed months never move: they move only
  through a recorded, attributed reversal.
- **§12 Out of scope.** Expense tracking comes out of the list, bounded to the
  managed-list shape. Publications is written in as scope with its own sentence.
  "Certificates due this month" is **not** added anywhere.

**Done when**

Every section above describes what the phase builds, and nothing else changes ·
the §5 rows are confirmed by the owner in the PR · §8's definition names its date
basis and its reversal rule · ENGINEERING's exception names its column, its
class and its test · `composer verify` green.

### Task 19 — The design contract, cleaned and committed
**Owner: Claude · `p4/t19-design-contract` · blocks T11–T16e · may run beside T01**

**File scope**
- `docs/design/design_handoff_training_centre/` — the nine files, cleaned
- `docs/design/README.md` — new: what was removed, why, and that the copy is
  reference, not specification

**Does**

The handoff exists only in the owner's main checkout, untracked. A worktree
cannot see it and neither can a reviewer, so a task that says "match the design"
is unreproducible. This commits it, after removing:

- the real logo (`assets/asclst-logo.jpg`) and anything embedding it, including
  base64 copies inside the two `.dc.html` prototypes, replaced with a neutral
  placeholder;
- real contact details — addresses, telephone numbers, email addresses, social
  links;
- third-party material whose licence does not allow publishing, including any
  bundled script whose origin cannot be established (`support.js` and
  `image-slot.js` are checked, not assumed).

**Where the spec and the handoff disagree, the spec wins**, and the README says
which known conflicts were already decided: seats, contact hours, the verifier's
empty submit.

**The owner reviews the cleaned copy locally before it is pushed.** Pushing
publishes it; there is no recall.

**Done when**

A grep for the removed logo's bytes, every real telephone number and email
address in the original, and the centre's real name in image alt text finds
nothing · both prototypes still open and render with the placeholder · the owner
has approved the diff · `composer verify` green.

### Task 2 — Relax the two carried pins
**Owner: Claude · `p4/t02-dependency-pins` · after T01, for sequencing only**

**File scope**
- `composer.json`, `composer.lock` — the Filament constraint
- `package.json`, `package-lock.json` — the `shell-quote` override
- `tests/Load/README.md` — the smoke-run record

**Does**

Phase 3.5 left two deliberate holds: Filament pinned to `~5.8.4` rather than
taking 5.9, and `shell-quote` overridden to `^1.12.0` because no `concurrently`
release depended on a patched version. Both were risk decisions taken while a
measurement harness was mid-flight.

**The harness is the reason this is a task rather than a chore.**
`tests/Load/README.md` requires every k6 script to be smoke-run after a Filament
or Livewire change, because the scripts drive Livewire's update endpoint.

**Done when**

The Filament constraint is a caret again, or the README records why it is not ·
the `shell-quote` override is removed if `concurrently` has shipped a patched
dependency, and kept with a dated reason if not · all four k6 scripts are
smoke-run on the new version and the result recorded · `composer audit --locked`
and `npm audit --audit-level=high` both clean · `composer verify` green.

---

## Wave 2 — Data foundations

### Task 3 — Publications: the domain and the admin side
**Owner: Claude · `p4/t03-publications-domain` · depends on T01**

**File scope**
- `database/migrations/*_create_articles_table.php`
- `app/Domain/Publications/Models/Article.php`
- `app/Domain/Publications/Actions/` — `CreateArticleAction`, `UpdateArticleAction`,
  `PublishArticleAction`, `UnpublishArticleAction`
- `app/Domain/Publications/Support/ArticleDownloadCounter.php` — the approved
  exception, deliberately not an Action
- `app/Domain/Publications/Policies/ArticlePolicy.php`
- `app/Domain/Publications/Filament/Resources/ArticleResource.php` and its `Pages/`
- `app/Providers/Filament/AdminPanelProvider.php` — **one `discoverResources` call** (seam)
- `database/seeders/RolePermissionSeeder.php` — the article permissions (seam, before T04)
- `database/factories/ArticleFactory.php`
- `lang/en/publications.php`
- `lang/en/activity.php` — the `Article` record-type label (seam, first)
- `tests/Feature/Publications/ArticleActionsTest.php`,
  `ArticleResourceTest.php`, `ArticleStorageTest.php`,
  `ArticleDownloadCounterTest.php`, `ArticleWriteBoundaryArchTest.php`

**Does**

The model, the staff side and the one anonymous write.

- **Storage.** The PDF goes on the existing `private` disk under `publications/`,
  through the same lifecycle the staff certificates use, so it is backed up with
  the rest of that disk and has no URL of its own. `config/filesystems.php` is
  **not** changed, and no file is ever placed on the `public` disk.
- **Staff writes** — create, update, publish, unpublish — are actor-aware Actions
  that write the activity log, like every other staff write.
- **The download counter is the owner-approved exception that T01 writes into
  `docs/ENGINEERING.md`, and is not an Action.** It does not claim to be one: an
  anonymous reader has no actor to pass and nothing to authorize.
  `ArticleDownloadCounter::increment(Article)` performs one atomic
  `UPDATE articles SET download_count = download_count + 1 WHERE id = ? AND
  published_at IS NOT NULL` and writes **no** activity-log entry. Logging every
  anonymous download would flood an append-only log with rows nobody can
  attribute. T03 adds the guard the exception requires: **no file under `app/`
  other than that class writes `download_count`**, proved by a probe that must
  fail. The `Article` model leaves `download_count` out of `$fillable`.
- The count is approximate by nature: rate-limited, not deduplicated per reader.
  "Most downloaded" sorts on it and is labelled as a count, not a ranking anyone
  should rely on.

**Done when**

Create, update, publish and unpublish are Actions behind permissions, tested with
an actor holding exactly the needed permission and one holding one fewer — never
as super admin · the stored file's path is on the private disk and no
`Storage::disk('public')` call exists in the domain · the counter increments
atomically, does not increment an unpublished article, and two concurrent calls
add two · the arch rule fails on a planted second writer, then passes with it
removed · `LocalizationTest` passes with `Article` recording activity ·
`composer verify` green.

### Task 4 — Expenses, and what Profit means
**Owner: Claude · `p4/t04-expenses` · depends on T01 and T03 (seeder seam) · blocks T10's profit chart**

**File scope**
- `database/migrations/*_create_expense_categories_table.php`, `*_create_expenses_table.php`
- `app/Domain/Finance/Models/ExpenseCategory.php`, `Expense.php`
- `app/Domain/Finance/Actions/` — `CreateExpenseCategoryAction`,
  `UpdateExpenseCategoryAction`, `RecordExpenseAction`, `ReverseExpenseAction`
- `app/Domain/Finance/Policies/ExpenseCategoryPolicy.php`, `ExpensePolicy.php`
- `app/Domain/Finance/Reports/ExpenseReport.php` — new
- `app/Domain/Finance/Reports/ProfitReport.php` (seam, before T10)
- `app/Domain/Finance/Exports/ReportDataBuilder.php` — `profit()` gains the
  expenses column; this is the one snapshot builder the screen, PDF and XLSX all
  read (seam)
- `app/Domain/Finance/Exports/ProfitReportExporter.php` — the new column
- `app/Domain/Finance/Filament/Resources/ExpenseCategoryResource.php`,
  `ExpenseResource.php`, and their `Pages/`
- `database/seeders/RolePermissionSeeder.php` (seam, after T03)
- `database/factories/ExpenseCategoryFactory.php`, `ExpenseFactory.php`
- `lang/en/expenses.php`; `lang/en/reports.php` — the profit definition and column keys only
- `lang/en/activity.php` — the `Expense` and `ExpenseCategory` record-type labels (seam, after T03)
- `tests/Feature/Finance/Expenses/` — `ExpenseActionsTest.php`,
  `ExpenseReversalTest.php`, `ExpenseReportTest.php`, `ProfitWithExpensesTest.php`,
  `ProfitExportParityTest.php`, `ExpenseResourceTest.php`

**Does**

A managed list of categories and expenses recorded against them, and the change
to Profit that §8 (T01) now defines.

- `ProfitReport` composes a third report, `ExpenseReport::totalForMonths()`,
  exactly as it composes revenue and wages today — it runs no SQL of its own.
  `paid_on` is filtered by local calendar month, never through `ReportPeriod`.
- **This changes what a published figure means, which is the risk.** The report
  states its definition on screen, and a month before the first expense row
  reads identically to the figure the report showed before this task.
- Money stays `decimal(12,3)` through the existing `Money` cast; no total is
  stored.
- **Corrections are reversals, as §8 now says.** `ReverseExpenseAction` writes
  `reversed_at`, `reversed_by` and `reversal_reason` once, behind the super-admin
  permission, and logs the reason. No Action, Filament form or bulk action edits
  `amount`, `paid_on` or `expense_category_id` after creation, and none deletes
  an expense. A reversed expense is excluded from `ExpenseReport` and therefore
  from Profit, so its original month moves — traceably, through the reversal,
  exactly as a reversed payment moves revenue.

**Done when**

Categories are managed without a developer, and a category in use cannot be
deleted · an expense cannot exist without a category · an expense paid on the
last day of a month counts in that month and one paid on the first day of the
next does not, in the centre's timezone, with `setTestNow` placing "now" on the
other side of UTC midnight · a month with no expenses returns the same profit
as the pre-T04 formula, asserted against an expected value computed by hand,
not by calling the report · a reversed expense is absent from `ExpenseReport`
and from Profit, asserted per report · reversal is set-once — a second reversal
is refused, and the `CHECK` rejects a partial reversal at the database · no edit
or delete path exists, proved behaviourally through the Filament resource ·
`ReportDataBuilder::profit()`, the PDF and the XLSX carry the same expenses
figure for one month · `LocalizationTest` passes with both new models recording
activity · `MoneyCastArchTest` covers the new columns · `composer verify` green.

### Task 5 — The read queries the design needs
**Owner: Codex · `p4/t05-read-queries` · depends on T01**

**File scope**
- `app/Domain/Enrollment/Models/Batch.php` — scopes only (seam)
- `app/Domain/Enrollment/Queries/NextUpcomingBatch.php` — new
- `app/Domain/Enrollment/Queries/CertificateIssuanceQueue.php` — new
- `app/Domain/Finance/Queries/BatchOutstanding.php`, `StudentsOwingMoney.php`,
  `RevenuePerCourse.php`, `ChargeAgeing.php` — new
- `app/Domain/Staff/Queries/AssignedHoursPerUser.php` — new
- `app/Domain/Enrollment/Services/EnrollmentQueryService.php`,
  `app/Domain/Finance/Services/StudentBalanceQuery.php`,
  `app/Domain/Finance/Data/EnrollmentBalance.php`,
  `app/Domain/Finance/Filament/Portal/Pages/MyBalance.php`,
  `resources/views/portal/my-balance.blade.php`, `lang/en/portal.php`,
  `tests/Feature/Finance/StudentBalanceQueryTest.php`,
  `tests/Feature/Portal/MyBalancePageTest.php`,
  `tests/Feature/Portal/PortalQueryCountTest.php` — owner-approved expansion
  (2026-10-10), seam: show localized course names and batch codes now;
  all catalogue reads pass through EnrollmentQueryService. Preserve existing
  unbilled-row handling, ownership and query counts as regression behavior
- `tests/Feature/Enrollment/Queries/NextUpcomingBatchTest.php`,
  `tests/Feature/Enrollment/Queries/CertificateIssuanceQueueTest.php`,
  `tests/Feature/Enrollment/Queries/AssignedHoursPerUserTest.php`,
  `tests/Feature/Finance/Queries/BatchOutstandingTest.php`,
  `tests/Feature/Finance/Queries/StudentsOwingMoneyTest.php`,
  `tests/Feature/Finance/Queries/RevenuePerCourseTest.php`,
  `tests/Feature/Finance/Queries/ChargeAgeingTest.php` — one file per query

`tests/Feature/Portal/PortalRowIsolationTest.php` remains unchanged: the T05
portal seam adds labels but does not replace its existing ownership coverage.

**Does**

Read queries the design's screens need. None introduces business logic: the rule
already lives in a model, an enum or a service, and only the selection is new.

- **`Batch::isOverCapacity()` and `hasHourMismatch()`** are PHP predicates
  evaluated after rows load. Add scopes expressing the same comparison in SQL.
  **The predicate stays the single definition** — a test runs both over the same
  fixture, so a drift fails.
- **The next upcoming batch for each course** (the owner's rule for T12): among
  the course's batches with status `planned` and `start_date` on or after the
  centre-local today, the earliest `start_date`, ties broken by lowest `id`.
  An inactive course has no card. Price comes from
  `PricingService::priceForBatch()` and hours from `effective_total_hours` —
  **neither is re-derived**. One query for every card, not one per card. With no
  qualifying batch the result is null, and T12 renders the fallback.
- **The certificate issuance queue**: enrollments with status completed and no
  current certificate. An outstanding balance does not exclude a row.
- Batch-level outstanding total; an "owes money" filter for the students list;
  the ageing chip per charge row; revenue per course; assigned batches and hours
  per user; course and batch names on portal balance rows.

**Done when**

Every scope is tested against its predicate on one fixture · the next-batch rule
is tested at its edges — a batch starting today counts, one that started
yesterday does not, a cancelled or active batch never counts, two on one date
pick the lower id, and the day boundary is the centre's, with "now" set across
UTC midnight · each query's statement count does not grow with row count ·
`composer verify` green.

### Task 6 — The N+1s the design would otherwise ship
**Owner: Claude · `p4/t06-batching` · depends on T01**

**File scope**
- `app/Domain/Enrollment/Filament/Resources/StudentResource.php`
- `app/Domain/Enrollment/Filament/Resources/BatchResource/RelationManagers/EnrollmentsRelationManager.php`
- `app/Domain/Enrollment/Filament/Resources/CourseResource.php`
- `app/Domain/Finance/Filament/Resources/PaymentResource.php`
- `tests/Feature/Performance/ListQueryCountTest.php` — new

**Does**

Four per-row calls the lists would make once per row: per-student balance in the
students list and the batch roster, tender chips per payment, enrolled-student
and batch counts per course. **Data loading only** — no column is restyled here;
T16b and T16c own the look.

P3.5-T04's lazy-loading guard only arms on multi-row hydrations, so a test that
loads one record proves nothing.

**Done when**

Each list's statement count is asserted at two row counts and does not grow ·
the measurement is recorded in the PR, not estimated · `composer verify` green.

---

## Wave 3 — The new admin surfaces

### Task 7 — Finance hub
**Owner: Claude · `p4/t07-finance-hub` · depends on T04**

**File scope**
- `app/Domain/Finance/Filament/Pages/FinanceHub.php` — discovered by the existing
  `discoverPages` line, so the panel provider is not touched
- `resources/views/filament/finance/finance-hub.blade.php`
- `lang/en/finance-hub.php`
- `tests/Feature/Finance/FinanceHubTest.php`

**Does**

A landing page tiling finance screens and reports that already exist, expenses
included. Every tile links to something built. A tile appears only when the
viewer may open its destination, using the destination's own authorization, not
a copy of it.

**Done when**

Each tile's visibility is tested with an actor holding exactly the destination's
permission and one holding one fewer — never as super admin · a viewer with no
finance permission cannot open the hub · no tile links to a route that does not
exist, asserted by resolving every tile's URL · `composer verify` green.

### Task 8 — Export history
**Owner: Claude · `p4/t08-export-history` · depends on T07**

**File scope**
- `app/Domain/Finance/Filament/Pages/ExportHistory.php`
- `resources/views/filament/finance/export-history.blade.php`
- `lang/en/export-history.php`
- `tests/Feature/Finance/ExportHistoryTest.php`

**Does**

Filament stores `Export` rows for every queued XLSX report, and nothing surfaces
them. Staff see their own exports — queued, ready, failed — instead of relying on
a notification they may have missed.

**It lists and links; it does not serve.** Each ready row links to the existing
`ReportXlsxDownload` route, which already re-checks the export permission on
every request. The task first records whether a queued PDF leaves a row anywhere:
if it does, PDFs are listed the same way; if not, the page says it lists
spreadsheet exports and no new table is added without the owner's approval.

**Done when**

A user sees only their own rows · a ready row's link is refused for a user whose
export permission was removed after queueing, proving the re-check is the
download route's and not the list's · a failed row offers no link ·
`composer verify` green.

### Task 9 — The certificate issuance queue
**Owner: Claude · `p4/t09-certificate-queue` · depends on T05**

**File scope**
- `app/Domain/Enrollment/Filament/Pages/CertificateIssuanceQueue.php`
- `app/Providers/Filament/AdminPanelProvider.php` — **one `discoverPages` call**
  for `Domain/Enrollment/Filament/Pages` (seam, after T03)
- `resources/views/filament/enrollment/certificate-issuance-queue.blade.php`
- `lang/en/certificates.php` — the queue's keys, appended
- `tests/Feature/Enrollment/CertificateIssuanceQueueTest.php`

**Does**

One list: completed enrollments with no current certificate, read through T05's
query. Each row links to the existing issue action. **An outstanding balance
never blocks issuing**, and the queue must not imply it does — a row with a
balance looks like any other.

**Done when**

A completed enrollment without a certificate appears, and disappears once one is
issued · a student owing money appears and is issuable · the page is visible
only with the issue permission, tested with exact and one-fewer actors ·
`composer verify` green.

### Task 10 — Charts on the reports
**Owner: Claude · `p4/t10-report-charts` · depends on T04 and T05**

**File scope**
- `app/Domain/Finance/Filament/Widgets/` — one chart widget per report that gets one
- `app/Domain/Finance/Filament/Pages/Reports/RevenueReportPage.php`,
  `ProfitReportPage.php`, `WageCostReportPage.php`, `PaymentMethodReportPage.php`
  — header widgets only (seam, before T16d)
- `lang/en/reports.php` — chart keys, appended
- `tests/Feature/Finance/Charts/`, one file per widget

**Does**

Each chart plots what its report already returns — it calls the report, never a
query of its own. Four reports get one; the outstanding, daily tender and payment
history reports do not, because a chart of a single date or one student's rows
says nothing the table does not. **A chart that implies a trend the rows do not
support is worse than no chart**: a period with no data plots as zero only when
zero is true, and the profit chart's series is the report's `profit` key, so it
cannot restate the definition differently.

**Done when**

Each widget's series equals its report's output for the same period, asserted
against hand-computed values · a negative profit month plots negative, not
clamped · `composer verify` green.

---

## Wave 4 — The public site

### Task 11 — Public site foundation
**Owner: Claude · `p4/t11-public-foundation` · depends on T01 and T19**

**File scope**
- `routes/web.php` — the public group (seam, first); `/` moves into it and
  `welcome.blade.php` is replaced
- `app/Providers/AppServiceProvider.php` — the `public-site` rate limiter (seam, first)
- `resources/views/layouts/public.blade.php` — new (seam, first)
- `resources/views/public/partials/` — header, footer, navigation
- `resources/views/welcome.blade.php` — deleted
- `resources/css/public.css`, `vite.config.js` (seam, first)
- `lang/en/public.php`
- `app/Support/PublicRoute.php` — new
- `tests/Feature/Public/PublicGroupTest.php`, `PublicSessionlessTest.php`,
  `PublicRouteTest.php`

**Does**

The layout, the route group and its middleware.

**Which routes are sessionless, and how.** Every route in `routes/web.php`
receives the `web` group today, so "no session for anonymous visitors" is a
property to be built, not assumed:

- **The public group** — home, about, programmes, contact, the publications index,
  an article page and an article download — runs `withoutMiddleware()` for
  `StartSession`, `ShareErrorsFromSession`, `PreventRequestForgery` and
  `AddQueuedCookiesToResponse`. These are **GET-only** pages. They contain no
  form, no `@csrf` and no `session()` or `old()` call; a test asserts every route
  in the group is GET, and that a response from it carries no `Set-Cookie`.
- **Not sessionless, and untouched:** the verifier group (its form posts and
  needs CSRF), the private-file group, both Filament panels and their login
  pages. A test asserts each of them still issues a session cookie and still
  refuses a POST without a CSRF token — the other side of the same property.
- **Contact is links, not a form.** A contact form would need a session, CSRF,
  spam handling and somewhere for messages to go; none of that is scoped.
- With no session, nothing on these pages can know who is signed in, which is the
  point: the public surface never varies by visitor, so it can be cached by any
  layer in front of it. **The application does not add its own response cache
  in phase 4** — with no host chosen, a cache layer is a deployment decision.

The `public-site` limiter is keyed by IP and applied to the whole group.

**Every public link goes through `PublicRoute`, from the first commit.** T18
will add Arabic routes named `ar.` plus the English name. Reusing the English
names would overwrite the English URL lookup, so the names must differ. A bare
`route('public.home')` in a view would then send an Arabic visitor back to
English on the first click.

- `PublicRoute::url($name, $parameters)` returns
  `route("{$locale}.{$name}")` when the request's locale is not English, and
  `route($name)` when it is.
- If the localised route does not exist, it **throws** rather than falling back.
  A silent fallback is exactly the defect this exists to prevent.
- Until T18 the locale is always English, so it behaves as `route()`; T18
  never has to edit it.
- `PublicRouteTest` scans `resources/views/public/` and the public layout and
  fails on a bare `route(`, so T12, T13 and T15 inherit the rule without
  restating it.

**Done when**

The two tests above pass, including the probe that adds a POST route to the
public group and must fail the GET-only assertion · `PublicRouteTest` resolves an
`ar.`-named route under an Arabic locale, throws for a missing one, and fails on
a planted bare `route(` in a public view · the layout uses logical CSS
properties only · `composer verify` green.

### Task 12 — Home, about, programmes, contact
**Owner: Claude · `p4/t12-public-pages` · depends on T05 and T11**

**File scope**
- `app/Http/Controllers/Public/PageController.php`, `ProgrammeController.php`
- `routes/web.php` — routes inside the public group (seam, after T11)
- `resources/views/public/home.blade.php`, `about.blade.php`,
  `programmes/index.blade.php`, `programmes/show.blade.php`, `contact.blade.php`
- `lang/en/public.php` — appended
- `tests/Feature/Public/ProgrammesTest.php`, `PublicPagesTest.php`

**Does**

The programme cards read T05's next-upcoming-batch query: name, description,
hours, price and start date. **Price, start date, hours and description are
public; seats are not**, and nothing on the page reads capacity or enrollment
counts. A course with no upcoming batch shows its name, description and hours,
**no date and no price**, and a contact call to action — a price with no batch
behind it is a promise the centre has not made.

**Done when**

A card's price and hours equal `PricingService` and `effective_total_hours` for
the chosen batch, asserted against hand-computed fixtures including a batch that
inherits the course price · the fallback renders with no price and no date · no
query on the page touches `enrollments` or reads `capacity`, asserted from the
query log · an inactive course has no card · `composer verify` green.

### Task 13 — Publications, public
**Owner: Claude · `p4/t13-publications-public` · depends on T03 and T11**

**File scope**
- `app/Http/Controllers/Public/PublicationController.php`,
  `PublicationDownloadController.php`
- `routes/web.php` — routes inside the public group (seam, after T12)
- `app/Providers/AppServiceProvider.php` — the `publication-download` limiter (seam, after T11)
- `resources/views/public/publications/index.blade.php`, `show.blade.php`
- `lang/en/publications.php` — public keys, appended
- `tests/Feature/Public/PublicationsIndexTest.php`, `PublicationDownloadTest.php`

**Does**

The index with search by title, topic, author and description; topic chips;
sorting by newest or most downloaded; the article page; and the download route.

**Every download re-reads publication state.** The route resolves the article by
slug **with `published_at` not null in the same query**, streams the file from the
private disk, and calls `ArticleDownloadCounter::increment()`. An unpublished slug and an
unknown slug return the same 404. There is no other way to reach the bytes: the
file has no URL, and the route is the only reader.

**Done when**

A published article downloads; **the same URL returns 404 after the article is
unpublished**, and the counter does not move · an unknown slug and an unpublished
one return identical responses · the download limiter refuses past its limit ·
search matches each of the four fields and nothing unpublished ever appears in
the index · `composer verify` green.

### Task 14 — The verifier, restyled
**Owner: Claude · `p4/t14-verifier-restyle` · depends on T19 (it shares no file with T11)**

**File scope**
- `resources/views/verify/form.blade.php`, `show.blade.php`, `not-found.blade.php` (seam, first)
- `lang/en/verify.php` — appended
- `tests/Feature/Verification/VerifierRestyleTest.php`

`VerifyCertificateController.php`, the verifier's routes,
`VerificationDisclosureTest.php` and the public layout are **not** in scope.

**Does**

A restyle of the three verifier pages, inheriting two properties rather than
re-deciding them, as stated under *One precision this plan must not lose*.

**The pages stay standalone Blade with inline CSS and no script**, which design
§7.4 requires and `VerificationDisclosureTest` enforces: it rejects every
executable or embedding element, and every external origin, on all three pages.
So the verifier does **not** adopt T11's public layout or any Vite entry. It
takes the design's look in its own inline styles, under the logical-property
rule, and its header links back to the public site by URL.

**The "enter a reference" prompt is the input's native `required` attribute**:
the browser refuses an empty submit before anything is sent, with no script. A
client that bypasses it posts an empty form and still gets the uniform miss. The
page stays on the full `web` stack because its form posts.

Contact hours come off the result. The projection is named field by field and
stays that way.

**Done when**

The existing verifier suite passes **unchanged, `VerificationDisclosureTest`
included** · the form's input carries `required` · a blank, a malformed and an
unknown reference produce byte-identical bodies, asserted directly · a revoked
certificate shows its revocation date · no contact-hours field renders ·
`composer verify` green.

### Task 15 — Portal sign-in from the public site
**Owner: Claude · `p4/t15-portal-entry` · depends on T11**

**File scope**
- `resources/views/public/partials/header.blade.php` — the link (seam, after T11)
- `lang/en/public.php` — appended
- `tests/Feature/Public/PortalEntryTest.php`

**Does**

A link to the student panel's existing login page. **Not a form**: the public
pages are sessionless, so a form posted from them would carry no CSRF token, and
a second sign-in path is exactly what this phase should not add. The link's copy
says a student with no email cannot be issued a login.

**Done when**

The link resolves to the student panel's login route by name · no form exists on
any public page, asserted across the group · `composer verify` green.

---

## Wave 5 — The admin revamp

Round 1 had this as one task over roughly twenty screens. It is five, split by
domain so each PR is reviewable and each carries the contract rules that belong
to its screens. **T16a lands first; T16b–T16e then run one at a time**, because
each restyles against T16a's theme and none may edit it.

The contract's rules are the acceptance criteria. **A revamp that makes any of
them read differently has broken the system while passing its own tests**, so
each sub-task asserts that its screens still say what the code enforces.

### Task 16a — Theme and shell
**Owner: Claude · `p4/t16a-admin-theme` · depends on T07–T10 and T19**

**File scope**
- `resources/css/filament/admin/theme.css` — new; `vite.config.js` (seam, after T11)
- `app/Providers/Filament/AdminPanelProvider.php` — theme, colours, navigation
  groups (seam, after T09)
- `app/Filament/Pages/Dashboard.php` — new, replacing the stock dashboard registration
- `app/Filament/Pages/PasswordChange.php`, `resources/views/filament/pages/password-change.blade.php`
- `lang/en/navigation.php` — new
- `tests/Feature/Admin/ThemeShellTest.php`

**Done when**

Every existing resource and page is still reachable from the navigation, asserted
by listing the panel's registered pages · the theme stylesheet uses logical
properties only · `composer verify` green.

### Task 16b — Students, courses, batches, enrollments, certificates
**Owner: Claude · `p4/t16b-admin-enrollment` · depends on T16a**

**File scope**
- `app/Domain/Enrollment/Filament/Resources/` — all four resources, their `Pages/`
  and `RelationManagers/`
- `app/Domain/Enrollment/Filament/Pages/CertificateIssuanceQueue.php` and its view
- `lang/en/enrollment.php`, `lang/en/certificates.php` — appended
- `tests/Feature/Admin/EnrollmentRevampContractTest.php`

**Contract rules asserted:** over-capacity enrolment is flagged, never blocked ·
an outstanding balance never blocks a certificate · a student with no email gets
no portal login.

### Task 16c — Money screens
**Owner: Claude · `p4/t16c-admin-finance` · depends on T16a**

**File scope**
- `app/Domain/Finance/Filament/Resources/` — Charge, Discount, Payment, PayrollRun,
  StaffCompensation, ExpenseCategory, Expense, with their `Pages/`
- `app/Domain/Finance/Filament/Pages/EnrollAndCollect.php`,
  `resources/views/filament/finance/enroll-and-collect.blade.php`
- `lang/en/` — `charges.php`, `payments.php`, `payroll.php`, `pricing.php`,
  `collect.php`, `expenses.php`, appended
- `tests/Feature/Admin/FinanceRevampContractTest.php`

**Contract rules asserted:** a second installment against one bill is refused ·
tender totals must equal the amount collected · pricing is written only through
its Actions · no money field uses `->numeric()`, whose float cast this project has
already paid for.

### Task 16d — Reports, finance hub, export history
**Owner: Claude · `p4/t16d-admin-reports` · depends on T16a**

**File scope**
- `app/Domain/Finance/Filament/Pages/Reports/*` (seam, after T10)
- `app/Domain/Finance/Filament/Pages/FinanceHub.php`, `ExportHistory.php`, their views
- `resources/views/finance/reports/page.blade.php`
- `lang/en/reports.php` — appended
- `tests/Feature/Admin/ReportsRevampContractTest.php`

**Contract rules asserted:** no export is an instant download · export permission
is re-checked at download · every chart still plots its report's own figures ·
the profit definition on screen matches §8.

### Task 16e — Staff, roles, activity log
**Owner: Claude · `p4/t16e-admin-staff` · depends on T16a**

**File scope**
- `app/Domain/Staff/Filament/Resources/` — Activity, Role, StaffProfile, User, with
  their `Pages/`, `RelationManagers/` and `Concerns/`
- `lang/en/staff.php`, `lang/en/activity.php` — appended
- `tests/Feature/Admin/StaffRevampContractTest.php`

**Contract rules asserted:** the last super admin cannot be deactivated, demoted
or deleted · the activity log offers no delete or edit action to any role,
including super admin.

**Done when, for each of T16b–T16e**

Every listed contract rule has an assertion that the restyled screen still
enforces it, tested with an exact-permission actor and one with one fewer —
never super admin · the existing suite for those screens passes unchanged · no
physical CSS property is introduced · `composer verify` green.

---

## Wave 6 — Arabic and RTL, last

### Task 17 — The Arabic catalogues
**Owner: Claude · `p4/t17-arabic-catalogues` · depends on T16b–T16e**

**File scope**
- `lang/ar/*.php` — every catalogue, existing and new
- `lang/en/activity.php` (seam, last) and `lang/ar/activity.php` — the `activity.field.*` group
- `app/Domain/Staff/Filament/Resources/ActivityResource.php` — `describeProperties()`
  and `describeChanges()` only (seam, after T16e)
- `tests/Feature/Localisation/CatalogueParityTest.php`,
  `ActivityFieldTranslationTest.php`

**Does**

Every `lang/ar/` counterpart, which has shipped empty since commit one precisely
for this. Composite strings go through keys with their own separators and
ordering — a lesson this project already paid for.

**It also closes the limitation spec §13 hands to this phase**: audited field
names render untranslated. Keys alone cannot fix that, because the code never
looks them up — `ActivityResource::describeProperties()` interpolates the raw
property `$key` and `describeChanges()` the raw `$field`. T17 routes both through
`activity.field.*`. A field missing from the group falls back to its raw name, as
today, so a gap shows as a visible English identifier rather than an error.

§13 is explicit that this closes *as part of* the Arabic work, not after it.

**The Arabic wording is a translator's decision, not a developer's.** This task
builds the catalogues and the parity test; the owner arranges review of the
Arabic itself before T18 merges.

**Done when**

`CatalogueParityTest` proves every English key has an Arabic counterpart and
fails on a planted missing key · every audited column has an `activity.field.*`
entry, derived from the models' audited attributes rather than a hand list ·
**behaviourally**: with the locale set to `ar`, a logged change and a logged
property render their Arabic field names through both describers, asserted
against a sentinel Arabic value planted in the test's catalogue, not against the
real copy, and the assertion fails if either describer goes back to
interpolating the raw name · `composer verify` green.

### Task 18 — RTL and locale switching
**Owner: Claude · `p4/t18-rtl` · depends on T17**

**File scope**
- `resources/css/app.css`, `resources/css/public.css`,
  `resources/css/filament/admin/theme.css` (seam, last)
- `resources/views/layouts/public.blade.php` (seam, last)
- `resources/views/verify/*` — direction, and the three `route()` call sites:
  `form.blade.php`'s action, and the "verify another" links in `show.blade.php`
  and `not-found.blade.php` (seam, after T14)
- `app/Http/Controllers/VerifyCertificateController.php` — the one redirect in
  `submit()` (seam)
- `resources/views/public/partials/header.blade.php` — the locale-carrying portal link (seam, last)
- `routes/web.php` — the `/ar` prefix on the public group, and `/ar/` verifier
  routes inside the existing verifier group (seam, last)
- `app/Http/Middleware/SetPublicLocale.php`, `SetGuestPanelLocale.php` — new
- `app/Providers/Filament/AdminPanelProvider.php` (seam, last),
  `StudentPanelProvider.php` (seam) — the guest-locale middleware
- `tests/Feature/Localisation/DirectionTest.php`, `PublicLocaleTest.php`,
  `VerifierLocaleTest.php`, `GuestLoginLocaleTest.php`, `LogicalPropertiesTest.php`

**Does**

Direction and the locale switch, over every surface the bilingual pass covers.

- **The public site carries its locale in the URL** (`/ar/...`), because it is
  sessionless: there is no session to remember a choice in, and a URL is
  cacheable and indexable.
- **The verifier gets `/ar/verify/certificates` routes inside its existing
  group**, under the same disclosure headers and the same named limiter, plus
  `SetPublicLocale`.
  - **The names are distinct**: `ar.verify.certificates.form`, `.submit` and
    `.show`. The English names keep resolving to the unprefixed URLs.
  - **Every link and redirect resolves through T11's `PublicRoute`**: the form's
    action, both "verify another" links, and the redirect in
    `VerifyCertificateController::submit()`. Without that, every one of them
    would return an Arabic visitor to the English pages. The controller's
    lookup, its normalisation and the uniform miss do not change. Only the name
    its redirect resolves does.
  - **One limiter budget covers both prefixes.** The limiter is keyed by client
    IP alone, not by route, so a second language cannot double the guess rate.
- **Both login pages honour a guest locale.** The Arabic site's portal link adds
  `?locale=ar`. `SetGuestPanelLocale` runs in both panels' middleware — **not**
  `authMiddleware`, where the login page would never see it — and acts only for
  guests. It validates the value against `SetLocale::SUPPORTED`, stores it in
  the login page's session, and applies it to that page's Livewire update
  requests as well, so a validation error re-renders in Arabic too. See
  ENGINEERING's notes on persistent middleware before wiring it. A signed-in user
  keeps `users.locale` through the existing `SetLocale`, and the guest value
  never overrides it.

**The conformance check that matters**: logical CSS properties have been enforced
since commit one, but the handoff's CSS is not this repository's and must be
checked before adoption. `LogicalPropertiesTest` scans `resources/css/` and the
Blade views for physical properties and fails on a planted `margin-left`.

**Done when**

Both directions render every public page, all three verifier pages, both login
pages and every panel screen, with `dir` set correctly · an `/ar/` public URL
renders Arabic and an unprefixed one English, with no session involved · the
`/ar` verifier still passes `VerificationDisclosureTest`'s checks, and its blank,
malformed and unknown misses are byte-identical to each other · **the Arabic
journey stays Arabic**: the form at `/ar/verify/certificates` posts to the `/ar`
submit URL; a valid reference redirects to `/ar/verify/certificates/{reference}`;
and the "verify another" links on the `/ar` result and `/ar` miss pages both
point under `/ar` · **the English journey stays unprefixed** through the same
four steps · the `/ar` and unprefixed verifier routes carry identical middleware
lists, asserted by comparing the routes, not by reading the file · requests across
both prefixes draw on one limiter budget, and the test fails if they do not · a
guest's `?locale=ar` login renders Arabic, an unsupported value falls back to
English, and a signed-in user's `users.locale` wins over a guest value · the
logical-properties scan passes and its planted probe fails · `composer verify`
green.

---

## Deferred

| Item | Why not now |
|---|---|
| **Certificates due this month** | Dropped by the owner, 2026-10-08. No data defines when a certificate falls due |
| **Contact form** | Needs a session, CSRF, spam handling and a destination; the public pages are sessionless and link instead |
| **Application response cache** | A deployment decision, waiting on the host |
| **Search latency attribution** | P3.5-T13's finding, measurement only |
| **Report latency attribution** | P3.5-T13's finding, measurement only |
| **Receipt queue-drain profile** | P3.5-T13's finding, measurement only |
| **Online payment from the portal** | `RecordPaymentAction` is staff-only. §12 keeps card payments out |
| **Production SLO targets** | Real targets need a chosen host and a measurement on it |

---

## What this plan does not cover

From §12, recorded so they do not reappear as assumptions: multi-tenancy,
attendance tracking, grades, timetabling, online card payments, a mobile
application, public student self-registration, and certificate template design or
printing.

**Hosting is still undecided.** A public site means real traffic, TLS and search
engines, and none of this phase's work chooses a host. That decision gates
deployment, not development.
