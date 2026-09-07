# Performance Audit — Phase 3 Baseline

**Audit date:** 2026-09-06
**Frozen commit:** `24073850bb30fa776907b814b30769e1f0347d35`
**Scope:** read-only performance assessment; implementation is deliberately deferred

## Outcome

No Critical or High performance defect was confirmed for the current medium,
single-centre deployment. Two Medium issues were measured.

The User resource has a real per-row authorization N+1. Rendering a populated
10-row page executed 59 statements, of which 56 were repeated super-admin role
and pivot lookups used by action visibility checks. Pagination bounds the cost,
and the authoritative Actions must retain their fresh authorization reads, but
the list's UX checks should not need dozens of repeated round trips.

The other Medium finding is the Outstanding Aged report query. At 4,000 unpaid
charges it takes roughly 0.24–0.26 seconds locally, hydrates all 4,000 rows, and
executes the allocation aggregate twice per charge through dependent
subqueries. The query also joins and selects catalogue data the report does not
use. This is a Medium improvement target, not a current incident.

Two Low findings should be kept bounded as the database grows: the Student
Payment History filter builds every student into one options array, and report
exports freeze the entire result set into an in-memory queue payload. The latter
is required for historical correctness; the concern is its transport and memory
shape, not the snapshot rule itself.

The portal's known N+1 seams are already guarded. The focused query-count suite
passed 14 tests / 42 assertions and proves that My Enrolments and My Balance use
a fixed number of statements as one student's history grows from one to twenty
enrolments. `StudentBalanceQuery::forStudent()` remains one statement.

## Method and limits

The audit combined:

- static inspection of Filament resources, relation managers, pages, filters
  and actions; portal and public controllers/pages; Blade views; Eloquent
  relationships and scopes; reports, exports, jobs and scheduled commands;
  migrations and indexes;
- direct query-count, duplicate-query, elapsed-time and memory measurements;
- MySQL `EXPLAIN` for the strongest query candidate;
- existing real-request query-growth tests for the portal flows.

Measurements ran on Windows with PHP 8.4.13, Laravel 13.30.1 and MySQL 8.4.6.
They are local comparative measurements, not production latency promises.

Every diagnostic write ran with `APP_ENV=testing`, the `mysql` connection and
the exact database `training_center_test`, after acquiring the repository's
machine-wide `Tooling\SerialLock`. The database identity was checked before
setup. No developer database was written.

The factory-built primary dataset reached:

| Table | Rows |
|---|---:|
| Students | 2,000 |
| Enrolments | 4,000 |
| Charges | 4,000 |
| Activity log | 10,013 |
| Payments / tenders / allocations | 0 |

The requested 8,000-payment dataset was not reached. Building fully valid
payment facts would have held the shared test lock disproportionately long, so
this report does not present the empty-allocation timings as payment-heavy
evidence. The first independent reproduction used the same model factories with
events disabled to shorten setup; it confirmed the charge result at 1,000 and
4,000 rows. Activity logging is unrelated to the measured report read.

Not every Filament interaction was driven through a browser. Findings without
direct runtime evidence are kept in a separate candidate section.

## Measurements

### Outstanding Aged report growth

| Unpaid charges | Queries | Duplicate statements | Three runs (ms) | Median |
|---:|---:|---:|---|---:|
| 1,000 | 1 | 0 | 65.52, 61.39, 62.83 | 62.83 ms |
| 4,000 | 1 | 0 | 259.20, 259.81, 260.10 | 259.81 ms |

Four times as many rows took 4.14 times as long. This dataset had zero
allocations, the cheapest possible case for both dependent allocation
subqueries, so the result is a lower bound and must not be projected as the
payment-heavy growth trend. Query count is constant, but database work, object
hydration and PHP bucketing remain row-proportional. A separate 4,000-row run
measured 236.74 ms and an 8 MiB allocator increase.

Building the presentation-ready `ReportDataset` for the same 4,000 rows took
335.75–369.98 ms across two runs. An independently constructed snapshot encoded
to 846,724 bytes of JSON before Laravel queue metadata or serialization
overhead, and one run reached a 20 MiB process peak increase. PHP allocator
deltas are noisy, so the payload size and scaling shape are more useful than a
single memory number.

### Student selector growth

The exact `Student::withTrashed()->orderBy(...)->get()->mapWithKeys(...)` shape
took 12.63 ms for 100 students. At 2,000 students it took 60.13–92.62 ms and
caused a 6 MiB allocator increase. This excludes browser parsing, DOM rendering
and Livewire payload costs.

### User resource query growth

A temporary Pest probe drove the real `ListUsers` Livewire component after
priming the permission cache:

| Users in database | Rows rendered | Total queries | Roles eager-load | Repeated rank lookups | Render time |
|---:|---:|---:|---:|---:|---:|
| 2 | 2 | 11 | 1 | 8 | 29.66 ms |
| 21 | 10 | 59 | 1 | 56 | 68.15 ms |

The populated page contained one eager-load query for displayed roles, followed
by 28 identical super-admin role-id lookups and 28 pivot `exists` lookups. The
query counts repeated exactly across two clean diagnostic runs. Render timing is
one local sample; the statement growth is the durable evidence.

## Confirmed findings

### Medium — User table action visibility repeats fresh rank queries per row

**Affected flow:** Admin → staff accounts (`/admin/users`).

`app/Domain/Staff/Filament/Resources/UserResource.php:168` registers Reset
Password and Delete row actions. Their `visible()` callbacks call the User
policy for each displayed record. `UserPolicy::resetPassword()` and
`UserPolicy::delete()` both reach `UserPolicy::outranks()` at
`app/Domain/Staff/Policies/UserPolicy.php:159`.

`User::isSuperAdmin()` at `app/Models/User.php:160` deliberately resolves the
super-admin role id and performs a fresh pivot `exists` query. Repeating that
correct security read from several visibility checks produces the measured
per-row query growth. The populated 10-row page spent 56 of 59 statements on
those two fingerprints.

**Impact:** 59 local statements rendered in 68.15 ms, so this is not High for a
same-host database and a paginated internal page. It is nevertheless the only
confirmed request-path N+1 and adds database round trips whenever staff accounts
are browsed. Student portal accounts share the users table, so the page will
normally remain full as adoption grows.

**Security constraint:** do not "fix" this by making `User::isSuperAdmin()` read
the model's loaded `roles` relation. The fresh id-based lookup exists because a
stale in-memory relation previously bypassed the last-super-admin protection.
The write Actions must remain self-authorizing against current database state.

**Future direction:** add a real list query-count regression, then first measure
request-scoped memoization of only `Role::superAdminId()`. Never cache the User
pivot result: the Action's binding authorization must remain fresh. Prove that
the role-id cache cannot leak across HTTP requests, queue jobs or long-lived
workers, and account for bootstrap and seeding paths. The normal request path
protects the canonical super-admin role from mutation, but trusted setup paths
still make cache lifetime a requirement rather than an assumption.

If the remaining pivot lookups still justify work, give the resource a
page-local/batched rank projection for UX visibility while leaving the Actions
self-authorizing against current state. Avoid duplicating policy rules in the
UI. Mutation-prove that removing the projection regresses query count and that
changing roles before an Action still causes the Action to use current state.

### Medium — Outstanding Aged repeats balance work and carries unused catalogue joins

**Affected flow:** Admin → financial reports → Outstanding Aged, including the
dataset frozen for XLSX and PDF export.

`app/Domain/Finance/Reports/OutstandingAgedReport.php:145` selects the canonical
`ChargeBalance::outstandingSql()` expression, then repeats it in the `WHERE` at
line 157. At line 159 it calls `EnrollmentQueryService::joinCatalogueTo()` with
the course dimension even though the result uses only
`enrollments.student_id`.

`app/Domain/Enrollment/Services/EnrollmentQueryService.php:312` therefore joins
`enrollments`, `batches` and `courses` and selects course id/code. The report
discards the course columns.

At 4,000 rows MySQL reported:

- a full scan of all 4,000 charges;
- the catalogue path through courses, batches and enrolments;
- `Using temporary; Using filesort` on the chosen primary join order;
- two `DEPENDENT SUBQUERY` instances, one for the selected outstanding amount
  and one for the outstanding predicate;
- `payment_allocations_charge_id_index` for each allocation lookup and primary
  key lookups into payments.

The existing `charges.due_date` index was not chosen for this shape. That does
not by itself prove a new index is needed.

**Impact:** acceptable today, but this was the slowest measured read and grows
with every charge. Real allocations add work inside both dependent subqueries;
the size of that increase is still unmeasured.

**Future direction:** publish the narrowest Enrollment-domain query helper that
adds only the enrolment/student identity required here, remove the unused
catalogue projection, then benchmark a realistic payment/allocation dataset.
Only after that evidence should the implementation choose between reusing one
aggregate result, changing the query shape, or adding a composite index.
`ChargeBalance` must remain the sole balance definition and no balance may be
stored.

### Low — Student Payment History hydrates every student into filter options

**Affected flow:** Admin → Student Payment History report.

`app/Domain/Finance/Filament/Pages/Reports/ReportPage.php:134` builds the filter
with an unbounded `Student::withTrashed()->get()->mapWithKeys(...)`. It selects
`students.*` although the label needs only id, student code, first name and last
name. `getViewData()` includes the options during normal rendering, and
`displayedFilters()` calls `filterFields()` again while an export snapshot is
captured.

**Impact:** 60–93 ms and 6 MiB at 2,000 students is Low today, but all options
also enter the Livewire/browser surface. The cost grows with the student
register even when no student is selected.

**Future direction:** use Filament's bounded, server-side searchable select and
a selected-option label resolver. Preserve `withTrashed()` so historical
students remain reportable, and select only the four label columns.

### Low — complete report snapshots are materialized in requests and queue payloads

**Affected flow:** report display, XLSX preparation and PDF generation.

`app/Domain/Finance/Exports/ReportDataBuilder.php:100` maps the complete result
into a `ReportDataset`. `ReportSnapshot::toArray()` includes every row.
`PrepareReportCsvExport.php:56` builds the complete CSV in a temporary object
before writing it, while `GenerateReportPdfJob.php:61` reconstructs the complete
snapshot and renders one full HTML/PDF document within its 60-second timeout.

**Impact:** the measured 4,000-row snapshot was about 827 KiB of JSON, before
queue overhead. Current timings are acceptable, so this remains Low. It becomes
a queue-payload, worker-memory and timeout concern for large all-history
exports.

**Constraint:** request-time freezing is intentional and correct. Receipts and
exports are issued-document snapshots; a worker must not re-query live finance
facts and silently change what was requested.

**Future direction:** first measure serialized job payload, Redis payload,
worker peak memory and PDF duration with realistic payments. If limits are
approached, persist or stream a frozen snapshot in bounded pieces while keeping
the same historical semantics.

## Candidates requiring targeted measurement

These are code-supported suspicions, not confirmed bottlenecks.

1. **Activity-log causer options.**
   `app/Domain/Staff/Filament/Resources/ActivityResource.php:187` loads every
   `User`, including soft-deleted users, into one filter array. Portal accounts
   share the users table, so the array can eventually resemble the full student
   population. The activity table itself correctly eager-loads `causer` and has
   no list N+1. Measure after representative portal-account adoption before
   changing filter semantics.
2. **Portal enrolment history is fixed-query but unpaginated.**
   `app/Domain/Enrollment/Filament/Portal/Pages/MyEnrollments.php:135` loads the
   authenticated student's complete history with `batch.course`. That is
   reasonable for expected per-student volume and is protected by a one-versus-
   twenty query-count test. Revisit only if product usage creates unusually
   large histories.
3. **Charge Resource outstanding filtering.** The resource repeats the
   canonical correlated outstanding expression, but the table is paginated and
   documents the trade-off. Benchmark a filtered and sorted table with real
   allocations before changing it.

## Index and query-shape review

| Flow | Current shape | Assessment |
|---|---|---|
| Public certificate verification | Exact lookup on unique certificate reference | Appropriate; no concern found |
| Portal balance | Student-scoped aggregate in one statement | Protected by query-growth tests |
| Portal enrolments/certificates | Eager `batch.course` plus one certificate `whereIn` query | Protected by real-request query-growth tests |
| Outstanding Aged | Due-date index plus charge-id allocation index; full charge scan and two dependent aggregates observed | Medium investigation target |
| Revenue, tender and wage reports | SQL groups/sums and indexed date-range predicates | No N+1 or PHP row-sum issue found statically |
| Activity list | Paginated, id-descending and eager causer | List is sound; filter options remain a candidate |
| User list | Roles eager-loaded, but row action visibility performs fresh role-id and pivot-exists reads repeatedly | Confirmed per-row N+1; 59 statements for a populated page |

## Non-production lazy-loading guard

A later task should add this to `App\Providers\AppServiceProvider::boot()`:

```php
Model::preventLazyLoading(! $this->app->isProduction());
```

That is suitable for this application if introduced as a tested development/CI
guard and left disabled in production initially. An unexpected lazy load should
fail during development and tests, not turn a public production request into a
500.

The future task should:

1. enable the guard for local/testing environments;
2. run focused resource, portal and report tests;
3. mutation-prove the guard by temporarily removing a required eager load and
   observing the covering test fail with `LazyLoadingViolationException`;
4. document narrow, justified exceptions rather than disabling the guard;
5. run the complete serial gate.

This guard catches Eloquent relationship lazy loading only. It does not detect
full scans, bad join order, correlated subqueries, repeated scalar queries, or
large query-builder result sets. In particular, it will not catch the User
resource finding because `roles()->whereKey(...)->exists()` is an explicit
relationship query, not a lazy-loaded property. It complements query-count
tests and `EXPLAIN`; it does not replace them.

## Recommended implementation order

Each item should be its own scoped, independently reviewed task.

1. **Super-admin role-id lookup:** add the User resource query-count guard and
   measure request-scoped memoization of the stable role id. Prove lifecycle
   safety for requests, workers and seeders, and never cache the User pivot.
2. **Remaining User resource rank reads:** if measurement still justifies it,
   batch the list's UX-only rank facts without weakening the fresh,
   self-authorizing Actions; mutation-prove both query growth and current-state
   authorization.
3. **Outstanding report query plan:** narrow the Enrollment join, add a
   payment-heavy benchmark/test fixture, compare `EXPLAIN`, and optimize without
   duplicating `ChargeBalance`.
4. **Bounded report student selector:** server-side search, selected-option
   resolution and a narrow projection, with a 2,000-student query/payload guard.
5. **Non-production lazy-loading guard:** enable it, mutation-prove it and run
   the full serial suite.
6. **Export capacity benchmark:** measure serialized payload and PDF worker
   peak/timeout with realistic allocations, then change transport only if the
   evidence justifies it.
7. **Activity filter:** measure after portal-account adoption and bound the
   filter without losing historical staff actors.

## Verification record

```text
php artisan test --compact tests/Feature/Portal/PortalQueryCountTest.php tests/Feature/Finance/StudentBalanceQueryTest.php
PASS — 14 tests, 42 assertions
```

A temporary diagnostic Pest test (removed after measurement) rendered the real
User resource twice against clean fixtures. It reproduced 11 queries for two
users and 59 queries for a populated 10-row page; 56 populated-page statements
were the repeated rank fingerprints.

The audit introduced no application, schema, dependency or configuration
change. The only tracked deliverable is this report.
