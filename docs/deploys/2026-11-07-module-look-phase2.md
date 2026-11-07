# Release 2026-11-07 — New look, Phase 2 + short footer on phones + Contact and Franchise pages

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `b516d15` |
| Full test suite | see the chore commit after this one |
| Composer | no |
| Front-end build (steps 6–7) | yes (new `cf-card` style) |
| Version | `VERSION` 2026.11.07.4 |

## What's in it
- **Trust strip on every service page**, under the name, in the page's colour: Verified professionals (ID and
  documents checked) · Your OTP starts the job (and closes it) · Rate every job. Only true promises. Words:
  Admin → Website text → "Service page trust strip" (English + Telugu).
- **One card style everywhere** (`cf-card`): service, product, shop, restaurant and Saved cards share the same
  corners, border and hover; the hover takes the business's colour. Food and Shop pages lose their leftover blue
  (shop header, category chips, product Add/Choose buttons, food filters, restaurant cart buttons).
- **Saved tabs:** All · Services · Products & dishes · Shops & restaurants, only for kinds you saved, with counts.
- **Short footer on phones and in the apps** (owner 2026-11-07): the Company / Services / For professionals /
  Legal columns are hidden on phones (still in the page for search engines) and not drawn at all inside the apps,
  nor the "Get the app" buttons. The same links are in the ☰ menu under "More" on phones. Footer keeps the logo,
  social icons, "All rights reserved" and "Made in Love with India". Desktop unchanged.
- **Homepage membership box** (owner 2026-11-07): premium dark-blue box with a gold "Members save more" label;
  each plan is a white card with price, "Benefits worth ₹…" (only when the benefits add up to more than the
  price) and its benefits with ticks, from the plan's own benefits in Admin → Plans. Button "See all benefits &
  join" (was "About membership"). Super Admin controls: Homepage & sign-up → Membership box (benefits to list,
  0–10, default 4; worth chip on/off); words in Website text → Homepage; on/off and position in Homepage sections.

- **Contact page** `/contact` (`/contact-us` 301s there): Call, WhatsApp, Email, hours, office address + map,
  grievance officer (from Shop Settings), help / bookings / partner boxes, social links. Details: Admin → Website &
  Pages → **Contact page** (Super Admin only, all blank at first: the page says "listed here soon" until filled).
  Words: Website text → "Contact page" (en + te).
- **Franchise page** `/franchise` (`/franchise-enquiry` 301s there): hero, live numbers from the app (services,
  businesses live, approved partners; test logins never counted; a 0 is hidden), why tiles, what you get, how you
  earn, investment box, who we look for, 5 steps, cities, partner stories, FAQ (Google FAQ markup), closing band,
  small print. Every word, list, picture and switch: Admin → Website & Pages → **Franchise page**, Super Admin only
  (checked against the role, not a grantable permission), audit-logged, [ ] placeholders refused. Investment figures,
  cities and partner stories ship EMPTY (hidden) — fill them only with real, agreed facts.
- **Applying = the one /partners form** (owner rule): new "Franchise owner" card ("Taking applications"); the
  lead is saved as `new` in Admin → Partner leads (role "Franchise owner") for HQ to call; no sign-up hand-off.
- Footer: "Contact us" under Company, "Franchise" under For professionals (also added to a saved custom footer);
  both in the sitemap; both hidden everywhere when switched off.

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy (phone width)
- A service page: the three-box trust strip under the rating.
- Homepage at phone width: footer has no link columns; ☰ menu ends with "More" (How It Works, Help & FAQs, …).
- Desktop: footer columns still there; ☰ menu has no "More".
- Homepage: membership box shows the plan's benefits and "See all benefits & join".
- /franchise (phone + desktop): hero, numbers strip, Apply → /partners with "Franchise owner" picked.
- /contact: "listed here soon" until the owner fills Admin → Contact page.
- Account → Saved with a service and a product saved: tabs, and tapping Services shows only the service.
- Tail of storage/logs/laravel.log: no new errors.
