# Phase 1 review — findings and dispositions

**Task:** P1-T15
**Review surface:** `main` at `217a380` for group 1. Groups 2 and 3 are repinned
to the tip after group 1's fixes merge; the SHA is recorded against each group
below when it is dispatched.
**Reviewers:** fresh subagents with no implementation context and no access to the
implementation session's history.

---

## Why this document exists

Fourteen tasks were each reviewed as they landed, and those reviews were
substantive. What none of them could see is the seam *between* tasks — a
permission seeded in T02 and first used in T11, a policy written in T05 against a
model that grew two columns in T07. Every defect in this project that reached a
merge did so by being invisible from inside a single task's diff.

**The dispositions matter as much as the findings.** A rejected finding with its
reasoning recorded is a question that stays answered. Without that record, the
same objection returns in phase 2 with nobody able to say why it was dismissed —
and it gets re-litigated, or worse, implemented on the second asking because
nobody remembers the first.

**Task 16 folds every rejection and deferral into the spec**, so a future reader
finds the answer where they look for it rather than in a review log they do not
know exists.

---

## Disposition rules

Every finding gets exactly one, with reasoning:

| Disposition | Means |
|---|---|
| **Fix now** | Becomes a branch `p1/t15-fix-{slug}` with a regression test **shown to fail before the fix**. A fix without a failing-first test is not accepted — it may be fixing nothing. |
| **Defer** | Names the phase it belongs to and why deferring is safe. "Phase 2 will handle it" is not a reason; "no data can be lost before phase 2 because X" is. |
| **Reject** | States the reasoning. A reviewer with no context can be wrong, and answering with reasoning rather than reflexive compliance is why triage exists. |
| **Unverified** | The reviewer could not establish it statically. Triage runs the stated experiment serially and then assigns a real disposition. |

A finding that arrives without severity, `file:line`, a concrete scenario, the
rule it violates, and the missing regression test is **sent back rather than
triaged**. Incomplete findings are where false approvals come from.

---

## Group 1 — Security

**Dispatched:** 2026-07-29 against `217a380`
**Scope:** authentication, Shield/Spatie wiring, `User` and `Role` policies, the
escalation invariants, the Action boundary, staff-account UI.

**Status:** running.

### Findings

_Awaiting the reviewer's report._

| # | Severity | File:line | Summary | Disposition | Reasoning |
|---|---|---|---|---|---|

### Unverified items

| # | Claim | Experiment | Result | Disposition |
|---|---|---|---|---|

### Gates after group 1 fixes

_To be recorded with real output: `php artisan test`, `vendor/bin/pint --test`,
`vendor/bin/phpstan analyse --memory-limit=1G`._

---

## Group 2 — Domain integrity

**Dispatched:** not yet — waits for group 1's fixes to merge
**Pinned at:** _to be recorded at dispatch_
**Scope:** staff files, students, courses, batches, instructor allocations,
enrolments — database constraints, transactions, locks, concurrency, UI
reachability.

### Findings

| # | Severity | File:line | Summary | Disposition | Reasoning |
|---|---|---|---|---|---|

### Unverified items

| # | Claim | Experiment | Result | Disposition |
|---|---|---|---|---|

---

## Group 3 — Cross-cutting operations

**Dispatched:** not yet — concurrent with group 2
**Pinned at:** _to be recorded at dispatch_
**Scope:** append-only activity log, backup and restore, localization, RTL,
hardcoded-string and CSS enforcement.

### Findings

| # | Severity | File:line | Summary | Disposition | Reasoning |
|---|---|---|---|---|---|

### Unverified items

| # | Claim | Experiment | Result | Disposition |
|---|---|---|---|---|

---

## Known context supplied to reviewers

Recorded here because it shapes what the findings can be trusted to mean.

**The permission-naming erratum.** `CLAUDE.md` illustrates the authorization rule
as `$user->can('students.delete')`. No permission of that shape exists — Shield
generates snake-case and `RolePermissionSeeder` creates `delete_user`,
`view_any_student`, `create_enrollment`, `update_assigned_batch_enrollment`. Every
reviewer is told this, because one matching the documented example literally
would flag every correct call in the codebase. **`CLAUDE.md` should be corrected
in T16** — this erratum is a workaround, not a fix.

**The documented role-check exception.** `UserPolicy::outranks()` and
`assignRole()` check the super-admin role deliberately, expressing rank rather
than ability; see the docblock at `app/Domain/Staff/Policies/UserPolicy.php:30`.
Reviewers are told it is intentional **and explicitly invited to challenge its
safety** — the instruction forbids reporting it as an unnoticed oversight, not
reporting it as unsafe. A finding that shows `isSuperAdmin()` can be fooled is
exactly what group 1 is for.

**Static review only.** All suites share one MySQL database,
`training_center_test`, and `RefreshDatabase` runs `migrate:fresh` against it.
Concurrent reviewers running tests would drop each other's schema and produce
failures belonging to nobody — which a reviewer with no context would reasonably
read as "the code is broken". Runtime verification is therefore central and
serialized.
