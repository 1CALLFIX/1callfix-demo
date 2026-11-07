# Release 2026-11-07 — Partner page shows Food and Shop as Live; Razorpay no longer asks for name and mobile

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `6de9c60` |
| Full test suite | 4580 tests, 4579 passed; only the known NotificationCenterAuditTest .env failure |
| Composer | no |
| Front-end build (steps 6–7) | no (Blade only, no new CSS classes; skip 6–7) |
| Version | `VERSION` 2026.11.07.8 |

## What's in it (owner 2026-11-07)
- **/partners → Pick your role:** Restaurant partner and Seller or shop showed "Opening soon" although Food and Shop
  are live in Nellore, and Delivery rider showed "Now hiring". Cause: the cards asked "is the module on for the whole
  country?", but modules are switched on per franchise/city. A card is now **Live** when its module is on in at least
  one place (`ModuleActivationService::isActiveAnywhere`).
- The card's button now says what really happens: **Apply now** when the form continues into the partner sign-up,
  otherwise **Join the waitlist**. Restaurants and sellers still only sign up when Super Admin opens it
  (Food Settings → "Take restaurant sign-ups", Shop Settings → "Take seller sign-ups"), unchanged.
- Delivery rider default text no longer says "Join the waitlist and be first in line".
- **Razorpay prefill:** every Razorpay checkout (service booking, bundle, shop checkout and order, parcel, parcel
  packs, membership, wallet top-up, partner dues — 11 screens) now passes the signed-in person's name, mobile
  (+91…) and email (`App\Support\RazorpayPrefill`), so the payment window goes straight to UPI / card. Guests are
  asked by Razorpay as before. A test fails for any future Razorpay checkout without it.

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- none new

## After the deploy (owner, Super Admin)
- To let restaurants and shops apply straight away: turn on "Take restaurant sign-ups" (Food Settings) and
  "Take seller sign-ups" (Shop Settings). Their cards then show **Apply now**.

## Browser checks after deploy
- https://1callfix.com/partners#join: Service professional, Delivery rider, Restaurant partner, Seller or shop show
  **Live**; the other five show Opening soon.
- Book a service with online payment while signed in: Razorpay opens without asking for name and mobile.
