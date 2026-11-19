# Release 2026-11-19.4 — Banners on every business page + paid packs for people's posts

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | 2026.11.19.3 (Second-hand). If .3 is not live yet, deploy both together: step 5 then expects both migrations. |
| Full test suite | to run before the push (runbook step 1). Affected folders green 2026-11-19: 1285/1285 (packs, second-hand, rent, homes, vehicles, payments, settings, customer web, banners, halls, UI, console, admin) and 1861/1861 for the banners commit |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | yes (new classes: pack cards, Featured tag, Boosted row, banner slots) |
| Version | `VERSION` 2026.11.19.4 |

## What's in it
### 1. Banner space on every "Explore 1CallFix" business page (owner 2026-11-19)
- Parcel, Shop, Food, Hotels, Events, Rental (halls and rent anything) and Second-hand pages each have a top banner
  slot. Admin → Banners → "Where it shows" now lists "Shop page — top", "Second-hand page — top", ... The Services
  pages keep the Mid slot. Franchise / area targeting as before; a franchise banner shows only in its own city;
  never on the homepage. Nothing live = nothing shown. No migration (the slot is a `placement` value).

### 2. Paid packs for rent and second-hand posts (owner 2026-11-19)
- Posting stays free. Four packs, fixed in code; every number is Super Admin's (Rental Settings → **Posts · Paid
  packs**). **Price 0 = that pack is off, and all prices are 0 after deploy**, so nothing changes until set.
  - ⭐ **Featured**: top of the list with a "⭐ Featured" tag for N days.
  - 🚀 **Boost**: Featured, plus a big card in the "🚀 Boosted near you" row at the top of the page. Limited spots
    per city (separately for rent and second-hand); when full, the card says when the next spot frees up.
  - 📦 **Small / Big bulk pack**: N Featured posts; one used at once on the post it is bought from, the rest with one
    tap ("Use 1 Featured from your pack") on any of the person's posts within the bulk validity days.
- People: Account → Second-hand (or Rent out) → a live post → **🚀 Get more calls** → tap a pack → Pay online
  (Razorpay) or Pay from wallet. Optional crossed-out "normal price" only when Super Admin enters a real one.
- Money: through the existing listing fee engine (`listing_fees`, listing_type `item`), the same Razorpay webhook
  (exact amount only, activates once). HQ holds the money; the franchise share (%) is fixed on each fee when bought
  and shown on People's posts ("Packs bought this month · Money (held by HQ) · Franchise share"). Paying it out to
  franchises is not automated in this release. No coupon or discount applies to a pack. No automatic refunds; a
  refund, if ever needed, goes through the manual money approval model.
- Featured / Boost end on their own: `homes:tidy` (already scheduled daily) ends lapsed ones; the lists also stop
  treating them as Featured / Boosted the moment their time runs out. Mark as sold ends both.
- People's posts shows 🚀 / ★ on each row and "Boosted / Featured until" on the post.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_19_200000_add_post_packs` (rental_items.boosted_until; listing_fees.franchise_share, credits,
  credits_used, credits_until; additive only)
- plus `2026_11_19_100000_add_second_hand_to_rental_items` only if 2026.11.19.3 is not live yet.

Rollback count for `migrate:rollback --step=`: 1 (2 if deployed together with .3)

## Step 10 — scheduled commands that must be listed
- none new (`homes:tidy` already listed)

## Test login (owner rule 2026-10-26)
- No new app or role: `test.secondhand@1callfix.test` (2 live posts) is the pack walk-through login.

## Walk-through after deploy
1. Super Admin: Rental Settings → Posts · Paid packs → set Featured, Boost and bulk prices (and franchise share %) → Save.
2. `test.secondhand`: Account → Second-hand → "Test sofa set" → 🚀 Get more calls → the switched-on packs show →
   Featured → Pay online (Razorpay test mode) → back on the list it says "⭐ Featured until ...".
3. `/<city>/second-hand`: the sofa is first with the ⭐ Featured tag.
4. Boost the "Test Redmi phone" → the "🚀 Boosted near you" row shows it at the top of the page.
5. `test.franchise`: Rental → People's posts → the pack money line shows the amount and the franchise share.
6. Admin → Banners → add a banner, "Where it shows" = Second-hand page — top → it shows on `/<city>/second-hand` only.

## Admin settings to do after deploy (owner)
- Pack prices, days, Boost spots per city, bulk counts, validity days and franchise share %: Rental Settings →
  Posts · Paid packs (all off until a price is set).
- Business page banners: Admin → Banners, as needed.
