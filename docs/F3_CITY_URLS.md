# F3: clean, admin-controlled, city-prefixed public URLs

Built on `feature/f3-clean-category-slugs`. Implements the thumb rule in `CLAUDE.md`
("every public link is admin-controlled, city-aware and never breaks").

## URLs

| URL | What |
|---|---|
| `/{city}` | City landing page (categories offered there, franchise intro copy) |
| `/{city}/{slug}` | A category or a service. Categories and services share one slug space ("catalog"); a category wins a lookup |
| `/choose-city?to={slug}` | Only when several cities are live and the visitor has not picked one. Carries the query string (UTM) |
| `/categories/{slug}`, `/services/{id\|slug}` | Old city-less URLs: permanent 301 to `/{city}/{slug}`, query string kept verbatim |
| `/sitemap.xml` | Sitemap index: `/sitemap-static.xml` + one `/sitemap-{city}.xml` per live city |

Everything that is not a city or a catalog slug falls through to the CMS fallback exactly as before
(CMS root pages such as `/franchise`, 404s, 405s). `api`, `admin`, `provider`, `livewire`, `storage`,
`build`, `vendor`, `push` never reach the city routes.

A public link is always built with `App\Support\Seo\PublicUrl` (never a hand-written string).

## Rules

- **Live city** = `cities.is_active` AND at least one franchise with `status = active`. Inactive or franchise-less
  cities have no public pages (404) and no sitemap entry.
- Several active franchises in one city: the one in the visitor's session zone, else the lowest id.
  Visiting `/{city}/...` points the session zone at that city (price/availability/providers follow the URL).
- **Slugs**: clean (`ac-repair`), `-2`/`-3` only on a real collision, never random. Format `a-z0-9-`, max 100.
  Reserved words come from the live route collection + CMS page slugs + a short fixed list.
- An item may re-take its own old slug; another item's old slug is rejected.
- Changing a slug leaves a permanent redirect from the old one (stored as an item reference, so there are no chains:
  always one hop). Audit-logged (`activity_log`, `<type>_slug`).
- **Who edits**: `catalog.edit_slugs` (global/HQ holders and Super Admin only; a franchise-scoped holder cannot).
  City slugs: Super Admin only. Checked server-side in `SlugManager::change()`.
- **Franchise content**: `seo.edit_city_content` (franchise-scoped: own city only; HQ/Super Admin: any city).
  Admin → Catalog → City Page Content. Title, meta description, intro. Never a slug.
- **Index only real pages**: a city/category/service page is `index, follow` (with canonical) and listed in the
  sitemap only when the city has at least `seo.min_providers_to_index` approved, active providers (for categories and
  services: with that category). Default 1; Admin → Settings → Site identity. Everything else is `noindex`.
- **QA/demo rows** are identified by the name prefix `[QA] ` (what `QaSeeder` writes; there is no marker column).
  They are never in a sitemap and always `noindex`; on production (`seo.hide_qa_rows`, default on in production,
  env `SEO_HIDE_QA_ROWS`) they are also 404 on public pages and absent from catalog queries.
- **Attribution**: `bookings.acquisition` gains a `city` key (the city slug of the first city-scoped page seen with the
  campaign). No migration. A city alone is never stored as attribution.

## Migrations (4, all additive)

1. `2026_10_05_100000_add_slug_to_cities_table`: nullable unique `slug`, backfilled (`Nellore` becomes `nellore`).
2. `2026_10_05_100100_create_slug_redirects_table`: `unique(scope, old_slug)`; `down()` refuses while rows exist.
3. `2026_10_05_100200_create_city_page_contents_table`: `down()` refuses while rows exist.
4. `2026_10_05_100300_seed_catalog_slug_and_seo_permissions`: `catalog.edit_slugs`, `seo.edit_city_content` (Super Admin).

NOT in this release: `unique(services.slug)` (after the owner has run the clean-up on production).

## `catalog:clean-slugs`

Dry run by default; `--apply` writes; `--keep=ID` (repeatable) picks which duplicate service keeps a shared slug
(default: the lowest id). Cleans random-suffix category/subcategory slugs (old slug stays a 301), renames duplicate
services to `-2`, `-3` and points them at the kept service (the redirect takes effect once the duplicate is
deactivated; nothing is ever deleted), leaves `[QA]` rows alone, and only REPORTS what it cannot decide safely
(suffix that does not match the name, service slug equal to a category slug, empty slugs).

## Known limits

- Subcategories get clean, editable, redirected slugs, but there is still no public subcategory page; the category
  page keeps filtering by `?sub=`.
- Franchise slugs (`nellore-central-DjGv`) are F3-later.
- Deleting a city/category with live redirects pointing at it makes those URLs 404; the admin screens already refuse
  deleting a category with services/subcategories and a city with franchises.
