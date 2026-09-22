# Implementation plan — and how to drive Claude Code / Codex through it

## The short answer

Do **not** hand an agent "implement this design". You will get twenty half-built screens and a pile of duplicated Blade.

Hand it **one slice at a time**, in the order below, with three things in context: the repo, `BACKEND_CONTRACT.md`, and the one screen's section of `README.md`. Each slice ends in something reviewable. Phase 0 is non-negotiable — build the shared components first, or every later slice reinvents them.

## Setup, once

1. Clone the repo and let the agent read it. Claude Code: run it in the repo root. Codex: point it at the repo.
2. Copy this handoff folder into the repo as `docs/design/` and commit it. Both tools work far better when the spec is a file in the tree than when it is pasted into a chat.
3. Open `Filament Revamp.dc.html` and `Public Site.dc.html` in a browser and leave them open. The agent cannot see them; **you** are the reviewer, and every slice should be compared against the prototype by eye.
4. Tell the agent the two standing rules, and keep repeating them:
   - *Read `docs/design/BACKEND_CONTRACT.md` before writing any query. If a screen needs something tagged "New query", write the scope — do not add business logic, and do not duplicate a rule that already lives in a model or enum.*
   - *The HTML prototypes are references, not code. Never copy their inline CSS. Use the components from Phase 0.*

## The prompt shape that works

For each slice:

> Read `docs/design/README.md` § "<screen name>" and `docs/design/BACKEND_CONTRACT.md` § "<screen name>".
> Implement it in `<the existing Resource/Page file>`.
> Use the shared components in `resources/views/components/tc/`.
> Constraints: no new business logic; reuse the services named in the contract; batch anything tagged "Reuse + batch".
> When you are done, list which contract lines you satisfied and which you skipped.

That last sentence matters more than it looks. It forces the agent to reconcile its work against the spec, and it makes review a two-minute diff instead of a re-read.

## Ordering

### Phase 0 — Shared components *(do this first, one PR)*

Eight components. All of them appear four or more times with real variants; nothing else should be extracted.

| Component | Build it as | Notes |
|---|---|---|
| Status badge | `->badge()` + enum `label()`/`color()` | Six families: batch, enrolment, student, charge, certificate, export. **The colour is the enum's answer.** No hex in a view. |
| Action menu | `ActionGroup::make()` | Build each action once in the Resource and call the same builder from the list and the view page so they cannot drift. |
| Button | `x-filament::button` | Six variants: primary, secondary, quiet link, export, destructive, add-row. A view picks a variant, never a colour. |
| Confirm dialog | `->requiresConfirmation()` + required reason | Pull the refusal text from the lang file so the dialog and the exception say the same thing. |
| Toast | `Notification::make()` | Persistent for anything shown once — a temporary password. |
| Meter cell | `x-tc.meter` | Two numbers in, bar + label + colour out. Thresholds live on the model (`isOverCapacity`, `hasHourMismatch`), not in the component. |
| Money | `x-tc.money` | One formatter over the existing Money support class. Three decimals, LYD, tabular numerals. **Never format money in a Blade file.** |
| Detail drawer | Infolist in a side panel | Reuse the schema the view page already declares. |

Also in Phase 0: the dark theme tokens from `README.md` into the Filament theme CSS, and self-hosted Instrument Sans.

### Phase 1 — Front desk *(highest value, riskiest; do it while attention is fresh)*

1. **Enrol & Collect** — the persistent Bill panel and the jumpable step cards. This is the screen staff live in. Respect the installment refusal.
2. **Dashboard** — KPI cards, live batches, alerts, tender split. Needs the two new scopes from the contract; write them here and every later screen benefits.

### Phase 2 — Lists

3. **Batches list** (chips + meter cells) → **Batch view** (tabs, roster, instructor cards)
4. **Students list** (chips + master-detail) — batch the balance query here
5. **Courses**, **Users & roles**

### Phase 3 — Finance

6. **Finance hub** + the export-history list
7. **Charges** (ageing tiles), **Payments** (tender chips, receipt-PDF states)
8. **Reports** (chips + a `ChartWidget`), **Payroll**, **Certificates** queue
9. **Activity log** timeline

### Phase 4 — Create pages

10. Student, Course, Batch — grouped sections, consequence panels, chained primary actions.

### Phase 5 — Cross-links *(one pass, after the screens exist)*

Every student / batch / course / bill / receipt / staff name becomes a link. Doing this last is deliberate: the targets must exist first, and it is a mechanical sweep an agent does well in one go. Insist on `stopPropagation` for links inside clickable rows.

### Phase 6 — Portal

11. The three portal pages, plus the course/batch names on balance rows.

### Phase 7 — Public site *(separate work stream; can run in parallel with 1–6 by a second agent)*

12. Public route group, layout, tokens, self-hosted fonts, the real logo asset.
13. Home: hero, stats, about, programmes, contact. Replace the `image-slot` placeholder with a real map.
14. **Certificate verifier** — public read-only route, rate-limited, exposing only what is printed on the certificate. All four outcomes.
15. **Publications** — `Article` model + migration, Filament upload resource, public index / show / download, download counter.
16. Portal sign-in modal against the existing student guard.

## Splitting the work between the two tools

They are not interchangeable. Use each where it is strongest:

- **Claude Code** — the phases that require reading a lot of existing code and staying consistent with it: Phase 0, Enrol & Collect, the Finance hub, anything touching the contract's "New query" lines. It holds a large repo in context and is better at *not* inventing a parallel service.
- **Codex** — the mechanical, well-specified sweeps: Phase 5 cross-links, the create pages, repetitive list screens once the components exist, the `Article` CRUD. Give it a finished sibling screen as the pattern to copy.

If you use both, run them on **separate branches and separate phases**. Two agents editing the same Resource will produce conflicts neither can resolve.

## Review checklist, per slice

- [ ] Compared against the prototype on screen, not from memory
- [ ] No hex colours or money formatting in any Blade file
- [ ] Status colours come from the enum
- [ ] No new business logic; contract lines reconciled explicitly
- [ ] Nothing tagged "Reuse + batch" left as a per-row call — check the query count
- [ ] The rules in `BACKEND_CONTRACT.md` § "Rules to preserve" still hold
- [ ] Dark theme only; small text still passes 4.5:1
- [ ] Reflows at a narrow window — every list in the prototype uses fluid grid tracks with `minmax()` floors, not fixed pixel columns

## What to expect to go wrong

- **The agent will duplicate the prototype's inline CSS.** It is right there in the file and it looks authoritative. Say so explicitly, every slice.
- **It will add a service that already exists.** The contract is the antidote — make it read the section, not skim it.
- **It will N+1 the balance columns.** They read like ordinary accessors. Check with a query counter, not by eye.
- **It will make exports feel instant.** Everything is queued. The UI must show state.
- **It will drift on the enum colours** if a single view hardcodes one badge. Grep for hex in Blade at the end of every phase.
