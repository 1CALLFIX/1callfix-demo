# Release 2026-11-16 (3) — account menu: Wallet, Refer a friend, Coupons, Contact us, Rate & Review, Call / WhatsApp

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `b0ad848e` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new menu markup uses new Tailwind classes) |
| Version | `VERSION` file bumped to `2026.11.16.3` |

## What's in it
- Header account menu, group "Your account": My Orders, Saved, **Wallet** (only while the Wallet tab is on in Earnings),
  **Coupons** (`/offers`), **Refer a friend** (only while the Referrals tab and the referral program are on), Earnings
  (loyalty points, only while that tab is on), My account. Signed out: Sign in plus the same entries.
- Group "Explore & help": Categories, Join as a Partner (as before: only for people who can still add a partner role and
  while the header partner button is on), **Rate & Review**, **Contact us** (`/contact`), Help Center.
- **Rate & Review**: in a browser it opens the Google review link set in Admin → System → Google reviews, in a new tab;
  hidden while no link is set; inside the Android app it is not shown (the Play Store rating line stays).
- **Need help?** strip at the bottom of the menu: Call and WhatsApp buttons from Admin → Website & Pages → Contact page;
  each hidden while its number is empty.
- My Orders: "Need help with an order? Contact us" line at the bottom.
- New website-text keys (Admin → Website text, with Telugu): `header.menu.wallet`, `.refer`, `.coupons`, `.rate_review`,
  `.contact`, `.need_help`, `.call`, `.whatsapp`.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Signed in: open the avatar menu: Wallet / Refer a friend appear only if their Earnings tabs are on; Coupons, Contact
  us, Help Center are there; Rate & Review appears once a Google review link is set; Call / WhatsApp strip appears once
  the Contact page has a phone / WhatsApp number.
- Phone: the menu scrolls inside the screen, nothing is cut off.

## Admin settings to do after deploy (owner)
- Admin → System → Google reviews: set the "write a review" link (Rate & Review shows only once it is set).
- Admin → Website & Pages → Contact page: set the phone and WhatsApp numbers (the Call / WhatsApp strip shows only once set).
