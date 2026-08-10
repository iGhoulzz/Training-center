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

---

## The unit of work: a task

A milestone is split into tasks, and the tasks are grouped into **waves**.

**Independence is required within a wave, not across the milestone.** Two tasks running at the same time must share no files — two agents editing one file in parallel produces merge conflicts that cost more than the parallelism saved. **Sequential dependencies between waves are expected and allowed.**

This rule previously read "no shared files, no sequential dependency" and forbade dependencies outright. That was written for phase 1, where Claude worked alone and the ordering lived in one head. It does not survive a milestone whose schema must exist before anything else can be built: phase 2 has an unavoidable dependency chain, and a rule forbidding it would be either ignored or worked around by inventing artificially large tasks. The property worth protecting was always *concurrent* file isolation.

Each task has:

- An ID: `P1-T04`
- A single owner: Claude or Codex
- An explicit file scope — which paths it may touch, **including named test files**, never a bare directory
- A definition of done, including which tests must pass
- The wave it belongs to, and what it depends on

Task assignment lives in the milestone's plan document under `docs/superpowers/plans/`.

### Concurrency: one task each, at most

**At any moment Claude may own one ready task and Codex may own one ready task.** That is the ceiling. "Parallel" means one Claude task and one Codex task whose exact file scopes do not overlap — never three simultaneous Codex tasks.

The limit is not about capacity. Every extra concurrent task is another branch to keep current, another diff a reviewer must hold in mind, and another chance that two scopes overlap in a way nobody notices until merge. Two is reviewable; more is bookkeeping.

A wave may legitimately carry only one task, when the dependency graph leaves the other agent nothing ready. The idle agent reviews.

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

Cleanup after merge:

```bash
git worktree remove ../Training-center-worktrees/P1-T04
git branch -d p1/t04-activity-log
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
3. **Implement.** Tests written alongside the implementation. Work stays inside the declared file scope — if the task genuinely needs a file outside it, stop and raise it rather than silently expanding scope.
4. **Verify locally.** One command, and it must pass with real output before opening a PR:
   ```bash
   composer verify
   ```
   That is `composer validate --strict`, then formatting and static analysis, then the full suite. Use `composer verify:fast` — the same without the suite — while working.

   **Do not restate this as separate tool invocations.** There is one definition, `Tooling\Gate::fastChecks()`, and both agents' Stop hooks, the Git hooks and CI all reach it. Restating it here is how the copies drift; the previous version of this step listed `vendor/bin/phpstan analyse` without `--memory-limit`, which exhausts PHP's default on this codebase and reports a crash rather than an analysis.

   Enable the Git hooks once per clone: `git config core.hooksPath .githooks`.

   The suite serialises across worktrees — they share one MySQL database. A run that says it is waiting is correct, not hung.
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
