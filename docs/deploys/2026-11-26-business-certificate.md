# Release 2026-11-26 (3) — "Verified 1CallFix Partner" certificate for every business partner

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `bb7b527` |
| Full test suite | passed 5,285 of 5,285; known failures only: none |
| Composer | composer.lock changed: **no** (reuses `chillerlan/php-qrcode` from the last release) |
| Front-end build (steps 6–7) | no — every class the new screens use is already in the live build (`app-CNS-_MDI.css`, checked 2026-11-26); the certificate and stickers use inline styles |
| Version | `VERSION` file bumped to `2026.11.26.3` |

## What's in it
- Owner 2026-11-26: a "Verified 1CallFix Partner" certificate for shops, restaurants (Shop app), hotels (Hotel app),
  function halls (Rental app, one per hall) and event organisers (Events app). House and vehicle owners have none
  (people renting out their own things, not a place customers walk into).
- Where: a 🏅 "Your Verified Partner certificate" card on each app's Home, and Shop app → More → My certificate.
  Only once 1CallFix approved the business and it is switched on; before that, numbered steps ("Being checked",
  "Needs changes", "Not sent yet", "Switched off").
- Certificate (A4 landscape, in the app's colour with a gold frame): business name, kind and city, owner name,
  partner ID (`1CF-NEL-S0012` shop, `F` restaurant, `H` hotel, `V` hall, `E` events), verified since (approval
  date), valid till (1 year), FSSAI for a restaurant, a "Verified Partner" seal, and a QR. Never the owner's phone
  or home address. Footer: "Issued by 1CallFix Solutions Pvt Ltd · 1callfix.com · Call +91 9014 609 609".
- "Download to print": a 2-page PDF — page 1 the certificate, page 2 two "We are on 1CallFix" stickers (door and
  counter) whose QR opens the business's own page on 1CallFix ("Scan to order food online", "Scan to book a room"…).
- The certificate's QR opens the same `/verify/{token}` check as the ID card: green "Verified 1CallFix partner" with
  a button to the business's page, red "Not active" (business switched off or owner suspended), amber "Certificate
  expired". After a year the partner taps "Get this year's certificate": new QR, the old print says "Not a valid card".
- Test logins: test.seller, test.restaurant, test.hotel, test.hall and test.organiser already have approved
  businesses, so the certificate shows straight away. **No `test-accounts create` needed.**

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_26_110000_create_business_certificates` (new table `business_certificates`; no enum change)

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- test.restaurant: Home shows the 🏅 card → My certificate shows the red certificate and the door sticker;
  Download to print opens the 2-page PDF; scan the certificate QR: green "Verified 1CallFix partner".
- test.seller (green), test.hotel (violet), test.hall (teal), test.organiser (pink): same, in their colour.
