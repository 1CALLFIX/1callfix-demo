# Plan — L3 (cash-commission exposure) + L8 (pay dues) + L1/L2/L5/L6 (one eligibility rule)

PLAN ONLY — nothing here is built. Source: `WALLET_AND_JOB_LIFECYCLE_AUDIT.md`, `WALLET_AND_PROVIDER_HOW_IT_WORKS.md`.
Rules kept: sweep-at-payout-request stays (owner decision 2026-09-18); every value is a Super Admin setting,
audit-logged, `null` = not configured (off), `0` = waived.

## 1. One shared eligibility rule

Today eligibility lives in three places that disagree:

| Check | `DispatchService::eligibleQuery()` (offers) | `providerEligibleForBooking()` (accept, consolidation) | `AcceptBookingAction` | `AdminReassignBookingAction` |
|---|---|---|---|---|
| KYC / active / skill / zone | yes | yes | via helper | **no** (L5) |
| `users.status` not suspended | **no** (L6) | **no** | web/API middleware | **no** |
| Wallet floor | **no** (L1) | **no** | yes (if set, L2) | **no** |
| Outstanding cash commission | **no** (L3) | **no** | **no** | **no** |

Proposal: add `App\Services\Providers\ProviderStanding` (one class) with:

- `blockers(Provider $p): array` — reasons the provider may not take NEW work right now:
  `suspended`, `below_wallet_floor`, `cash_dues_over_limit`. Each reason carries the numbers for the message.
- `scopeEligible(Builder $q): Builder` — the SQL form of the same rule, applied in `eligibleQuery()` (so blocked
  providers are never offered, rung or pushed) and in scheduled-candidate discovery.

Called from: `eligibleQuery()`, `providerEligibleForBooking()`, `AcceptBookingAction` (replacing
`assertMeetsMinimumWalletBalance`, still the race backstop), `AdminReassignBookingAction` and
`ReassignInProgressJobAction` (fixes L5; an explicit admin "override with reason" only if you want one), and the
provider "Ready for jobs" panel (`BuildsProviderEligibility`) so the provider sees WHY. Suspend also sets
`is_online = false` (L6).

No migration needed for this part.

## 2. Settings (Super Admin, audit-logged)

| Key | Meaning | null | 0 |
|---|---|---|---|
| `wallet.provider_min_balance_to_accept_jobs` (exists) | wallet floor | — (today `'0'` default = off) | waived |
| `provider.max_outstanding_cash_commission` (new) | most unpaid cash commission before new offers stop | off | any debt blocks |
| `provider.floor_counts_dues` (new, on/off) | floor compares `balance − outstanding cash commission` | — | — |

Effective standing = `wallet balance − outstanding cash commission (if switch on)` vs floor, and outstanding vs max.
You choose the numbers after reading the real outstanding total (command in the audit report).

## 3. L8 — "Pay dues / Add money" for a blocked provider

Reuse, no parallel engine:

1. Provider web gets **Wallet** (provider layout) showing balance, outstanding cash commission, floor and a
   "Pay ₹X now" button. Amount = `max(dues + floor − balance, minimum top-up)`.
2. The button calls the existing `WalletTopUpService::requestTopUp()` → Razorpay order → existing webhook
   `wallet_topup` branch credits the wallet.
3. **New, small:** right after a captured provider top-up, run the existing
   `PayoutService::settleCashCommissionReceivables()` (make it callable — today it is private and only runs at payout
   request). Dues are cleared from the money just paid, oldest first, franchise share paid out proportionally as
   today. This does NOT reverse the 18 Sep decision: the sweep also still runs at payout request.
4. Standing is re-checked live, so the provider can go online and accept immediately after paying.

## 4. Floor vs payout (W1/W2)

- **Payout leaves the floor**: `withdrawable = balance − floor − outstanding dues − non-withdrawable credits`.
  Otherwise a payout drops the wallet to 0 and the provider is blocked the next minute.
- **Top-up money is not withdrawable**: without this, a provider tops up ₹500 to pass the floor and withdraws it
  (W2). Same for referral / campaign / loyalty money (CLAUDE.md: promotional credit is never withdrawable — W1).
- This needs to know which part of the balance is "earned". Two options:
  - (a) **Ledger-derived, no migration:** withdrawable = sum of credits whose ref is an earnings ref
    (`commission:` / tip / compensation / interim) − payouts − dues debits, capped at balance.
  - (b) **Column, migration:** `wallets.withdrawable_balance` maintained by `WalletService`. Cleaner, needs a
    backfill. I'd show you the up/down before running it.
  Recommendation: (a) first; move to (b) only if it gets slow.

## 5. Order and tests

1. `ProviderStanding` + wiring into dispatch/accept/admin assign + readiness panel (L1, L5, L6). Tests: a provider
   below the floor gets no `DispatchAttempt` row and no push; a suspended provider is skipped; admin assign to a
   pending-KYC / suspended / blocked provider is refused.
2. Cash-dues limit (L3). Tests: over the limit → no offers, accept refused with the dues amount; under → normal.
3. Pay-dues page + settle-on-top-up (L8). Tests: top-up capture clears dues oldest first and is idempotent on a
   replayed webhook; provider becomes eligible immediately.
4. Withdrawable = balance − floor − dues − non-withdrawable (W1/W2). Tests: payout above withdrawable refused;
   top-up money cannot be withdrawn.

Ship 2 and 3 together, otherwise a cash-only provider with dues is locked out with no way to pay.

## 6. Admin WhatsApp / Call fallback (added 2026-10-16, owner approved the idea)

Purpose: when no provider responds in the app, Super Admin or franchise staff reach a provider directly, so no
customer is left waiting. No WhatsApp API: `wa.me` click-to-chat with a pre-typed message; the admin presses Send.

- **Where:** admin booking page (`app/Livewire/Bookings/Show.php`) while the job is unassigned / searching, and the
  stuck-jobs list (highlighted once the T+5 dispatch escalation fires).
- **Who:** permission `bookings.reassign`, scoped: Super Admin all, franchise head/operator own franchise only.
- **Provider list:** built from the shared `ProviderStanding` eligibility check (§1) + skill + zone. Ineligible
  providers are shown greyed with the reason (dues, low balance, KYC, suspended), no buttons.
- **Buttons per eligible provider:** WhatsApp (`https://wa.me/91XXXXXXXXXX?text=…`, number from the provider's
  registered `users.phone`) and Call (`tel:`).
- **Offer message:** job code, service, area/landmark (not the full address), date/time, provider earning, link to the
  job in the provider web app, signed "1CallFix Operations, {business number}". **No customer name, phone or full
  address** before assignment.
- **After the provider agrees:** admin assigns on the same page. Assign goes through the L5 eligibility check (this
  feature depends on L5). A second button sends a "Job assigned to you" WhatsApp with the link.
- **Audit:** every WhatsApp/Call click is logged (who, provider, booking, time) via ActivityLog and shown on the booking
  timeline, so two staff don't message the same provider unknowingly.
- **Sender number:** the owner's business WhatsApp **90146 09609**. A `wa.me` link always sends from the WhatsApp
  logged in on that computer/phone, so staff must use WhatsApp Web/Desktop linked to the business account (WhatsApp
  Business allows linked devices). Setting `ops.whatsapp_business_number` (Super Admin, audit-logged) holds the number
  used in the message signature; null = no signature line.
- **No migration, no money movement** (approval model not involved).
- **Tests:** button only for permitted, in-scope users; ineligible providers get no button; message never contains
  customer phone/name/full address; click is logged; assign still refuses an ineligible provider.

## Owner decisions (2026-10-16)

1. **Minimum wallet balance:** Super Admin sets the amount and can activate / deactivate it (switch + amount; off =
   no floor). Not fixed at ₹500.
2. **Maximum unpaid cash commission:** a Super Admin setting; the owner will enter ₹1,000 or ₹1,500. Live impact
   (prod 2026-10-15): at ₹1,000 all 3 providers are over (₹2,618.20 / ₹1,499.40 / ₹1,099.50); at ₹1,500 only
   provider 71 is over.
3. **Dues count against the wallet floor: yes.** Dues are cleared from the wallet when the provider pays
   (settle-on-top-up, §3) and, as today, at payout request.
4. **Admin assign:** admins pick from eligible providers only; if an ineligible provider is assigned anyway, a written
   reason is mandatory and logged.
5. **Withdrawable:** option (a), ledger-derived, no migration. The provider requests a withdrawal and it is paid;
   otherwise the money stays in the wallet.
