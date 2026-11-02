REF: 1CF-COUPON-HARDENING-003-FINAL
DATE: 05 Oct 2026 IST

Read CLAUDE.md and docs/WORK_QUEUE.md first.

STEP 0: Save this whole prompt, unchanged, as
docs/COUPON_HARDENING_FINAL.md (do not commit it). If context is
cleared, re-read that file. Do not ask me to paste it again.

BASELINE
main = 44a7f0e. Branch = feature/coupon-engine-c1-c3.
914f2e7 plus 9 hardening commits. Keep all of them. No squash, no
unnecessary rebase. Record git status, branch and git log -20 first.

LIMITS (apply to everything)
No push, merge or deploy. No production changes. No migration on the
real project database. Do not create a migration: if a step seems to
need one, STOP and show UP and DOWN SQL. A scratch SQLite copy is
allowed only for browser checks (never database/database.sqlite).
No invented business rules. No real credentials in context.

CODE PROTECTION
- Extend existing code. Search before writing any new class, check or
  setting. Never create a second CouponService, coupon engine,
  OnlinePaymentGuard, pricing engine, referral engine, loyalty engine,
  membership engine, or module-specific coupon engine.
- Additive changes only. No refactors of working code. No pint on
  existing files.
- Every production code change: failing test committed first, then
  the fix. One commit per business change.
- Run these suites after each change, sequentially, never --parallel:
  Pricing, Booking, Bookings, Payments, Cancellation, Dispatch,
  Membership, Coupons.
- Evidence for every claim: file and line, test name, raw output.
- If two rules conflict or something is ambiguous, stop and ask.
- Anything policy-like goes in a "Decisions needed" list. Ship it
  null or off until I answer.

LOCKED DECISIONS
D1  A new-customers-only coupon needs the explicit "Global / all
    eligible scope" choice (needs coupons.approve). Empty targeting
    never means global. Enforce in the form, CouponAdminService and
    the engine. Never trust a client-supplied franchise, zone,
    module or global flag.
D5  Flash sale discount: online payment only.
D6  Prime DISCOUNT: online payment only. Do not touch the free-visit
    waiver, the membership purchase or other Prime entitlements.
D7  Loyalty: earning stays allowed on cash and online bookings.
    Redemption: online payment only.
D8  Referral: referee discount is online payment only. Referrer
    reward is paid only after the referred customer's qualifying
    transaction is completed AND eligible online-paid. No reward for
    code entry, registration, booking created or assigned, or payment
    initiated. Cash or cash+online completion: no reward.
D9  Cancel and rebook: existing cancellation policy, unchanged. A
    later cash booking gets normal pricing and no coupon.
D12 Raw payment_method updates: grep and report only. No DB
    constraint, no migration.

"Online payment only" means Razorpay, wallet, or wallet + Razorpay.
Cash and cash+online are rejected, including the "1 rupee online +
rest cash" trick. Every benefit calls the one existing
OnlinePaymentGuard. New rules apply to NEW bookings only. Existing
bookings, holds, loyalty points and earned referral rewards keep
their values (add a test: an existing cash booking with a flash-sale
price is unchanged).

==================== RUN A: STEPS 1 TO 9 ====================

1  FULL SUITE. Run in chunks by directory, never one big run and
   never --parallel (an earlier run died from low memory; close
   other apps). Per chunk record: command, exit code, tests, passed,
   failed, skipped, assertions, duration, raw output. No clean-suite
   claim unless every chunk passes.

1B MAIN BASELINE. Use a temporary git worktree in a temp folder at
   44a7f0e. Do not check out main in this working tree. Run
   NotificationCenterAuditTest::test_provider_status_shows_log_
   fallback_by_default. Report pass or fail. Remove the worktree.

2  PAYMENT_METHOD RAW UPDATES (report only). Search the repo for every
   raw, mass or query-builder update touching bookings.payment_method
   (update(, DB::table('bookings'), where()->update, query()->update,
   bulk, direct SQL). Per hit: file, line, code, caller, whether
   model events are bypassed, whether OnlinePaymentGuard can be
   bypassed, risk (SAFE / REVIEW / HIGH). Change nothing.

3  MID-JOB EXTRAS (report only, invent nothing). Report: table and
   model, how an extra is created and priced, whether it changes the
   booking total, which payment methods exist for extras, payment
   record and status, commission (rate, provider share, settlement
   timing), audit trail, and whether an extra can change the original
   coupon, bypass the guard, create a separate payment or change the
   booking payment method. Mark anything missing NOT IMPLEMENTED.

4  CUSTOMER-VISIBLE IMPACT (report only, change no display yet).
   Report where flash-sale, Prime, loyalty and referral amounts are
   shown to customers today (catalog, service page, wizard, cart,
   checkout, API payloads). Report what a cash customer will see
   after the change. Propose wording so a cash customer always sees
   the full price before paying and is never shown a price they
   cannot get on their chosen payment method. Display changes wait
   for my approval of the wording (Run B). Also report whether the
   Prime free-visit waiver can apply on a cash booking today.

5  D5 FLASH SALE. Failing tests: cash rejected, cash+online rejected,
   online allowed. Backend guard via OnlinePaymentGuard. Run the
   suites. Commit: "feat: enforce online payment for flash sale
   benefits".

6  D6 PRIME DISCOUNT. Same pattern. Commit: "feat: enforce online
   payment for prime discounts".

7  D7 LOYALTY. Failing tests: cash completion still earns points;
   redemption rejected on cash and cash+online, allowed online.
   Commit: "feat: enforce online payment for loyalty redemption".

8  D8 REFERRAL. First report how the referrer reward is triggered
   today (event, listener, status). Extend that trigger, add no
   second one. Failing tests: referee discount rejected on cash and
   cash+online, allowed online; referrer reward only after an
   eligible online-paid completed transaction, with a test for each
   non-qualifying event above. Commit: "feat: enforce online payment
   for referral benefits".

8B D1 GLOBAL SCOPE. Failing tests: new-customer coupon with explicit
   Global allowed; without Global rejected; empty targeting
   rejected; forged or omitted global flag rejected; existing
   customer rejected; genuinely new customer eligible. Commit:
   "fix: require explicit global scope for new customer coupons".

9  GATE AND HARD STOP. Report D1, D5, D6, D7, D8 as PASS or FAIL
   with commits, suite counts, failures and unresolved risks.
   Include the full report below. Then STOP, even if everything
   passes. Do not start C3. Run B begins only after I reply.

==================== RUN B: AFTER MY REPLY ====================

10 DISPLAY CHANGES. Implement only the wording I approved for
   flash, Prime, loyalty and referral amounts. Failing tests first.

11 C3, customer coupon flow. Extend existing code only.
   a) No internal value ever reaches a customer: reasonCode,
      internal reason, diagnostics, exception class, funding or
      franchise details. Map CouponException to the generic message
      "This coupon cannot be applied to this order." and to "Offers
      apply on online payment only." for payment-eligibility
      rejections. Cover API, Form Requests, Livewire, wizard, cart,
      checkout, bundles, payment selection, JSON and Flutter
      payloads. Grep CouponException, reasonCode, getMessage. Test
      API, Livewire and JSON payloads.
   b) Online-only on every surface, through the one guard, including
      bundles.
   c) Customer sees full price, discount and payable, all
      server-computed. Visit and inspection charges stay separate:
      "Visit and inspection charges are separate from coupon
      discounts." Coupons never reduce them.
   d) Unpaid-hold countdown uses coupons.unpaid_hold_minutes. Verify
      reservation, countdown, expiry, release, retry, payment
      success and accounting.
   e) Rate limit: propose setting names and scope (for example per
      customer and per IP, count and window) in the existing coupon
      settings, Super Admin only. Do not choose values. STOP and ask
      me before enabling the limiter. Other C3 work may continue.
   f) Coupon bookings keep F1 acquisition data (source, campaign,
      UTM, attribution). Trace the flow and add a regression test.
   g) Per-surface toggles are Super Admin only, enforced server-side,
      using the existing settings authorization.
   Tests: Razorpay, wallet, wallet+Razorpay allowed; cash and
   cash+online rejected; targeting types (global, city, zone, module,
   customer, customer type, provider, hotel, category, service,
   product), marking any unimplemented type NOT IMPLEMENTED;
   lifecycle (draft, active, pause, resume, archive, edit, existing
   booking preserved, reserved and released usage, unpaid hold,
   expiry, daily, total and per-customer caps); security (forged
   scope, module, franchise, zone, client payment method, exception
   leakage); pricing (full price - discount = payable, visit charge
   untouched).

12 BROWSER CLICK-THROUGH on scratch SQLite only, with screenshots:
   coupon list, new coupon, Global scope, zero daily cap error, draft
   save, activate, pause, resume, archive, edit, permission denial,
   customer coupon field, online payment selection, cash rejection,
   full price / discount / payable display, generic rejection
   message. Anything not actually clicked is "NOT VERIFIED" with the
   reason.

13 FINAL TESTS. Relevant suites, then the full suite in chunks,
   sequentially. Report raw output.

==================== REPORT FORMAT ====================
(Run A ends with items 1 to 9 and 13 to 15; Run B adds the rest.)
1  Repository state: branch, HEAD, main baseline, git status.
2  The 9 original hardening commits, confirmed NOT SQUASHED.
3  New commits, chronological, one per business change.
4  Full test results per chunk, with raw output.
5  Main baseline result for the NotificationCenterAuditTest test.
6  payment_method raw update audit table.
7  Mid-job extras findings.
8  Decisions D1, D5, D6, D7, D8, D9, D12: status each.
9  Customer-visible behavior changes (display impact from Step 4).
10 C3 changes and customer-visible behavior changed (Run B).
11 Security: no internal reason leaked, server-side authorization
   and payment enforcement confirmed (Run B).
12 Browser verification list with results (Run B).
13 Decisions needed.
14 Remaining blockers and unresolved risks.
15 Must state: NO MIGRATION RUN. NOT PUSHED. NOT MERGED. NOT
   DEPLOYED.

HARD STOP after each run's report. Do not push, merge, deploy,
migrate, squash, or invent a rate-limit value.
