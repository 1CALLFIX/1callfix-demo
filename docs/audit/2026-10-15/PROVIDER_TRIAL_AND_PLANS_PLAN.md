# Plan (final, rev 3) — provider free period ("trial")

**PLAN ONLY. Nothing is built, migrated, pushed, merged or deployed. Approved in principle by the owner, subject to this
document matching the decisions below and passing implementation review.**

- Branch `fix/l4-online-pay-before-dispatch`. L3/L4/L8 work is preserved and untouched by this plan.
- Rev 3, 2026-10-15. It replaces rev 1 and rev 2. Rev 2 proposed a commission rework, a "Provider Trial" plan row, a
  trial service and an ID-number fingerprint migration. The owner withdrew all of those.

## 0. Owner decisions

| # | Decision |
|---|---|
| D1 | **The commission split is not changed.** HQ %, franchise % and provider % are already set and active in the admin panel, and can be moved in any direction there (e.g. ₹1,000 job → HQ ₹100 / franchise ₹200 / provider ₹700). |
| D2 | **The "trial" is simply: no subscription plan is initialised for providers.** No plan row, no subscription, no commission plan is created or changed. Whether this is a trial or permanent is the company administration's call. |
| D3 | **Off until Super Admin enables it.** Two months is the default duration after activation. Admin controls the start and end dates. Never backdated. |
| D4 | **No ID number and no ID fingerprint** (M1 rejected). Background verification is done by the franchise head / franchise operator through the existing KYC review. |
| D5 | Paid plans stay disabled. Registration, KYC, wallet/dues and subscription stay separate checks; the free period never blocks jobs. |
| D6 | L4 merge waits until the outstanding verification items are closed (§4). L3/L8 remain mandatory safety work. |

## 1. What already holds today (verified in code)

- **Commission:** `CommissionService` computes HQ = price × platform % (provider agreement → franchise
  "Platform fee (%)" → global setting) and franchise = price × franchise revenue-share %, separately (lines ~103, ~121,
  ~395, ~454). HQ 10 + franchise 20 → 100 / 200 / 700 on ₹1,000; cash job → provider owes 300. Covered by existing
  tests. Reminder only: a franchise with a blank "Platform fee (%)" uses the global default as the HQ share.
- **No provider subscriptions exist locally;** jobs do not depend on any plan (dispatch checks KYC, active, skills,
  zone, online, location). So every provider is already "free".

## 2. The one real gap (must close before calling it "admin-controlled")

| # | Gap | Effect | Fix |
|---|---|---|---|
| G-A | `GET /api/plans?acting_as=provider` lists any **active** provider plan, and `POST /api/plans/{id}/subscribe` subscribes, with no master switch and no role check (`app/Http/Controllers/API/PlanController.php` lines 22-26, 60-74) | A provider plan could be initialised without the admin deciding it. Locally an active ₹0 "[QA] Provider Commission Plan" (id 12) is subscribable now | Setting `provider.plans_enabled` (Super Admin, audit-logged, **null = off**). While off: the provider list returns nothing and subscribe refuses. `acting_as=provider` also requires the caller to actually be a provider. Deactivate any QA provider plan on prod (§5). |
| G-B | ₹0 plans with `auto_renew = true` renew forever (`RenewalService::processOne`) | Only matters if a provider plan is ever enabled | Out of scope while plans are off; noted for when the admin turns plans on. |

## 3. Free-period controls (settings only, no migration)

All Super Admin, on one settings card, audit-logged through `SettingsAuditor`, null = off:

| Setting | Default | Meaning |
|---|---|---|
| `provider.free_period_enabled` | null (off) | Shows the free period to providers/admin. Has **no effect on jobs or money**. |
| `provider.free_period_starts_on` | — | Set by the admin; cannot be saved in the past (never backdated). |
| `provider.free_period_months` | 2 | Default length after activation. |
| `provider.free_period_ends_on` | start + months | Admin can edit; shown read-only to providers. |
| `provider.plans_enabled` | null (off) | G-A master switch. Stays off. |

Display only: provider dashboard line "Free period: no subscription fee until {date}" when enabled; admin card shows the
dates. When the end date passes nothing happens automatically: no charge, no plan, jobs continue. What follows is the
admin's choice.

Not built (withdrawn): per-provider trial rows, `ProviderTrialService`, extensions, reminders, one-trial-per-person
identity checks, ID fingerprint, migration M1, any commission change.

## 4. L4 outstanding verification items (closed 2026-10-15, commit `efb4b85`, local only)

1. `DataClearAuditLogTest` raced under `--parallel` (shared `storage/logs/data-clear.log`). Fixed test-only: each test
   process gets its own log file. `tests/Feature/Operations` passed 100/100 three runs in a row in parallel.
2. Provider "Job completed" message claimed wallet credit on an unpaid online job. Now says no earnings were added and
   the team will follow up; paid jobs keep the old text. New test; `tests/Feature/Notifications` 54/54.
3. Earlier full run on `371e3d5`: 3,774 tests, 3,772 pass (both failures pre-existing; one now fixed by item 1). The
   only remaining known failure is `NotificationCenterAuditTest` (`.env`-caused, already documented).

## 5. Production read-only check (please run and paste the output)

```bash
cd /home/1callfix.com/public_html && php artisan tinker --execute='echo json_encode([
 "provider_plans"=>App\Models\Plan::where("eligible_actor_type","provider")->get(["id","name","is_active","price"]),
 "provider_subscriptions"=>App\Models\Subscription::whereHas("plan",fn($q)=>$q->where("eligible_actor_type","provider"))->count(),
], JSON_PRETTY_PRINT);'
```

Shows whether a provider plan is buyable on the live site today (G-A).

**Result (owner ran it on prod, 2026-10-15):** `provider_plans: []`, `provider_subscriptions: 0`. No provider plan
exists on the live site, so nothing is buyable today and nothing needs deactivating. G-A is a safeguard for the future
(it stops a provider plan being initialised if one is ever created), not a live leak.

## 6. Build order after approval

1. L4 merge (when you approve).
2. L3 + L8 per `L3_L8_PLAN.md`.
3. G-A switch + role check, free-period settings card and display line. Tests: switch off → list empty and subscribe
   refused; non-provider cannot act as provider; start date in the past refused; free period changes no commission and
   no job eligibility; every settings change audit-logged.
