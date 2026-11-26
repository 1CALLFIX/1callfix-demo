# Release 2026-11-26 (2) — 1CallFix ID card for partners and riders

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `ec4393c` |
| Full test suite | passed 5,277 of 5,277; known failures only: none |
| Composer | composer.lock changed: **yes** — adds `chillerlan/php-qrcode` 5.0.0 (+ `chillerlan/php-settings-container`), the QR on the PDF card. Run `composer install --no-dev --optimize-autoloader` (step 4). Needs ext-gd: live has it. |
| Front-end build (steps 6–7) | no — every class the new screens use is already in the live build (`app-CNS-_MDI.css`, checked 2026-11-26); the card itself uses inline styles |
| Version | `VERSION` file bumped to `2026.11.26.2` |

## What's in it
- Owner 2026-11-26: a professional 1CallFix ID card for the people who go to customers — service partners
  (Partner app → More → My ID card) and riders / drivers (Rider app → More → My ID card).
- Card: photo, name, work ("AC Repair · Plumbing", "Delivery Rider", "Mini truck driver"), partner ID
  (`1CF-NLR-P0075` / `1CF-NLR-R0012`), city, valid till (1 year), QR. Never the home address or personal phone.
  In the app's colour (Partner blue, Rider orange), brand wordmark on the blue pill.
- Shows on the phone, and "Download to print" gives an A4 PDF with front and back at real card size
  (85.6 × 54 mm) to cut and laminate, or to take to a print shop for a plastic card.
- Only after KYC approval and with a face photo the franchise approved: new optional document
  "Your photo for the 1CallFix ID card" on the service partner and rider document lists (Finish joining page,
  front camera, JPG / PNG only). Franchise approves it like any other document. Numbered steps on the card screen
  until then.
- Back of the card (owner 2026-11-26): **Blood Group** (e.g. O +ve, tapped from 8 chips) and **Emergency Contact
  No's** (one or two family numbers, each with a tapped "who": Father, Wife, Brother…), entered on My ID card after the
  photo is approved (the card and PDF wait for them; "Change blood group or numbers" later). A band at the bottom:
  "This is the property of 1CallFix Solutions Pvt Ltd. If found, please return it to the company. Call +91 9014 609 609"
  (name and number from the Contact page settings `contact.company` / `contact.phone`). Blood group and emergency
  numbers are printed on the card only, never on the public QR page.
- The QR opens `/verify/{token}` (public, noindex): a big green "Active 1CallFix partner" with photo, name, work, ID,
  or red "Not active" (suspended, KYC withdrawn, account switched off), amber "Card expired", red "Not a valid
  card" (unknown or replaced). Checked live on every scan.
- "I lost my card" and the yearly renewal make a new QR: the old card says "Not a valid card" at once.
- Owner 2026-11-26: the company number is written the easy way everywhere it is shown, "+91 9014 609 609"
  (Contact page call and WhatsApp, Franchise page, ID card, partner support numbers): `ContactDetails::pretty()`.
  Links (tel:, wa.me) and search-engine data keep the plain digits; numbers with words or starting with 0 stay as typed.
- test.provider, test.rider and test.truckdriver get an approved placeholder photo from `test-accounts create`.
- Shops, restaurants, hotels and halls: a "Verified 1CallFix Partner" certificate / counter sticker is the next
  release (no person card for them).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_26_100000_create_partner_id_cards` (new table `partner_id_cards` with `blood_group` and `emergency_contacts`; adds two optional
  `kyc_document_requirements` rows `id_card_photo` for `provider` and `field_worker`; no enum change)

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new

## After deploy (owner)
- On the server: `php artisan test-accounts create --password=<your test password>` (gives the test logins their
  approved photo; same password as before).

## Browser checks after deploy
- test.provider: More → My ID card shows the blue card; Download to print opens the PDF; scan the QR with a phone
  camera: green "Active 1CallFix partner".
- test.rider and test.truckdriver: orange card, "Delivery Rider" / "Mini truck driver".
- "I lost my card" → Yes: the old QR now says "Not a valid card", the new one is green.
- A real partner without a photo: My ID card shows "Add my photo" → Finish joining → upload a selfie → the franchise
  approves it in the partner's documents → the card appears.

## Admin settings to do after deploy (owner)
- none (Admin → Partner documents can rename or switch off the photo document)
