# Pending front-end build items

Class names that were left out of recent admin pages because they are **not in the
currently deployed compiled CSS** (`public/build/assets/app-*.css`), and a front-end build
(`npm run build` locally, then the `scp` step in CLAUDE.md) was not otherwise needed.
Add them back in the **next change that needs a front-end build anyway**, then delete the
entry here.

## My account (commit f8e306d, 0e follow-up)

| Class dropped | Where it belonged |
|---|---|
| `hover:bg-slate-700` | The three submit buttons in `resources/views/livewire/account/my-account.blade.php` ("Save details", "Change password", "Save notice text"), next to `bg-slate-800`. |
| `hover:text-white` | The admin-header name link to My account in `resources/views/layouts/admin.blade.php` (currently `text-gray-300 underline`). |
| `hover:underline` | Same header link. It currently shows a permanent `underline` instead of underlining only on hover. |

## Refund Controls (0c)

| Class dropped | Where it belonged |
|---|---|
| `sm:grid-cols-2` (with `grid-cols-1`) | The limits and escalation grids in `resources/views/livewire/refund-controls/manage.blade.php`. They use plain `grid-cols-2` today, which stays two columns on phones. |

## How to check a class before using it

```
grep -c -- '\.the-class' public/build/assets/app-*.css
```

`0` means it is not compiled yet.
