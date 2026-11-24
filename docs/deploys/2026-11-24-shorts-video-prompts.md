# Release 2026-11-24.2 — 🎬 YouTube Short prompts in Social posts

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `2e5bd26` (2026.11.24.1) |
| Full test suite | passed 5247 of 5247 (local, 2026-11-24); known failures: none |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no (Blade + Alpine only; every CSS class checked present in the built stylesheet) |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.24.2 |

## What's in it
Owner 2026-11-24: "from our digital marketing section we will get the prompt to generate AI short videos on YouTube
Shorts create … in both English and Telugu, controlled by Super Admin". No extra server load: the prompt is text built
from the post already on the screen; no AI call, no video made on the server, no new job.

- Admin → Marketing → Social posts: every post shared today and every 💡 ready-made idea gets a **🎬 YouTube Short**
  button. It opens 4 coloured steps: 1 voice (English / తెలుగు; the presenter shown), 2 the words with 📋 Copy,
  3 where to paste them in the YouTube app (＋ → Create a Short → ✨ AI video), 4 ⬇️ our 5-second ending to add after
  the AI clip, in English or Telugu (`public/videos/shorts-ending-en.mp4`, `-te.mp4`, about 2 MB each: logo,
  Google Play, 1callfix.com, the real app screen).
- The words: an English prompt (YouTube asks for English) for an 8-second vertical clip: a scene for the post's
  module in the post's city, a friendly Indian presenter saying the post's own caption (whole sentences, at most
  22 words, no emoji / links / hashtags). Telugu version: the presenter speaks the post's own Telugu caption; no
  Telugu caption, no Telugu button. No new translations.
- Rules in every prompt: no logos, words or brand names on screen (AI draws them wrong; our ending carries the brand);
  the presenter is a host, never a customer. Any offer or discount wording always says it applies when paying
  online, in both languages (thumb rule: online payment only for all benefits).
- Super Admin only (⚙️ Settings on the same screen, audited): On / Off for the whole team, and the presenter:
  🔁 Take turns (default), 👩 Woman, 👨 Man.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Test login and walk-through
- `test.marketing@1callfix.test` (existing Marketing role login): Social posts → 💡 Ready-made ideas → 🎬 YouTube Short
  on an idea → English / తెలుగు → 📋 Copy → paste into the YouTube app on a phone → ⬇️ download an ending.
- Super Admin: Social posts → ⚙️ Settings → 🎬 YouTube Short prompts → Off: the 🎬 buttons disappear for the
  test.marketing login; On again; pick 👨 Man and check the next prompt says "young Indian man".

## Browser checks after deploy
- `https://1callfix.com/videos/shorts-ending-en.mp4` and `-te.mp4` play (200, video/mp4).
- Social posts shows the 🎬 button on today's posts and ideas; the ⚙️ Settings card shows the Shorts switch.

## Admin settings to do after deploy (owner)
- None required (on by default, presenter takes turns). Check on a phone that the YouTube app shows ✨ AI video under
  ＋ → Create a Short for the 1CallFix channel.
