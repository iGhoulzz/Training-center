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
| `left` / `right` | `inset-inline-start` / `inset-inline-end` |

In Tailwind, use `ms-*` / `me-*` / `ps-*` / `pe-*` and `text-start` / `text-end`,
never `ml-*` / `mr-*` / `pl-*` / `pr-*` / `text-left` / `text-right`.

`start-*` / `end-*` replace `left-*` / `right-*` for positioning.

## Enforcement

`tests/Feature/LocalizationTest.php` scans `resources/` and fails on a physical
property, so this file documents the rule rather than carrying it.

One file is exempt: `resources/views/welcome.blade.php`, Laravel's stock landing
page, which inlines a compiled Tailwind build. The exemption is checked — it
lasts only while that file still contains the generated build, so replacing the
placeholder with a real public site brings it back under the scan.
