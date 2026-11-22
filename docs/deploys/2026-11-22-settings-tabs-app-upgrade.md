# Release 2026-11-22.1 — Settings: no more "(soon)" tabs, App Upgrade

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `3963c03` (2026.11.21.8 module page banners) |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no. Admin screen uses the admin layout's Tailwind CDN; `public/js/app-update.js` is a plain file in git with inline styles |
| New scheduled commands | none |
| Version | `VERSION` 2026.11.22.1 |

## What's in it
Owner 2026-11-22: "in admin panel → settings still many are showing coming soon, please check and fix".

- **App Upgrade** (real setting, Super Admin only, global): per app (customer / partner / rider) the newest Play Store
  version code and what older phones see: Do nothing / Ask to update (Later button, quiet for a day) / Must update.
  Inside the Android apps only, `public/js/app-update.js` reads the phone's build with the Capacitor App plugin (in
  every shell since the first one) and shows the card; a browser, or a build it cannot read, is never asked.
  Wording in Website text & language → "Update the app screen" (en + te). Audit-logged.
- **Mobile Apps**: the three apps with their Play Store pages, the one share link (`/app?go=1`) with Copy, the current
  Android / iPhone links, and links to App download links, App Upgrade and Notifications.
- **Vendor / Provider, Customer, UI / Home Screen, In-App Support, Advanced / System**: signposts to the screens built
  for those settings since (Partner page, documents, KYC, Homepage sections, Banners, Contact page, Modules, Backup …),
  each link shown only to roles that can open it. In-App Support shows the current phone / WhatsApp / email / hours;
  Advanced / System shows version, mode, error details, queue and page cache status.
- **Dynamic Links** tab removed: deep links need a new app build; the share link it would have held is on Mobile Apps.

## Step 10 — checks
- Admin → Settings: no tab says "soon"; App Upgrade shows three coloured cards.
- Leave App Upgrade on "Do nothing" until a new app version is live on the Play Store.
