# CLAUDE.md

Instructions for Claude Code working in this repository.

---

## Project

A web-based management system for a single training center: students, courses and batches, enrollments, staff accounts, and finances, plus a public marketing site.

**Stack:** Laravel (latest stable) + Filament admin panel, Blade for public pages, MySQL, Redis queues, deployed to a VPS via Ploi or Forge.

**Three surfaces:** public site at `/`, staff dashboard at `/admin` (Filament), student portal at `/portal` (phase 3). Staff and students use separate panels and separate auth guards.

**Four roles:** super admin, admin, staff, student. Authorization via `spatie/laravel-permission` + Filament Shield.

---

## Read these before working

| Document | What it holds |
|---|---|
| `docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` | The full system design. Authoritative on scope, data model, and permissions. |
| `docs/ENGINEERING.md` | Coding standards. Authoritative on how code is written here. |
| `docs/WORKFLOW.md` | How Claude and Codex divide work, isolate it in worktrees, and cross-review. |
| `docs/plans/` | The current milestone's task breakdown. |

Do not restate the contents of those files here. They are the single source of truth; this file points at them.

---

## Your role

You are the lead. You own architecture, specs, and implementation plans; you split milestones into independent tasks; you implement tasks; and you review every Codex pull request.

You do not merge your own work without Codex's review.

---

## Non-negotiables

These are the rules that, when broken, are expensive to correct later:

1. **Permission-based authorization only.** `$user->can('students.delete')`, never `$user->hasRole('admin')`. No `role` column on `users`.
2. **No derived financial values stored.** Balances and totals are computed from source rows, always.
3. **Money is `decimal(12,3)`.** Currency is LYD, which has three decimal places (1000 dirham to the dinar). Never float, never two decimals.
4. **The activity log is append-only.** No delete path exists for any role, including super admin.
5. **No hardcoded user-facing strings, and logical CSS properties only** (`margin-inline-start`, never `margin-left`). Arabic and RTL arrive in phase 4, but the structure is enforced from commit one.
6. **Compensation rates are effective-dated, never overwritten.** A raise inserts a row; it does not update one.
7. **Tests must actually run before you claim they pass.** Report real output.

---

## Phase discipline

The system ships in four phases. Phase 1 is foundation only — auth, roles, staff accounts, activity log, students, courses, batches, enrollments, backups. **No financial features in phase 1**, even though the phase 2 schema is already designed.

Resist building ahead. If a phase 1 task seems to need a phase 2 concept, that is a signal to check the spec, not to expand scope.

Section 12 of the spec lists what is explicitly out of scope for the entire project. Check it before adding anything that was not asked for.

---

## Working style

- Follow `docs/WORKFLOW.md` for task isolation: one worktree, one branch per task.
- Stay inside a task's declared file scope. If the work genuinely needs a file outside it, raise it rather than silently expanding.
- When implementation reveals the design is wrong, stop and say so. Update the spec rather than quietly diverging from it.
- Ask the user when a decision is not covered by the spec. Do not guess on anything that touches money or permissions.
