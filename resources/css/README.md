# Stylesheet rules

Use logical CSS properties only. Physical properties do not flip for Arabic:
`margin-left` is still on the left in an RTL layout, so a panel that reads
correctly in English develops crushed gutters and stranded icons in Arabic.

The admin panel already renders `dir="rtl"` for a user whose language is Arabic
— Filament ships the direction and P1-T14 wired the locale to it. This is a live
constraint, not preparation for one.

| Never | Always |
|---|---|
| `margin-left` | `margin-inline-start` |
| `margin-right` | `margin-inline-end` |
| `padding-left` | `padding-inline-start` |
| `padding-right` | `padding-inline-end` |
| `border-left` | `border-inline-start` |
| `text-align: left` | `text-align: start` |
| `float: left` | `float: inline-start` |
| `clear: left` | `clear: inline-start` |
| `left` / `right` | `inset-inline-start` / `inset-inline-end` |

In Tailwind, use `ms-*` / `me-*` / `ps-*` / `pe-*` and `text-start` / `text-end`,
never `ml-*` / `mr-*` / `pl-*` / `pr-*` / `text-left` / `text-right`.

`start-*` / `end-*` replace `left-*` / `right-*` for positioning, and
`border-s-*` / `border-e-*` replace `border-l-*` / `border-r-*`.

Every suffix counts, not just the numeric one: `ml-auto`, `-ml-2`, `mr-px`,
`left-[1rem]` and `border-l-red-500` are as direction-blind as `ml-4`.

The rule applies to inline `style` attributes too — `style="left: 0"` is the
same property wherever it is written.

## Enforcement

`tests/Feature/LocalizationTest.php` scans `resources/` and fails on a physical
property, so this file documents the rule rather than carrying it.

The detector has its own self-tests — one set of physical samples it must catch,
one set of logical samples it must not — covering raw CSS and the Tailwind
numeric, fractional, negative, `auto`, `px` and arbitrary-value forms. A scan
that quietly matches less than this table promises is worse than no scan: it
reports the rule as enforced while `ml-auto` sails through. Anything added to
the table above belongs in both sample sets.

One file is exempt: `resources/views/welcome.blade.php`, Laravel's stock landing
page, which inlines a compiled Tailwind build. The exemption is checked — it
lasts only while that file still contains the generated build, so replacing the
placeholder with a real public site brings it back under the scan.
