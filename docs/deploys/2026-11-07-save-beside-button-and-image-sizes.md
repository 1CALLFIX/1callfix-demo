# Release 2026-11-07 — "Saved" beside the Save button + best image size on every upload

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `7b13dbc` |
| Full test suite | 4571 tests, 4568 passed; the 3 failures: dish test (suite ran during the edit, green on its own), provider layout raw-script check (fixed: run-once script, re-run green), known NotificationCenterAuditTest .env failure. Affected folders re-run: 309/309 |
| Composer | no |
| Front-end build (steps 6–7) | yes (customer/seller views use the new hint line) |
| Version | `VERSION` 2026.11.07.5 |

## What's in it (owner 2026-11-07)
- **Saved beside the button, on every admin, seller, restaurant, partner and rider screen.** On long pages the
  "Saved" message sat at the top, out of sight. Now, after pressing Save (any form submit, or a save / update /
  create / approve … button), the screen's own result appears right next to that button: green with the screen's
  message, or red "Not saved: fix the fields marked in red" (and the first red field scrolls into view). If the
  button has scrolled away, the same note also pops up at the bottom right. Nothing is shown when the screen gave no
  result (never a made-up "Saved"). One file, `public/js/admin-save-feedback.js`, loaded by the four layouts; the
  old session-message boxes are marked `data-flash` so it can read them. It runs once per tab
  (`data-navigate-once` + a guard in the file), so moving between partner pages never doubles the note.
- **Best image size under every image upload** (21 uploads: banners, category / sub-category icons, service
  pictures, homepage door pictures, parcel picture and icon, site logo, franchise picture, shop logo and cover,
  product photos, job evidence, rider safety photo, KYC documents, customer claim and return photos), e.g.
  "Best size 1920 × 640 px (3:1 wide) · PNG or JPG · up to 2 MB. Keep words and faces in the middle…". All sizes in
  one place: `App\Support\ImageSizes`; the MB shown is tested against each screen's real limit. Customer lines are
  Website text ("Photo upload hints", en + te).
- **Menu (dish) photos have their own size:** Restaurant → Menu shows "Best size 800 × 800 px (square) · JPG, PNG
  or WebP · up to 4 MB. The dish itself in good light…" (shops keep the product line), and dish photos are shrunk to it.
- **Automatic shrinking of display pictures.** Banners, category / sub-category icons, service pictures, homepage
  door pictures, parcel picture and icon, franchise picture, shop / restaurant logo and cover, product photos and
  restaurant menu (dish) photos are now saved
  at the size the site shows (the best size above, already 2× for sharp phones) instead of the full phone photo.
  It only ever makes a picture smaller, keeps its shape (never crops, never enlarges), keeps PNG transparency, turns
  phone photos the right way up, and drops hidden photo data (camera, GPS). If the result would not be smaller, or
  the file is GIF / SVG / unreadable, the upload is saved exactly as before; a shrink problem never blocks a save.
  One file: `App\Support\ImageShrinker`. Proof files are NOT shrunk (job evidence, KYC, rider safety, parcel claim
  and shop return photos stay original). Site logo keeps its own small copies as before. Pictures already uploaded
  are not changed; only new uploads are shrunk.

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Franchise page: change a word, scroll to the bottom, Save → green note beside Save.
- Same screen: clear "Hero: heading", Save → red "Not saved" beside Save, page scrolls to the field.
- Admin → Banners, Categories, Services: size line under each picture upload.
- Shrinking: upload a big phone photo (3–6 MB) as a test banner, keep it inactive, open the stored picture
  (right-click → open image in new tab): it should be about 1920 px wide and a few hundred KB. Delete the test banner.
- Tail of storage/logs/laravel.log: no new errors.
