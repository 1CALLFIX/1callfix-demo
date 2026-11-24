# Release 2026-11-24.3 — app notifications open signed in; one address for browsers, one for the apps

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `fa4e7ef` (2026.11.24.2) |
| Full test suite | passed 5251 of 5251 (local, 2026-11-24); known failures: none |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.24.3 |

## What's in it
Owner 2026-11-24: "when a service provider is offline and a service is booked the provider receives a notification,
but when it is pressed the app opens logged out".

Cause: the three Android apps open **www**.1callfix.com, where the person is signed in. Notification links are built
by the queue worker from `APP_URL`, which is `https://1callfix.com` (no www). Both addresses serve the site, and a
sign-in cookie belongs to one exact address, so the app opened `https://1callfix.com/provider` with no sign-in and
showed the sign-in page. Every app notification with a link was affected (partner, rider and customer), not only the
"go online" one. Browsers were fine: the website's service worker already opens a link on its own address.

- Server (works for the apps already on phones, no app update needed): a push to an app token now carries its link
  on the app's address (`config app.native_app_url`, default `https://www.1callfix.com`, env `NATIVE_APP_URL`).
  Browser pushes are unchanged. `App\Support\PushApp::appLink()`, `PushChannel`.
- Android (next Play Store build of all three apps): a tapped notification always opens on the app's own address,
  like the service worker; a bare path is joined to the address, not to the start page (`/app/partner` + `/provider`
  was wrong); the web view writes its cookies to storage whenever the app goes to the background, so a phone that
  closes the app keeps the sign-in. `mobile/.../MainActivity.java`.
- One address each (owner 2026-11-24: "address redirect to the other ... your call"): browsers use **1callfix.com**
  (already the address Google indexes: canonical tags, sitemap, emails), the apps stay on **www** (moving them would
  sign every app user out once). `RedirectToCanonicalHost`:
  - Apps, always on: a page of ours opened in an app on 1callfix.com goes back to www (302), so a link anywhere
    (banner, message) can no longer show an app user signed out.
  - Browsers: the existing switch Admin → SEO settings → "Host redirect" (off until ticked; now says the apps stay on www).
    When on, www and http go to https://1callfix.com with a 301. Apps are never redirected by it. GET pages only;
    posts, /api, Livewire, uploads, health check and the Razorpay webhook are untouched, as before.
- Shorts endings (`public/videos/shorts-ending-en|te.mp4`) re-rendered with the owner's 1CallFix wordmark rule
  (Arial Bold; 1 and Fix light blue, Call white).
- Checked and fine: every sign-in in the apps already uses "remember me" (`NativeApp::inApp()`), so the 2-hour
  session renews itself; no middleware signs partners out.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Test login and walk-through
- `test.provider` in the Partner app on a phone (current Play Store build is enough for the server fix): sign in, go
  **offline**, close the app (swipe it away). From a customer login book a service in the provider's zone and area so
  the "🔔 New job near you" wake-up push is sent. Tap it: the partner home opens **signed in** with the Go online
  switch. Same check for a job offer push while online (opens Jobs, signed in).
- After the next app build: repeat once with the new build.

## Browser checks after deploy
- A browser notification (admin alert or customer booking update) still opens its page.
- Before the switch: `https://www.1callfix.com/` answers 200. After the owner ticks it: `curl -I https://www.1callfix.com/nellore?x=1`
  gives 301 to `https://1callfix.com/nellore?x=1`; `https://1callfix.com/` 200; `curl -I -A "1CallFixApp/partner" https://1callfix.com/provider`
  gives 302 to `https://www.1callfix.com/provider`.

## Admin settings to do after deploy (owner)
- Admin → SEO settings → "Host redirect": tick it and press Save SEO settings. Someone signed in on www in a
  browser signs in once more on 1callfix.com; app users are not affected.
