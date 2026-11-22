# Release 2026-11-22.5 — Posting through Buffer; Direct / Buffer / By hand per place

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `df9e962` (2026.11.22.4 Facebook Connect main address) |
| Full test suite | passed 5159 of 5159 (local, 2026-11-22); known failures: none |
| Composer | no |
| Migrations | one (below) |
| Front-end build (steps 6–7) | no (admin screens use the Tailwind CDN; no customer page changed) |
| Scheduled commands | none new; `marketing:posts-due` (every 5 min) now also posts through Buffer and confirms Buffer posts |
| Version | `VERSION` 2026.11.22.5 |

## What's in it
Owner 2026-11-22: "why does google and insta are not sending posts from admin panel", "on buffer they will get
autoposted or else we will add link with buffer account", "we will also completely build with buffer again and we will
on/off direct or buffer from admin".

On live before this release: Facebook went out by itself; Instagram failed on post #2 because the post had no picture
(Instagram never takes a post without one); Google Business was one tap by design (no Google API approval).

- **🚦 How each place posts** (Admin → Marketing → Social posts, Super Admin): one row per place with tap chips:
  - Facebook, Instagram: **Direct** (our own Facebook connection) / **Buffer** / **By hand**
  - Google Business, LinkedIn, X, Threads: **Buffer** / **By hand**
  - WhatsApp Status: **By hand** only
  Each place uses one way only, so a post can never go out twice. Coloured badge: 🤖 Posts by itself, ⚠️ what is
  missing, 👆 One tap. Facebook / Instagram stay Direct until changed (same as today). Changes are audited.
- **🟪 Post through Buffer** card: paste the Buffer API key (checked with Buffer first, stored encrypted, never shown
  again), channels are read and matched to our places automatically (tap to change; 🔄 Refresh channels after adding a
  channel in Buffer). Buffer's own enum names are read from its API at connect.
- Posts set to Buffer are handed to Buffer at their time (publish now), shown as "📨 Sent to Buffer", and the next run
  asks Buffer: live → ✓ Posted (via Buffer, with the post's link); Buffer error → ⚠️ with Buffer's reason and Try again.
  Never retried by itself. A refused key marks Buffer as needing a new key and stops Buffer posting.
- Google Business posts are "What's new" with a **Learn more** button carrying the tracked link (clean words, no URL
  in the text). X / Threads / Google Business text is shortened to fit; the link is never cut.
- LinkedIn, X, Threads show in the post editor only once they post by themselves through Buffer.
- **Scheduling a post with Instagram ticked and no picture is refused** ("Instagram needs a picture. Add one, or untick
  Instagram.").
- "undo" is hidden (and refused) for a post that went out by itself: undoing it would only post it a second time.
- The Facebook card's old "Automatic posting On/Off" step is replaced by the per-place chips.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_22_200000_social_post_deliveries_via_buffer` (adds nullable `via`, `external_id` to `social_post_deliveries`)

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- `marketing:posts-due` `*/5` (unchanged). `php artisan marketing:posts-due` prints "… Buffer confirmed N (N failed) …".

## Browser checks after deploy
- Admin → Marketing → Social posts as Super Admin: the 🚦 card shows Facebook / Instagram on **Direct** with
  "Posts by itself", Google Business on **By hand**; the 🟪 Buffer card says Not connected.
- As a marketing user (not Super Admin): neither card shows.
- New post with Instagram ticked and no picture → Schedule shows the picture message.

## Admin settings to do after deploy (owner)
1. In Buffer: connect the **Google Business Profile** as a channel (and any other place you want).
2. Buffer → your picture → Settings → **API** → **+ New Key** → copy.
3. Admin → Marketing → Social posts → 🟪 **Post through Buffer** → paste → **Connect Buffer**.
4. 🚦 **How each place posts** → Google Business → **Buffer**. Choose Direct or Buffer for Facebook / Instagram (not
   both: if Facebook / Instagram are also channels in Buffer, pick one way here).
5. Post #2 ("Instagram needs a picture"): open it, add a picture, press **Try again** on Instagram.
