# Release 2026-11-21.6 — Digital Marketing: social posts, contacts and app invites

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `07f666d` (2026.11.21.5 Live Visitors fixes) |
| Full test suite | 5127/5127 passed (2026-11-21, final code), no known failures |
| Composer | no (`composer.lock` unchanged; `maatwebsite/excel` was already installed) |
| Front-end build (steps 6–7) | no. Admin screens use the admin layout's Tailwind CDN; `public/js/social-share.js` is a plain file in git; the public unsubscribe page uses only classes already in the built CSS |
| Version | `VERSION` 2026.11.21.6 |

## What's in it
Owner 2026-11-21: "do all our tasks from our own platform", daily posts on Facebook / Instagram / Google Business,
skipped when a module is off, and invite existing customers to the new app.

### Admin → Marketing & Offers → Social posts
- **Today**: each post with its picture and one **Share on …** button per platform. Phone: caption copied + the phone's
  share sheet with picture and caption. Computer: caption copied, picture downloaded, platform opened. Then **Done ✓**
  (optional post link). All platforms done → Posted.
- **Plan** (two weeks, colour per module), **Drafts without a day**, editor in 5 coloured steps (picture, words in
  English + Telugu, module + city + link, where to post, when) with a Save bar at the bottom.
- **💡 Ready-made ideas** from live data: live banners, offers (always "applies when you pay online"), most viewed items
  this week, new shops/restaurants, open jobs, get the app. Each idea once per 14 days.
- **🗓️ Fill my next 7 days**: one draft per free day, rotating modules. Drafts only; nothing posts itself.
- **Module auto-skip**: posts for a module switched off in their city (or everywhere) are skipped with the reason
  (`marketing:posts-due`, every 15 min); scheduling one is refused with the reason.
- **Reminder**: email + push to the marketing team at the Super Admin's time (default 09:30) "N posts ready", and an
  18:00 nudge if not shared; once a day each.
- Links carry `utm_source=<platform>&utm_medium=social&utm_campaign=post-<id>` → Live Visitors "Came from" and bookings.
- Settings (Super Admin): default platforms, posting time, reminder time.
- Automatic posting (no tap) needs Meta / Google approval of the business apps; `social_post_deliveries` is ready for it.

### Admin → Marketing & Offers → Contacts & app invites
- **Import** Excel / CSV (Name, Mobile, Email, City in any order, or no headings): preview with problems in plain words,
  repeated rows left out, existing contacts' blanks filled, cities matched; a city team's imports go to its own city.
- **Email** the app invitation (queued, once per contact, never to unsubscribed or joined ones, unsubscribe link).
- **WhatsApp**: "Send on WhatsApp" per contact (wa.me with the invitation filled in), recorded.
- **Joined ✓**: contacts whose mobile signs up are matched (on import and daily).
- The invitation words are the new Notification Center template **"Invite to the new 1CallFix app"** (also usable from
  Compose for app users).
- Public `/unsubscribe/{token}`: the link shows a button; the button records it (mail scanners cannot unsubscribe).

### Access
- `marketing.posts.manage`, `marketing.contacts.manage`: Super Admin + every role with `banners.manage`; row-scoped by
  city / franchise / country.
- New **Marketing** role (posts, contacts, banners, Live Visitors, Dashboard) and test login **`test.marketing@1callfix.test`**
  (city scope) in `php artisan test-accounts`.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_21_500000_create_marketing_tables` (new tables `social_posts`, `social_post_deliveries`, `marketing_contacts`)
- `2026_11_21_500100_marketing_permissions_role_and_invite_template`

Rollback count for `migrate:rollback --step=`: 2 (the tables' down() refuses while posts or contacts exist)

## Step 10 — scheduled commands that must be listed
- `marketing:posts-due` (every 15 minutes)

## After deploy (owner)
- `php artisan test-accounts create --password=...` on the server to add `test.marketing` (password not in git).
- Walk-through with `test.marketing@1callfix.test`: Social posts → 💡 ideas → Use this → Schedule for today →
  on the phone tap Share on Instagram → Done ✓. Contacts → import a 3-row CSV → Send on WhatsApp to your own number →
  Email 1 contact (your own email).
- Super Admin: Social posts → Settings: tick the platforms you use, pick the posting and reminder times.
- Edit the invitation words in Notification Center → Templates if needed.
