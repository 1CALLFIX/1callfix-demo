# Full function check: app and admin panel (2026-10-17)

Owner's request: before pushing and merging, check every option in the app and the admin panel, look closely at
anything doubtful, skip what is proven to work, and keep a progress report.

Branch checked: `fix/l3-l8-provider-standing` (`beec33f`, plus the fixes listed under "Fixed during this check").
Nothing was pushed, merged or deployed. The live site was only read (public pages and read-only database
queries). Every click and save ran on a throwaway copy of the local database.

## Progress

| # | Layer | What was done | Status | Result |
|---|---|---|---|---|
| 1 | Pages | Every web page (136 addresses) opened as 9 roles: guest, customer, provider, Super Admin, franchise owner, zone manager, support, city admin, country admin. 1,224 requests, a fresh app for each, like a real visit | Done | **0 real errors.** Four admin file links crash on a non-number ID (fixed, see below). Every 403/404 checked: each is a correct refusal, a switched-off feature or a deleted test record |
| 2 | Admin tabs and buttons | 105 screens loaded; every tab opened; every public action (button) pressed with plausible inputs: 1,262 calls | Done | **0 real errors.** The remaining failures were checker artefacts (upload internals, wrong test inputs, network calls blocked on purpose) |
| 3 | Actions with no automated test | 244 of 619 actions had no test. Each was pressed in layer 2; the money and settings ones were then checked by hand (below) | Done | Settings: 1 bug (fixed). Money: approval-model gaps (F1, F2) |
| 4 | Settings "Save" on every tab | All 20 Save buttons pressed without edits; every value that changed was compared with the value the app uses when the setting is unset | Done | 1 bug: Branding renamed the public site (fixed). All other first saves store the same value the app already used |
| 5 | Mobile-app API | 66 API read endpoints as guest, customer, provider, Super Admin (264 calls) | Done | **0 server errors.** 401/403/404 all correct |
| 6 | Live site links | Read-only crawl of https://1callfix.com public pages, two levels deep (72 addresses) | Done | **0 broken links**, none slower than 2 s |
| 7 | New features in a real browser | Local server on the copy; signed in as a test provider and a test admin | Done | All work; 1 wording gap (fixed). Details below |
| 8 | Full automated test suite | All test folders on the branch, including the fixes | **Stopped** | The computer ran low on memory and the run was stopped after 5 of ~70 folders (Unit, Acquisition, Admin, Ai, AllUsers: 124/124 pass). Must be re-run in full before the push (deploy rule step 1), ideally with Chrome closed |

## Findings

| ID | Severity | Where | What happens | Status |
|---|---|---|---|---|
| F1 | **Blocker (money rule)** | Admin → Bookings → a booking → "Waive charge" and "Resolve dispute" | Anyone holding `bookings.reassign` for that booking's area (franchise staff included) can waive a customer's cancellation charge, which also **refunds automatically** what was paid, or correct a disputed amount. No dedicated permission, no amount limit, no second approver, no queue. On Admin → Cancellation policy the same two actions are Super Admin only. Both break the CLAUDE.md "Manual money actions — approval model" rule | Open: needs the approval model, same work as L7 |
| F2 | **Blocker (money rule)** | Admin → Bookings → a booking → Cancel → "Waive fee" checkbox | Anyone holding `bookings.cancel` can cancel with the cancellation fee waived. Same approval-model gap | Open, together with F1 |
| B5 | Blocker (known) | Admin → Payouts "Mark paid / Mark failed"; Earnings Control wallet adjustment (W3) | Outside the approval model (already in GO_LIVE_READINESS.md) | Open: L7 |
| F3 | Medium | Admin → Settings → Branding & Links → Save | The live site has no platform name saved. The form pre-filled "1CallFix Admin", so one untouched Save renamed the public header, footer and page titles to "1CallFix Admin" | **Fixed** (`7813be6`) + test |
| F4 | Low | Provider → Payments → Wallet & dues, when online payment is off | The page explained a "Pay" button that was not shown and never said how much to pay | **Fixed** (`7813be6`): now "pay ₹X to your Franchise Office" + test |
| F5 | Low | `/admin/kyc/documents/provider/{id}`, `/field-worker/{id}`, `/admin/kyc/videos/{id}`, `/admin/chat/attachments/{id}` | A non-number ID gave a server error (500) instead of "not found". Admin only, nothing leaked | **Fixed**: the four routes accept numbers only, so other IDs get "not found" + test |
| F6 | Owner action | Live site `/about`, `/contact`, `/refund-policy` | The pages already exist on production as **empty drafts** (that is why they show "not found"). Write the text in Admin → CMS and switch each one on. Footer links appear automatically | Owner (S1) |
| F7 | Info | Customer `/earnings/wallet`, `/loyalty`, `/referrals` | Show "not found" because the Earnings feature is switched off (EARN3, off by default). Correct | No action |

## New features checked in the browser (local copy)

Test setup: maximum unpaid commission ₹1,000; minimum balance ₹200, switched on; unpaid commission counts against
the minimum; business WhatsApp 9014609609. Provider A: wallet ₹300 and owes ₹1,200. Provider B: no dues.

| Check | Result |
|---|---|
| Provider Wallet & dues page shows both blocks (over the ₹1,000 limit; ₹-900 after dues, below ₹200), balances, limit, minimum and the job-by-job list | Pass |
| "Settle dues from wallet": ₹300 applied, dues fall to ₹900 (now under the limit), the minimum still blocks; amount to restore = ₹1,100 (900 + 200 − 0) | Pass |
| Provider dashboard "Will you get jobs?" shows "Wallet & dues in order ✕" with the reason | Pass |
| Admin booking page: eligibility per provider with the exact reason; NOT ELIGIBLE in the assign list | Pass |
| WhatsApp and Call buttons only for the eligible provider; message has the service and the business number, **no customer name, phone or full address**; opens in a new tab | Pass |
| Assigning a not-eligible provider without a reason is refused with the reason shown | Pass |
| With a reason: assigned, and logged as "Admin assigned an ineligible provider (override)" with admin, reason and the blocks overridden | Pass |
| Settings → Wallet shows the four new fields with the stored values; changing the limit to ₹1,500 and saving stores it | Pass |
| "Pay ₹X now" (Razorpay) | Not tested here: live keys mean real money. Covered by automated tests up to creating the order |

## Cannot be checked from here (owner, on a phone or the live accounts)

- One real ₹1 payment and refund through live Razorpay, including "Pay ₹X now" on Wallet & dues after deploy.
- Job-offer push notification on a real provider phone with the app closed.
- WhatsApp button opening the WhatsApp app on the admin's phone or desktop.
- Phone OTP sign-in (Firebase) and Google sign-in on the live domain.
- E-mail arriving in an inbox, not spam.

## Fixed during this check

- `7813be6` Branding Save keeps the public name "1CallFix" (F3); Wallet & dues states the amount to pay when
  online payment is off (F4). Two tests added; both test files pass (17 + 17).
- F5: the four admin file links accept numbers only (`routes/admin.php`); `tests/Feature/Admin/AdminFileRouteIdTest.php`
  fails on the old routes (500) and passes now. KYC + chat tests still pass (68/68).

## Method notes (for repeating the check)

- The checks ran on a copy of the local database, updated with the 21 pending local migrations; the developer database
  itself was not changed.
- A first crawl run showed ~150 "server errors" that were all caused by the checker reusing one app between requests
  (Livewire swaps the redirect helper). With a fresh app per request they disappear, so they are not counted.
- Records picked at random were sometimes soft-deleted test data; those 404s were re-checked with live records (all 200).
