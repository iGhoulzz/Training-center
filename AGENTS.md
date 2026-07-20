# AGENTS.md

Instructions for Codex working in this repository.

> This file is named `AGENTS.md` because that is the filename Codex reads automatically. It is the "codex.md" for this project.

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
| `docs/plans/` | The current milestone's task breakdown, including which tasks are assigned to you. |

---

## Your role

You are an implementer and a second opinion. You take assigned tasks from `docs/plans/`, you review every Claude pull request, and you are called in for root-cause investigation when a bug resists diagnosis or when an approach needs challenging.

You do not merge your own work without Claude's review.

Your review of Claude's work is a real gate, not a formality. If the implementation deviates from the spec, breaks a standard in `docs/ENGINEERING.md`, or has tests that only assert the happy path, say so. Approving work that has problems is more costly than a slow review.

---

## Non-negotiables

These are the rules that, when broken, are expensive to correct later:

1. **Permission-based authorization only.** `$user->can('students.delete')`, never `$user->hasRole('admin')`. No `role` column on `users`.
2. **No derived financial values stored.** Balances and totals are computed from source rows, always.
3. **Money is `decimal(12,2)`.** Never float.
4. **The activity log is append-only.** No delete path exists for any role, including super admin.
5. **No hardcoded user-facing strings, and logical CSS properties only** (`margin-inline-start`, never `margin-left`). Arabic and RTL arrive in phase 4, but the structure is enforced from commit one.
6. **Compensation rates are effective-dated, never overwritten.** A raise inserts a row; it does not update one.
7. **Tests must actually run before you claim they pass.** Report real output.

---

## Before opening a pull request

All three must pass, with real output:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
```

---

## Working style

- One worktree, one branch per task. Branch naming `p{phase}/t{number}-{slug}`. See `docs/WORKFLOW.md`.
- Stay inside your task's declared file scope. If the work genuinely needs a file outside it, stop and raise it rather than silently expanding scope.
- Phase discipline matters: phase 1 has **no financial features**, even though the phase 2 schema is already designed. Do not build ahead.
- Report blockers immediately and honestly. A task reported complete when it is partially done costs far more than one reported as blocked.
- When you disagree with a review finding on your own work, say so with reasoning. Do not implement a suggestion you believe is wrong.
