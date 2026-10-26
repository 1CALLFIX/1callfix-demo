# Release 2026-10-25 — delivery riders join through /partners

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `073b7f9` (Parcel Stage 1) |
| Full test suite | 4083 of 4084 pass; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new Tailwind classes on the rider dashboard, rider payments and the worker review screen) |
| Version | `VERSION` file bumped to `2026.10.25.3` |

## What's in it
Owner rule 2026-10-25: every partner (service professional, delivery rider, shop owner, any future role) joins
through `/partners`, then the same procedure: mobile OTP, document upload, admin approval.
- **/partners → Delivery rider** now hands off to the sign-up (card shows "Now hiring" / "Apply now") as soon as
  parcel prices are complete for at least one franchise. Parcel does not have to be switched on for customers:
  riders are signed up first (the Modules screen still needs an approved rider before parcel can be activated).
  Without prices it stays a waiting-list entry, as before.
- **The one sign-up page** (`/provider/register`) takes a rider from that hand-off: name and mobile prefilled, OTP,
  the field-worker document list (ID, address proof, bank details; driving licence and RC optional), no services
  step. It creates a **pending** rider (field worker + `parcel_rider`, two-wheeler) and marks the lead converted.
  A number that already has an account is told to sign in, as for providers.
- **Rider app:** a pending rider can sign in at `/rider/login`; the dashboard says the documents are under review
  and there is no Go online until approved (dispatch already skipped unapproved riders).
- **Admin → Workers → Review:** per-document Approve / Reject (reason required), copied from the provider screen.
  Until now a worker's pending documents could not be approved one by one, so the worker could never be approved.
  Then Approve Worker as before.
- Admin "Add parcel rider" stays for walk-ins. The Modules readiness message now points to /partners + Review.
- **Rider payments:** riders get the partner **Payment Accounts** (bank / UPI, verified by admin) and **Request
  Payout** screens, reused as they are, under Earnings. Cash dues are recovered first and a payout is refused
  while any remain (PayoutService, unchanged). Admin pays from Finance → Payouts as before.
- **Rider sign-in:** "Apply on the partner page" (→ /partners#join) replaces "Ask your local office", plus
  **Forgot password?** (the shared reset page).
- **Test rider (new thumb rule: every module ships with a test login):** `php artisan test-accounts create` now also
  makes `test.rider@1callfix.test` (mobile 6000000109), an approved parcel rider in the chosen franchise. It counts
  as a ready rider, so Admin → Modules can switch Parcel on for testing. `test-accounts disable` switches it off.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new (`parcel:release-scheduled`, `parcel:expire-unpaid` still listed)

## Browser checks after deploy
- `/partners`: the Delivery rider card shows **Now hiring** and **Apply now**; choosing Delivery rider in the form
  shows **Continue to sign-up** (no waitlist note).
- Your earlier test entry (Delivery rider, Nellore) is still in Admin → Partner Leads as `waitlist`; applying again
  on /partners with the same mobile moves it to `handed_off`.
- Admin → Workers → open any worker with documents: each document has Approve / Reject.

## Test login and walk-through (owner, on the server)
`php artisan test-accounts create --password='<your test password>' --franchise=<Nellore franchise id>`
The one password applies to every test login, including the test admin roles, so do not use an easy one like
"password" on the live site. Then: Admin → Modules → Parcel → Activate for that city; sign in at /rider/login as
`test.rider@1callfix.test`, Go online (allow location); as a customer book a parcel at /parcel; accept the offer as
the rider and finish pickup and delivery with the codes. While Parcel is on, real orders in that city go to this
rider too; switch Parcel off again (or run `test-accounts disable`) before real riders are approved.

## Admin settings to do after deploy (owner)
- Decide whether **driving licence** and **vehicle RC** should be required for riders (today they are optional for
  all field workers; the required list is ID, address proof, bank details). There is no admin screen for this list
  yet; changing it is a small code/data change.
- Ask waitlisted riders to apply again on /partners (no SMS is sent automatically).
