# Deploy Decisions Log

Business decisions that a production deploy depends on, recorded where the
code lives so they don't get lost. Newest first. Each entry says what was
decided, by whom, when, and what it affects. Full investigations live in
their own reports; this file only holds the outcome.

---

## 2026-09-23: KYC video waiver and 30% platform fee ship as-is

**Decided by:** Mohammed Shabbeer Shaik (project owner)
**Ref:** 1CF-DEPLOY-20260923-SIGNOFF (full investigation:
1CF-DEPLOY-20260923-MIGRATION-REVIEW)
**Context:** first production deploy of `main` @ `972354f`. Both changes had
been on `main` for about three weeks with **no sign-off recorded anywhere in the
repository before this date**.

### 1. KYC verification video is waived globally (decision D9)

- **Migration:** `2026_09_02_002000_waive_kyc_verification_video_globally.php`
  (commit `93e2341`, merged in `50d2f5d`, 2026-09-02).
- **What it does:** sets `kyc.require_verification_video = '0'` at global
  scope. From then on, admins can approve **any** provider (CSV-imported,
  admin-created or self-registered) without an approved verification video.
  It doesn't change any provider records. A franchise can turn the
  requirement back on for itself with a franchise-scope override in
  Admin → Settings.
- **Why it exists:** there is still no way for a provider to submit a
  video, so with the requirement on, no provider could be approved at all.
- **Before:** `PHASE_PSR_PROVIDER_SELF_REGISTRATION_DISCOVERY.md` §5.3 raised
  this as decision D9. It offered three options (include it in the
  registration form, defer it, or waive it per franchise), but no answer was
  recorded. The build chose a global waiver.
- **Decision:** ship it as a global waiver ahead of the production launch.
  Building the video upload flow, or making the waiver franchise-scoped by
  default, is deferred to a later phase and doesn't block launch.

### 2. The platform fee defaults to 30% and franchises at 0% are backfilled

- **Migrations:** `2026_09_05_002000_backfill_unconfigured_franchise_platform_fee.php`
  and `2026_09_05_004000_seed_global_platform_fee_default.php` (commit
  `63ad689`, merged in `9edc494`, 2026-09-06).
- **What they do:** any active franchise whose `platform_fee_percent` is
  exactly 0 is set to "not set" (NULL). The global default,
  `commission.default_platform_fee_percent`, is seeded to **30** only if no
  admin has already set a value. As a result, franchises that were at 0%
  charge 30% on commissions calculated **from now on**. Commissions already
  recorded, franchises with a non-zero fee, and providers with a negotiated
  rate are unaffected. Neither migration's `down()` undoes this.
- **Before:** the seed migration's comment called 30% "approved", but no
  decision record existed anywhere.
- **Decision:** ship both migrations unmodified. No franchise is known to
  need an exclusion. If one later turns out to need 0%, correct its rate in
  the admin panel (Admin → Settings / the franchise's commission fields).

### Why this was acceptable for launch

The project goes to production now, and development continues afterward.
Both changes can be adjusted after launch without code changes: KYC through
a per-franchise setting, and the platform fee by correcting a franchise's
rate in the admin panel.
