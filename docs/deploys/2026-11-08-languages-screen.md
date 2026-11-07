# Release 2026-11-08 — Languages screen

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `0e38ae3` |
| Full test suite | 4619 passed of 4620; known failure only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new header menu and admin screen classes) |
| Version | `VERSION` file bumped to `2026.11.08.1` |

## What's in it
- **Admin → Website & Pages → Languages** (Super Admin only, audit-logged): English always on; Telugu on/off; add any
  language (code, its own name, English name, right to left); a new one starts **off**; remove an added one only while off;
  "Language for a first-time visitor" moved here from Website text; "Words typed: N of M" per language.
- **Website text**: picks ONE language to show next to English; **Download sheet** (CSV that opens in Excel / Google
  Sheets) and **upload** it back (CSV or Excel). Upload only fills the boxes and warns about missing `:name` words;
  nothing is live until Save. A word not typed yet shows in English, never blank.
- **Top bar**: two languages on = one switch button (as now); three or more = a small language menu.
  Right-to-left languages set `dir="rtl"` on the page (basic only; full mirroring later).
- **Homepage sections**: card offer lines get a column for each added language.
- No migration: the list is one setting, `i18n.languages`. Until it is first saved, Telugu follows the old
  `i18n.telugu_enabled` switch, so **the live site does not change by deploying this**.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- `/` loads and looks the same as before (English, Telugu switch only if Telugu was on).
- Admin → Website & Pages → Languages opens; Website text opens with the language picker and Download button.

## Admin settings to do after deploy (owner)
- Nothing required. To add Tamil: Languages → Add a language (Tamil, தமிழ், ta) → Website text → pick Tamil →
  Download sheet → translator fills column 4 → upload → Save → Languages → tick Tamil → Save.
