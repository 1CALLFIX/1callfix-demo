# Release 2026-11-04 — Food problem rules (restaurant's say, wallet refunds, repeat customers)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `f99de19` or `7b05b90` (VERSION 2026.11.03.8 / .7) |
| Full test suite | 2026-11-04, folder by folder: 4477 tests, all pass except the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env, unrelated) |
| Composer | no |
| Migrations | 1 (below) |
| Front-end build (steps 6–7) | no (only CSS classes already in the live build) |
| Worker restart (step 9) | yes (as every deploy) |
| Version | `2026.11.04.1` |

## What's in it (owner decision 2026-11-03, after the live walk-through and a look at Swiggy / Zomato / DoorDash)
1. **The restaurant gets a say.** Each food problem shows under the restaurant's Orders → Problems with **Agree** and
   **Dispute** (a dispute needs a reason). Answer by the Food Settings time (default 30 minutes); no answer counts as
   Agree. The admin queue shows "Restaurant: agrees / disputes — reason / no answer in time". The admin still decides
   whose fault it was; the restaurant pays only for "Restaurant's fault".
2. **Refunds that cost nothing extra.** A food refund is the food only — the admin cannot raise it above the price of
   the food (delivery and packaging are never refunded). It goes to the **1CallFix wallet** (instant, nothing leaves the
   platform, no Razorpay refund); the customer can tick "Refund to my bank or card instead" on an online order. Cash
   orders: wallet only. Shop returns are unchanged.
3. **Repeat customers.** Problems are counted over Food Settings "Count a customer's problems over the last" (90 days).
   From "Mark a customer Repeat" (2) the queue shows a red **Repeat** badge with "N problem(s) in 90 days · accepted ·
   ₹ refunded". From "Stop Report a problem" (4) the customer sees "Please call our support team" instead (they can
   still order; blank = never stop it).
4. **Food Dashboard:** "Restaurants' mistakes" — the restaurants with the most "restaurant's fault" problems.
5. Leftovers from the walk-through: a cash order is marked paid once handed over (it stayed "pending"); the rating box
   on a food order says "Anything to tell the restaurant?"; the admin message after accepting says "Next: request
   the refund." (it wrongly said the refund was already in the approval queue).

New Site text (English + Telugu): food.issue.paused, food.issue.to_wallet, food.issue.to_bank,
food.rating.comment_placeholder. New Food Settings (Problems & refunds): food.restaurant_reply_minutes,
food.repeat_days, food.repeat_flag_count, food.repeat_pause_count.

## Step 5 — migrations that must be pending (exactly this)
- `2026_11_04_100000_add_restaurant_answer_to_marketplace_returns` (adds 4 nullable columns + an index; no enum)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands
- none new

## Browser checks after deploy
- Customer (test.customer) → a delivered Test Restaurant order → Report a problem: the form says the refund goes to
  the wallet; an online order has the bank / card tick box.
- Restaurant (test.restaurant) → Orders → Problems: Agree / Dispute with the deadline.
- Admin → Returns & food problems → Food problems: the restaurant's answer and the customer's problem count.
- The open test problem #1 (order NLR-MKT-0311-00000002) was reported before this release: it has no answer deadline,
  so the restaurant cannot answer it; decide or decline it as before.
