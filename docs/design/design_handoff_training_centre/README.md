# Handoff: Training Centre UI — Filament panel revamp + public site

## Overview

Two deliverables for the Arab Specialist Center for Legal Studies and Training (A.S.C.L.S.T), a Libyan legal-training centre running a Laravel + Filament 5 application (`iGhoulzz/Training-center`, branch `main`).

1. **Admin panel revamp** — a redesign of 20 existing Filament screens, each shown side by side with what the panel renders today.
2. **Public marketing site** — a new, non-Filament public front end: centre information, programmes, a certificate verifier, a publications library with PDF downloads, and a student-portal sign-in.

## About the design files

The two `.dc.html` files in this bundle are **design references written in HTML**. They are prototypes that show intended look and behaviour. They are **not** production code and must not be copied into the app.

The task is to **recreate these designs inside the existing Laravel + Filament 5 codebase**, using its established patterns:

- Panel screens → Filament Resources, Pages, Widgets, RelationManagers, Blade view components, Alpine for interaction.
- Public site → Blade templates on a public (non-panel) route group with Tailwind, outside the Filament panels.

Deliberate note on duplication: the prototypes repeat their CSS literals on every screen so each screen paints and forks independently. **Do not port that duplication.** `TASKS.md` names the eight shared components to extract instead, and `BACKEND_CONTRACT.md` is the file to read before writing any query.

## Fidelity

**High-fidelity.** Colours, type, spacing, radii, states and copy are final. Recreate them faithfully with the codebase's Tailwind config and Filament theme. The one exception: the contact-section map is an intentional placeholder (`<image-slot>`) awaiting a real map — see Assets.

---

## Deliverable 1 — Admin panel (`Filament Revamp.dc.html`)

Dark theme only, per the client. A topbar toggle switches every screen between **Today** (a faithful recreation of the current panel) and **Proposed**. Only the Proposed side is to be built; Today exists so reviewers can see what changes.

### Screens

Each entry lists purpose, then what changes from today.

**Dashboard → "Front desk"**
Landing screen for staff. Today: Filament's `AccountWidget` + `FilamentInfoWidget`, no data.
Proposed: date + centre line; primary "Enrol & collect" button; four KPI cards (Collected today, Outstanding, Active enrolments, Certificates due) each with a value, a trend pill and a 14-bar sparkline; a "Batches running now" list where each row is a button opening the batch, showing code, course, seats, a capacity bar and a status pill; a "Needs attention" panel (over-capacity batch, unissued certificates, hour shortfall) and a "Today's tenders" panel with a bar per payment method.

**Batches list**
Today: a 9-column Filament table with two separate badges for capacity and hours.
Proposed: filter chips (All / Active / Planned / Over capacity / Hour mismatch) with counts; a 6-column grid — Batch (code + course), Schedule (a span bar + date range, ellipsised), Status (dot pill), Seats (fraction + percentage + capacity bar coloured green/amber/over), Hours (`30 / 30` + "allocated" or "mismatch"), overflow menu. The whole row opens the batch.

**Batch view**
Today: a two-column read-only field grid plus two tables.
Proposed: a hero band (code, status pill, course, dates, hours) with three inline stats (Seats, Hours allocated, Outstanding) and Enrol / Edit actions; a segmented tab control (Enrolments 18 / Instructors 2); roster rows with avatar, Arabic name, code, status pill, enrolled-on, balance (a button opening the bills) and a `⋯` menu; instructor cards with an assigned-hours bar and departed staff visibly greyed.

**Students list**
Today: a 5-column table.
Proposed: filter chips (All / Active / Prospective / Owes money / No portal login); a master-detail split — rows on the left (avatar, name, code, phone, status pill, balance), a sticky detail panel on the right with the selected student's fields, three jump chips (Bills / Receipts / Certificates), their enrolments, and "Issue portal login" + Edit.

**Enrol & Collect**
The highest-traffic screen. Today: a four-step wizard with no running total until the end and no collection panel until after Confirm.
Proposed: four step cards along the top showing each step's chosen value, all clickable to jump back; the step body on the left, a **persistent Bill panel** on the right that is visible from step one (student, batch, list price, discount, "Amount to bill", receipt reference, where the receipt goes). Step 1 is inline search with result cards, not a select. Step 2 is batch cards with seats bars and price. Step 3 is discount radio rows showing the resulting amount. Step 4 is the amount to collect with quick-fill chips, plus tender rows (method, amount, reference) that can be split across methods, with a live "Balanced ✓" indicator.

**Finance** (new hub page)
Today: nothing — Charges, Payments, Payroll and the seven reports are separate sidebar entries.
Proposed: four clickable KPI tiles (Collected today, Outstanding, Wage cost, Profit); quick-nav grouped Money in / Money out / Reports, putting all seven reports one click deep; an **Exports** panel listing queued export jobs with Ready / Running / Failed states and a Download or Retry action.

**Charges**
Proposed: four ageing tiles (Not yet due, 1–30, 31–60, 60+) with bars; rows showing student (links to them), bill ref + batch (links), billed amount, a paid-vs-billed progress bar, outstanding (links to payments), a state pill and the due date.

**Payments**
Proposed: **Export Excel** and **Export PDF** in the header, plus a "Queued" explainer band; rows showing student (links), bill ref (links), tender chips (`● Cash 312.500`) that answer "how was it paid" without opening the row, the total, the state pill, "by <staff>" (links to their account), and a per-row **Receipt PDF** button with a `Generating…` state.

**Certificates**
Proposed: an amber "3 completed enrolments have no certificate yet" queue banner with a Review action; rows with student (links), course (links), reference, issued date, issuer (links) and a status pill (Valid / Replaced / Revoked).

**Reports**
Proposed: report chips for all seven reports; the header carries Export Excel / Export PDF; a horizontal bar chart of the rows plus a period-total panel naming the source class.

**Payroll runs**
Proposed: cards per run — type, period, state pill (Finalized / Draft), total with line count, Review lines, created-by.

**Activity log**
Proposed: a timeline instead of a table — timestamp, a status dot, the actor (links to their account), an event pill, the record (links to it), the field-level diff in mono, and the IP with "No IP (console or scheduled task)" spelled out.

**Courses**
Proposed: cards — name, code + hours, state pill, default price, a revenue bar, and two footer jump buttons ("3 batches →", "54 enrolled →").

**Users & roles**
Proposed: account and staff profile merged into one row (they are two resources today) — avatar, name, job title + employment type, role chips, last login (with "Never" dimmed), hire date, state pill, and Activity → / Payroll → jumps.

**Create pages — Student, Course, Batch**
Today: one flat column of fields in schema order (11 for a student), no grouping, no explanation.
Proposed: grouped sections (Student: Identity / Contact / Records), required fields marked with an amber left border and an asterisk, and a right-hand panel stating consequences — for a student, a live summary card plus "email present, a portal login can be issued" and a surname-match hint about the sibling discount; for a course, what its batches inherit; for a batch, an "Inherited from <course>" panel. Primary actions chain forward: "Create & enrol her now", "Create & add first batch".

**Student portal** (rendered inside a browser frame, since it is a separate Filament panel)
Three tabs. Overview proposed: an identity band with the amount owed, a "Currently studying" card with a progress bar, and a certificates card. My enrolments: course, batch, enrolled, completed, status pill, certificate reference. My balance: a row per enrolment naming the course and batch (today it identifies an enrolment by id) plus a total band.

**Component sheet** and **JS toolkit** are documentation pages inside the prototype, not screens to build. Read them: the component sheet is the extraction plan, the JS toolkit lists which libraries to use (all already shipping inside Filament).

### Backend footnotes

Every Proposed screen ends with a dashed panel listing what backs it, tagged **Exists** / **Reuse + batch** / **New query**. These are transcribed into `BACKEND_CONTRACT.md`. The client's standing instruction: *check what the backend supports so we do not find ourselves adding endpoints or business logic.* No screen requires new business logic; a handful need new read queries, all named.

---

## Deliverable 2 — Public site (`Public Site.dc.html`)

Light theme, navy/gold/cream, three routes inside one prototype.

**Home** — a navy top strip (phone, email, the Arabic centre name); a sticky nav (logo, About / Programmes / Publications / Verify / Contact, and a "Student portal" pill); a hero with the seal, the centre's full name with "Legal Studies" italic in gold, two CTAs, and a four-cell stats strip; About with three pillar cards; Programmes as four cards (tag, hours, name + Arabic name, description, price, start date, seats pill); the certificate verifier; a three-item publications teaser with "All publications →"; Contact with four detail rows and a located map card; footer.

**Certificate verifier** — an input plus two "Try …" sample buttons. Returns four distinct outcomes from the register, mirroring `CertificateStatus`:
- **valid** → green, ✓, holder / programme / issued / contact hours
- **replaced** → gold, ↻, "Superseded by a later certificate", "ask the holder for the current reference"
- **revoked** → red, ✕, "should not be relied on"
- **not found** → neutral, ?, "Check the reference on the printed certificate"
An empty submit returns a neutral "Enter a reference to search" with the expected format. Below the card, when nothing has been searched: "Nothing is stored from a lookup."

**Publications library** (`/publications`) — its own page. Navy header, search across title/topic/authors/description, topic chips with counts, a Newest first ⇄ Most downloaded sort toggle, a live result line ("2 publications in Arbitration matching …"), a card grid, and an empty state.

**Article page** (`/publications/{slug}`) — navy header with topic pill, issue date, title, Arabic title and authors; an "Original filing" block with a PDF thumb, file size, download count, "uploaded by the administration" and a Download PDF button; the body text; a "More in <topic>" related list.

**Student portal sign-in** — a modal from the nav and footer: navy header with the seal, email + password, Sign in, and the honest note that the registrar issues logins and a portal account needs an email on the student record.

---

## Interactions & behaviour

**Panel**
- Today / Proposed toggle swaps every screen; sidebar navigates; the active item is amber on a 13%-amber ground.
- Enrol & Collect: step cards jump; Continue advances; on step 4 the primary becomes "Collect payment" and fires a success toast naming the receipt.
- Cross-links: student, batch, course, bill, receipt and staff names all navigate. Every one calls `stopPropagation` so a link inside a clickable row does not also trigger the row.
- Toasts: bottom-right, dark card, 3.6 s auto-dismiss, manual close. One is deliberately persistent in spirit — a temporary password is shown once.
- Animations: `tcRise` 0.25 s ease on screen change; `tcGrow` 0.5–0.6 s scaleX from left on bars.

**Public site**
- Sticky nav; in-page anchors on home; Publications switches page and scrolls to top.
- Verifier: Enter-to-submit not wired in the prototype — wire it in the build.
- Library search filters as you type; sort toggles; chips are single-select.
- Modal: overlay `rgba(9,20,35,.68)` + 5 px blur, `asSpinIn` 0.26 s. Close on the × — add Escape and overlay-click in the build.
- Animations: `asRise` 0.6–0.8 s on hero, `asFloat` 7 s infinite on the seal, `asSweep` 0.7 s on programme-card top rules.

## State management

**Panel:** `screen`, `mode` (today/proposed), `step` (1-4), `tab`, `sel` (selected student index), `filter`, `report`, `portalTab`, `toast`.
In Filament this is mostly URL state and Livewire properties, not client state: `screen` is a route, `filter` is a table filter, `step` is the wizard's own state, `sel` is a query parameter so a chosen student survives a refresh.

**Public site:** `page` (home/library/article), `article` (slug), `topic`, `query`, `sort`, `refInput`, `result`, `loginOpen`, `toast`.
In Blade: `page`/`article` are routes; `topic`, `query`, `sort` are query-string parameters so a filtered library is linkable and indexable; the verifier result is a POST/GET response; `loginOpen` is Alpine.

## Design tokens

### Panel (dark)
| Token | Value |
|---|---|
| Page background | `#030712` |
| Surface | `#111827` |
| Hairline / outline | `rgba(255,255,255,.1)` |
| Row hover | `rgba(255,255,255,.03)` |
| Subtle fill | `rgba(255,255,255,.04)` |
| Text | `#f9fafb` |
| Text secondary | `#e5e7eb` / `#d1d5db` |
| Text muted | `#9ca3af` |
| Text dim | `#6b7280` |
| Primary | `#f59e0b`, hover `#fbbf24`, on-primary `#1c1204` |
| Success | `#4ade80` on `rgba(74,222,128,.14)` |
| Info | `#60a5fa` on `rgba(96,165,250,.14)` |
| Warning | `#fbbf24` on `rgba(251,191,36,.14)` |
| Danger | `#f87171` on `rgba(248,113,113,.14)` |
| Accent (roles) | `#a78bfa` on `rgba(167,139,250,.15)` |
| Neutral pill | `#d1d5db` on `rgba(255,255,255,.09)` |

Avatar palette (cycled): amber `rgba(245,158,11,.18)`/`#fbbf24`, blue `rgba(96,165,250,.18)`/`#60a5fa`, green `rgba(74,222,128,.18)`/`#4ade80`, violet `rgba(167,139,250,.2)`/`#a78bfa`, pink `rgba(244,114,182,.18)`/`#f472b6`.

Type: **Instrument Sans** 400/500/600/700. Page title 28 px/600/−0.025em; section title 14–15 px/600; body 12.5–13.5 px; metadata 11–12 px; column headers 11.5 px/600/0.05em/uppercase; numerals `font-variant-numeric: tabular-nums` on every money and fraction.

Radii: 6–8 px pills and small controls, 9–11 px buttons and inputs, 14 px cards, 16 px hero bands, 999 px status pills and avatars.
Spacing: 32 px page padding, 24 px section gaps, 14–18 px card padding, 13–16 px grid gaps, 6–10 px inline gaps.
Sidebar 258 px; topbar 64 px; sticky detail panels `top: 96px`.

### Public site (light)
| Token | Value |
|---|---|
| Page background | `#faf6ec` (cream) |
| Card | `#ffffff` |
| Navy (primary) | `#0d2745`, hover `#143a63` |
| Gold | `#c9a44c`, light `#e0c179`, dark/eyebrow `#806119` |
| Ink | `#14181f` |
| Body text | `#3d4654` |
| Body secondary | `#5b6474` |
| Metadata | `#67707e` |
| Arabic subtitle | `#6f5f3f` |
| Hairline | `rgba(13,39,69,.09)` – `rgba(13,39,69,.16)` |
| Card shadow | `0 2px 12px rgba(13,39,69,.04)`; hover `0 8px 26px rgba(13,39,69,.09)` |

Verifier result colours: valid `#7bd8a0` on `rgba(74,187,120,.12)`; replaced `#e0c179` on `rgba(201,164,76,.12)`; revoked `#f0908f` on `rgba(224,102,102,.12)`; neutral `#faf6ec` on `rgba(250,246,236,.06)`.

Type: **Instrument Serif** 400 (+ italic) for display, **IBM Plex Sans Arabic** 400/500/600/700 for UI and all Arabic. H1 `clamp(38px,5.4vw,64px)`/1.06/−0.015em; H2 `clamp(28px,3.4vw,40px)`/1.15; article H1 `clamp(30px,4.2vw,46px)`; card title 20–21 px serif; body 14–17 px/1.7–1.85; eyebrow 12.5 px/700/0.14em/uppercase.

Radii: 999 px nav pills and chips, 9–12 px buttons and inputs, 16–18 px cards, 20–22 px panels and the modal.
Layout: `max-width: 1200px` with 24 px gutters; article and its header `max-width: 860px`; section padding 80–88 px vertical.

**Contrast rule that was enforced:** all small text meets 4.5:1 against its opaque background. `#67707e` on cream is ≈4.6:1; `#806119` on cream ≈4.6:1. Do not lighten these back toward the greys they replaced.

## Assets

- `assets/asclst-logo.jpg` — the centre's seal, supplied by the client. Used in the nav (46 px circle), hero (258 px circle, floating), footer (44 px) and the portal modal (52 px). Always circular with a `rgba(201,164,76,.5)` hairline. Get a transparent PNG or SVG from the client before launch; the supplied file is a JPEG on a cream ground, which is why every use is a circular crop.
- `image-slot.js` — the drop-target web component used for the contact map placeholder. **Prototype tooling only; do not ship it.** Replace with a real embedded map (or a static map image) of Hay al-Andalus, Tripoli, with the centre pinned.
- Icons are inline SVG strokes at 1.5–2.2 weight. In the build use the codebase's existing icon set (Filament ships Heroicons).
- Fonts are loaded from Google Fonts. Self-host both families for a Libyan audience — latency to Google's CDN is unreliable there and the Arabic subset is large.

## Files

| File | What it is |
|---|---|
| `Filament Revamp.dc.html` | Admin panel prototype, 20 screens, Today/Proposed toggle |
| `Public Site.dc.html` | Public site prototype: home, publications library, article page, verifier, portal modal |
| `assets/asclst-logo.jpg` | Centre seal |
| `image-slot.js` | Prototype-only map placeholder component |
| `BACKEND_CONTRACT.md` | **Read first.** What each screen needs, and whether it exists in the repo today |
| `TASKS.md` | Ordered implementation plan, shared components, and how to drive Claude Code / Codex through it |
| `github.md` | Repo, branch and the screen → source-file map |

Open either `.dc.html` in a browser to interact with it.
