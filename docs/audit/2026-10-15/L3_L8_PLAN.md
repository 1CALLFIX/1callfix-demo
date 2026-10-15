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

## Decisions needed

1. Floor amount (you said ₹500) and whether it applies everywhere or per franchise/zone.
2. Max outstanding cash commission (after you see the real total).
3. Does the floor count dues (switch above)?
4. Admin assign: hard block, or allow with a written override reason?
5. Withdrawable option (a) or (b).
