# PHASE PSR — Provider Self-Registration (Discovery)

**Status:** Discovery only. No code, no migrations, no scaffolding. This document
confirms the *shape* of a public, unauthenticated provider registration flow so
build can start against a confirmed design, same discipline as every prior phase
doc.

**Origin of this phase:** reverses the standing "CSV-only, no self-signup"
decision recorded in `docs/PROVIDER_ONBOARDING_RUNBOOK.md` and
`ProviderPreRegisterImporter`'s class docblock, per Mohammed's explicit
instruction: providers get *direct public access* to a registration form.

**Reference UX (external example, screenshots supplied):** a 2-step public form.
Step 1 — business/service details + address (map/autocomplete pin) + phone +
document upload. Step 2 — personal info, password, terms acceptance, submit.

**Builds on (all present; none rebuilt by this scope):**

| Component | Source | Role in this phase |
| --- | --- | --- |
| `ProviderPreRegisterImporter::commit()` | `app/Services/Onboarding/ProviderPreRegisterImporter.php:150-215` | The exact `User`+`Provider` creation shape to match |
| Provider web auth shell | `app/Livewire/Provider/Auth/Login.php`, `app/Http/Middleware/EnsureIsProvider.php`, `routes/web.php:172-191` | Where the new public route hangs; the layout to reuse |
| Customer signup (2-step, phone-OTP-verified) | `app/Livewire/Customer/Auth/Signup.php` | Structural template for the 2-step + Firebase phone OTP |
| `InteractsWithAuthThrottle` | `app/Livewire/Customer/Auth/Concerns/InteractsWithAuthThrottle.php` | Per-(identifier,IP) + per-IP throttle for Livewire actions |
| `CustomerLocationContext::nearestCoveringZone()` | `app/Services/Customer/CustomerLocationContext.php:154-174` | Address pin → zone → franchise resolution (radius-based) |
| `window.cfLocate` / `cfWireLocateButton` | `resources/js/geolocation.js` | Browser geolocation + "use my location" button wiring |
| `KycDocumentService::upload()` + `requirementsFor()` | `app/Services/Kyc/KycDocumentService.php` | Document intake pipeline + which docs are required |
| `kyc_document_requirements` (seeded) | migrations `2026_08_14_020000` / `_021000` | Configurable required-document list, per country |
| `ReviewProviderKycAction` | `app/Actions/ReviewProviderKycAction.php` | Admin approval — already origin-agnostic |
| `Providers\Index` / `Providers\Show` | `app/Livewire/Providers/Index.php`, `Show.php` | Admin review queue — already origin-agnostic |

---

## 1. Reuse of the existing creation logic

### 1.1 What the CSV importer actually does per committed row

`ProviderPreRegisterImporter::commit()` (`:158-192`), inside one `DB::transaction`:

```php
$user = User::create([
    'uuid' => (string) Str::uuid(),
    'name' => $row['name'],
    'phone' => $row['phone'],
    'role' => 'provider',
    'status' => 'active',
    'franchise_id' => $row['franchise_id'],
    'zone_id' => $row['zone_id'],          // nullable
    'preferred_language' => 'en',
    // no password, no email
]);

Provider::create([
    'user_id' => $user->id,
    'franchise_id' => $row['franchise_id'],
    'zone_id' => $row['zone_id'],
    'provider_type' => 'independent',
    'kyc_deadline_at' => now()->addDays(30),
    // kyc_status NOT set — the providers table column default 'pending' applies
]);
```

### 1.2 Can this be reused directly?

**Not `commit()` itself.** It is CSV-batch-shaped: it takes `array $previewRows`
produced by its own `validateRows()`, loops them, tallies created/skipped, and
writes a `CatalogImportRun` (`entity_type = 'providers_prereg'`). A single
self-submitted application is a different envelope.

**Yes to the inner atomic create.** The `User::create` + `Provider::create` pair
above is the whole "make a pending provider shell" operation, and it is the piece
worth sharing.

`CustomerAccountResolver::completeSignup()` / `createFromGoogle()` are **not**
reusable — both hard-code `'role' => 'customer'` (`:129`, `:178`) and never touch
`providers`.

### 1.3 Recommendation — one shared creator, two callers

Extract the inner pair into a single Action, e.g.
`App\Actions\RegisterProviderAction::execute(...)`, returning the `Provider`.
Then:

- **`ProviderPreRegisterImporter::commit()`** calls it once per `created` row
  (password `null`, email `null`, franchise/zone from the CSV columns). Its
  CSV concerns — `validateRows()`, partial-success, `CatalogImportRun` — stay
  exactly where they are.
- **The new self-registration component** calls it once, with a hashed password
  (Step 2), an optional verified email, and franchise/zone **derived from the
  address pin** (see §3.3), then loops the uploaded files through
  `KycDocumentService::upload()` in the same request.

This matches the PW1 precedent ("1 new Action, 0 migrations" where possible) and
keeps a single definition of "what a pending provider row looks like". Refactoring
`commit()` onto the shared Action is a small, test-covered change
(`tests/Feature/Onboarding/ProviderPreRegisterTest.php` already pins the shape).

**What self-registration adds that CSV never had:** a password at creation, an
optional email, an address the applicant typed, KYC documents in the same
submission, and franchise/zone derived rather than dictated. None of these
conflict with the CSV path; they are extra inputs to the same core write.

---

## 2. The public route

| Item | Proposal | Notes |
| --- | --- | --- |
| Path | `GET /provider/register` | Sits beside `GET /provider/login` (`routes/web.php:172-174`) |
| Middleware | `guest` only (the same group `/provider/login` is in) | No `auth`, no `EnsureIsProvider`. Public by design |
| Component | `App\Livewire\Provider\Auth\Register` | New; mirrors `Customer\Auth\Signup`'s 2-step `#[Locked] string $step` structure |
| Name | `provider.register` | |
| Layout | `components.layouts.provider` | Same layout `Provider\Auth\Login` uses (`Login.php:100`) |
| Submit action | Livewire method on the component (not a form POST to a controller) | Consistent with every other auth screen in this app; throttling handled in-component (§6) |
| Guest redirect | No change needed | `bootstrap/app.php` already branches guests; register is public regardless. Add a "Become a partner" link on `/provider/login` and (optionally) a footer link on the customer site |
| Already-authenticated visitor | `mount()` redirects: to `provider.dashboard` if they already have a `providerProfile`, else render the form (an existing customer may register as a partner — see §6.5) | Mirrors `Signup::mount()` / `Login::mount()` |

---

## 3. Fields

### 3.1 What maps cleanly to existing columns

| Form field | Persisted to | Required | Rule / source |
| --- | --- | --- | --- |
| Full name | `users.name` | Yes | `string`, ≤ 255 (importer rule, `:67`) |
| Mobile number | `users.phone` | Yes | Unique (DB constraint since `2026_08_01_003000`); **phone-OTP-verified before submit** (§6). ≤ 20 chars |
| Email | `users.email` | Optional | `nullable`, unique. If given, verify via `OtpService` email code (same as `Signup::verifyEmailCode()`) or accept unverified — decision D4 |
| Password + confirm | `users.password` (hashed) | Yes (Step 2) | `min:8`, `confirmed` — identical to `Signup::completeSignup()` (`:213`) |
| Terms acceptance | not stored today | Yes (Step 2) | Checkbox gate. Storing an audit timestamp needs a column — decision D5 |
| Address (typed + map pin) | **see §3.2** | Yes | Used to derive zone/franchise; street text has no home today |
| KYC documents | `provider_documents` via `KycDocumentService` | Required set per §3.4 | JPG/PNG/PDF, ≤ 10 MB each |
| Service categories the applicant works in | `providers.skills` (JSON array of `service_category_ids`) | Decision D3 | `DispatchService::hasSkill()` reads this array; today only admin (`Providers\Show::updateSkills()`) or `QaSeeder` ever writes it |

### 3.2 Schema gap — "business / service details + address" has nowhere to land

The `providers` table (`2026_08_01_012000`) has **no** column for:

- business / trading name
- a registration or service address (street text)
- a home / base coordinate

`providers.current_lat` / `current_lng` / `location_updated_at` are the **live GPS
fix while the provider is online** (written by `SetProviderOnlineStatusAction`),
not a registration address — reusing them for a fixed home address would corrupt
dispatch distance maths.

The CSV importer captures none of this either — it only ever had name, phone,
`franchise_id`, `zone_id`.

**Options (decision D1):**

- **D1-A — MVP, no migration.** Capture the address only as the *input to zone
  resolution* (§3.3). Do not persist street text or a business name. The provider
  edits richer profile fields later from the partner dashboard (a future phase).
  The reference screenshots' "business name" field is dropped for v1, or folded
  into `users.name`.
- **D1-B — one additive migration.** Add
  `providers.business_name` (nullable string), `providers.registration_address`
  (nullable string), `providers.base_lat` / `base_lat` (nullable decimal).
  Additive, backfill-null, no behavioural change to dispatch. ~1 migration.

Recommendation: **D1-A for the first cut** — nothing downstream reads these fields
yet, so persisting them now is storage without a consumer. Revisit when the
partner profile-edit screen is built.

### 3.3 Franchise & zone — derived from the pin, never picked

**How CSV providers get franchise/zone today:** the CSV supplies `franchise_id`
(required integer) and `zone_id` (optional integer) as literal columns; the admin
reads the numbers off the Geography → Franchises / Zones screens
(`docs/PROVIDER_ONBOARDING_RUNBOOK.md §3`). There is **no address→zone logic in
the importer at all.**

**What the customer side already does** (and self-registration should reuse):
`CustomerLocationContext::nearestCoveringZone($lat, $lng)` (`:154-174`) —

- iterates active zones with a recorded `center_lat`/`center_lng`,
- measures `DispatchService::haversineKm()` (`App\Services\DispatchService`) to each,
- keeps those whose own `default_dispatch_radius_km` (default 8) reaches the point,
- returns the nearest such zone, or **`null`** when the point is outside every
  serviced radius.

`franchise_id` is then `zone->franchise_id` — **derived, never accepted from the
client**, exactly the rule `CustomerLocationContext`'s docblock and
`AddressController::store()` (`App\Http\Controllers\API\AddressController`)
already enforce.

**Proposed behaviour:**

| Situation | Result |
| --- | --- |
| Pin resolves to a covering zone | Store `providers.zone_id` + `providers.franchise_id` (and the same on `users`) from that zone. Optionally show the resolved zone/city name read-only for the applicant to confirm |
| Pin outside every zone radius (`null`) | Honest "we don't operate in your area yet" — same out-of-coverage message the customer picker shows (`LocationPicker::useCurrentLocation()` sets `outOfCoverage`). **Block submission** in v1 (decision D2: block, vs. accept as an unassigned application for ops to place manually) |
| Geolocation denied / no pin | Applicant must drop a pin manually or type an address that geocodes. Map autocomplete (Google Places or similar) is a **new dependency** not currently in the app — decision D6 |

The applicant **does not** see a franchise or zone dropdown. This keeps a public
form from being a way to probe or mis-assign the franchise structure.

> Note: `nearestCoveringZone()` is explicitly documented as a *coarse browsing
> convenience*, not an authority on serviceability. That is an acceptable trade
> for routing an application into a review queue; the admin confirms/moves the
> zone during KYC review just as they can for a CSV row.

### 3.4 KYC document types — read from the seeded table, do not hard-code

`kyc_document_requirements` exists and is seeded (`2026_08_14_021000`). For
`applicable_type = 'provider'`, global rows (`country_id = null`):

| `document_type` | `label` | `is_required` (default) |
| --- | --- | --- |
| `id_proof` | Government ID proof | **Yes** |
| `address_proof` | Address proof | **Yes** |
| `bank_details` | Bank / settlement details | **Yes** |
| `business_proof` | Business registration proof | No |
| `tax_document` | Tax / GST document | No |
| `skill_certificate` | Skill certificate | No |
| `police_verification` | Police verification | No |

Country-specific overrides are merged in by
`KycDocumentService::requirementsFor('provider', $countryId)` (`:110-125`).

**The form renders its upload slots from `requirementsFor()`**, marking
`is_required` rows mandatory and the rest optional — never a hard-coded list.
Which country's requirements apply is known only *after* the pin resolves a zone →
franchise → `franchise.country_id`, so the document step must come **after** the
address step (Step 1 order in the reference UX already puts address before
documents — consistent).

Upload goes through `KycDocumentService::upload($provider, $type, $file, $actor,
'self')`:

- MIME **and** extension whitelist: JPG / PNG / PDF only (`:28-32`, `:82-98`)
- size cap `kyc.max_document_size_mb` (default 10) (`:103-106`)
- stored on the **private** disk (`Storage::disk('local')`) under a UUID filename (never the original)
- creates a `provider_documents` row at `status = 'pending'`, `is_current = true`,
  `upload_source = 'self'`

### 3.5 Wrinkle — `KycDocumentService::upload()` needs the Provider row + an actor

The service signature is `upload(Provider|FieldWorker $subject, string $type,
UploadedFile $file, User $actor, ...)` and writes to
`kyc/providers/{$subject->id}/{$type}/...`. It needs (a) a persisted `Provider`
and (b) a `User` for `uploaded_by`. A 2-step pre-auth form collects files *before*
the account exists.

**Recommendation:** on Step 2 submit, inside one request:

1. `RegisterProviderAction::execute(...)` → creates `User` (+ hashed password) and
   `Provider`. The user is **created but not logged in**.
2. Loop the files Livewire has already staged (`WithFileUploads` temp storage)
   through `KycDocumentService::upload($provider, $type, $file, $user, 'self')`.
3. Commit. If any step throws, the whole thing rolls back and nothing is written
   to the private disk or the DB.

This reuses `KycDocumentService` verbatim. The alternative (stage files keyed to
the session, move them post-create) adds a temp-file lifecycle for no real gain.

---

## 4. On submit

**Order of operations (Step 2 "Submit application"):**

1. Re-assert phone was OTP-verified this session (`#[Locked]` property, same trust
   boundary as `Signup`) and terms checkbox is ticked.
2. Validate name / password / (optional email) / at least the required documents
   present.
3. Resolve `zone_id` + `franchise_id` from the stored pin via
   `nearestCoveringZone()`. If `null` → out-of-coverage stop (D2).
4. `DB::transaction`:
   - `RegisterProviderAction::execute(name, phoneE164, hashedPassword,
     email|null, franchiseId, zoneId)` →
     - `User`: `role = provider`, `status = active` (D7), `phone_verified_at =
       now()`, `preferred_language = 'en'`, `franchise_id`, `zone_id`, password
       hash, email if verified.
     - `Provider`: `provider_type = 'independent'`, `kyc_status` left at the
       column default `'pending'` (**never** set to `approved` — same invariant
       the importer guards), `kyc_deadline_at = now()->addDays(30)`,
       `skills = [...]` if D3-yes.
   - For each uploaded file: `KycDocumentService::upload(...)`.
5. Commit. **No `Auth::guard('web')->login()`.**
6. Render a terminal confirmation view (see below).

**`users.status` — decision D7:**

- The CSV importer uses `active`. `EnsureIsProvider` gates only on "has a
  `providers` row" — **not** on `kyc_status` or `status` — so an `active` +
  password self-registrant *could* immediately sign in at `/provider/login` and
  land on the dashboard, which already shows an eligibility panel explaining
  "pending KYC, no work yet" (PW1 §3.3, by design: "a provider mid-KYC can still
  sign in and see why they have no work yet").
- **D7-A (recommended):** `status = 'active'`, matches the CSV path exactly, no
  new special-casing. The dashboard eligibility panel + the dispatch-time
  `kyc_status = 'approved'` filter (`DispatchService`) are the real gates.
- **D7-B:** `status = 'pending_verification'` (the `users` enum already has it)
  and teach `Provider\Auth\Login` to refuse a provider whose `status` is not
  `active`, until an admin approves. Stricter, but adds a login-path branch and a
  second place "is this provider allowed in" is decided.

**Confirmation screen:** a static Livewire view — *"Application received. Our team
will review your details and documents and contact you at {masked phone} within
{N} business days. You can't sign in until your application is approved"* (wording
depends on D7). **No auto-login, no dashboard link, no "resend" affordance.**
A `KycNotification`-style acknowledgement to the applicant is optional (decision
D8) — the existing `KycNotification` types are `kyc_approved` / `kyc_rejected`,
so an "application received" type would be new.

---

## 5. Admin approval parity

### 5.1 The review queue — already origin-agnostic

`Providers\Index::filteredProvidersQuery()` (`:121-128`) filters on
`kyc_status` (default tab `pending`) + name/phone search, scoped by
`AuthorizationService::scopeQuery` on the provider's own franchise/zone. **No
CSV-origin assumption anywhere** — no join to `catalog_import_runs`, no
`upload_source` filter. A self-registered provider appears in the `pending` tab
identically, and because zone/franchise was derived from the pin (§3.3) it lands
in the **correct franchise's** scoped queue automatically.

### 5.2 The approval action — already origin-agnostic

`Providers\Show::approve()` → `ReviewProviderKycAction::approve()`
(`app/Actions/ReviewProviderKycAction.php:22-40`) gates on:

1. `KycDocumentService::missingApprovedRequirements($provider, country_id)` is
   empty — i.e. every **required** doc type (`id_proof`, `address_proof`,
   `bank_details` by default) has an `approved`, `is_current` row.
2. `kyc_video_status === 'approved'` **if** `kyc.require_verification_video` is on
   (default `'1'`, per-franchise overridable).

Both checks read `provider_documents` / `providers` columns the self-registration
flow populates the same way a CSV provider's later uploads would. Approving a
self-registered provider is the **same clicks** as approving a CSV one:
admin reviews each document (`KycDocumentController`, which keys off the
per-document `status`, not `upload_source`), approves them, then approves the
provider.

### 5.3 The one real gap — verification video has no provider-facing entrypoint

`ReviewProviderKycAction` requires an **approved verification video** by default,
but a full-repo search finds **no route, controller, or Livewire component that
calls `KycVerificationVideoService::submit()`** — only its own tests. The admin
`KycDocumentController` can approve/reject an already-submitted video, and
`Settings\Manage` owns both the `kyc.require_verification_video` on/off toggle
(`Manage.php:236`, `:453`, `:886`, `:897`) and the video size cap — but nothing
lets a provider actually submit one. This gap **pre-exists** this
phase (it bites CSV providers too), but self-registration is the first flow that
would surface it at scale: a self-registered provider will be **un-approvable**
until either

- a provider-side video submission path is built (its own small phase), or
- the reviewing admin sets `kyc.require_verification_video = '0'` for that
  franchise.

**Flag for Mohammed (decision D9):** is a verification video in scope for the
self-registration form (record/upload in Step 1's document section), deferred to
a follow-up phase, or waived per-franchise for now?

### 5.4 Minor confirmations still worth a glance during build

- `KycDocumentController` document-stream/authorisation path assumes nothing about
  origin (schema says `upload_source` is metadata only) — low risk, verify.
- `Provider\Concerns\BuildsProviderEligibility` (dashboard panel) reads
  `kyc_status` / doc state — origin-agnostic, but eyeball it renders sensibly for
  a brand-new self-registrant with zero approved docs.

---

## 6. Spam / abuse — this is now public internet

The endpoint creates `users` + `providers` rows, writes uploaded files to disk,
and (with OTP) sends SMS. All three are abusable. Livewire actions share the one
`/livewire/update` endpoint, so **route-level `throttle:` middleware never sees
them** — every protection below is in-component.

### 6.1 Phone-OTP verification before the application is accepted — **required, not optional**

Reuse the customer signup's exact Firebase phone flow: `dispatch(
'firebase-send-phone-otp', ...)` → `#[On('firebase-phone-token')]` →
`FirebaseTokenVerifier::verify($idToken)` → store `verifiedPhoneE164` in a
`#[Locked]` property (`Signup.php:84-141`). Step 2 submit refuses unless
`verifiedPhoneE164` is set and matches the phone field.

This is Step 1's phone field in the reference UX anyway — make it a *verified*
field. It:

- kills the bulk of drive-by / botnet spam (each attempt costs a real SMS round
  trip the attacker must complete),
- guarantees the phone (which **is** the provider's login identifier) is real and
  reachable,
- brings Firebase's own reCAPTCHA challenge on the SMS-send step for free, which
  makes a *separate* CAPTCHA largely redundant (§6.4).

### 6.2 Throttling — `InteractsWithAuthThrottle`, both actions

Apply per-(identifier, IP) **and** per-IP limits (the trait does both keys) to:

| Action | Suggested limit | Basis |
| --- | --- | --- |
| Send phone OTP | `maxPerIdentifier: 5`, `maxPerIp: 20`, 60s decay | Matches `Signup::requestPhoneCode()` and `routes/api.php` OTP throttles |
| Submit application | `maxPerIdentifier: 3`, `maxPerIp: 10` | Matches `routes/api.php:22` `register` → `throttle:10,1` |
| Send email OTP (if D4-verify) | `maxPerIdentifier: 5`, `maxPerIp: 20` | Matches `Signup::sendEmailCode()` |

### 6.3 File-upload abuse

`KycDocumentService` already whitelists JPG/PNG/PDF (MIME + extension) and caps at
10 MB. Add at the component level:

- **max N files per submission** = the number of requirement slots from
  `requirementsFor()` (no arbitrary extra uploads),
- **persist nothing to the private disk until** phone is verified *and* the
  transaction in §4 is committing — an abandoned or failed form writes zero
  files (Livewire's `WithFileUploads` temp files are GC'd automatically),
- reject a submission whose total upload payload exceeds a sane ceiling
  (e.g. `N × 10 MB`).

### 6.4 CAPTCHA — secondary, add only if OTP + throttle prove insufficient

Firebase phone auth already runs reCAPTCHA on SMS-send, so a standalone CAPTCHA on
top of the OTP path is mostly redundant. Recommendation: **do not** add a separate
CAPTCHA on day one; keep it as a known lever if abuse data later shows the OTP
challenge is being farmed. If added, gate it on the OTP-send action, not the final
submit.

### 6.5 Data-layer duplicate / identity rules

- `users.phone` is unique — a second registration on the same number fails at the
  DB. Handle it gracefully *before* the insert:
  - phone already belongs to a **provider** → "You already have a partner
    application — sign in" + link to `/provider/login`.
  - phone belongs to a **non-provider** user (existing customer) → **decision
    D10:** attach a new `providerProfile` to that existing `users` row (common
    real case: a customer wants to become a partner — keeps one identity), *or*
    refuse like the CSV importer does (`ProviderPreRegisterImporter:127-130`).
    Recommendation: **attach** — but require the person to prove the number via
    OTP (already happening) and do **not** touch their existing password/role
    beyond adding the `providers` row.
- The KYC review queue is the ultimate backstop: nothing a spammer submits goes
  live without an admin approving each document + the provider. Worst case is junk
  rows in the `pending` tab. If volume becomes a problem, an admin "dismiss /
  bulk-reject stale pending applications" affordance is a cheap follow-up (out of
  scope for v1).

### 6.6 Dependency blocker to call out now

Firebase phone OTP needs the Firebase project config the team **still hasn't
supplied** (same blocker noted for the auth rebuild's live testing). Without it,
§6.1 can't run and the only fallback is email OTP (`OtpService`), which verifies
an *email*, not the *phone* the account is keyed on — materially weaker for a
public provider form. **Self-registration should not ship before Firebase phone
auth is live.**

---

## 7. Decisions for Mohammed

| # | Decision | Recommendation |
| --- | --- | --- |
| D1 | Persist business name / street address / base coordinate? (needs 1 additive migration) or capture address only as zone-resolution input? | **D1-A** — no migration for v1; nothing reads those fields yet |
| D2 | Pin outside all serviced zones: block submission, or accept as an unassigned application for ops to place? | **Block** with the honest out-of-coverage message; revisit if demand data says otherwise |
| D3 | Let the applicant tick the service categories they work in → `providers.skills`? | **Yes** — it's useful review context; admin can still edit it on `Providers\Show` |
| D4 | Optional email: require an email OTP verification, or accept unverified? | Accept **unverified** for v1 (phone is the verified identity); verify later from the dashboard |
| D5 | Store a terms-acceptance timestamp? (needs a column) | Nice-to-have; defer unless legal wants the audit trail now |
| D6 | Map address autocomplete — add Google Places (or similar) as a new front-end dependency, or pin-drop + browser geolocation only? | Pin-drop + `cfLocate` only for v1 (matches the customer side); autocomplete is a separate front-end task |
| D7 | New self-registrant `users.status` = `active` (can sign in, sees "pending" dashboard) or `pending_verification` (login refused until approved)? | **`active`** — matches CSV path, fewer gates to reason about |
| D8 | Send the applicant an "application received" notification? (new `KycNotification` type) | Optional; the on-screen confirmation is the minimum |
| D9 | Verification video: in the registration form now, deferred to a follow-up phase, or waived per-franchise? (there is **no** provider-facing video submission path today) | Decide explicitly — this is the one thing that will block approval of every self-registered provider |
| D10 | Phone belongs to an existing customer: attach a `providerProfile` to that user, or refuse? | **Attach** (after OTP proof), don't force a second identity |

---

## 8. Proposed build order (after decisions locked)

1. **`RegisterProviderAction`** + refactor `ProviderPreRegisterImporter::commit()`
   to call it. Pin the unchanged CSV behaviour with the existing
   `ProviderPreRegisterTest`. (No migration if D1-A / D5-defer.)
2. **`App\Livewire\Provider\Auth\Register`** — 2-step component: Step 1
   (phone + Firebase OTP verify, address pin → `nearestCoveringZone()`, document
   slots from `requirementsFor()`), Step 2 (name, password, optional email,
   terms, submit). Throttle both actions via `InteractsWithAuthThrottle`.
3. **Route** `GET /provider/register` in the `guest` group + "Become a partner"
   link on `/provider/login`.
4. **Submit handler** — the §4 transaction: `RegisterProviderAction` →
   `KycDocumentService::upload()` loop → confirmation view. No login.
5. **Confirmation view** + (D8) optional applicant notification.
6. **Tests** — happy path creates the exact CSV-parity shape; out-of-coverage
   stop; duplicate-phone (provider / customer / none) branches; throttle trips;
   documents land as `pending` / `is_current` / `upload_source='self'`; no session
   is started; the provider then shows up in the admin `pending` queue and is
   approvable via the unchanged `ReviewProviderKycAction` (given docs approved +
   D9 resolved).
7. **Doc** — fold a "Self-Registration" section into
   `docs/PROVIDER_ONBOARDING_RUNBOOK.md` (it currently states self-signup does not
   exist).

**Not in this phase:** partner profile-edit screen, map autocomplete, verification
video submission path (D9 may split it out), any change to dispatch or the
approval action's logic.
