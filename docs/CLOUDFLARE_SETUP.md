# Cloudflare in front of 1callfix.com — Layer 1

Owner decision 2026-11-09: Cloudflare in front for attack protection and to keep images, styles and scripts near the
customer. **Pages are never saved by Cloudflare** (the same way Urban Company, Blinkit, Swiggy, Zomato and Flipkart run:
checked 2026-11-09, every one of their pages comes back `DYNAMIC` / `no-store`). Saved pages for new visitors are a
possible Layer 2, only if real traffic ever needs it.

Do this only after release `docs/deploys/2026-11-09-cloudflare-layer1.md` is live (the site must trust Cloudflare's
addresses first, or sign-in limits would lock out everyone together).

## What already works on our side (checked 2026-11-09)
- Pages say `private, no-store`: Cloudflare will never save them.
- Built styles/scripts (`/build/assets/`) say "keep 1 year"; uploaded images (`/storage/`) say "keep 7 days". Their file
  names change when they change, so nothing needs clearing after a deploy.
- Private files (invoices, KYC, receipts) are served by the app with `private`: Cloudflare will not keep them.
- Server certificate covers `1callfix.com` and `www.1callfix.com` (valid to 2026-12-20, renews by itself).
- Uploads are at most 10 MB (Cloudflare free allows 100 MB). Cloudflare stops waiting for a page after 100 seconds.

## Step A — add the site (nothing changes for customers yet)
1. Cloudflare dashboard → **Add a domain** → `1callfix.com` → **Free** plan.
2. Cloudflare scans the current DNS. Compare its list with **CyberPanel → DNS → Add/Delete Records → 1callfix.com**.
   Every record in CyberPanel must be in Cloudflare with the same value. Found publicly on 2026-11-09:

| Type | Name | Value | Cloudflare cloud |
|---|---|---|---|
| A | `1callfix.com` | `31.97.186.175` | **Orange** (proxied) |
| A | `www` | `31.97.186.175` | **Orange** (proxied) |
| A | `mail` | `31.97.186.175` | **Grey** (DNS only) — email breaks if orange |
| A | `ftp` | `31.97.186.175` | **Grey** |
| A | `api` | `31.97.186.175` | **Grey** (left as it is today) |
| MX | `1callfix.com` | `mail.1callfix.com`, priority 10 | — |
| TXT | `1callfix.com` | `v=spf1 a mx ip4:31.97.186.175 ~all` | — |
| TXT | `_dmarc` | `v=DMARC1; p=none;` | — |
| TXT | `default._domainkey` | copy the full value from CyberPanel (long DKIM key; a cut-off copy breaks email) | — |

   Anything else in CyberPanel (other names, `cpanel`, `webmail`, `_domainkey` records, Google/Bing verification TXT):
   copy it too, grey cloud unless it is the website itself.

## Step B — settings, before switching
- **SSL/TLS → Overview:** `Full (strict)`.
- **SSL/TLS → Edge Certificates:** Always Use HTTPS **on**, Automatic HTTPS Rewrites **on**, Minimum TLS `1.2`.
- **Caching → Configuration:** Caching level `Standard`; Browser Cache TTL `Respect Existing Headers`.
  **No "Cache Everything" rule** anywhere (that would save pages).
- **Speed → Optimization:** Rocket Loader **off** (it breaks Livewire/Alpine buttons).
- **Scrape Shield:** Email Address Obfuscation **off** (it rewrites pages).
- **Security → Bots:** Bot Fight Mode **off** (on the free plan it cannot spare Razorpay's payment messages or the apps).
- **Security → WAF → Custom rules → Create rule** "Payments and apps":
  `URI Path starts with /api/` → action **Skip** → tick all remaining custom rules, Security Level, Browser Integrity
  Check, and all rate limiting rules. (Razorpay's payment messages arrive at `/api/webhooks/razorpay`.)
- **Network:** WebSockets on, HTTP/3 on (defaults).

## Step C — the switch (owner, at the domain registrar)
1. Cloudflare shows two name servers (like `xxx.ns.cloudflare.com`).
2. At the registrar where `1callfix.com` was bought: Domain → **Name servers** → change `ns1.1callfix.com` /
   `ns2.1callfix.com` to Cloudflare's two. Save.
3. Takes minutes to a few hours. Cloudflare emails when the site is active. The site keeps working the whole time
   (both old and new name servers point at the same server).
4. Do it at a quiet hour (early morning), not during a campaign.

## Step D — checks after Cloudflare says active (Claude does these)
- `curl -sI https://www.1callfix.com/` shows `server: cloudflare` and `cf-cache-status: DYNAMIC` (page not saved).
- An image under `/storage/` and a file under `/build/assets/` show `cf-cache-status: HIT` on the second request.
- Sign in, book, pay (Razorpay test), Food order, admin login, rider / partner / customer apps open.
- A Razorpay payment message arrives (admin payment shows captured).
- Send and receive one email on the 1callfix.com mailbox.
- `storage/logs/laravel.log`: visitors show their own addresses, not 172.x / 162.158.x / 104.x.

## Undo (if anything is wrong)
Either: Cloudflare → DNS → click the orange clouds to grey (instant, site served directly again).
Or: set the registrar name servers back to `ns1.1callfix.com` / `ns2.1callfix.com`.
