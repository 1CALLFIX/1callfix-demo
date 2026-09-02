# Provider Onboarding Runbook — Bulk Pre-Register (CSV)

How to onboard service providers in bulk through the existing admin
**Bulk Pre-Register** path. This is the admin-driven provider creation
flow; providers can also self-register publicly — see
**§0. Public self-registration** below.

> Scope of this document: the CSV pre-register path, plus a summary of the
> public self-registration path (§0). The single-add admin form and any
> Provider Web P2 work are **out of scope** and on hold.

---

## 0. Public self-registration (PHASE PSR)

A prospective partner can register themselves at **`/provider/register`**
(public, no login). The form is a 2-step Livewire component
(`App\Livewire\Provider\Auth\Register`):

1. **Verify mobile** — Firebase phone OTP. The verified number becomes the
   account's sign-in identifier, exactly like a CSV row's `phone`.
2. **Details** — full name, password (set now, min 8), optional email
   (stored **unverified**), work address + a "use my current location"
   pin, and the KYC document upload slots. The slots are rendered from
   `kyc_document_requirements` (the same table admin review reads), so
   `id_proof`, `address_proof` and `bank_details` are required and the
   rest optional.

**What it creates** — identical shape to a CSV row, through the shared
`App\Actions\RegisterProviderAction`:

- a `users` row — `role = provider`, `status = active`, `preferred_language
  = en`, `phone_verified_at` set, a hashed password, email if supplied;
- a `providers` row — `provider_type = independent`,
  `kyc_status = pending` (the table default — never forced), `kyc_deadline_at
  = now + 30 days`, plus `registration_address` / `registration_lat` /
  `registration_lng` from the pin.
- one `provider_documents` row per uploaded file — `status = pending`,
  `is_current = true`, `upload_source = self`.

**Franchise / zone** are derived from the pin
(`CustomerLocationContext::nearestCoveringZone()`), never chosen by the
applicant. A pin **outside every serviced zone radius** does **not** block
registration — the application is attached to the nearest zone by distance
and flagged (`registration_lat/lng` give the real spot) for an operator to
confirm or move during review.

**No session is started.** The applicant sees an "application received"
screen and cannot sign in until an admin approves — same review queue, same
`ReviewProviderKycAction`, same clicks as a CSV-imported provider (see §5).

**Abuse controls** — Firebase phone OTP (with its own reCAPTCHA) gates the
whole flow; both the OTP-send and the submit actions are rate-limited
per-(number, IP) and per-IP via `InteractsWithAuthThrottle`; document
uploads reuse `KycDocumentService`'s JPG/PNG/PDF + 10 MB limits.

**Duplicate number** — a phone already attached to any user is refused with
"sign in instead". Letting an existing customer add a partner profile on
the same number is a planned follow-up, not built yet.

**Verification video** — `kyc.require_verification_video` is set to `'0'`
globally (migration
`2026_09_02_002000_waive_kyc_verification_video_globally`) because no
provider-facing video-submission path exists yet. This waiver applies to
**every** provider, CSV or self-registered. A franchise that later needs
videos re-enables the requirement for itself with a franchise-scope
override in **Admin → Settings**.

CSV bulk pre-register (below) is unchanged and still the right tool for
onboarding a known list in one go.

---

## 1. Where it lives in the admin UI

**Admin sidebar → Operations → Providers**, then the **"Bulk Pre-Register"**
button at the top-right of the Providers screen (next to *Export CSV*).

- URL: `/admin/providers` (route `admin.providers.index`)
- Clicking **Bulk Pre-Register** opens an amber panel with the
  upload → **Validate** → preview → **Confirm Pre-Register** steps.

### Permissions

| Action | Permission required |
| --- | --- |
| See the Providers screen / open the panel | `providers.view` |
| Commit the pre-register (the Confirm step) | `providers.manage` |

Franchise-scoped operators can only pre-register providers **into their own
franchise**. A row pointing at a franchise outside the actor's scope is
reported as an error and skipped; the rest of the file still commits.

---

## 2. CSV format

Use the **"Download template"** button in the Bulk Pre-Register panel (left
of the file picker) to get `providers-prereg-template.csv` — it has the
correct header row plus one example data row. Fill in your rows and re-upload.

- Accepted file types: `.csv`, `.xlsx`, `.xls`.
- **Row 1 is the header row.** Data starts on row 2. Error/preview messages
  refer to spreadsheet row numbers (so the first data row is "row 2").
- Header names are automatically lowercased and spaces become underscores,
  so `Franchise ID` is read as `franchise_id`. Keeping the headers exactly
  as below avoids surprises.

### Columns

| Column | Required | Rules |
| --- | --- | --- |
| `name` | Yes | Non-blank string, ≤ 255 characters. |
| `phone` | Yes | Non-blank, ≤ 20 characters. Must be unique **within the file**. Must not already belong to a non-provider user (see below). Stored trimmed, as written. |
| `franchise_id` | Yes | Integer. Must match an existing Franchise `id`. |
| `zone_id` | No | Integer. If given, must be an existing Zone whose `franchise_id` equals this row's `franchise_id`. Leave blank if unknown. |

### Minimal example

```csv
name,phone,franchise_id,zone_id
Ravi Kumar,+919000000001,3,17
Sita Devi,+919000000002,3,
Anil Rao,+919000000003,5,42
```

> **Phone formatting:** open the file as CSV or format the `phone` column as
> text before saving from a spreadsheet, so long numbers are not converted
> to scientific notation or have a leading `+`/`0` stripped. The value is
> stored exactly as read (after trimming whitespace).

### What happens on Validate / Confirm

- **Partial success by design.** A bad row (missing field, unknown
  `franchise_id`, mismatched `zone_id`, out-of-scope franchise, duplicate
  phone in the file) is listed under "problems found" and skipped. Every
  valid row still commits.
- A phone that is **already attached to a provider** is reported as
  *"skipped (already registered)"* — not an error. Re-running the same file
  is safe.
- A phone already registered as a **customer/worker/etc.** (any non-provider
  role) is an **error** for that row — it is not converted.
- Blank rows are silently skipped.

### What a committed row creates

For each `created` row, in one transaction:

- a `users` row — `role = provider`, `status = active`, `franchise_id` /
  `zone_id` from the CSV, `preferred_language = en`, **no password, no email**;
- a `providers` row — `provider_type = independent`,
  `kyc_status = pending` (the table default — never forced to `approved` by
  this importer), `kyc_deadline_at = now + 30 days`.

A pre-registered provider is a **pending account shell**. It is **not
dispatchable** until it submits and passes KYC review, exactly like any other
new provider. The run is recorded as a `CatalogImportRun`
(`entity_type = providers_prereg`) with created/skipped counts.

---

## 3. Finding valid `franchise_id` / `zone_id` values

Both live under the **Geography** group in the admin sidebar and both list
screens have a sortable **ID** column — read the numeric ID straight off the
table.

- **`franchise_id`** — Admin sidebar → **Geography → Franchises**
  (`/admin/franchises`). The **ID** column is the value to put in the CSV.
  Requires `franchises.manage`.
- **`zone_id`** — Admin sidebar → **Geography → Zones** (`/admin/zones`).
  Use the **"All franchises"** filter at the top of the list to narrow to
  one franchise, then take the **ID** column value. The zone you pick must
  belong to the same franchise as the row's `franchise_id`, or the row is
  rejected. Requires `zones.manage`.
- If you do not know the zone, **leave `zone_id` blank** — it is optional and
  can be set later from the provider's admin record.

---

## 4. Legacy password-set flow (required before first login)

Pre-registered rows are **password-less and email-less**, so a provider
**cannot sign in to Provider Web straight after pre-registration**. Each
provider must complete the one-time "set your password" flow first:

1. Provider opens **`/provider/login`** and enters their **mobile number**
   (the `phone` from the CSV) with any password.
2. Because the account has no password yet, the login screen redirects them
   to the one-time **Set your password** page at **`/auth/set-password`**
   (route `customer.auth.migrate`). The **"Forgot password"** link on the
   login screen leads to the same place.
3. On that page they **verify the account's mobile number via Firebase phone
   OTP**, then choose a password (minimum 8 characters, entered twice).
   *This step depends on Firebase phone auth being configured and working.*
4. After the password is set they are signed in on the web session but land
   on the **customer home**. They then go to **`/provider`** (or reopen
   `/provider/login`), which takes them to the partner dashboard.

Notes:

- Provider Web login also refuses any account that has no `providers` row.
  Bulk Pre-Register creates that row, so a pre-registered provider clears
  this check once their password is set.
- Equivalent API path (same underlying `setPassword`):
  `POST /api/auth/password/forgot` → Firebase phone verification →
  `POST /api/auth/password/reset` with `{ identifier, new_password, id_token }`.
- There is currently **no admin "send invite / set password" action** on the
  provider record — the provider drives the password-set flow themselves.

---

## 5. Quick checklist

- [ ] Confirm you have `providers.manage` (and the target franchise is in
      your scope).
- [ ] Look up `franchise_id` in Geography → Franchises.
- [ ] Look up `zone_id` in Geography → Zones (filter by franchise), or leave
      blank.
- [ ] Grab the CSV via **Download template** in the panel (or build one with
      headers `name,phone,franchise_id,zone_id`); format the phone column as
      text.
- [ ] Admin → Operations → Providers → **Bulk Pre-Register** → upload →
      **Validate**.
- [ ] Review the problem list and the preview counts, then **Confirm
      Pre-Register**.
- [ ] Tell each provider to sign in at `/provider/login` and complete the
      mobile-verify → set-password flow before they can use Provider Web.
- [ ] Providers remain `kyc_status = pending` and non-dispatchable until KYC
      is approved.
