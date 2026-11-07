# Release 2026-11-07 — ImgBB pictures copied onto our own server

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `b94a46c` |
| Full test suite | 4576 tests, 4575 passed; only the known NotificationCenterAuditTest .env failure |
| Composer | no |
| Front-end build (steps 6–7) | no (no views or CSS changed; skip 6–7) |
| Version | `VERSION` 2026.11.07.7 |

## What's in it (owner 2026-11-07, "go ahead")
- New one-time command `php artisan images:copy-remote`. Service pictures, category and sub-category icons that
  still point at ImgBB (i.ibb.co, a free host: slow, sometimes down) are downloaded, shrunk to the size the site
  shows (`App\Support\ImageShrinker`), saved on our own server (public disk: services/, categories/,
  subcategories/), and the item then points at our copy. Audit found 93: services 45, categories 6, sub-categories 42.
- Dry run by default (downloads to check, changes nothing). `--apply` makes the change, row by row, only if the
  row still has the same address. A picture that fails to download or is not a picture is reported and left as is.
- Every change is logged to `storage/app/image-moves/<time>.json`; `--undo=storage/app/image-moves/<time>.json`
  puts the old addresses back.

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- none new

## After the deploy (one time, in this order)
1. Fresh backup: mysqldump to `~/backups/pre-imgbb-move-<timestamp>.sql` (check size and "Dump completed").
2. `php artisan images:copy-remote` (dry run): expect "Would move 93 of 93".
3. `php artisan images:copy-remote --apply`; note the undo file it prints.
4. Four-phase cache clear (cached homepage blocks may still hold the old addresses).
5. Check: no ibb.co addresses left in the three columns; homepage, a category page and a service page show pictures.
