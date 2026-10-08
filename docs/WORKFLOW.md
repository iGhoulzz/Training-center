# Development Workflow — Claude and Codex

How the two agents divide work, isolate it, review each other, and keep documentation current.

---

## Remote status: historical, closed at the end of phase 1

> **This section is a record, not a current instruction.** Phase 1 is complete, the repository is pushed to `iGhoulzz/Training-center`, and every review from phase 2 onward happens on a real pull request. It is kept because the local-review period is part of the project's audit trail and the reasoning below explains why those branches look the way they do.

There was **no Git remote during phase 1** by decision. The GitHub repository was created once the foundation phase was done.

This changes *how* review happens, not *whether* it happens. During phase 1, steps 5 and 6 of the task lifecycle become a **local branch review**:

```bash
# Reviewer inspects the task branch against main
git diff main...p1/t04-activity-log
git log main..p1/t04-activity-log
```

The reviewing agent reads the full diff, applies the review contract below, and reports findings. The author resolves them. Only then does the branch merge into local `main`.

Once phase 1 completes and the repository is pushed:

```bash
gh repo create Training-center --private --source=. --remote=origin
git push -u origin main
```

From phase 2 onward, reviews move to real pull requests (`gh pr create`), and the review contract applies unchanged. The full phase 1 branch history is preserved by the initial push, so the local-review period remains auditable.

**The review gate itself is never optional, in either mode.** No agent merges its own work unreviewed.

---

## Phase 1 exception: Claude works alone

**Codex joins from phase 2.** Phase 1 (foundation) is implemented entirely by Claude.

The review gate still exists — it changes shape. Instead of per-task cross-review, a **fresh reviewer subagent reviews all fourteen task diffs at the end of the phase**, with no implementation context. That is Task 15 of the phase 1 plan.

Two consequences follow, and both are handled in the plan rather than left implicit:

- **Task branches are not deleted at merge time.** The reviewer needs each task's isolated diff. Branches are deleted only after Task 15 consumes them.
- **The escalation guards (Task 4) are reviewed first**, before any other diff. Tasks 5–14 build on them, so a defect there is the most expensive one to discover late.

Everything below describes the standing two-agent model, which resumes at phase 2.

---

## Roles

**Claude** — lead. Owns architecture, specs, and implementation plans. Splits milestones into tasks. Implements tasks. Reviews every Codex pull request.

**Codex** — implementer and second opinion. Takes assigned independent tasks. Reviews every Claude pull request. Called in for root-cause investigation when a bug resists diagnosis, and for a second implementation when an approach looks wrong.

Neither agent merges its own work without the other's review.

Claude implements and first-reviews through its own subagents, as described in
the next section. It remains the accountable author of every Claude task.
**Subagent review never replaces Codex's review of the pull request.**

---

## Claude's subagents: Sonnet implements, Opus reviews

**The reason is cost, not capacity.** The lead's session is the most expensive
context in the project: every turn re-reads it, and every diff the lead opens
stays in it for the rest of the session. Implementation is mostly reading and
writing files, and a cheaper model given a precise brief does it well.
Judgement — the plan, the brief, the review routing and the verdict — stays with
the lead.

| Role | Who | Defined in |
|---|---|---|
| **Lead** | Claude's main session (Opus) | `CLAUDE.md` |
| **Implementer** | Subagent, Sonnet | `.claude/agents/implementer.md` |
| **Reviewer** | Subagent, Opus | `.claude/agents/reviewer.md` |
| **Cross-reviewer** | Codex, on the PR — unchanged | `AGENTS.md` |

The lead writes plans and briefs, routes reviews, checks each finding, pushes,
opens PRs and answers Codex.

The implementer writes code and tests for one brief and commits on the task
branch. The reviewer reviews a committed range and reports findings. Neither
subagent pushes, merges, edits the plan or the spec, or talks to Codex.

Both subagent definitions preload the project's skills —
`laravel-best-practices`, `pest-testing` and `tailwindcss-development` — and
carry Boost's `search-docs`. They therefore start with this codebase's
conventions and version-correct documentation, rather than whatever the model
remembers.

### The loop

```
brief ─▶ implement ─▶ triage ─▶ review ──approve──▶ push ─▶ PR ─▶ Codex review
            ▲                       │                               │
            └──── findings ◀────────┴───────────── findings ◀───────┘
```

1. **Brief.** The lead writes it; the template is below.
2. **Implement.** Spawn `implementer` in the background, so the lead can work on
   something else meanwhile. It commits in the task worktree and returns its
   report.
3. **Triage**, without reading code. The lead checks four things:
   - `git diff --stat` stays inside the File scope;
   - the report carries real output lines;
   - every guard and authorization test has a probe listed;
   - `STATUS` is `done`.

   A report that fails any of these goes straight back. A bad report is not
   reviewed.
4. **Review**, routed by the table below.
5. **Findings go back to the same implementer** with `SendMessage`, so it keeps
   its context and pays no cold start. The lead first checks each finding
   against the code — a reviewer can be wrong — and forwards only the ones that
   stand. The loop then returns to step 3, and the re-review covers the delta
   plus the code around every changed hunk. A revision introduces new defects
   more reliably than a first draft does.
6. **Approve.** The lead pushes. The full gate belongs to the lead: the pre-push
   hook or CI, never a subagent. The lead then opens the PR, which states that a
   subagent implemented it and which reviewer passed it.

   Codex's findings re-enter the loop at step 5 in the same way.

**Round cap.** After three subagent review rounds on one task, or a finding
that survives two fix attempts, the loop stops. Escalate the model or the
person: the lead fixes that finding itself, or spawns an Opus implementer. If a
guard is defeated a third time, stop patching it and narrow the claim — name
what the check really proves, as `docs/ENGINEERING.md` describes for guards.

### Who reviews a round

| Situation | Reviewer |
|---|---|
| Touches authorization, money, concurrency or locking, the Action write boundary, file serving, a public route, or an architecture guard | **Opus reviewer, always.** The lead verifies each finding before forwarding it |
| More than ~300 changed lines, or more than 8 files | Opus reviewer |
| The lead's session is already long | Opus reviewer, so the diff stays out of the lead's context |
| Docs only, or a small diff on none of the surfaces above | **The lead, inline**: `git diff --stat`, then only the changed hunks |
| A fix round | The **same** reviewer, continued with `SendMessage`, over the delta and its surroundings. The lead reviews inline only when the fix is under ~30 lines and on no high-risk surface |

A round is reviewed once, in full, by one reviewer. A second full review of the
same round doubles the cost and finds the same things.

### Who implements

- **Sonnet implementer by default.**
- **The lead, directly**, when the change is smaller than a brief for it would
  be — roughly 30 lines — or when it fixes a finding the implementer has already
  failed twice.
- **An Opus implementer** (`model: "opus"` on the Agent call) when the task is
  mostly design under uncertainty: a race, a lock, a new guard. This should be
  rare. If the uncertainty is a design question, it belongs in the plan, not in
  a stronger implementer.
- Never Haiku for code in this repository.

**Subagents do not raise the concurrency ceiling.** Claude still actively
implements one task at a time, with one implementer on it. A task whose PR is
waiting on Codex is no longer active, so the next task's implementer may run
while that review happens.

### The brief

A subagent starts with no memory of the lead's session and no access to the
lead's notes. **Anything the task depends on is in the brief, or it does not
exist.** Point at long material by section and line, and paste only what is
short and decisive.

```markdown
TASK: P4-T03 — Publications: the domain and the admin side
WORKTREE: C:/Users/User/Desktop/Training-center-worktrees/P4-T03   BRANCH: p4/t03-publications-domain
BASE: <sha of origin/main the branch was cut from>

FILE SCOPE (exact; anything else is a question, not a change):
- …the plan's list, verbatim…

SEAMS: <file> — the one line you may add, and nothing else in it

DONE WHEN (verbatim from the plan):
- …

READ (only these):
- spec §6 lines <a>–<b>; §5 permission matrix lines <c>–<d>
- docs/ENGINEERING.md "The write boundary" (lines <e>–<f>)
- nearest sibling to copy: app/Domain/Staff/Actions/<Example>Action.php
- design: docs/design/design_handoff_training_centre/Public Site.dc.html, grep "<screen anchor>"

DECISIONS ALREADY MADE (do not re-decide):
- …

LESSONS THIS TASK NEEDS:
- …only the ones that apply, e.g. "the lazy-loading guard only arms on multi-row hydrations"…

TESTS TO WRITE: <named files>, and the probes expected to fail
COMMIT TRAILER: Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

### Token discipline

- **Reports are capped**: the implementer at 300 words, the reviewer at 500.
  When the Opus reviewer has reviewed a round, the lead reads its findings, not
  the diff.
- **Continue, don't respawn.** `SendMessage` to an existing subagent is cheaper
  than a cold start, for up to two fix rounds. After that, the subagent's own
  context costs more than a fresh brief that includes the open findings, so
  start a new one.
- **No exploratory subagents for what a single `grep` answers.** A spawn costs a
  cold start: the definition, `CLAUDE.md`, the preloaded skills and the brief,
  all before any work begins.
- **The design prototypes are large.** `Filament Revamp.dc.html` alone is over
  200 KB, so a brief names the screen and the reader greps for it. Nobody reads
  a prototype whole.
- **Visual checks are the lead's**, in the browser pane against `php artisan
  serve` run from the worktree, because subagents have no browser. Prefer
  `read_page` and `get_page_text` for structure, and take screenshots at half
  scale.

---

## The unit of work: a task

A milestone is split into tasks, and the tasks are grouped into **waves**.

**Independence is required within a wave, not across the milestone.** Two tasks running at the same time must share no files — two agents editing one file in parallel produces merge conflicts that cost more than the parallelism saved. **Sequential dependencies between waves are expected and allowed.**

This rule previously read "no shared files, no sequential dependency" and forbade dependencies outright. That standing rule proved too strict for milestones whose tasks have unavoidable dependencies: phase 2's schema must exist before anything else can be built. A rule forbidding that dependency would be either ignored or worked around by inventing artificially large tasks. The property worth protecting was always *concurrent* file isolation.

Each task has:

- An ID: `P1-T04`
- A single owner: Claude or Codex
- An explicit file scope — which paths it may touch, **including named test files**, never a bare directory
- A definition of done, including which tests must pass
- The wave it belongs to, and what it depends on

Task assignment lives in the milestone's plan document under `docs/superpowers/plans/`.

### Concurrency: one task each, at most

**At any moment Claude may actively implement at most one ready task and Codex may actively implement at most one ready task.** That is the ceiling. "Parallel" means one Claude task and one Codex task whose exact file scopes do not overlap — never three simultaneous Codex tasks.

The limit is not about capacity. Every extra concurrent task is another branch to keep current, another diff a reviewer must hold in mind, and another chance that two scopes overlap in a way nobody notices until merge. Two is reviewable; more is bookkeeping.

A wave may legitimately carry only one task, when the dependency graph leaves the other agent nothing ready. The idle agent reviews.

### Declared shared seams

A file scope says what a task **owns**. It says nothing about the registries a task **joins**, and phase 2 wave 2 proved that is where same-wave tasks actually meet.

A **seam** is a file whose content is an enumeration that grows whenever a task adds a unit of some kind — a policy registration list, a panel's discovery calls, an architecture test's allowlist, a permission seeder. No task owns it; each appends to it. Two tasks compared on their declared scopes can look perfectly disjoint and still both append to the same seam, for the same structural reason, on the same day.

**Every task declares the seams it will join, alongside the files it owns**, naming the line it expects to add. A seam discovered during implementation is raised, exactly as an unplanned file would be.

**Only one task per wave may modify a given seam.** Where two tasks would, they are not run concurrently: they are **sequenced** — the second starts from `main` after the first has merged with green CI, and rebases onto it. This costs a wave. It is worth it, and the reason is what happens instead:

> Phase 2's T2 and T5 were both Finance work, running concurrently, and each independently introduced the first Filament resource its branch knew of. Each therefore added the **same** `Domain/Finance` `discoverResources()` line, and — following the guidance then in force — a **different** policy registration apiece, which later turned out to have been redundant all along, since Laravel discovers those policies unaided. Two seams, neither declared by either task. `AppServiceProvider` conflicted loudly on the two different lines and was resolved by hand: the safe outcome. `AdminPanelProvider` took the identical line from both branches and **auto-merged it with no conflict marker**, producing two identical blocks — a clean rebase, a green suite, and Filament scanning one directory twice. Nothing in the suite asserts otherwise, so nothing would have caught it.
>
> Note which half was dangerous. The registrations that *differed* conflicted and got human attention; the line that was *identical* merged in silence. Sameness is the hazard, not disagreement.

When two branches do **the same thing for the same reason**, git's confidence is highest exactly where "keep both" is wrong. That is why the rule is prevention rather than a resolution protocol — a protocol only helps if somebody is looking, and a silent auto-merge is precisely the case where nobody is.

**After any rebase that touches a seam, verify by counting rather than reading.** Reading the file is what missed it the first time:

```bash
grep -c "Domain/<Domain>/Filament/Resources" app/Providers/Filament/AdminPanelProvider.php   # expect 1
grep -n "Gate::policy" app/Providers/AppServiceProvider.php                                  # expect each model once
```

A dropped registration fails **silently** — Laravel's convention discovery resolves those policies unaided — and a duplicated discovery line fails silently too. Silence in both directions is why this step counts.

### Starting a downstream task

**A downstream worktree is created from updated `main`, and only after the PR it depends on has merged with green CI.**

```bash
git checkout main && git pull --ff-only
git worktree add ../Training-center-worktrees/P2-T04 -b p2/t04-payments
```

Branching from a dependency's *branch* instead of merged `main` couples two reviews together: the downstream diff then contains the upstream work, so the reviewer cannot see what the task itself changed, and every upstream correction has to be replayed downstream by hand.

---

## Isolation: one worktree, one branch per task

Every task gets its own Git worktree and branch. Agents never share a working directory.

**No agent edits the other agent's active worktree.** Not to fix a typo, not to apply its own review finding, not to unblock itself. A worktree belongs to the agent that owns the task until that task's branch is merged. An edit arriving from outside is invisible to the owner's next `composer verify`, absent from their mental model of their own diff, and indistinguishable — in the log — from work they did themselves.

```bash
git worktree add ../Training-center-worktrees/P1-T04 -b p1/t04-activity-log
```

**Branch naming:** `p{phase}/t{number}-{slug}` — e.g. `p1/t04-activity-log`.
**Worktree location:** `../Training-center-worktrees/{TASK-ID}` — outside the main repo, so it never appears in the project tree.

### Every checkout gets its own test database

Each checkout — the main one included — runs its suite against `training_center_test_<8 hex of its path>`, created on first run. Nothing to configure: `tests/bootstrap.php` resolves the name and prints it.

**This is what lets two agents run gates at the same time.** The suite lock is keyed on the database, so different databases no longer take turns — measured at roughly 19 seconds of genuine overlap on two checkouts rebuilding their schemas — while two runs against *one* database still queue, which is the case the lock exists for.

**The main checkout is generated too, and that is deliberate.** A checkout still on older code locks a key derived from its git directory, against the bare `training_center_test`. If a checkout on current code used that name it would hold a *different* lock over the same schema. Generating everywhere means no run on this code touches that name, so the two can never meet.

A machine needs the grant once, from a MySQL administrator. The escaped underscores are load-bearing: a bare `_` is a wildcard in a grant, so without them this would also cover `training_center` itself.

```sql
GRANT ALL PRIVILEGES ON `training\_center\_test\_%`.* TO 'training_center'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `training\_center\_test\_%`.* TO 'training_center'@'localhost';
FLUSH PRIVILEGES;
```

Without it the first run stops with the exact grant to paste, rather than failing somewhere inside the first test.

CI and the Linux target select their own database through a real environment variable, which PHPUnit's `<env>` does not override, so neither is touched by any of this.

Cleanup after merge — the database goes with the worktree, or it accumulates:

```bash
git worktree remove ../Training-center-worktrees/P1-T04
git branch -d p1/t04-activity-log
```

```sql
-- The name the removed worktree printed on every run.
DROP DATABASE `training_center_test_<its 8 hex>`;
```

---

## Task lifecycle

0. **Review the plan before any of it is implemented.** Codex reviews each phase
   plan — the architecture and the enforcement approach, not the prose — and
   signs off before task 1 begins.

   This step exists because of Task 4. It needed five rounds (T04 → T04e), and
   every one of them inherited the same defect: **the plan specified an unsound
   enforcement architecture** — guards implemented by overriding Spatie's write
   methods on the models. That was visible in the written plan. One review round
   there would have replaced five rounds of code remediation, each of which found
   a new bypass method rather than the reason bypasses kept existing.

   Phase 2 is financials, which has the identical shape: hard invariants over an
   unbounded write surface. A plan-level error there is a phase-level error.

1. **Assign.** Claude writes the task into the milestone plan with owner, file scope, and definition of done.
2. **Isolate.** The owning agent creates its worktree and branch.
3. **Implement.** Tests written alongside the implementation. Work stays inside the declared file scope — if the task genuinely needs a file outside it, stop and raise it rather than silently expanding scope. Claude's tasks run through the subagent loop in "Claude's subagents" above, and reach step 5 only once its reviewer has approved.
4. **Verify locally.** One command, and it must pass with real output before opening a PR:
   ```bash
   composer verify
   ```
   That is `composer validate --strict`, then formatting and static analysis, then the full suite. Use `composer verify:fast` — the same without the suite — while working.

   **Do not restate this as separate tool invocations.** There is one definition, `Tooling\Gate::fastChecks()`, and both agents' Stop hooks, the Git hooks and CI all reach it. Restating it here is how the copies drift; the previous version of this step listed `vendor/bin/phpstan analyse` without `--memory-limit`, which exhausts PHP's default on this codebase and reports a crash rather than an analysis.

   Enable the Git hooks once per clone: `git config core.hooksPath .githooks`.

   Each worktree runs against its own MySQL database, so two worktrees' suites run at the same time. Two runs against one database still serialise; a run that says it is waiting is correct, not hung.
5. **Open a PR** against `main`, describing what changed, why, and how it was verified.
6. **Cross-review.** The *other* agent reviews. See the review contract below.
7. **Resolve.** The author addresses findings. Disagreement is legitimate — a reviewer can be wrong, and the author should say so with reasoning rather than complying reflexively.
8. **Merge** once the reviewer approves and CI is green. Squash merge.
9. **Clean up** the worktree and branch. **Tag the branch tip first and push the tag** — a squash merge leaves the branch's commits unreachable from `main`, so an unpushed tag is the only record of the review history, and a local-only tag is one disk failure from nothing.

---

## The review contract

The reviewer is checking for:

- **Correctness** — does it do what the task said, including the unhappy paths?
- **Spec compliance** — does it match `docs/superpowers/specs/`? Silent deviation from the spec is a finding, not a detail.
- **Standards compliance** — against `docs/ENGINEERING.md`. Particularly: permission checks are permission-based not role-based, money is decimal, foreign keys are constrained, no hardcoded user-facing strings, logical CSS properties.
- **Test quality** — do permission tests assert the negative case, not just the positive? Are unhappy paths covered?
- **Scope** — did the change stay inside its declared file scope?

The reviewer verifies claims rather than trusting the PR description. If the description says tests pass, the reviewer confirms it.

**Review is read-only. Findings are implemented by the task's author, never by the reviewer.** The reviewer may read the branch, run the suite against it, and inspect anything in the repository — and writes nothing. A reviewer who fixes what they find has reviewed their own work by the time anyone reads it, which is the one thing this whole cycle exists to prevent. It also destroys the signal in how many rounds a task took.

A review that finds nothing is a valid outcome and should be stated plainly. Manufacturing findings to appear thorough wastes both agents' time.

---

## Milestone completion

A milestone is done when every task is merged, the full test suite passes on `main`, and **the documentation has been updated in the same pass**. Documentation updates are part of the milestone, not a follow-up.

On completing a milestone, Claude updates:

| Document | Update |
|---|---|
| `docs/superpowers/specs/` | Any design decision that changed during implementation. The spec must describe what was actually built, not what was originally imagined. |
| `docs/ENGINEERING.md` | Any convention established or revised during the milestone. |
| `docs/superpowers/plans/` | Mark the milestone complete; record deviations from the plan and why. |
| `docs/CHANGELOG.md` | What shipped, in plain language. |
| `CLAUDE.md` / `AGENTS.md` | Only if the workflow itself changed. |

The rule behind this: a spec that no longer matches the code is worse than no spec, because it is trusted and wrong. If implementation revealed the design was mistaken, the spec gets corrected — the design document is a living record, not a historical artifact.

Then Claude writes the next milestone's plan, and the cycle repeats.

---

## Escalation

Stop and ask the user rather than guessing when:

- A task requires a decision the spec does not cover.
- Implementation reveals the design is wrong.
- The two agents disagree on a review finding and cannot resolve it with reasoning.
- A task cannot be completed as scoped.

Report blockers honestly and immediately. A task reported as complete when it is partially done costs far more than one reported as blocked.
