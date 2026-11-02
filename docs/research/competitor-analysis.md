# Clipper Studio: competitor analysis and product direction

Research date: 2026-11-02. All web sources below were read on that date unless noted. Prices are in USD unless marked IDR; FX used for IDR conversions is Rp18,161 = 1 USD (BI JISDOR, 2 Nov 2026, [tirto.id](https://tirto.id/rupiah-melemah-ke-rp18149-per-dolar-as-hari-ini-2-november-hEtF)). Anything marked **UNVERIFIED** could not be confirmed on a primary source; the note says why.

## Ringkasan eksekutif (Bahasa Indonesia)

- **Pasar global sudah ramai dan harganya mirip:** Opus Clip, Klap, Vizard, Submagic, Quso, Reap, dan lainnya menjual paket sekitar $15–40/bulan dengan fitur inti yang sama: skor viral, reframe wajah, subtitle animasi, b-roll, dan auto-post. Pembeda mereka sekarang ada di agen AI, API/MCP, dubbing, dan analitik.
- **Ancaman terbesar justru dari platform sendiri:** TikTok Smart Split (gratis, global, minimal 1.000 follower, 150 menit/bulan) dan CapCut (gratis/murah, harga IDR resmi). Tapi saran klip AI di YouTube Studio belum tersedia untuk Indonesia atau Bahasa Indonesia, CapCut tidak menerima link YouTube, dan tidak ada alat bawaan yang mengirim satu klip ke TikTok, YouTube, Facebook, Instagram, dan Threads sekaligus.
- **Pesaing lokal (KlipAja, KlipKlip, YukClip, Klipers) menang di harga dan pembayaran:** paket kredit sekali bayar Rp19–199 ribu lewat QRIS/e-wallet. Fitur mereka lebih dangkal: tidak ada autopilot, tes hook A/B, atau publikasi ke lima platform.
- **Celah terbesar Clipper:** belum bisa menerima pembayaran (paket Pro $29 ≈ Rp527 ribu, terlalu mahal untuk pasar ini dan diaktifkan manual oleh admin), subtitle tidak bisa dikoreksi, input hanya link YouTube, analitik hanya dari TikTok, dan tidak ada instruksi topik untuk AI.
- **Rekomendasi:** tetap jadi AI clipper (otomasi, distribusi, analitik), jangan membangun editor timeline ala CapCut. Tambahkan "editor koreksi" ringan (teks subtitle, batas klip, hook, crop) dan tombol ekspor untuk finishing di CapCut atau Premiere. Prioritas pertama: pembayaran IDR (QRIS) dengan harga lokal, koreksi subtitle, dan instruksi topik untuk AI.

---

## 1. What Clipper has today (baseline)

From [README.md](../../README.md), [welcome page](../../resources/views/public/welcome.blade.php) and a read of the code:

- **Input:** YouTube URL only (`StoreProjectRequest` validates `youtube_url`). Transcript from YouTube subtitles via yt-dlp, else Groq Whisper. Indonesian is the default language of every prompt.
- **AI:** moment detection with viral score and reasons, sentence-aligned bounds, hook variants, captions/hashtags, b-roll cues (`GeminiService`). Viral score buckets and hook angles are re-fed into the prompt once enough TikTok stats exist (`ClipPerformanceSummary::promptInsight`, `hookInsight`), so the AI learns from the user's own results.
- **Output:** 1080×1920 9:16 only. Face-tracked reframe with speaker switching, blur-fill, gameplay split-screen, jump cuts, zoom punch-in, karaoke subtitles with styles and emoji, hook, fixed title, progress bar, Pexels/Pixabay b-roll, user music library, auto SFX, loudness normalisation, logo watermark.
- **Editing:** clip start/end can be moved on the transcript (`PATCH /clips/{id}/bounds`); hook text can be edited and re-rendered. No subtitle text correction, no manual crop override, no new clip from a transcript selection.
- **Distribution:** TikTok (inbox draft or direct), YouTube Shorts, Facebook Reels, Instagram Reels, Threads; send now, schedule, calendar; Autopilot (watch channel/keyword, review queue, daily slots); hook A/B tests on TikTok; REST API + MCP.
- **Analytics:** TikTok `video.list` stats only (`TikTokStatsService`). No YouTube, Meta or Threads insights.
- **Business:** Free 30 render-minutes/month, Pro $29 for 300 render-minutes (seed in `2026_11_01_000001_create_plans_and_render_usages.php`). No checkout: an admin moves accounts to Pro. Invite-only beta.

---

## 2. Competitor landscape

### 2.1 Global AI clippers

#### Opus Clip ([opus.pro](https://www.opus.pro/))
- **Core:** ClipAnything (any genre, plus prompt-described moments), ReframeAnything (subject tracking), virality score, Auto hook, filler/pause removal, dubbing, speech enhancement, AI B-roll ([pricing](https://www.opus.pro/pricing)).
- **Workflow:** "AI editor" for manual or AI edits (editor type UNVERIFIED), XML export to Premiere/DaVinci (Pro+), brand templates (1 on Free/Starter, 4 on Pro), team seats, Video Editing API + Scheduler API + MCP (Pro+), YouTube channel auto-import.
- **Distribution:** auto-post to YT Shorts, TikTok, Instagram (Starter); Pro adds Facebook, LinkedIn, X, Pinterest, Threads, Bluesky. Analytics "coming soon" on Pro, clip analytics and trend analysis on Business ([pricing](https://www.opus.pro/pricing)).
- **Differentiators:** Agent Opus (multi-model AI video generation, avatars, voice cloning; [blog](https://www.opus.pro/blog/multi-model-ai-aggregation-mainstream-agent-opus-leads-video)); OpusSearch "AI growth agent" ([blog](https://www.opus.pro/blog/celebrating-2-years-of-opusclip-from-the-best-ai-clipping-tool-to-the-best-ai-growth-tool-for-video-creators)).
- **Pricing:** Free $0, Starter $15/mo (150 credits), Pro $29/mo (300 credits per pack), Business custom. 1 credit = 1 minute of *source* video ([help](https://help.opus.pro/docs/article/how-are-credits-consumed)). Annual Pro ≈ $14.50/mo: UNVERIFIED, not on the pricing page, third-party only ([castmagic](https://www.castmagic.io/blog/opus-clip-pricing)).
- **Free tier:** watermark, up to 1080p, 9:16 only, media deleted after 3 days. Monthly credits UNVERIFIED (60 per third parties, 90 on one Opus tool page).
- **Target / Indonesian:** creators, podcasters, brands, teams, developers. Indonesian is on the supported-language list; UI language UNVERIFIED.

#### Klap ([klap.app](https://klap.app/))
- **Core:** virality score 0–100, Auto Reframe; AI Reframe 2 adds split-screen, screencast and gaming layouts; captions in 52 languages including Indonesian; dubbing (29 or 52 languages, its own pages disagree, [blog](https://klap.app/blog/ai-clip-maker)).
- **Workflow / distribution:** API, MCP server, Zapier/Make/n8n; auto-post to TikTok, YouTube, Instagram, LinkedIn; analytics on all plans ([pricing](https://klap.app/pricing)).
- **Pricing (yearly):** Basic $14/mo (100 clips), Pro $39/mo (300 clips), Pro+ $94/mo (1,000 clips). Monthly $29/$79/$189: UNVERIFIED, third-party only ([adapter.to](https://adapter.to/blog/how-much-does-klap-cost)).
- **Free tier:** no free plan; one free video, preview only, no download or posting.
- **Target:** speech-heavy creators (podcasts, interviews, education).

#### Vizard ([vizard.ai](https://vizard.ai/))
- **Core:** highlight detection with virality score, reframe, captions with emoji, B-roll, social captions/hashtags, translation into 100+ languages; Indonesian supported for transcription and translation ([help](https://help.vizard.ai/en/articles/8767561-what-languages-are-supported)).
- **Workflow:** both a text (transcript) editor and a timeline; brand templates; team workspace; API with rate limits per plan ([pricing](https://vizard.ai/pricing)).
- **Distribution:** auto-post to YouTube, TikTok, Instagram, X, LinkedIn, Facebook ([tool page](https://vizard.ai/tools/ai-viral-clip-maker)).
- **Differentiators:** Vizard Agent and AI Studio (generation); details UNVERIFIED (JS-rendered, [agent.vizard.ai](https://agent.vizard.ai/)).
- **Pricing:** 1 credit = 1 uploaded minute; paid plans from 600 credits/mo; Creator includes 4K and no watermark. Dollar amounts UNVERIFIED (page showed placeholders); third parties give Creator $29/mo or $14.50 yearly, Business $39 or $19.50 ([techpilot](https://techpilot.ai/tools/vizard-ai/)).
- **Free tier:** 60 credits/mo, 720p export capped at 10 min, 3-day storage, 1 social account.

#### Submagic ([submagic.co](https://www.submagic.co/))
- **Core:** caption styles in 123 languages (Indonesian included), Magic Clips (long to short), transcript-based editing with silence removal, B-roll, auto-zoom, AI eye contact, translator, SFX and music, AI avatars (AI Actor Studio), API, Zapier/Make/n8n ([integrations](https://www.submagic.co/integrations)).
- **Distribution:** TikTok, Instagram Reels/Stories, YT Shorts; LinkedIn, Facebook, X "coming soon" ([scheduler](https://www.submagic.co/free-social-media-scheduler)). No virality score found.
- **Pricing (per member, monthly/annual-per-month):** Starter $19/$12 (45 credits, 1080p), Pro $39/$23 (120 credits, 3 users), Business $69/$41 (300 credits, 4K/60fps) ([pricing](https://www.submagic.co/pricing)). Upload 3 credits, Magic Clips run 8 credits ([help](https://care.submagic.co/en/article/how-much-does-it-cost-to-use-submagic-1xm83pa/)).
- **Free tier:** trial of 3 short videos, watermark ([help](https://care.submagic.co/en/article/does-submagic-have-a-free-trial-7opyg3/)).
- **Target:** creators, agencies, marketers, e-commerce.

#### Munch → Munch Studio ([munchstudio.com](https://www.munchstudio.com/))
- **Status:** pivoted. getmunch.com 301-redirects to munchstudio.com; help center says GetMunch became Munch Studio on 2025-12-31 ([help](https://intercom.help/munch-e1ea69d858ab/en/articles/11801418-supported-languages-in-munch-studio)). It is now an AI social-media manager (strategy, posts, carousels, video repurposing, approval-then-publish, per-post analytics, multi-brand agency workspaces).
- **Pricing (annual):** Essential $38/mo ($456/yr, 500 repurposing min), Premium $60/mo ($720/yr, 1,000 min) ([pricing](https://www.munchstudio.com/pricing)). Monthly prices UNVERIFIED (competitor page only).
- **Free tier:** 7-day trial only. **Indonesian:** not supported (EN, DE, ES, IT, PT, RU only).
- **Takeaway:** an AI clipper moved *up* into social management, not *sideways* into editing.

#### 2short.ai ([2short.ai](https://www.2short.ai/))
- **Core:** YouTube link to Shorts/TikTok/Reels (source must have captions), active-speaker face tracking, animated subtitles, logo presets, vertical/square/horizontal output, 1080p no watermark; Google Drive import (Lite+). Indonesian among ~26 languages. No virality score, b-roll, auto-post or API.
- **Pricing (monthly only):** Free (30 AI min/mo; FAQ says 15, UNVERIFIED), Lite $9.90 (5 h), Pro $19.90 (15 h), Premium $49.90 (50 h) ([pricing](https://www.2short.ai/pricing)).
- **Target:** YouTubers, podcasters, agencies. The closest *input* model to Clipper (YouTube link first) at a lower price.

#### Quso.ai (formerly vidyo.ai) ([quso.ai](https://quso.ai/))
- **Core:** AI clips with virality score, reframe, word-level captions, AI B-roll, filler/silence removal in a text editor, brand kit, subtitles in 99 languages, AI Content Planner, scheduling to TikTok, Instagram, YouTube, LinkedIn, Facebook, X, Pinterest, social analytics.
- **Indonesian:** subtitles yes ([page](https://quso.ai/ai-subtitle-generator/indonesian-subtitle-generator)), but clipping transcription covers 15–19 languages without Indonesian ([tool](https://quso.ai/tools/video-to-text)).
- **Pricing (monthly):** Lite $29 (100 credits), Essential $39 (300, adds scheduling), Growth $49 (600, adds brand kit, analytics) ([pricing](https://quso.ai/pricing)). Annual prices on the page were a promotion that ended Nov 1; regular annual UNVERIFIED.
- **Free tier:** 75 credits/mo, 720p, watermark, TikTok publishing, 7-day retention.

#### Captions (company renamed Mirage) ([captions.ai](https://www.captions.ai/))
- **Status:** company renamed Mirage on 2025-09-04, app still called Captions ([TechCrunch](https://techcrunch.com/2025/09/04/captions-rebrands-as-mirage-expands-beyond-creator-tools-to-ai-video-research)); raised $75M on 2026-03-24 to expand in Asia ([TechCrunch](https://techcrunch.com/2026/03/24/mirage-raises-75m-to-continue-building-models-for-its-ai-video-editing-app-captions/)).
- **Core:** AI Edit (style-based cuts and b-roll, refine by prompt), chat editor, 100+ caption styles, eye contact, filler removal, AI avatars and twins, AI Shorts from upload or YouTube link, "Long to Short" ranking on iOS ([what's new](https://captions.ai/help/whats-new)). Dubbing ~30 languages incl. Indonesian, plus Lipdub ([dubbing](https://captions.ai/help/docs/captions/dubbing)).
- **Pricing (iOS, monthly):** Basic $9.99 (200 credits), Max $24.99 (500, includes Clips and AI Edit), Frontier $69.99 (1,400) and up ([subscriptions](https://captions.ai/help/docs/subscriptions)). Teams plans discontinued.
- **Free tier:** 60–200 lifetime credits, basic tools; watermark status UNVERIFIED (docs contradict each other).
- **Target:** mobile-first talking-head creators. Note the Asia push.

#### Descript ([descript.com](https://www.descript.com/))
- **Clips:** "Create clips" makes 1–20 clips (10 s–5 min), 9:16 or 1:1, optional topic to steer selection; or ask Underlord ([help](https://help.descript.com/repurpose/create-clips-from-your-content.md)). No virality score or face tracking mentioned.
- **Editor:** transcript editor plus timeline; exports to Premiere XML, .prproj (beta), Resolve, FCPXML, AAF, EDL ([help](https://help.descript.com/hc/en-us/articles/10255813481613-Timeline-exports)). API, CLI and MCP ([help](https://help.descript.com/api-and-mcp/api)). Publishes to YouTube (not Shorts) and LinkedIn; no TikTok, no scheduler.
- **2026 launches:** Underlord v2 (Jan), API + MCP open beta (Apr), MCP in Claude/ChatGPT marketplaces (Jun), AI thumbnails (Aug), AI music/SFX (Sep) ([changelog](https://feedback.descript.com/changelog)).
- **Pricing (per person, monthly / annual-per-month):** Free; Hobbyist $24/$16 (10 media h); Creator $35/$24 (30 h, 4K); Business $65/$50 ([pricing](https://www.descript.com/pricing)).
- **Indonesian:** translation and dubbing yes since 2025-11-13; **transcription no** (Malay yes) ([languages](https://help.descript.com/script-editing/supported-transcription-languages)). So clipping Indonesian speech is not supported.

#### Newer global tools (brief)

| Tool | Differentiator | Pricing | Free tier |
|---|---|---|---|
| Reap ([pricing](https://www.reap.video/pricing)) | Clipping + dubbing 80+ langs; API/CLI/MCP on every paid plan | Creator $24/mo or $9.99 yearly (10 h); Studio $50 or $29 yearly | 1 h, 720p, watermark |
| Spikes Studio ([pricing](https://spikes.studio/pricing)) | Streamer-focused, AI b-roll, scheduler | PRO+ $32.99/mo (300 min) | 30 min/mo, 720p, watermark |
| Choppity ([pricing](https://www.choppity.com/pricing)) | Editor-first clipper, bulk upload, teams, API | Starter $24/mo, Creator $32, Team $48 | 1 h AI analysis |
| Eklipse ([pricing](https://eklipse.gg/pricing/)) | Gaming highlights across 3,000+ games, Twitch/Kick/YouTube, live clipping | Premium from $24.99/mo | Yes, limits not stated |
| StreamLadder ([App Store](https://apps.apple.com/us/app/streamladder-ai-clip-editor/id6760137802)) | Twitch → TikTok; ClipGPT scans streams with virality score | Gold+ClipGPT $26.99/mo (iOS); web prices UNVERIFIED | Basic conversion free |
| Riverside Magic Clips ([pricing](https://riverside.com/pricing)) | Clipping bundled with podcast recording, no AI credits used | Pro $29/mo, Grow $39/mo | Included, count not stated |

### 2.2 CapCut ([capcut.com](https://www.capcut.com/))

- **Long-to-short:** web "AI clip maker" / "Long video to shorts" picks highlights, reframes 16:9 → 9:16 with subject tracking, captions in up to 20 languages; input from device, CapCut cloud, Drive, Dropbox. **No YouTube-link input** ([clip maker](https://www.capcut.com/tools/ai-clip-maker), [long to shorts](https://www.capcut.com/tools/long-video-to-shorts), [Indonesian page](https://www.capcut.com/id-id/tools/long-video-to-shorts)). Free quota (60 min/mo free, +240 with Pro) is from a mid-2025 marketing article: UNVERIFIED as current ([resource](https://www.capcut.com/resource/turn-long-video-to-short-video-with-ai)).
- **Editor:** full multi-track timeline, templates, effects, Script to Video, Auto Reframe, Auto Captions, filler-word removal (Pro status UNVERIFIED), 100+ AI avatars ([desktop AI](https://www.capcut.com/tools/desktop-ai-power), [filler words](https://www.capcut.com/tools/filler-words)).
- **Pricing (Indonesia, official):** Pro Rp188,000/mo (~$10.35) or Rp1,209,000/yr; Ultra Rp1,399,000/mo; Teams from ~Rp86,000/seat; Pro includes 500 AI credits/mo; 7-day trial ([pricing](https://www.capcut.com/pricing)). USD $19.99/mo or $179.99/yr from search snippets only (UNVERIFIED; page didn't render). Prices were "upgraded" on 2026-03-25 ([help](https://www.capcut.com/help/capcut-pro-price)). Resellers sell Pro for Rp37–74k ([literally-apps](https://www.literally-apps.id/blog/capcut-pro-murah-2026)).
- **Free tier:** any Pro element forces Pro at export; 4K, 60 fps and no-watermark locked on desktop ([help](https://www.capcut.com/help/join-capcut-pro-for-exporting)).
- **Publishing:** TikTok linking and posting from the **mobile app only** ([help](https://www.capcut.com/help/how-to-link-tiktok-with-capcut)).
- **Risks for CapCut users:** June 2025 ToS row over a perpetual content licence and likeness use in sponsored content ([DPReview](https://www.dpreview.com/news/1239418455/), [CapCut reply](https://www.capcut.com/resource/about-capcut-terms-of-service), [current ToS](https://www.capcut.com/clause/terms-of-service)). US: covered by the TikTok USDS joint venture that closed ~2026-01-22 ([TikTok newsroom](https://newsroom.tiktok.com/announcement-from-the-new-tiktok-usds-joint-venture-llc?lang=en)).
- **Commerce:** CapCut Commerce Pro now redirects to Pippit, which hosts Indonesian TikTok Shop affiliate templates ([Pippit](https://www.pippit.ai/templates/capcut-product-affiliate)); template monetisation has an Indonesian help page ([help](https://www.capcut.com/id-id/help/what-is-template-monetization)).
- **Target:** everyone; it is the default finishing tool of Indonesian clippers.

### 2.3 Platform-native tools

- **TikTok Smart Split** (2025-10-28, TikTok Studio Web, global): auto-clips, reframes, captions videos over 1 min into shorts ([newsroom](https://newsroom.tiktok.com/new-ai-powered-tools-to-make-it-easier-to-create-and-share-on-tiktok?lang=en)). Limits 1k+ followers, 150 min/mo, 60 min/30 GB uploads: partly verified (search snippet of [Creator Academy](https://www.tiktok.com/creator-academy/article/smart-split)).
- **YouTube Studio "Clips and Shorts":** AI-suggested clips, titles and captions from long videos, but **only in BR, CA, FR, DE, IN, MX, KR, ES, UK, US and in EN, FR, DE, HI, KO, PT, ES. Indonesia and Bahasa Indonesia are not on the list** ([support](https://support.google.com/youtube/answer/15824265)). "Turn audio into Short" is English and AU/CA/UK/US only ([support](https://support.google.com/youtube/answer/15260303)). Made on YouTube 2026 announced a conversational AI editor in Shorts, no new long-to-short clipping ([blog](https://blog.google/products-and-platforms/products/youtube/made-on-youtube-updates-2026/)).
- **Instagram Edits:** free, no watermark, timeline editor, templates, posting to IG/FB, insights; **no long-to-short auto-clipping** ([Meta](https://about.fb.com/news/2025/04/introducing-edits-streamlined-video-creation-app/)). Meta One subscriptions launched 2026-09-15; "Edits Plus" coming, no price ([Meta](https://about.fb.com/news/2026/09/introducing-meta-one-subscription-service-more-features-ai/)).
- **TikTok Content Posting API rules** (updated 2026-08-04, [guidelines](https://developers.tiktok.com/docs/en/content-sharing-guidelines)): unaudited apps post `SELF_ONLY` to private accounts, max 5 users/24 h; audit lists as unacceptable "an app that copies arbitrary contents from other platforms to TikTok" and "a utility tool to help upload contents to the account(s) you or your team manages"; required UX includes manual privacy choice, no app watermark on content, editable preset captions, music-usage consent.

### 2.4 Indonesia-focused tools

All sell one-time credit packs with QRIS / e-wallet payment, Indonesian subtitles and 9:16 crop.

| Tool | Packs (IDR → USD) | Free | Payment | Notable features |
|---|---|---|---|---|
| KlipAja.id ([pricing](https://klipaja.id/harga), [about](https://klipaja.id/ai-video-clipper-indonesia)) | Lite Rp19k ($1.05) 10 videos · Plus Rp49k ($2.70) 30 · Max Rp99k ($5.45) 75 · Ultra Rp199k ($10.96) 180. 1 credit = 1 video ≤2 h = 3 clips (6 after 30 credits) | 1 credit, watermark | QRIS, VA, DANA, GoPay, ShopeePay, LinkAja, Alfamart (Mayar) | Face tracking, 22 subtitle styles, split and gameplay layouts, "Instruksi AI" topic targeting. Claims 29,000+ clippers, 301,000+ clips (self-reported) |
| KlipKlip.id ([site](https://klipklip.id/)) | Starter Rp25k ($1.38) 10 tokens · Kreator Rp100k ($5.51) 50. 1 token = 20 min | 1 token | Midtrans | Virality score, word-level subs, YouTube upload with stats, Org API. Claims 780+ creators |
| YukClip ([site](https://yukclip.com/)) | Rp10k ($0.55) 5 clips … Rp100k 100 clips; desktop/Android "unlimited" Rp399k (promo Rp99k) | ? | QRIS, e-wallet, transfer | On-device AI or bring-your-own key, Telegram bot. Claims 500+ clippers |
| Klipers ([site](https://klipers.pro/), [Play](https://play.google.com/store/apps/details?id=pro.klipers.android&hl=en_US)) | Basic Rp60k, Pro Rp175k, Lifetime Rp1.55M (UNVERIFIED, snippets) | ? | ? | Android app, face tracking, podcast split layout |
| KlipDong ([blog](https://klipdong.id/blog/clipper-dibayar-berapa-dari-mana/)) | Not published | ? | ? | Web + desktop, auto and manual clipping, hooks |

None of them shows autopilot channel watching, hook A/B testing, or posting to more than one platform (KlipKlip uploads to YouTube only).

### 2.5 The Indonesian market

- **Audience size** (DataReportal Digital 2026 Indonesia, ad reach, [report](https://datareportal.com/reports/digital-2026-indonesia)): TikTok 180M adults (largest TikTok ad audience in the world), YouTube 151M, Facebook 121M, Instagram 108M.
- **Clipper economy:** local campaign platforms pay Rp400–15,000 per 1,000 views, typically Rp2–5k ($0.11–0.28) (Clippo, TryBuzzer, Konten.com, tokoclippers; [klipaja](https://klipaja.id/blog/cara-jadi-clipper-tiktok-pemula-2026), [klipaja/Clippo](https://klipaja.id/blog/cara-join-campaign-clippo-cuan-ngeklip)). Whop Content Rewards campaigns pay ~$0.15–3 per 1,000, median ~$1.30, 5–10× local rates ([Whop](https://whop.com/contentrewards/), [Shopiator](https://shopiator.com/blog/whop-content-reward-campaigns)). These figures come mostly from clipping-tool blogs; treat as estimates.
- **Typical clipper toolchain:** local AI clipper (KlipAja etc.) → CapCut for CTA overlays → manual TikTok upload → screen recording as view proof ([klipaja](https://klipaja.id/blog/cara-jadi-clipper-tiktok-pemula-2026)).
- **Willingness to pay:** CapCut Pro Rp188k/mo official; Canva Pro Rp95k/mo, Pro Lite Rp33k ([Medcom](https://www.medcom.id/teknologi/tips-trik/ob3yDl5K-harga-canva-pro-2026-dan-cara-langganannya-secara-resmi)); clippers expect micro-packs under Rp100k with non-expiring credits. QRIS has 67M users (July 2026, [Kompas](https://money.kompas.com/read/2026/09/05/203447826/volume-transaksi-qris-capai-15-miliar-hingga-juli-2026-pengguna-tembus-67-juta)).
- **Clipper's Pro at $29 ≈ Rp527k/mo** is 2.8× CapCut Pro and 5× KlipAja's largest-but-one pack. On features it compares well with Opus Pro ($29, 300 *source* minutes vs Clipper's 300 *output* minutes), but that is the wrong reference point for this market.

---

## 3. Feature gap matrix

Legend: ✓ have · ◐ partial · ✗ missing · ? not found in sources. Based on the sources above; "?" means not stated, not proven absent.

### 3.1 Clip output quality

| Feature | Clipper | Opus | Klap | Vizard | Submagic | Quso | 2short | Captions | Descript | CapCut | KlipAja |
|---|---|---|---|---|---|---|---|---|---|---|---|
| AI moment pick + virality score | ✓ | ✓ | ✓ | ✓ | ◐ no score | ✓ | ◐ no score | ✓ ranking | ◐ no score | ✓ | ? |
| Prompt/topic-guided clipping | ✗ | ✓ ClipAnything | ? | ? | ? | ? | ✗ | ◐ prompt edit | ✓ topic | ? | ✓ Instruksi AI |
| Indonesian transcription for clipping | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | ✓ | ✗ | ◐ | ✓ |
| Face-tracked reframe, speaker switch | ✓ | ✓ | ✓ | ✓ | ? | ✓ | ✓ | ✓ | ✗ | ✓ | ✓ |
| Split layouts (gameplay / two-speaker) | ◐ gameplay only | ? | ✓ | ? | ? | ? | ? | ✓ podcasts | ◐ layout packs | manual | ✓ |
| Animated word-level captions + styles | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Fix subtitle text | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ? | ✓ | ✓ | ✓ | ? |
| Filler / silence removal | ✓ | ✓ | ? | ? | ✓ | ✓ | ✗ | ✓ | ✓ | ◐ Pro? | ? |
| B-roll | ✓ stock | ✓ AI | ? | ✓ | ✓ | ✓ | ✗ | ✓ | ? | ✓ | ? |
| Music / SFX / loudness | ✓ | ◐ speech enh. | ? | ? | ✓ | ? | ✗ | ✓ AI music | ✓ | ✓ | ? |
| AI hook text overlay | ✓ | ✓ Auto hook | ? | ? | ? | ? | ✗ | ? | ? | ◐ templates | ? |
| Translation / dubbing | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ subs | ✗ | ✓ | ✓ | ◐ | ✗ |
| Aspect ratios beyond 9:16 | ✗ | ✓ paid | ? | ? | ? | ? | ✓ | ✓ | ✓ 1:1 | ✓ | ? |
| 4K export | ✗ | ✓ Business | ? | ✓ | ✓ Business | ✗ | ✗ | ? | ✓ | ✓ Pro | ? |
| AI avatars / generation | ✗ | ✓ Agent Opus | ✗ | ✓ AI Studio | ✓ | ? | ✗ | ✓ | ✗ | ✓ | ✗ |

### 3.2 Workflow speed

| Feature | Clipper | Opus | Klap | Vizard | Submagic | Quso | 2short | Captions | Descript | CapCut | KlipAja |
|---|---|---|---|---|---|---|---|---|---|---|---|
| YouTube link input | ✓ | ✓ | ? | ? | ? | ? | ✓ | ✓ | ? | ✗ | ✓ |
| File / Drive upload | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ Drive | ✓ | ✓ | ✓ | ? |
| Transcript-based correction editor | ◐ bounds only | ✓ | ? | ✓ | ✓ | ✓ | ✗ | ✓ chat | ✓ | ✓ | ? |
| Timeline editor | ✗ | ? | ✗ | ✓ | ? | ✓ desktop | ✗ | ◐ | ✓ | ✓ full | ✗ |
| XML / NLE export | ✗ | ✓ Pro | ? | ✗ | ✗ | ? | ✗ | ✗ | ✓ | n/a | ✗ |
| Saved style templates | ◐ brand kit defaults | ✓ | ✓ | ✓ | ✓ | ✓ | ◐ presets | ✓ | ✓ | ✓ | ✓ styles |
| Batch render + ZIP | ✓ | ? | ? | ? | ? | ? | ? | ? | ? | ✗ | ? |
| Team workspace | ✗ | ✓ | ? | ✓ | ✓ | ◐ | ✗ | ✗ dropped | ✓ | ✓ | ✗ |
| API / MCP | ✓ / ✓ | ✓ / ✓ | ✓ / ✓ | ✓ / ? | ✓ / ? | ✗ | ✗ | ? | ✓ / ✓ | ✗ | ✗ |
| Indonesian UI | ✓ | ? | ? | ? | ? | ? | ? | ? | ? | ✓ | ✓ |

### 3.3 Distribution and growth

| Feature | Clipper | Opus | Klap | Vizard | Submagic | Quso | 2short | Captions | Descript | CapCut | KlipAja |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Direct publish: TikTok / YT / FB / IG / Threads | ✓ all 5 | ✓ all 5 (Pro) | ◐ TT, YT, IG | ◐ no Threads | ◐ TT, IG, YT | ◐ no Threads | ✗ | ? | ✗ | ◐ TikTok, mobile | ✗ |
| Scheduler / calendar | ✓ | ✓ | ✓ | ✓ | ✓ Pro | ✓ | ✗ | ? | ✗ | ✗ | ✗ |
| Channel watch / auto-import | ✓ Autopilot + review | ✓ auto-import | ? | ? | ? | ? | ✗ | ✗ | ✗ | ✗ | ✗ |
| Hook A/B test on real posts | ✓ TikTok | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Post analytics | ◐ TikTok only | ◐ Business | ✓ | ? | ? | ✓ Growth | ✗ | ✗ | ✗ | ✗ | ✗ |
| Results fed back into AI selection | ✓ | ? | ? | ? | ? | ? | ✗ | ✗ | ✗ | ✗ | ✗ |
| Free tier | ✓ 30 min | ✓ | ✗ trial | ✓ 60 cr | ✗ trial | ✓ 75 cr | ✓ | ✓ credits | ✓ | ✓ | ✓ 1 credit |
| Local payment (QRIS/e-wallet), IDR price | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ? | ✗ | ✓ | ✓ |
| Self-serve checkout | ✗ admin flips plan | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

**Reading the matrix**
- Clipper is at or above parity on output quality for Indonesian talking-head content, and ahead on distribution: it is the only tool found with five-platform publishing, channel autopilot with a review queue, real-post hook A/B tests and a stats-to-prompt loop together.
- Its gaps are mostly *correction* and *commerce*: subtitle text fixes, prompt-guided clipping, file upload, cross-platform analytics, IDR payment.
- The CapCut-style features (timeline, effects, avatars) are where everyone else is weak too, except CapCut.

---

## 4. Strategic question: AI clipper or CapCut-like editor?

### Recommendation

**Keep deepening as an AI clipper (automation + distribution + analytics) for Indonesian creators. Do not build a timeline editor.** Add a narrow correction layer so users never *have* to leave for small fixes, and a clean hand-off for when they *want* to finish in CapCut or Premiere.

### Reasoning

1. **Cost for a small Laravel + ffmpeg team.** A real timeline editor means a heavy browser client (multi-track timeline, frame-accurate preview via canvas/WebCodecs, keyframes, undo/redo, asset library), a project document model, and a guarantee that the browser preview matches the ffmpeg render. Clipper's current preview is an Alpine.js approximation of the final render. My estimate is 4–6+ engineer-months to reach a usable MVP and a permanent maintenance load, which is the whole team's capacity for a feature that is worse than a free download. (Estimate, not sourced.)
2. **CapCut sets a price ceiling of near zero.** CapCut has the full editor, template marketplace and effects library, sells Pro at Rp188k/mo officially and Rp37–74k via resellers, and is already the default finishing step in the Indonesian clipper workflow ([sources in §2.2](#22-capcut-capcutcom), [§2.5](#25-the-indonesian-market)). Instagram Edits is free with no watermark. A Clipper editor would be judged against free tools from ByteDance and Meta.
3. **Where Clipper is differentiated is not editing.** No competitor found combines YouTube-link input, Indonesian-first AI, five-platform publishing, autopilot with review, real-post hook A/B tests and a performance loop into the AI. The market leaders are moving the same way: Munch pivoted into a social-media manager, Opus calls itself a "growth tool" and is adding analytics and trend analysis, Klap sells analytics on all plans. Nobody is winning by adding timelines.
4. **Platform-native clippers attack the plain clipping step, not the pipeline.** TikTok Smart Split is free but needs 1k followers, caps at 150 min/mo and only feeds TikTok; YouTube's AI clip suggestions exclude Indonesia and Bahasa Indonesia ([§2.3](#23-platform-native-tools)). The defensible value is "one YouTube video in, posts on five platforms out, learning from results", which no platform will build for its competitors.
5. **Monetisation follows automation, not editing.** Automation consumes render minutes and AI calls, so it maps cleanly to credit packs and subscriptions. An editor drives storage and support cost but little metered usage. The real monetisation blocker today is commerce, not features: no checkout, no IDR, no QRIS, and a $29 price against a market of Rp19–199k packs.

### Hybrid options considered

| Option | Verdict | Why |
|---|---|---|
| **Correction editor** (fix subtitle words, move bounds, edit hook/title, override crop per segment, swap or drop a b-roll, mute music) on top of the existing transcript UI | **Build** | Covers most "the AI got it 90% right" cases. Reuses the transcript modal and re-render; no new preview engine. Every serious competitor has text-based correction. |
| **Style templates / presets** (subtitle + hook + layout + music + SFX saved as named presets, later shareable) | **Build** | Extends brand kit; speeds the workflow; Opus, Submagic, Klap, Quso all sell templates by tier. |
| **Export for finishing** (clean MP4 without burned-in subs + `.srt`/`.ass` + hook text + b-roll files; FCPXML/Premiere XML) | **Build** | Turns CapCut from a competitor into the finishing step that clippers already use. Opus gates XML export to Pro; Descript exports to every NLE. Whether CapCut desktop imports FCPXML is UNVERIFIED, so the safe CapCut hand-off is the asset bundle. |
| Full timeline editor | **Don't build** | Cost and competition reasons above. |
| AI generation (avatars, text-to-video) | **Not now** | Expensive per render, crowded (Opus, Vizard, Captions, Submagic, CapCut), off-strategy for repurposing. |

### Risks to plan around

- **TikTok audit scope.** TikTok's guidelines reject apps that copy "arbitrary contents from other platforms to TikTok" and pure upload utilities ([guidelines](https://developers.tiktok.com/docs/en/content-sharing-guidelines)). Clipper's flow is YouTube → TikTok, so it must stay framed and enforced as *creators repurposing their own videos*, which the welcome page already says. A "clip other people's streams for campaigns" mode would strengthen the local market case but could put the TikTok audit at risk; keep that segment on download/inbox-draft flows.
- **YouTube ingestion.** yt-dlp breaks on bot checks (README troubleshooting already lists it) and downloading via yt-dlp sits outside YouTube's API terms. File upload reduces dependence on it.
- **Price mismatch.** Local tools anchor expectations at Rp19–199k per pack. Without IDR pricing Clipper competes only with global tools on their price, where Opus has more features per dollar.

---

## 5. Prioritised roadmap: top 10

Sizes: S ≈ days to 1 week, M ≈ 2–4 weeks, L ≈ 1–2 months for one developer. Rough estimates.

| # | Feature | Why (user value · evidence) | Size | Builds on / depends on |
|---|---|---|---|---|
| 1 | **IDR checkout with QRIS/e-wallet; credit packs + monthly plan** | No revenue path exists today (admin flips plans). Local tools all sell Rp19–199k packs via Midtrans/Mayar; CapCut prices in IDR; QRIS has 67M users. Suggested starting point to test: a Rp49–99k render-minute pack and a ~Rp99–149k/mo plan (hypothesis, not researched pricing). | M | `Plan`, `RenderQuota`, `render_usages`; needs Midtrans/Xendit/Mayar integration, webhooks, invoices, top-up credits that don't expire |
| 2 | **Subtitle and transcript text correction** | Whisper and YouTube auto-subs mangle Indonesian slang, names, brands; a wrong word in a burned-in karaoke subtitle forces a full redo in CapCut. Every competitor with captions lets users edit text (Vizard, Submagic, Quso, Descript, Captions). | S–M | Transcript modal (`_modal-trim`), `GET /clips/{id}/transcript`, subtitle ASS generation, re-render |
| 3 | **Prompt-guided clipping + manual clip from a transcript selection** | "Cari bagian yang bahas X" is how users think. Opus ClipAnything, Descript topic, KlipAja "Instruksi AI" have it. Manual selection covers what the AI missed. | S | `GeminiService::analyzeWithTranscript` (add an instruction param), project creation form, `PATCH bounds` logic for new clips |
| 4 | **Cross-platform analytics + wider performance loop** | Today only TikTok stats feed hook tests and the AI. YouTube Analytics, Instagram/Facebook insights and Threads insights would make the "learns from your results" claim true across platforms and power a per-clip dashboard. Klap, Quso Growth, Opus Business sell analytics; Munch Studio built its product around it. | M | `TikTokStatsService`, `ClipPerformanceSummary`, existing Meta/Threads/YouTube tokens (needs extra read scopes and app review) |
| 5 | **Correction editor: per-segment crop override, b-roll swap/remove, music/SFX toggles on the result screen** | Fixes the remaining "AI was almost right" cases (wrong face picked, odd b-roll) without leaving Clipper. This is the hybrid answer to "should we build an editor". | M | `FaceTracker` output, b-roll cue list, `RenderClipJob` re-render, result screen |
| 6 | **Source upload (file, Google Drive) and more URL sources (Twitch/Kick VODs via yt-dlp)** | Every global competitor accepts uploads; CapCut accepts only uploads. Reduces yt-dlp/bot-check fragility and supports podcasts recorded locally. Twitch/Kick covers the streamer-clipper segment (Eklipse, StreamLadder, Spikes). | M | `YtDlpService`, storage/upload limits already sized for 2 GB in Docker nginx, `AnalyzeVideoJob` |
| 7 | **Export for finishing: clean MP4 + SRT/ASS + asset bundle; FCPXML/Premiere XML** | Clippers finish in CapCut anyway; make Clipper the first step instead of a rival. Opus (Pro) and Descript offer NLE export. | S (bundle) / M (XML) | `DownloadController` ZIP, ASS subtitle files, SmartCut piece list for XML |
| 8 | **Named style presets / templates** | Faster repeat work, consistent channel look, natural paid-tier lever (Opus 1 vs 4 templates, Submagic 3 vs 10). | S | Brand kit defaults, `ProjectCreator` settings snapshot |
| 9 | **More layouts and ratios: two-speaker split for podcasts, 1:1 and 4:5, 16:9 passthrough** | Podcasts with two hosts are the core Indonesian clip source; Klap Reframe 2, KlipAja, Klipers and Captions all ship split-speaker layouts. 1:1/4:5 helps Facebook and Instagram feed. | M | `VideoLayoutService`, `FaceTracker` (already tracks two-shots), gameplay split filter graph |
| 10 | **Mobile-first review: Telegram (or WhatsApp) bot + PWA for the Autopilot queue** | Approving clips is the daily habit that keeps Autopilot running; Indonesian users live on mobile chat. YukClip already ships a Telegram bot. | S–M | Autopilot approve/reject endpoints, REST API tokens, `publicMediaUrl` signed links for previews |

**Next tier (not top 10):** Indonesian ↔ English subtitle translation for Whop-style global campaigns (all global tools have it; Groq/Gemini make it cheap); team workspaces for agencies; speech enhancement; trend-based source discovery for Autopilot (Opus "trend analysis", OpusSearch).

### Suggested order

1 → 2 → 3 first: money, then the two most common reasons a user leaves for CapCut or another clipper. Then 4 and 5 to deepen the distribution and correction story, 6 and 7 to widen input and output, 8–10 as retention polish.
