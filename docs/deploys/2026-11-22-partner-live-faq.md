# Release 2026-11-22.7 — Partner page FAQ with live answers

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `e10a4f1` (2026.11.22.6 Post now + time chips) |
| Full test suite | passed 5169 of 5169 (local, 2026-11-22); known failures: none |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no (the partner page's styles live in its own view) |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.22.7 (2026.11.22.8: "an event organiser", not "a event organiser") |

## What's in it
Owner 2026-11-22: "check thoroughly and add more practical and required questions and also add the faq's with realtime
data and also check the partner page thoroughly about which is live and any data mismatch is there".

Mismatches found on live before this release:
- FAQ "Riders and drivers": "These roles are not open yet", while Delivery rider shows **Live · Apply now**.
- FAQ "Shops and restaurants": "These roles are not open yet", while Restaurant partner, Seller or shop, Hotel,
  Function hall, Property owner and Event organiser show **Live · Apply now**.
- FAQ "What documents do I need?" was vague although every kind of partner has a real list in Admin → KYC documents.
- Step 4 "Open the 1CallFix Partner app, go online…" did not fit hotels, shops and owners.

Changes:
- **Live answers** (new `App\Support\PartnerPage\PartnerFaq`), marked with a green **LIVE DATA** tag, are read at page
  time from the app itself: roles open now and opening soon (module registry), live cities (city on + active
  franchise), documents per kind of partner (Admin → KYC documents, needed vs. optional), upload size, payouts
  (minimum payout, payout timing when set, cash-job rule), visit charge (₹ from Cancellation settings, with the no-show
  wait and call count), the mid-work cap (%), parcel weight / distance limits, rider cash, cab driver status, franchise
  enquiries (link to /franchise), service categories customers book. A fact that is not set is never invented: its
  question is left out (support phone) or answered without a number (commission).
- Admin switch **Show live answers in the FAQ** (Partner page, default on). The admin's own questions show below them.
- Default questions: kept "Can I choose my own hours?" and "How does a job start and finish?"; added "Do I need a
  smartphone?", "Can I apply for more than one role?", "Can I add extra work or parts during a job?", "Can I cancel a
  job I accepted?". Removed the stale "not open yet" answers.
- Tab "Shops and restaurants" is now "Shops, stays and owners" (hotels, halls, property and event organisers are there).
- Step 4 is now "Start working" (dashboard; professionals and riders go online, businesses add their listings).
- Page cache is 60 s and any admin save flushes it, so a change shows within a minute.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- /partners#faq: Everyone → "Which roles are open right now?" lists Delivery rider and Restaurant partner under Apply now.
- Riders and drivers → "Are delivery rider jobs open?" says Yes; documents list shows Government ID proof etc.
- Shops, stays and owners → "What does a restaurant partner need?" shows FSSAI licence or registration.
- No "These roles are not open yet" anywhere on the page.

## Admin settings to do after deploy (owner)
Blank on live, so their answers stay general until set (Admin → Partner page):
1. Commission range (lowest / highest %) or each role's terms line.
2. Payout timing note and approval time note.
3. Partner support numbers (none set; the page shows no help line).

## 2026.11.22.10 — cab driver answer
Owner 2026-11-22 ("please check and fix When will cab driver jobs open?"). Taxi is not built for customers yet
(`is_implemented` false, on nowhere), so the fact was right but the question promised a date and the answer was a dead
end. Now "Can I join as a cab driver?": "Cab rides are opening soon. Pick "Cab driver"… we will call you on your mobile
as soon as rides start in your city." plus "Want to earn now? Delivery rider jobs are open in <live cities>." only while
rider sign-ups are open. No migrations, no build. Full suite 5171 of 5171.
