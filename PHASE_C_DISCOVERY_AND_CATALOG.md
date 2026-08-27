# Phase C — Customer Discovery, Catalog & Marketplace Homepage

> **Branch:** `feature/customer-web-foundation`
> **Date:** 2026-08-27
> **Scope:** Customer-facing discovery: marketplace homepage, category/subcategory explorer, service catalog and detail with option configuration, search, offers, and the two database-driven banner slots.
> **Guiding principle (unchanged from Phase B):** zero backend rebuild. Every figure, price, badge, banner and rating on these screens is read from the existing schema through the existing services. Nothing on any screen is sample data, and nothing that would have to be fabricated is shown at all.

---

## 1. Status summary

| # | Item | Status | Notes |
|---|---|---|---|
| 1 | Homepage | **Done** | Dynamic marketplace IA; every section hidden when its data is absent |
| 2 | Header / navigation | **Done** | Real routes replace the Phase B placeholders; persistent search from `xl` |
| 3 | Search | **Done** | Results screen + live suggestion dropdown, one shared query layer |
| 4 | Categories | **Done** | Explorer + per-category page, active-service counts |
| 5 | Subcategories | **Done** | Filter rail on the category page, URL-bound and shareable |
| 6 | Services | **Done** | Paginated grid, category filter, in-category search, 3 sorts |
| 7 | Service options | **Done (display + estimate)** | Backend cannot yet carry them into a booking — see §7 |
| 8 | Hero banner (slot 1) | **Done** | `banners.placement = 'top'`, accessible carousel |
| 9 | Mid-page banner slider (slot 2) | **Done** | `banners.placement = 'mid'`, independently configured |
| 10 | Offers | **Done** | Real flash sales only; section/screen disappears when none are live |
| 11 | NEW badge | **Done** | Existing Badge engine, automatic `recently_created` rule |
| 12 | Most booked | **Done** | Real count over `bookings`; section hidden with no history |
| 13 | Location filtering | **Done** | Session zone → franchise → city/country drives price, badges, sales, banners |
| 14 | Test/demo data | **Done** | Existing `qa:seed` extended; `qa:clean` proven to fully reverse it |
| 15 | Negative testing | **Done** | 173 customer-web tests, majority asserting what must NOT appear |
| 16 | Browser testing | **Done** | 6 screens × 6 breakpoints, a11y probe, carousel interaction |
| 17 | Accessibility | **Done** | See §9 |
| 18 | Performance | **Reviewed** | See §10 |
| 19 | Tests BEFORE | **1559 passed** / 1559, 3978 assertions |
| 20 | Tests AFTER | **1668 passed** / 1668, 4242 assertions | +109 tests, 0 failures |

---

## 2. What was built

### Screens (all public, unauthenticated — matching the existing catalog API)

| Route | Component | Purpose |
|---|---|---|
| `/` | `Customer\Home` | Marketplace homepage |
| `/categories` | `Customer\Catalog\CategoryIndex` | Full category explorer |
| `/categories/{category:slug}` | `Customer\Catalog\CategoryShow` | One category: subcategories, search, sort, paginated grid |
| `/services` | `Customer\Catalog\ServiceIndex` | Whole catalog with filters |
| `/offers` | `Customer\Catalog\ServiceIndex` | Same screen, narrowed to live flash sales |
| `/services/{service}` | `Customer\Catalog\ServiceShow` | Detail, options, reviews, availability |
| `/search` | `Customer\Search` | Results screen |
| (header/hero) | `Customer\SearchBar` | Live suggestion dropdown |

### Homepage information architecture

Hero (heading + search + location + category shortcuts) → **hero banner carousel** → New & noteworthy → Most booked → **mid-page banner slider** → category collections → offers → membership → trust → FAQ.

Every section between the hero and the trust block is conditional on real data and renders nothing at all when it has none.

---

## 3. Backend reuse — what was used, not rebuilt

| Need | Existing thing used |
|---|---|
| Catalog visibility rule | Extracted from `ServiceCatalogController` into `Services\Catalog\ServiceCatalogQuery`, now shared by the API **and** every customer screen |
| Price cascade | `Service::resolvePrice()` → `FlashSaleService::priceFor()` (unchanged order) |
| Franchise override | `FranchiseServicePricing` via the session zone |
| Badges (NEW / POPULAR / …) | `BadgeService` + `badges` / `badge_assignments` (seeded by migration) |
| Offers | `FlashSaleService`, `flash_sales` / `flash_sale_targets` |
| Banners, both slots | `Banner::scopeForSlot()` — the model's own docblock names this caller |
| Ratings | `reviews` joined through `bookings` |
| Availability | `DispatchService::nearbyForService()` (the `/api/providers/nearby` call) |
| Zone/franchise context | `Customer\CustomerLocationContext` (Phase B), extended with `viewerScope()` / `bannerContext()` |
| Demo data | `qa:seed` / `qa:clean` + `QaManifest` |

**Two shared services gained batched methods** (`BadgeService::badgesForMany()`, `FlashSaleService::priceForMany()`), and the single-entity methods now delegate to them — one implementation, no duplicated rules. This removes what would otherwise have been 3 queries per card on every grid.

---

## 4. Backend gaps found (documented, not worked around)

### 4.1 BANNER ADMIN GAP

`banners` already supports everything both slots need to be **live, targeted and scheduled** without a code change: `placement` (top/mid), `franchise_id`, `zone_id`, `module`, `category_id`, `title`, `image`, `link`, `starts_at`, `expires_at`, `sort_order`, `is_active`. `Livewire\Banners\Manage` already administers all of it. **No new banner CMS is needed or was built.**

The following are requested by the Phase C brief and have **no column**:

| Field | Required for | Suggested shape |
|---|---|---|
| `subtitle` | Second line of banner copy | `string` nullable |
| `mobile_image` | Separate portrait/mobile asset | `string` nullable; fall back to `image` |
| `cta_text` | Admin-controlled button label | `string` nullable |
| `audience` | Target logged-in vs new vs segment | `string` nullable, or a rules JSON |
| `tracking_key` | Impression/click attribution | `string` nullable + a `banner_events` table |
| destination *type* | Link to a service/category/offer by ID rather than a raw URL | `link_type` + `link_id`, alongside the existing free-text `link` |

Consequences today, all visible in the shipped UI:
- The carousel renders **no invented CTA label**. The banner's own `title` is the link text; an arrow supplies the affordance. Inventing "Shop now" in Blade would be exactly the hard-coded banner content the architecture forbids.
- One stored image serves both breakpoints (`object-cover`, taller box on small screens).
- No subtitle is rendered.
- No impression/click tracking exists.

### 4.2 Service options are not carried into a booking

`service_option_groups` / `service_options` are real and admin-managed. `booking_options` and the `BookingOption` model exist — **with no writer anywhere in `app/`**. `CreateBookingAction::execute()` takes no options argument and computes `price_quoted` from `price_quoted ?? base_price` alone.

`CUSTOMER_WEBAPP_READINESS_MATRIX.md` row 17 describes options as already flowing through `CreateBookingAction`. **That is not what the code does.**

Phase C therefore displays and prices options, and labels the figure an **estimate**, confirmed at booking. **Phase D dependency:** `CreateBookingAction` must accept selected option IDs, re-read their deltas server-side, write `booking_options`, and include them in `price_quoted`.

### 4.3 `services.slug` has no unique index

`service_categories.slug` is unique; `services.slug` and `service_subcategories.slug` are not. Category routes bind on slug; **service routes bind on the primary key** rather than silently resolving to whichever duplicate the database returned first. A future migration adding a unique index (after de-duplicating existing rows) would allow slug URLs for services.

### 4.4 No per-service FAQ relationship

`faqs` has a free-text `category` column and no link to `services` or `service_categories`. Per-service FAQs are therefore **not** rendered on the detail page — inventing a "category name must match" convention would be inventing a relationship. FAQs appear on the homepage and help centre, from real rows.

### 4.5 No "what's included" field

`services` has `description` only. The detail page renders it under "About this service". A structured inclusions list needs a column or a related table.

### 4.6 No popularity/ranking engine

Confirmed by the Badge engine's own migration docblock and by inspection: no popularity/trending statistics engine exists (`RankingEngine` is provider-dispatch ranking, a different domain). "Most booked" is therefore a **direct aggregate over `bookings`** — nothing stored, no new table, no second engine — and the manual POPULAR badge remains the admin-curated label. With no booking history the section is hidden rather than faked.

### 4.7 Sort by rating is not offered

Rating is derived (reviews → bookings → service), not a column. Sorting a paginated catalog by it would need a correlated aggregate; sorting only the current page and calling it a catalog ranking would be a lie. Offered sorts are Recommended / Price ↑ / Price ↓ / Newest.

### 4.8 Geography does not filter the catalog

`Service` / `ServiceCategory` / `ServiceSubcategory` carry **no geography columns**, and `FranchiseServicePricing.is_offered` is not used to hide a service even in the admin call-centre booking form. Zone/franchise context therefore changes the **price, badges, offers and banners** — never the row set. This matches the existing API exactly. A per-franchise catalog would need a real backend decision first.

### 4.9 AI / natural-language search not wired

`Ai\BookingNaturalLanguageFilter` parses a phrase into **booking** filters (customer, status, date range over `bookings`) and is wired into the admin bookings screen. That is a different question from "which catalog services match this phrase", so it is deliberately not called here. A catalog NLP parser is a Phase D+ item.

### 4.10 Personal care not seeded

The Service domain models no personal-care concepts (no practitioner preference, no treatment inventory). Seeding that vertical would produce demo data that cannot be booked or dispatched. Omitted deliberately.

---

## 5. Demo / QA dataset

**Mechanism reused, not replaced:** `php artisan qa:seed --scale=small|default` and `php artisan qa:clean`, backed by `QaManifest` (exact per-row tracking). Both refuse to run outside `local`/`testing`/`qa`, and `qa:seed` refuses to run twice without a clean.

Extended for Phase C:

- **Catalog** — 6 categories / 11 subcategories / 45 services across AC & Appliance Repair, Home Repair & Maintenance, Cleaning, Pest Control, Painting & Home Improvement, plus one deliberately **inactive** category.
- **Options** — 22 option groups / 66 options: required single-choice, optional multi-choice, negative deltas, and services with none.
- **Badges** — manual POPULAR / FEATURED / BEST VALUE / TRENDING assignments including one **zone-scoped** and one **already expired**; NEW left to the automatic rule, with `created_at` backdated on most services so NEW appears on some and not others.
- **Banners** — 3 in the hero slot and 3 in the mid slot, plus **inactive**, **expired**, **not-yet-started**, **other-module**, **zone-targeted**, **franchise-targeted** and **no-image** rows.
- **Flash sales** — one live, one completed, one draft.
- **Reviews** — created through the real `ReviewService::submit()` on completed bookings; roughly two in three, so both rated and unrated cards appear.
- **Booking distribution** — deliberately uneven (70% to a "head" of services) so the Most Booked ranking is meaningful rather than noise.

Placeholder artwork is generated as inline SVG **data URIs**, so the demo needs no binary assets, no `storage:link`, and can never render as a broken image.

`DemoDatasetTest` asserts both that the dataset exercises the features **and** that `qa:clean` leaves zero rows behind in every table Phase C touched, while leaving the migration-seeded badge *definitions* intact.

---

## 6. Testing

**Before:** 1559 tests / 1559 passed / 3978 assertions.
**After:** 1668 tests / 1668 passed / 4242 assertions. No test was weakened, altered or deleted.

New (`tests/Feature/CustomerWeb/`, 173 tests total in the directory):

| File | Covers |
|---|---|
| `CatalogDiscoveryTest` | Visibility rule, taxonomy navigation, sorts, franchise pricing, zone-change refresh, most-booked ranking |
| `CatalogBadgeAndOfferTest` | NEW window (incl. admin-reconfigured and disabled), manual/expired/zone-scoped badges, live vs finished vs draft vs sold-out sales |
| `HomepageBannerTest` | Both slots independent, multi-slide, single-slide, no-banner fallback, activation/scheduling, zone/franchise/module targeting, `javascript:` link rejection |
| `ServiceDetailTest` | 404 rules, option groups, server-side estimate, **forged-selection rejection**, ratings, availability |
| `CustomerSearchTest` | Name/description/category/subcategory matching, LIKE-wildcard escaping, min length, no-results, recent searches, suggestion dropdown |
| `CatalogEmptyStateTest` | Every screen against an empty catalog, filtered-empty vs catalog-empty, zero price, no duration, higher-franchise-price-is-not-a-discount, public access |
| `DemoDatasetTest` | Dataset shape and **full reversibility** of `qa:clean` |

---

## 7. Bugs found and fixed during browser testing

Each of these was found by driving the real application, not by a unit test:

1. **Carousel controls were completely dead.** `scrollTo({behavior:'smooth'})` never moved anything in the test browser — not the carousel, not a plain container, not `window`; `behavior:'auto'` always worked. Swiping was unaffected, so the failure was silent. `carousel.js` now falls back to an instant jump when a smooth scroll does not start.
2. **Carousel dots never updated on programmatic navigation** — they were synced only from the `scroll` event, which programmatic scrolls did not reliably emit. `goTo()` now paints the controls for the slide it is moving to.
3. **Pause/play button lied.** It labelled itself from the timer, so it read "Start banner rotation" whenever hover or a hidden tab had paused rotation. It now reflects the viewer's own choice.
4. **Changing zone did not refresh the catalog.** `LocationPicker` is a separate component; the screens behind it kept the previous franchise's prices until a reload. Fixed with an `#[On('customer-zone-changed')]` listener in the shared trait.
5. **Header overflowed** at 360 px (9 px) and at 1024 px (15 px). Fixed by dropping a duplicate mobile search icon (the bottom nav already has Search) and tightening the location cap and row gaps in the affected bands.
6. **Heading-level jump** (h1 → h3) on `/services` and `/offers`. Fixed with a visually-hidden `<h2>` above the grid.
7. **Category explorer subcategory line** overflowed its grid cell and was drawn under the next row.
8. **`x-customer.initial` printed `{{ $initial }}` literally** — an echo on the same line as `@endphp` is swallowed by Blade's raw-block extraction.
9. **Two clear buttons** in every search field (mine plus WebKit's native one).
10. **`50%` searches returned nothing.** SQLite does not treat `\` as a LIKE escape character by default; the statement needs an explicit `ESCAPE '\'`. Caught by a test after the MySQL-only assumption shipped.
11. **Broken images left empty boxes.** The lettered placeholder now renders *behind* every image, so a 404 reveals it with no JavaScript.
12. **Tailwind sourced only compiled views** (`storage/framework/views`), making a production build depend on which pages had been visited on the build machine — `view:clear` before `npm run build` shrank the bundle from 90.9 kB to 74.5 kB. Now sources `resources/views` and `app/` directly.

---

## 8. Browser verification

Chrome, against the running application with the seeded demo dataset.

- **Screens:** homepage, category explorer, category page, catalog grid, offers, service detail, search.
- **Breakpoints:** 360, 375, 390, 768, 1024, 1280, 1440 — automated horizontal-overflow probe over every screen at every width. **All clean.**
- **Carousel:** next/prev, arrow keys, dot jumps, wrap-around at both ends, dot state, live-region announcement, pause/resume, snap preserved.
- **Options:** selecting "2 ACs" + an add-on on a flash-sale-discounted service produced ₹399.20 + ₹450.00 + ₹249.00 = **₹1,098.20** with a correct line-item breakdown, computed server-side.
- **Location:** choosing a zone made the zone-targeted hero banner and the zone-scoped TRENDING badge appear, and re-priced the catalog.
- **Search:** live dropdown matched by name, description and category; results screen, empty state and clear all verified.

---

## 9. Accessibility (WCAG 2.1 AA)

Automated probe across all six screens: **single `<h1>` per page, no heading-level jumps, no image missing `alt`, no control without an accessible name, no duplicate IDs.**

- Carousel follows the APG pattern: `aria-roledescription="carousel"`, labelled slides announcing position, real `<button>` controls, Left/Right arrow keys, a visible pause control (2.2.2), and a polite live region that announces the current slide **only while rotation is stopped** so it never talks over the reader.
- Auto-advance never starts under `prefers-reduced-motion` (2.3.3), and pauses on hover, on focus within, and while the tab is hidden.
- Without JavaScript the carousel is still a scroll-snap row: swipeable, keyboard-scrollable, every slide reachable. Arrows and dots stay hidden until JS reveals them.
- Filter and result changes are announced through polite live regions (4.1.3).
- Every interactive target is at least 44 px (2.5.5) — including the carousel dots, whose 8 px pip sits inside a 44 px button.
- Selected states are never colour alone (1.4.1): chips use `aria-pressed`, the active zone row carries the word "Selected".
- Service cards are a single link, so each service is one tab stop and one announced link, not four.
- Rating renders as a screen-reader sentence ("Rated 4.5 out of 5 from 2 reviews") alongside the visual figure.

---

## 10. Performance

- **JS bundle: 2.65 kB (1.08 kB gzip).** No Alpine, no carousel library, no framework — the only browser code is the carousel.
- **CSS: 74.6 kB (15.2 kB gzip).**
- **No N+1 on any grid.** `CatalogPresenter::cards()` batches badges, flash-sale pricing and review aggregates for a whole page; per-card calls would have been 3 queries each. Category/subcategory are eager-loaded.
- **Pagination** on every grid (12/page); homepage rails are capped (4–8).
- **Images**: every below-the-fold image is `loading="lazy"` + `decoding="async"` with explicit `width`/`height` so nothing reflows. Only the first hero slide and the detail-page hero are eager, with `fetchpriority="high"`.
- **Search** is debounced 300 ms with a server-enforced 2-character minimum, so a typing burst is one request rather than eight.
- **Most-booked** filters with `whereHas` + `withCount` in one query rather than loading and counting in PHP.

---

## 11. Known issues / limitations

1. **Smooth scrolling could not be verified in the automated browser** (it is disabled there). The reduced-motion and fallback paths were verified; the animation itself was not.
2. **Sort by rating** is not offered — see §4.7.
3. **Price sorts use the stored cascade** (`discount_price ?? base_price`), not the final displayed price, which can also include a franchise override and a flash sale. Ordering by a partially-applied cascade would put cards visibly out of order; documented rather than approximated.
4. **`public/storage` symlink** must exist for admin-uploaded images to resolve (`php artisan storage:link`). It was missing locally; created. Seeded demo artwork is unaffected (data URIs).
5. **No vertical switcher.** Parcel/Taxi/Rental/Hotel/Marketplace have real backends but no customer screens; a nav row of placeholders would make the app look larger and emptier at once. Adding one later is a change to one array in the header plus its routes — nothing in the layout or catalog architecture assumes a single vertical.

---

## 12. Phase D dependencies

1. `CreateBookingAction` must accept and price **service options** (§4.2) — the detail screen already collects and validates them.
2. The **Book now** CTA on the detail page is where the booking wizard attaches; it currently routes to the honest `coming-soon` placeholder.
3. **Address → zone/franchise** resolution at booking time replaces the browse-time zone context for pricing authority.
4. Flash-sale **redemption** (`FlashSaleService::redeem()`) must be called at booking, not at browse time — nothing in Phase C writes a redemption.
5. Membership entitlements already adjust price inside `CreateBookingAction`; the homepage strip is a teaser only.

---

## 13. Compliance notes

- **No proprietary assets or content** from any reference marketplace were copied. No logo, image, banner artwork, icon set, copy, or layout was reproduced. The reference was used for information architecture and interaction patterns only (rails, filter chips, sticky booking summary, dual banner slots) — patterns common across the category and not owned by any one operator. All copy is original, all artwork is either database content or generated SVG placeholders.
- **Branding** is read from `Setting::get('branding.platform_name')` throughout; no brand string is hard-coded in any Blade file.
- **No Stitch dependency.** Blade + Livewire + Tailwind + Vite only.
- **Git:** all work on `feature/customer-web-foundation`. `main` untouched, nothing deployed, no destructive database command run, no production configuration changed.
