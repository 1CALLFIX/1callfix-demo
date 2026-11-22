# Release 2026-11-22.3 — Post any item, franchise social posts (HQ approval), partners share their business

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `1f035fd` (2026.11.22.2 Facebook & Instagram auto-posting) |
| Composer | no |
| Migrations | `2026_11_22_100000_franchise_owners_get_social_posts` (grants `marketing.posts.manage` to `franchise_owner`) |
| Front-end build | no: customer / partner views use only classes already in the built CSS; `public/js/social-share.js` is a plain file |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.22.3 |

## What's in it
Owner 2026-11-22: "each and every single product can also be posted … even a franchisee's will also social post
section … ease of use for every shop, hotel, user, businesses and all".

- **📣 on every item photo** (beside ♡ and Share; every product, shop, restaurant, dish, hotel, hall, event, vehicle,
  home, rental post, service): only for people with `marketing.posts.manage`. Opens Social posts with the item's
  picture, words, link, module and city filled in. Customers never see it (signed-in pages are not page-cached).
- **Franchise owners** get Social posts for their own city. Their scheduled posts wait as **"Waiting for HQ
  approval"**; HQ (Super Admin or a whole-company marketing grant) sees **⏳ Waiting for your approval** with Approve /
  Edit / Send back (note required). Only approved posts go out automatically or appear under Today.
- **Partners** (shops, restaurants, hotels, halls, events, vehicles): **📣 Share your business** on their dashboard
  with their live pages only, picture and ready words, one tap to the phone's share sheet (in the Android apps via
  @capacitor/share; WhatsApp and Facebook show the page picture from the link). Links carry `utm_source=partner`.
- Automatic posting to partners' **own** Facebook / Instagram needs Meta App Review + Business Verification (later).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_22_100000_franchise_owners_get_social_posts`
