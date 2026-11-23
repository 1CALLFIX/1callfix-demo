# Release 2026-11-23.2 — shop pages send Google's "local shop" card

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `a1cb081` (2026.11.23.1) |
| Full test suite | passed 5184 of 5184 (local, 2026-11-23); known failures: none |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.23.2 |

## What's in it
Owner 2026-11-23 asked about an SEO section (keywords, links) on every item for owners to fill. Decision: no owner
SEO fields. Google ignores the keywords tag, owner-typed links are a spam risk for the whole site, and the ease-of-use
rule says no technical fields for vendors. Every item page already gets its clean city link, title, description,
sitemap entry and Google card automatically. The one gap was the shop page itself (restaurants and hotels had a card,
shops did not):

- `/{city}/shop/{shop}` now sends a schema.org `Store` card built from what the shop already filled in: name, link,
  logo, description, address (street, city, India), map position, opening hours when saved, and the rating only when
  real reviews exist (the same ones the page shows). The shop's phone is never published.
- Only on pages Google may index (never a test shop or a shop with no items on sale): unchanged rule.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Open a live shop page, view source: one `<script type="application/ld+json">` with `"@type":"Store"`. Optional:
  paste the page link into Google's Rich Results Test (search.google.com/test/rich-results).
