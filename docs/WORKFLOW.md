# Development Workflow — Claude and Codex

How the two agents divide work, isolate it, review each other, and keep documentation current.

---

## Remote status: local until phase 1 completes

There is **no Git remote during phase 1** by decision. The GitHub repository gets created once the foundation phase is done.

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

A milestone is split into tasks that are **independent** — no shared files, no sequential dependency. Dependent work stays in one task and goes to one agent. Two agents editing the same file in parallel produces merge conflicts that cost more than the parallelism saved.

Each task has:

- An ID: `P1-T04`
- A single owner: Claude or Codex
- An explicit file scope — which paths it may touch
- A definition of done, including which tests must pass

Task assignment lives in the milestone's plan document under `docs/plans/`.

---

## Isolation: one worktree, one branch per task

Every task gets its own Git worktree and branch. Agents never share a working directory.

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

1. **Assign.** Claude writes the task into the milestone plan with owner, file scope, and definition of done.
2. **Isolate.** The owning agent creates its worktree and branch.
3. **Implement.** Tests written alongside the implementation. Work stays inside the declared file scope — if the task genuinely needs a file outside it, stop and raise it rather than silently expanding scope.
4. **Verify locally.** All three must pass, with real output, before opening a PR:
   ```bash
   vendor/bin/pint --test
   vendor/bin/phpstan analyse
   php artisan test
   ```
5. **Open a PR** against `main`, describing what changed, why, and how it was verified.
6. **Cross-review.** The *other* agent reviews. See the review contract below.
7. **Resolve.** The author addresses findings. Disagreement is legitimate — a reviewer can be wrong, and the author should say so with reasoning rather than complying reflexively.
8. **Merge** once the reviewer approves and CI is green. Squash merge.
9. **Clean up** the worktree and branch.

---

## The review contract

The reviewer is checking for:

- **Correctness** — does it do what the task said, including the unhappy paths?
- **Spec compliance** — does it match `docs/superpowers/specs/`? Silent deviation from the spec is a finding, not a detail.
- **Standards compliance** — against `docs/ENGINEERING.md`. Particularly: permission checks are permission-based not role-based, money is decimal, foreign keys are constrained, no hardcoded user-facing strings, logical CSS properties.
- **Test quality** — do permission tests assert the negative case, not just the positive? Are unhappy paths covered?
- **Scope** — did the change stay inside its declared file scope?

The reviewer verifies claims rather than trusting the PR description. If the description says tests pass, the reviewer confirms it.

A review that finds nothing is a valid outcome and should be stated plainly. Manufacturing findings to appear thorough wastes both agents' time.

---

## Milestone completion

A milestone is done when every task is merged, the full test suite passes on `main`, and **the documentation has been updated in the same pass**. Documentation updates are part of the milestone, not a follow-up.

On completing a milestone, Claude updates:

| Document | Update |
|---|---|
| `docs/superpowers/specs/` | Any design decision that changed during implementation. The spec must describe what was actually built, not what was originally imagined. |
| `docs/ENGINEERING.md` | Any convention established or revised during the milestone. |
| `docs/plans/` | Mark the milestone complete; record deviations from the plan and why. |
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
