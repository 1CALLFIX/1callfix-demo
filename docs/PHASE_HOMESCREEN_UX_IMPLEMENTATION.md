# 1CF-HOMESCREEN-UX-001 — Homescreen location + search UX

Branch: `feature/homescreen-location-search`
Base: `origin/main` @ `f6632b50a515117ab13640fd029337a06c330393` (local `main` and
`origin/main` were identical at the start of this work — no unpushed local
commits, nothing to stop for).

This document is **Part 1 — inspect first**, written before any code change
on this branch, and left in place afterwards as the record of what already
existed vs. what this phase added.

## 1. Current customer home page

- **Livewire component:** `app/Livewire/Customer/Home.php`, using the
  `ResolvesCatalogContext` trait (`app/Livewire/Customer/Concerns/ResolvesCatalogContext.php`).
  `render()` builds `activeZone`, banners, category shortcuts, "New &
  noteworthy" / "Most booked" rails, offers, collections, membership plans and
  FAQs, all from the database (see that class's own docblock — no
  fabricated/hard-coded marketing content anywhere on the page).
- **Blade view:** `resources/views/livewire/customer/home.blade.php`. No
  search box lives on this page itself — search is a persistent control in
  the header only (both a compact desktop box and a full-width mobile row).
  The homepage hero instead carries the location affordance (see part 3
  below) beside the category grid.
- **Header/topbar partial:** `resources/views/components/customer/header.blade.php`.
  Renders `<livewire:customer.search-bar :compact="true" />` twice — once in
  the `hidden sm:flex` desktop bar, once in the mobile row — and
  `<livewire:customer.location-picker />` once. The two SearchBar instances
  are independent Livewire components that never share state (each has its
  own `term`/`showSuggestions`).
- **Search bar component:** `app/Livewire/Customer/SearchBar.php` +
  `resources/views/livewire/customer/search-bar.blade.php`.
  - `updatedTerm()` gates on a 2-character minimum before showing matches;
    an empty, focused field shows a default "Popular right now" /
    "Browse services" list instead.
  - `submit()` always **redirects** to `route('customer.search', ['q' =>
    $term])` — there is no in-place results rendering, by design (shareable
    URL, back-button history, pagination).
  - Suggestions come from `App\Services\Catalog\ServiceCatalogQuery` —
    `searchCategories()`/`searchServices()`, both plain SQL `LIKE` matches
    against `services`/`service_categories`/`service_subcategories` columns.
    No external search index, no Elasticsearch/Algolia/etc.
  - The full search screen is `App\Livewire\Customer\Search`, behind route
    `customer.search`, using the exact same `ServiceCatalogQuery` layer —
    guaranteed not to disagree with the dropdown.
  - There is no separate `SearchController`; it is Livewire component +
    redirect route only.

**This phase changed:** `SearchBar` now also computes a small list of
rotating placeholder examples (see part 2 below) in the SAME render pass,
reading the SAME `ServiceCatalogQuery` — it does not add a second query
layer and does not touch `updatedTerm()`/`submit()`/`suggestionPayload()`'s
existing matching logic at all.

## 2. Where the selected location is stored today

- **Session key:** `CustomerLocationContext::SESSION_KEY = 'customer.zone_id'`
  (`app/Services/Customer/CustomerLocationContext.php`). Only a **zone id**
  was stored in session before this phase — never franchise, lat/lng, or a
  formatted address. Franchise is always re-derived server-side from
  `zone->franchise_id`, never accepted from the client (the class's own
  docblock states this explicitly, matching the rule
  `AddressController::store()` already enforces for saved addresses).
- **Model:** `app/Models/Address.php` — the `addresses` table
  (`user_id, franchise_id, zone_id, label, lat, lng, address_line, landmark,
  city, pincode, is_default`), used for logged-in customers' saved addresses.
- **Location-picker Livewire component (header, zone-only selector before
  this phase):** `app/Livewire/Customer/LocationPicker.php` +
  `resources/views/livewire/customer/location-picker.blade.php`. Let the
  customer pick from a searchable list of active `Zone` rows, or use browser
  geolocation via `window.cfLocate()` (`resources/js/geolocation.js`).
  Coordinates from geolocation were (and remain) **never stored** — only
  used to look up a zone.
- **Booking wizard:** `app/Livewire/Customer/Booking/Wizard.php` does **not**
  re-geocode at booking time. It picks from the customer's saved `Address`
  rows (`resolvedAddress()`), and `placeBooking()` passes
  `franchise_id`/`zone_id`/`address_id` straight from that `Address` row into
  `CreateBookingAction::execute()`. The Action itself does no lat/lng→zone
  resolution — it trusts the caller-supplied zone/franchise, which the
  Wizard/API controller has already derived from a real `Address` row.
  Equivalent logic lives in `app/Livewire/Customer/Account/Addresses.php`
  for the standalone saved-addresses screen.

**This phase changed:** `CustomerLocationContext` gained four new,
**purely descriptive** session companions to the one authoritative zone id —
`customer.location_label`, `customer.location_address`,
`customer.location_lat`, `customer.location_lng` — so the location bar can
show a real place name/address instead of only the zone's admin-facing name.
These are written ONLY alongside a `setZone()` call that has already been
validated against a real active zone (never independently), are cleared
together with the zone, and are never read back by anything that decides
serviceability, pricing or dispatch — those all still go through
`zone()`/`franchiseId()`/`viewerScope()` exactly as before. **No second
source of truth was created**: there is still exactly one session key
(`customer.zone_id`) that anything server-side trusts.

## 3. Existing Google Places / address search code — there was none

There was **no Google Places Autocomplete integration anywhere in this
codebase** before this phase. The only prior Google API usage is:

- `.env.example`: `GOOGLE_MAPS_API_KEY=`
- `config/services.php`: `'google_maps' => ['key' => env('GOOGLE_MAPS_API_KEY')]`
- Loaded only in the **admin** layout
  (`resources/views/layouts/admin.blade.php`):
  `<script src="https://maps.googleapis.com/maps/api/js?key=...">` — the
  classic Maps JavaScript API, used for **drawing zone boundaries**
  (`resources/views/components/zone-map.blade.php`), a different Google
  product/billing model from Places.
- No Places widget, no Places REST calls, no session-token mechanism, no
  referrer/country restriction anywhere in code. Customer-facing address
  entry (the Wizard's inline add-address form, the standalone Addresses
  screen) was a plain manual text form with an optional "use my current
  location" geolocation button — no autocomplete of any kind.

**Whether the browser key is restricted (referrer / API allow-list) and
whether "Places API (New)" is enabled on the same Google Cloud project as
the existing Maps JavaScript API key cannot be verified from inside this
codebase or this session** — that lives in the Google Cloud Console, which
this session has no access to. See part 7 below for what to check there.

**This phase's approach:** since there was no existing Places integration to
reuse, and the spec explicitly allows building one (§7: "If the integration
uses a browser key, report what you can verify... Do not build a parallel
integration" — there was no first integration to be parallel to), this phase
adds ONE new, minimal Places (New) integration:

- Reuses the exact same `config('services.google_maps.key')` /
  `GOOGLE_MAPS_API_KEY` the admin Maps JS API already uses — no second key,
  no second config path. Added one sibling config value,
  `services.google_maps.places_region` (env `GOOGLE_PLACES_REGION`, default
  `in`), to restrict Places suggestions to India by default (every
  production franchise/zone today is in Nellore, India).
- New file `resources/js/places-autocomplete.js` — plain `fetch()` calls to
  `places.googleapis.com/v1/places:autocomplete` and
  `.../v1/places/{id}` (Place Details), no new script tag/SDK load, no new
  dependency (the customer bundle is deliberately dependency-free — see
  `resources/js/app.js`'s own docblock).
- The key is echoed into the customer layout the same way the admin layout
  already echoes it (`components/layouts/customer.blade.php`), gated behind
  `@if (config('services.google_maps.key'))` so a blank key means the
  feature silently degrades to the plain zone-name search box, never a
  broken or empty picker.
- Debounced ~300ms, 3-character minimum, one Places session token per
  "search session" (drawn fresh after every completed pick) — see part 7.

## 4. Zone and franchise matching

- **Resolver:** `CustomerLocationContext::nearestCoveringZone(float $lat,
  float $lng): ?Zone` — unchanged by this phase, reused as-is.
  1. **Point-in-polygon** against each active zone's `boundary_polygon`
     (admin-drawn, ≥3 points) — the nearest by centre distance wins when a
     point falls inside more than one.
  2. **Radius fallback** for a point no polygon contains: nearest active
     zone whose `center_lat`/`center_lng` + `default_dispatch_radius_km`
     (default 8km) circle reaches it, using
     `DispatchService::haversineKm()`.
  - Franchise is **always** `zone->franchise_id` — never independently
    matched, never accepted from the client.
  - **No match → `null`**, never an exception, never a "least far" fallback.
    Callers (`LocationPicker`) show a friendly notice and leave the
    previously active zone untouched.

**This phase changed:** added `LocationPicker::selectPlace()` /
`selectRecent()`, which call this exact same `nearestCoveringZone()` method
with the lat/lng a Places pick (or a recent-location pick) resolved to —
the same call shape `useCurrentLocation()` already used for GPS fixes. No
new resolution logic, no second geo-to-zone algorithm.

## 5. Catalog/zone filtering and serviceability

`app/Services/Catalog/ServiceCatalogQuery.php` is the single shared
catalog-visibility rule for every customer screen (Home, ServiceShow,
ServiceIndex, CategoryIndex/Show, Search, and the REST
`ServiceCatalogController`). Per its own docblock: **geography does not
remove rows.** `Service`/`ServiceCategory`/`ServiceSubcategory` carry no
geography columns at all. Franchise/zone context changes:
- the resolved **price** (`Service::resolvePrice()`, `FranchiseServicePricing`),
- which **badges/flash sales/banners** apply (scope-covering only),
- the **ranking** of `mostBooked()` (franchise-scoped booking counts).

It never hides a service row. This is unchanged by this phase, and this
phase does not alter search-result logic in any way (see part 9's
limitation note on this exact point, restated for the browsing-in-general
case: picking a location changes pricing/ranking context, not which rows
the catalog query returns).

**Booking-time serviceability** is unaffected: the Wizard resolves
franchise/zone from the customer's chosen saved `Address` row exactly as
before; this phase only changes what happens on the discovery/home screen
before a booking starts.
