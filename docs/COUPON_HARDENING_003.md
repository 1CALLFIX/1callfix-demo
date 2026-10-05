REF: 1CF-COUPON-HARDENING-003 (complete, self-contained)
DATE: 05 Oct 2026 IST

Read CLAUDE.md and docs/WORK_QUEUE.md first.

STEP 0: Save this entire prompt, unchanged, as
docs/COUPON_HARDENING_003.md (do not commit it yet). If context is
ever cleared, re-read that file. Do not ask me to paste it again.

BASE: main 44a7f0e. Branch feature/coupon-engine-c1-c3. C2 = 914f2e7.

ABSOLUTE LIMITS
Do NOT: start C3, merge, push, deploy, change production, modify
unrelated work, or create/run any migration on the real project
database. Scratch exception: you may migrate a SCRATCH COPY (never
database/database.sqlite) for browser checks. Say so in the report.
New migrations are proposals only: show UP/DOWN SQL and wait for my
approval.

CODE-PROTECTION RULES (from me)
- No rework, no duplication, no spoiling built code. Extend what
  exists: CouponService, TargetMatcher, CouponSettings,
  CouponHoldSweepService, CouponDispatchGate, CouponAdminService,
  existing migrations, CouponEngineTest, CouponAdminScreensTest.
- Additive changes only. No refactors of working code. No pint on
  existing files.
- Before writing any new class or check, search for an existing one
  and extend it. The shared online-payment guard must be ONE class
  that existing benefit paths call.
- Run affected suites after every commit. Full suite at the end.

STANDING RULES
- No invented policy. Any policy-like value or behavior (fees,
  refunds, windows, limits, defaults) goes in a "Decisions needed"
  list in the report. Ship it null or off until I answer.
- No real credentials in context or output. No production dumps.
- Raw evidence in every claim: file and line, test name, query output.
- If two sections conflict or a rule is ambiguous, stop and ask.
- For each fix: commit the failing test first, then the fix.
- Commits (unpushed), four separate: (a) engine and targeting
  [E,F,G,H,K], (b) payment and cash guards [A,B,C,D,I], (c) admin
  permissions and settings [J,L], (d) tests and report-only artifacts.
  Show me the commit list before stopping.

A. ONLINE PAYMENT ONLY (confirmed standing rule)
Every coupon, referral code, promotional discount, auto-applied
offer, challenge, bulk coupon, discount, and wallet or referral
credit use is valid only on approved online payment: Razorpay,
wallet, or wallet + Razorpay. Cash-only is not eligible. A coupon
booking must never be converted to cash while keeping the benefit.
Enforce server-side on every path. Customer message:
"Offers apply on online payment only."
Create ONE shared online-payment guard that every benefit type calls.

B. CUSTOMER WHO WANTS CASH
The coupon is not kept. The customer may cancel and create a NEW
booking in cash at the full applicable price. Example: service 500,
coupon -100, online payable 400; customer chooses cash, so coupon is
invalid; cancel and rebook gives service 500, coupon 0, pay 500 cash.
Never preserve the benefit because the customer says they did not
know. Messaging must explain the rule clearly.
Trace what happens today when a customer cancels a coupon booking to
rebook in cash: cancellation fee, free-minutes window, refund of any
online payment. Report only. Do not decide fee policy. List it under
Decisions needed.

C. PARTIAL CASH + ONLINE
Not eligible. Example: 500 service, 100 coupon, 400 payable; customer
tries 200 cash + 200 online: no benefit. "1 rupee online + rest cash"
must not unlock the coupon. The entire coupon-eligible payment must
be digital. Report whether split payments exist today and where. If
they do not exist, add a rejecting guard and test only. Do not build
split payments.

D. LATER ADDITIONAL CHARGES
A later separate charge paid in cash (approved extra work) must not
invalidate the original coupon. Original eligibility is judged on the
original booking and payment. Before changing any guard, report how
mid-job extras are recorded and paid today, and whether paying them in
cash writes to bookings.payment_method or anything the coupon guard
reads. The guard must stay strict for every other path.

E. EMPTY TARGETING
A coupon with no targeting is currently accepted and applies
everywhere. Fix it. Blank city, franchise, module or area targeting
never means everywhere. Provide an explicit "Global / All eligible
scope" choice requiring coupons.approve. Enforce in the form AND in
CouponAdminService. Before this goes in, report any existing coupons
in production-like data with empty targeting.

F. SERVER-AUTHORITATIVE FRANCHISE / ZONE / MODULE
CreateBookingAction trusts caller-supplied franchise context. Fix it.
Derive server-side: customer address, country, city, zone, franchise
or HQ-operated status, module, module activation, coupon eligibility.
Never trust client or caller supplied franchise_id, zone_id, city_id,
module or ownership context. Apply to bundle children too (they
arrive with franchise_id, zone_id and address_id from the caller).
A mismatch is rejected. The domain layer must protect itself even if
called from another surface. Run the full pricing, bundle, payment,
dispatch and coupon suites after this and report counts.

G. MODULE SUPPORT
Only Services is connected to the coupon engine. Parcel, Food,
Grocery, Pharmacy, Taxi, Hotel, Ecommerce and future modules are not.
A coupon is not redeemable just because a context object exists for a
future module. Add an explicit connected-modules check (config
registry, "service" only). Then use ModuleActivationService (do not
duplicate its logic and do not invent activation tables) to check the
module is enabled for the franchise or zone. Reject if not connected,
not enabled, or enabled but coupon integration is not implemented.
No usage row is created on rejection.

H. GENERIC CUSTOMER-FACING REJECTION
Every non-redeemable coupon returns the same text:
"This coupon cannot be applied to this order."
This replaces invalid_code, not_targeted, inactive, expired, usage
limit, daily cap, budget exhausted, wrong city, zone, module and
customer. Same message, same response shape. The precise reason stays
in the internal result, server logs, audit/activity log and admin
diagnostics. Update the existing daily_cap_reached and expired tests
and wording accordingly.

I. BUNDLE CASH CONVERSION
A bundle parent can currently be switched to cash. Fix at the
authoritative bundle/payment layer (mirror the Booking::booted guard).
Tests: coupon bundle, parent change to cash is rejected, no coupon
remains under cash, no inconsistent child payment methods, no bypass
through retry or admin mutation.

J. SUPER ADMIN SETTINGS
A holder with coupons.view + coupons.approve can currently change
coupons.enabled and unpaid_hold_minutes. Fix. Global coupon settings
are Super Admin only: coupons.enabled, coupons.unpaid_hold_minutes,
and later the rate limit, per-surface toggles and other global
controls. coupons.approve does not grant them. Every change is audit
logged.

K. NULL VS ZERO
Null = no limit. 0 = invalid. Positive = valid. For total usage
limit, total budget and daily cap. Enforce in the service/domain
layer, not only Livewire (usage_limit = 0 is currently blocked only
in the form).

L. VISIT / INSPECTION TEXT
Replace the old coupon UI text with exactly:
"Visit and inspection charges are separate from coupon discounts."
Remove any other wording on visit charges or extras. Do not change
visit-charge business logic. Rule stays: if work is performed the
visit/inspection charge is 0; if the provider arrives and no work is
done, the no-work charge is separate from coupons; the Prime free-
visit waiver is a separate entitlement.

M. PROVIDER ECONOMICS
Keep the regression: commission and payout are identical with and
without an HQ-funded coupon. Keep customer discount, commission,
payout, franchise settlement and platform marketing expense separate.

N. HISTORICAL IMMUTABILITY
Editing, pausing or archiving a coupon never changes existing booking
financials. Existing unpaid holds survive pause and archive.

O. OWNER SCOPE (inspect before proposing)
Inspect coupons.franchise_id and every use of it, the coupon target
table, the flash-sale scope model, and AuthorizationService scopes.
Write one paragraph defining coupons.franchise_id vs a possible
owner_scope vs coupon_targets vs funding_mode. Do not create duplicate
ownership concepts. If owner_scope_type/owner_scope_id is genuinely
required, give: schema evidence, why existing fields cannot do it,
exact UP and DOWN SQL, index impact, null/backfill behavior, affected
files. Do not run it.

P. DAILY-SPEND INDEX
Show the exact daily-cap query. Run EXPLAIN on MySQL only, never on
SQLite, using a local MySQL with seeded test data and no production
credentials. If useful, propose:
CREATE INDEX coupon_usages_coupon_status_reserved_idx
ON coupon_usages (coupon_id, status, reserved_at);
with exact DOWN SQL. Do not run it. Also write a rehearsal plan for
all six coupon migrations on MySQL, with extra attention to the
booking_id foreign key drop and re-add in 2026_10_04_100100. The
tests run on SQLite and production is MySQL.

Q. COUNTRY, SCOPE AND AUDIT (report only)
- Report whether coupon_targets and TargetMatcher support a country
  target. Report whether "all live franchises" already covers a
  whole-country coupon. Do not add a country type. Propose with
  schema impact if needed.
- "All live franchises/modules" is evaluated at redemption time, with
  an exclude list. A franchise not yet live is never reached.
- For coupon, referral code, promotional discount, auto-applied,
  challenge, bulk, wallet credit, referral credit, loyalty, flash
  sale and Prime discount: report whether each can apply to a cash
  booking today (file and line, test name or query output). Any
  change to flash sale, Prime or loyalty behavior needs my approval.
- Scope menu presets, customer selection by phone, and the reach
  preview are NOT part of this workstream (they are C2.2). Here,
  only prove the domain layer supports them via tests 37 to 41.

R. REQUIRED TESTS (all must exist)
1 Nellore coupon rejected for Guntur booking.
2 Client-supplied Nellore franchise cannot override Guntur address.
3 Services coupon rejected for Parcel.
4 Future-module coupon rejected until connected.
5 Coupon rejected when module disabled for franchise.
6 Empty targeting rejected.
7 Explicit global scope works.
8 Generic rejection message identical for every non-redeemable reason.
9 Bundle parent cannot change to cash while keeping coupon.
10 Cash-only coupon booking rejected.
11 Cash + online on coupon-eligible payment rejected.
12 Razorpay-only eligible. 13 Wallet-only eligible.
14 Wallet + Razorpay eligible.
15 Online-paid coupon booking stays valid when a later separate
   charge is paid cash (guard still strict elsewhere).
16 Payment retry cannot convert a coupon booking to cash.
17 Unpaid hold expiry cannot convert a coupon booking to cash.
18 Admin cannot change a coupon booking to cash.
19 Super Admin settings allowed.
20 coupons.approve alone cannot change global settings.
21 coupons.manage alone cannot change global settings.
   (also coupons.view only and franchise-scoped holder get 403)
22-24 usage_limit: null accepted, 0 rejected at service layer,
   positive accepted. 25-26 total budget null/0. 27-28 daily cap
   null/0 (cover positive for each).
29 Provider commission unchanged with HQ-funded coupon.
30 Provider payout unchanged with HQ-funded coupon.
31 Pause preserves existing unpaid hold. 32 Archive preserves it.
33 Confirmed-booking cancellation does not restore budget/daily cap.
34 Cancelling an UNPAID hold restores budget and daily cap (same
   test class as 33).
35 Bundle consumes one coupon usage.
36 Bundle budget and daily-cap accounting correct.
37 Completed service has no visit/inspection charge.
38 Coupon never reduces visit/inspection charge.
39 Coupon targeted to one franchise rejected in another.
40 Coupon targeted to one customer rejected for another customer.
41 "All live franchises" reaches a live franchise.
42 "All live franchises" does not reach a non-live franchise.
43 Every benefit type above rejected on cash, accepted online.
Also commit the passing probes from scratchpad/ArchProbeTest.php.txt
as real tests where they are not duplicates.

S. BROWSER VERIFICATION (scratch copy only)
After the fixes, click through /admin/coupons with screenshots: list,
settings card, new coupon, invalid daily cap, valid draft, targeting,
limits, funding display, activate, pause, resume, archive, edit,
permission denial, Super Admin settings access.

T. REPORT, then STOP
1 Files and lines changed. 2 Tests added or changed. 3 Relevant test
counts and probe counts. 4 Browser results. 5 Exact rejection
messages. 6 Schema findings and what coupons.franchise_id means.
7 Whether an owner-scope migration is actually required.
8 Daily-cap EXPLAIN. 9 Cash-audit table for every benefit type.
10 Extras and split-payment findings. 11 Country-scope findings.
12 Cancel-and-rebook trace. 13 Decisions needed list. 14 Remaining
gaps. 15 Whether C1/C2 now satisfy the permanent coupon rules.
16 Whether C3 is safe to begin. 17 Commit list.
Raw output only. STOP after the report.

=====================================================================
ADDENDUM 1 TO 1CF-COUPON-HARDENING-003

1. Commits (replaces "one commit"): four separate commits on this
   branch, unpushed: (a) engine and targeting (E, F, G, H, K),
   (b) payment and cash guards (A to D, I), (c) admin permissions and
   settings (J, L), (d) tests and report-only artifacts. Show me the
   commit list before stopping.

2. Extras path (Section D): before changing any guard, report how
   mid-job approved extras are recorded and paid today, and whether
   paying them by cash writes to bookings.payment_method or anywhere
   else the coupon guard reads. Test 15 must prove the original coupon
   stays valid AND the guard stays strict for every other path.

3. Partial payments (Section C): report whether split cash + online
   payment exists today and where. If it does not, add a rejecting
   guard and test only. Do not build it.

4. Unpaid hold cancel: add tests that cancelling an UNPAID hold
   releases the reservation and restores budget and daily cap, while
   cancelling a CONFIRMED booking does not. Both in the same test
   class.

5. Messages (Section H): update the existing daily_cap_reached and
   expired tests and wording to the generic message. Keep the real
   reason in the internal result, audit log and admin diagnostics.

6. Index and migrations (Section P): run EXPLAIN on MySQL only, never
   on SQLite. Use a local MySQL with seeded test data. No production
   credentials or dumps in this session. Also write a rehearsal plan
   for all six coupon migrations on MySQL, with extra attention to the
   booking_id foreign key drop and re-add in 2026_10_04_100100.

7. Shared online-payment guard: create ONE guard that every benefit
   type calls. Customer message: "Offers apply on online payment
   only." AUDIT ONLY: for coupon, referral code, promotional discount,
   auto-applied, challenge, bulk, wallet credit, referral credit,
   loyalty, flash sale and Prime discount, report whether it can apply
   to a cash booking today (file and line, test name or query output).
   Any change to flash sale, Prime or loyalty behavior needs my
   approval.

8. Extra tests, after the 36: 37. Coupon targeted to one franchise is
   rejected in another. 38. Coupon targeted to one customer is
   rejected for another customer. 39. "All live franchises" reaches a
   live franchise. 40. "All live franchises" does not reach a
   franchise that is not live. 41. All of the above reject on cash and
   accept on online payment.

9. Scope menu presets, customer selection by phone and the reach
   preview are NOT part of this workstream. They come in C2.2 after
   review. Here, only prove the domain layer supports them (tests 37
   to 41).

=====================================================================
ADDENDUM 2 TO 1CF-COUPON-HARDENING-003

1. Scratch exception: "Do NOT run any migration" applies to the real
   project database and production. You may migrate a scratch copy
   (never database/database.sqlite) for the browser check, and you
   must say so in the report.

2. Country scope: report whether coupon_targets and TargetMatcher
   support a country target today. Report whether "all live
   franchises" already covers a whole-country coupon. Do not add a
   country type. If one is needed, propose it with schema impact.

3. Cancel-and-rebook (Section B): trace what happens today when a
   customer cancels a coupon booking to rebook with cash:
   cancellation fee, free-minutes window, refund of any online
   payment. Report only. Do not decide the fee policy. List it as a
   decision for me.

4. No invented policy: any policy-like value or behavior (fees,
   refunds, windows, limits, defaults) goes into a "Decisions needed"
   list in the report. Ship it null or off until I answer.

5. Order of work: for each fix, commit the failing test first, then
   the fix. Run the affected suites after every commit. If two
   sections conflict, or a rule is ambiguous, stop and ask. Do not
   choose.

6. Report raw output and stop.
