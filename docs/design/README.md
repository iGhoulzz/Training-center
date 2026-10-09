# Design handoff (phase 4 reference)

This folder holds the owner's UI handoff for phase 4: a redesign of the Filament
admin panel and a new public marketing site. It is reference material for the
phase 4 tasks that rebuild those screens.

## Reference, not specification

Where anything in here disagrees with
`docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` or with
`docs/superpowers/plans/2026-10-08-phase-4-public-site-and-arabic.md`, the spec
and the plan win. The handoff describes what the designer drew. They describe what
the project builds. Nine conflicts are known, and the screenshots still show the
drawn version of each. Seven are decided; two are open and name the task that
settles them:

1. Public programme cards show no "seats left" pill. The screenshots show one.
2. The certificate verifier's result shows no contact hours. The valid-result
   screenshot has a "Contact hours" row.
3. The verifier's "enter a reference" prompt is the input's native `required`
   attribute, and every miss stays one identical not-found page. The screenshot of
   the empty submit shows a separate message.
4. "Certificates due this month" is dropped from phase 4, both the dashboard KPI
   card and the queue banner on the certificates screen.
5. The prototypes' CSS is not copied into the app. It is rewritten as Tailwind with
   logical properties, as `docs/ENGINEERING.md` requires.
6. **The student portal sign-in is a link, not a form or modal.** The handoff draws
   an email-and-password modal on the public site (`13-portal-sign-in.jpg`), and
   `BACKEND_CONTRACT.md`'s "Student portal sign-in" row offers "a link or a sign-in
   form". The plan's T15 decides on a link to the student panel's own login page.
   The public pages are sessionless, so a form posted from them would carry no CSRF
   token, and a second sign-in path is not wanted.
7. **"Request a seat" is a link to contact, not a form.** Public student
   self-registration is out of scope (spec section 12). A programme with no upcoming
   batch shows a contact call to action instead of a date and price (plan, T12).
8. **Open — the verifier's "Try …" sample buttons (decided in T14).** The verifier
   section draws two buttons that fill in a sample reference. A sample that
   resolves would publish a real holder's name and course on the open internet,
   and one that does not resolve demonstrates only a miss. The default is that they
   are not built. The owner may decide otherwise in T14.
9. **Open — the embedded map on the contact section (decided in T12).** The handoff
   README and `TASKS.md` say to replace the map placeholder with "a real embedded
   map". No task scopes one, and an embedded map loads a third-party origin into the
   public pages. The default is the address as text plus a "Get directions" link,
   which loads nothing. The owner may decide otherwise in T12.

## What was removed, and why

The repository is public and MIT licensed, so the handoff was cleaned before it was
committed.

- The centre's real logo is replaced by a neutral "TC" disc, and the real centre
  name, in English and Arabic, by "Training Centre".
- Contact details are replaced with fake ones: the street address, the telephone
  numbers, the public email address and the staff-style email addresses. The sample
  people's names in the prototypes are invented and were kept.
- The two interactive prototypes (the `.dc.html` files) are not committed, and
  neither are the design tool's runtime scripts (`support.js`, `image-slot.js`).
  They carry no licence, so publishing them is not ours to do. The screenshots below
  stand in for the prototypes. That is the owner's decision of 2026-10-09.
- **Screenshots show states, not behaviour.** What the prototypes did on a click,
  a toggle or a submit is written down in the handoff README's "Interactions &
  behaviour" and state sections, which are committed. Where a screenshot and that
  text disagree, the text describes the intent. The live prototypes remain with the
  owner, and can be shared on request for a task that needs to see an interaction
  run.
- `github.md`, the design tool's sync log, is not committed. It only mapped screens
  to source files in an earlier state of the repository.

The three written documents in `design_handoff_training_centre/` are the owner's
originals with the same cleaning applied. Each opens with a short note saying what
changed, and the paths inside `TASKS.md` were corrected to this folder.

## The screenshots

They were rendered on 2026-10-10 from cleaned copies of the two prototypes, in
Microsoft Edge at a viewport 1440 px wide, as JPEG at quality 85, at full page
height. The prototypes load their fonts from Google Fonts, so the type is the real
type.

`design_handoff_training_centre/screens/admin/` holds the admin prototype.
`NN-<screen>-today.jpg` is the panel as it renders now, and
`NN-<screen>-proposed.jpg` is the redesign. NN follows the order of the screens
list in the handoff README. Sub-states are part of the name: the Enrol & Collect
steps (`05-enrol-collect-step-1-proposed.jpg` to `step-4`), the batch view's
Enrolments and Instructors tabs, and the student portal's three tabs, which exist
in both states. The Today version of a screen has no sub-states and is captured
once. `19-component-sheet.jpg` and `20-js-toolkit.jpg` are the prototype's
documentation pages, captured once.

`design_handoff_training_centre/screens/public/` holds the public site, numbered in
the order the prototype is navigated: the home page, the five certificate verifier
outcomes (valid, replaced, revoked, not found, empty submit), the publications
library in four states, two article pages, the student portal sign-in modal and the
download toast. The verifier outcomes are cropped to the verifier section, and the
modal and the toast are captures of the first 900 px only, because both are fixed to
the viewport.

The public prototype has no Arabic and English toggle. Arabic appears in it only as
subtitles beside the English text, so there are no Arabic captures yet. In the admin
prototype the report chips and the filter chips change nothing on screen, so one
capture each stands for them.
