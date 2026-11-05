# Niche research: Gen Z career, money and job-hunting (Indonesia)

Researched 2026-11-05. Scope: which Indonesian long-form channels to clip for a 9:16 account (TikTok, Shorts, Reels, Threads), whether each allows clipping, and which short-clip formats get views. Goal of the account: views and followers first.

How to read this file:

- **Verified** = I opened the primary page (channel metadata, video description, platform policy) and quote or cite it.
- **Reported** = a news article or third-party page says it; I did not see the primary source.
- **Impression** = my reading of a sample. A sample found by search is biased toward what already ranks. Treat it as a direction, not data.
- **unknown** = I looked and found nothing. It does not mean "allowed".

## 0. Rules that apply before any channel

Verified:

- YouTube reused-content policy applies **even with the original creator's permission**. Clips are fine only when you add commentary, a storyline or a critical angle. Clips edited together "with little or no narrative" are not allowed, and monetization can be removed from the whole channel. Source: [YouTube monetization policies](https://support.google.com/youtube/answer/1311392?hl=en).
- YouTube scraped-content rule lists "re-posting ... other videos without adding anything of your own" as a violation. Source: [YouTube spam, deceptive practices policy](https://support.google.com/youtube/answer/2801973?hl=en).
- On 2026-10-01 YouTube announced that Shorts recommendations "further prioritize original content" and reduce "the reach of content re-uploaded from other creators without adding anything of your own". It is not a ban. Wording quoted in [PPC Land](https://ppc.land/re-uploaded-shorts-lose-reach-as-youtube-favours-original-clips/) (secondary; the official thread is [support.google.com/youtube/thread/470890423](https://support.google.com/youtube/thread/470890423), but its body did not load for me). Indonesian coverage: [Kompas Tekno, 2026-10-05](https://tekno.kompas.com/read/2026/10/05/18030077/youtube-tekan-konten-clipper-dan-repost-video-orisinal-diprioritaskan).
- TikTok treats content "largely repurposed from another source without creative edits" as unoriginal and not eligible for the For You feed. Source: [TikTok Creator Academy, originality policy](https://www.tiktok.com/creator-academy/article/tiktok-originality-policy).
- Creative Commons BY on YouTube: others "can reuse your work subject to the terms of the CC BY license", with credit (title, author, source URL, license). A video with a Content ID claim cannot carry CC BY, and YouTube "can't grant rights to content already on the platform". Source: [YouTube Help, Creative Commons](https://support.google.com/youtube/answer/2797468?hl=en).

Consequence for this product: every clip needs a visible transformation (hook, subtitle, framing, own commentary or context line), not a raw cut. That matters more after 2026-10-01 than the permission question for Shorts reach.

## 1. Channels

Numbers: subscriber counts from `channel_follower_count` in yt-dlp (YouTube rounds them), pulled 2026-11-05. Length and views come from the latest ~25 uploads on the channel's Videos tab (long-form only) plus the public RSS feed (`/feeds/videos.xml?channel_id=`). Upload frequency is a rough estimate from the RSS window, and the RSS window is short for high-volume channels.

| # | Channel | Subs | Typical long-form length | Cadence (rough) | Fit |
|---|---|---|---|---|---|
| 1 | [Young On Top](https://www.youtube.com/@YoungOnTopTV) (Muda Sukses Podcast) | 34K | median 30 min, up to 46 | ~0.7 per week (11 in 106 days) | Career-first: probation, resign, job hopping, SMK graduate to manager. Source views are low (median ~2.5K), so the clips carry the reach |
| 2 | [Fellexandro Ruby](https://www.youtube.com/@FellexandroRuby) (Underdog Podcast, formerly Thirty Days of Lunch) | 626K | median 52 min, up to 112 | ~1.6 long-form per week (3 in 13 days) | Guest interviews on career, AI at work, money and retirement, fresh graduates |
| 3 | [Samuel & Claudya](https://www.youtube.com/@samuelandclaudya) | 270K | median 22 min | weekly, Fridays 17:00 WIB (stated in channel description) | Office-worker view of personal finance and FIRE: probation, resign, household money |
| 4 | [Muda Cuma Sekali](https://www.youtube.com/@MudaCumaSekali) | 450K | median ~48 min (8 to 98) | ~2 per month | Business, crypto, creator economy. Weaker career fit |
| 5 | [Ternak Uang](https://www.youtube.com/@ternakuang) | 185K | median 38 min (22 to 103) | irregular, 1 to 4 per month | Personal finance talks ("Panduan Investasi Gaji 10 Juta", "Cara Terlepas dari Sandwich Generation") |
| 6 | [Timothy Ronald](https://www.youtube.com/@timothyronald) | 3.22M | median 17 min (6 to 35) | very high, 15 uploads in 4 days incl. Shorts | Money mindset, investing and crypto for 15 to 25 year olds |
| 7 | [Raymond Chin](https://www.youtube.com/@RaymondChins) | 3.52M | median 12 min (9 to 60) | very high, 15 uploads in 7 days incl. Shorts | Business, economy, AI, jobs ("Makin Susah Cari Kerja", "Resign Karena Lingkungan Kerja Toxic?") |
| 8 | [Satu Persen](https://www.youtube.com/@satupersenindonesianlifeschool) | 3.67M | median 22 min (6 to 31) | ~1.6 per week | Life skills with a career and personal-finance slice. Most episodes are psychology, so filter |
| 9 | [Ngomongin Uang](https://www.youtube.com/@ngomonginuang) | 1.25M | median 16 min (4.5 to 94) | ~4 uploads per week | Economy and finance explainers, mostly macro and politics. Not clip-friendly (see section 2) |
| 10 | [Close The Door / Deddy Corbuzier](https://www.youtube.com/@corbuzier) | 25.4M | median 75 min | ~3 long-form per week | General celebrity podcast. Occasional money and career guests. Weak niche fit |

Also seen and set aside: [Najwa Shihab](https://www.youtube.com/@NajwaShihab) (10.5M, politics; one career episode, [Kuliah, Kerja, dan Kenyataan](https://www.youtube.com/results?search_query=Kuliah%2C+Kerja%2C+dan+Kenyataan+Mata+Najwa&sp=EgIwAQ%253D%253D), 1h57m, ~564K views, shows under the Creative Commons filter), [Keke Genio](https://www.youtube.com/@KekeGenio) (331K, about 1 upload per 3 months lately), [Menjadi Manusia](https://www.youtube.com/@menjadimanusia) (932K, 10-minute median, brand collabs), [Glints Indonesia](https://www.youtube.com/@GlintsIndonesia) (69K, 2 to 8 minute videos, too short to clip), [Zapfinance TV](https://www.youtube.com/@zapfinancetv) (168K, ~4 minute videos), [The Daily Ketchup Podcast](https://www.youtube.com/@TheDailyKetchupPodcast) (Creative Commons, but a Singapore news-commentary channel, off-audience).

### What is clippable (impression, from titles and durations, not from watching every episode)

- Young On Top: concrete career dilemmas with a one-line answer ("Pindah-Pindah Kerja Itu Red Flag atau Justru Bikin Makin Jago?", "Kerja Capek, Karier Gak Maju. Harus Gimana?"). Each 30-minute episode should give 3 to 6 clips.
- Fellexandro Ruby: guest explains a number or a rule (how much is needed to retire, what skills survive AI). Long episodes give many candidate moments.
- Samuel & Claudya: personal confessions with a stake ("Saya Pernah Gagalkan Probation Karyawan", "Apakah Menyesal Resign Setelah Financial Freedom?"), and numbered tips ("Empat Tips ...").
- Timothy Ronald and Raymond Chin: blunt one-liners, provocative questions, comparisons ("Mental Miskin vs Mental Kaya"). Highest proven demand, also the most crowded (section 3).

## 2. Clipping permission per channel

Status key: **allowed** (written source), **tolerated** (public statement, no written terms), **unclear** (no policy found, a contact route exists), **not allowed**, **unknown**.

| Channel | Status | Evidence |
|---|---|---|
| Young On Top | **allowed under CC BY, with credit** | Verified. The six newest uploads (2026-08-24 to 2026-10-27) carry the Creative Commons license on their watch pages, and many of its other videos (Muda Sukses Podcast episodes back to ~1 year) show under YouTube's own Creative Commons search filter. Examples: [WQJmQGoxi-o](https://www.youtube.com/watch?v=WQJmQGoxi-o), [8_PpqAe2k7k](https://www.youtube.com/watch?v=8_PpqAe2k7k), [W9uTBO4xLGE](https://www.youtube.com/watch?v=W9uTBO4xLGE), [s8zXZwGhmiI](https://www.youtube.com/watch?v=s8zXZwGhmiI), [lpl7KhIt_WI](https://www.youtube.com/watch?v=lpl7KhIt_WI), [EBIgjzvAO3c](https://www.youtube.com/watch?v=EBIgjzvAO3c). License is per video, so check each one before clipping. CC covers the video, not music or third-party clips inside it. I did not confirm that a "CC BY" label means the commercial-use variant; check the license line in the description. No clip policy text on the channel itself |
| Timothy Ronald | **tolerated** | Reported, not verified at source. [Poskota, 2025-07-23](https://www.poskota.co.id/2025/07/23/jadi-clipper-konten-timothy-ronald-dapat-bonus-rp1-juta-jika-masuk-fyp-apakah-benar) quotes him from a YouTube upload: "Yang bantu klip dan videonya bisa tembus FYP, saya langsung kasih Rp1 juta". The article does not name the episode and gives no terms. The likely source, "Timothy Ronald Show - Clipper" ([NfJb0XXAKYI](https://www.youtube.com/watch?v=NfJb0XXAKYI)), is unavailable now. A fan TikTok restates it ([@finfolkmoney](https://www.tiktok.com/@finfolkmoney/video/7512336713570651398)). No written policy found |
| Raymond Chin | **unknown** | No clip policy in channel description or the videos I checked. A [Threads post](https://www.threads.com/@devahmad_/post/DQOcnrSkUCr/list-creator-you-tuber-yang-bisa-lo-buat-jadi-content-clipper-ishowspeed-mr?hl=en) from a third party lists him as clippable, which is not permission. He talks about "clipper affiliate" as a business model ([Indonesians.id](https://www.indonesians.id/berita-utama/1802173037/raymond-chin-nilai-clipper-affiliate-jadi-peluang-bisnis-digital-bermodal-hp), reported), but that is not a license for his own content |
| Ternak Uang | **unknown** | Descriptions give only a business contact (business@ternakuang.id, seen in [UmSgGDLfuxY](https://www.youtube.com/watch?v=UmSgGDLfuxY) description feed). Ternak Klip (ternakklip.com) is a clipper marketplace; its homepage names no Ternak Uang, Timothy Ronald or Raymond Chin campaign, and says nothing on source-video rights. I could not confirm any link between the two |
| Fellexandro Ruby / Underdog Podcast | **unknown** | Channel description only says "For business & collaboration, contact my PA". Search results list hello@thirtydaysoflunch.com as the podcast contact (reported; not seen by me in current descriptions). Rights holder reported as PT Berkarya Berbagi Bersama ([ListenNotes](https://www.listennotes.com/podcasts/thirty-days-of-lunch-podcast-thirty-days-of-EfaSCnDig_U/)) |
| Samuel & Claudya | **unknown** | No clip or repost text in description or the six latest video descriptions. They invite audience stories at tanyakosam@gmail.com (a submissions address, not a permissions route) |
| Muda Cuma Sekali | **unknown** | No policy in channel or latest descriptions. The channel runs episodes about clipping ("Clipper Marketing Strategy ft. Leo Giovani", which suggests a pro-clipper stance), but that is an impression, not terms |
| Satu Persen | **unclear, leans no** | Verified: official [media page](https://satupersen.net/page/media-relations) lists partnership@satupersen.net and a Content Collaboration form (satu.bio/formcollab) and says nothing about clipping. Its [terms](https://satupersen.net/page/terms-and-conditions) say Satu Persen holds the IP and bar users from "menyalin, transmisi, reproduksi atau memodifikasi" content outside the service. Those terms are written for their service users, so I read them as a caution, not a ruling on YouTube clips |
| Ngomongin Uang | **not allowed** | Verified, one description. [Nepal Setelah Rezim Tumbang](https://www.youtube.com/watch?v=hEugpkVoAYg) (2026-11-01) says: "DILARANG melakukan re-upload konten konten video Youtube Ngomongin Uang dan menggunakannya kembali sebagai konten pribadi tanpa seijin tim Ngomongin Uang." Only 1 of the 15 latest descriptions carries it, so treat it as the channel's stance. Contact: partnership@ngomonginuang.com (channel description) |
| Close The Door | **unknown** | No policy found. [KlipAja](https://klipaja.id/blog/cara-klip-tanpa-kena-hak-cipta-panduan-lengkap-2026) (a clipper-tool vendor, so not neutral) says the channel disables transcripts. Deddy Corbuzier has publicly objected to reposts without credit ([detik](https://hot.detik.com/celeb/d-4914057/konten-dicuri-deddy-corbuzier-kapan-indonesia-maju), old article) |
| Najwa Shihab | **allowed under CC BY on selected videos** | Verified on 5 of the 6 newest uploads (the Dec 2025 one is not CC). Only one career episode, so low priority |

Clipper marketplaces, which are the other route to written permission (campaign briefs list approved source material):

- [AyoKlip](https://ayoklip.com/): pays per verified view, from Rp 1 per view, 5% platform fee. Its homepage names no creators or campaigns; the campaign list sits behind /campaigns.
- [Ternak Klip](https://ternakklip.com): homepage names no source creators and says nothing on rights.
- [Clippo](https://app.clippo.id/campaigns): the one campaign I could open ([Leo Giovanni](https://app.clippo.id/campaigns/cmqf032t2008sbx26rwthlgdv)) is about clipping itself, not this niche.
- None of these showed a career or money campaign I could open. I did not log in.

Platform terms do not replace the creator's permission. Under UU Hak Cipta, re-broadcasting a video without the rights holder's consent can be prosecuted, per [Hukumonline](https://www.hukumonline.com/klinik/a/jerat-pidana-ire-uploader-i-video-di-iyoutube-i-lt597f0045bbbe1/) (secondary legal commentary, not legal advice).

## 3. Formats that get views in this niche

Sample: public YouTube Shorts found through YouTube search (queries such as "tips cari kerja", "gaji fresh graduate", "nego gaji", "podcast gaji karyawan") and public TikTok videos found through web search. View counts were read from the platform pages on 2026-11-05. The sample is biased toward videos that already rank, and TikTok search itself was not reachable, so this is a directional picture only.

### Verified data points, TikTok (plays from the video page)

| Plays | Length | Account | Pattern |
|---|---|---|---|
| 43.6M | 44 s | [@vmuliana](https://www.tiktok.com/@vmuliana/video/7638595320879975687) | Salary-negotiation tip, "Jangan langsung ditolak", sponsored (Gemini). Caption carries the prompt |
| 5.6M | 50 s | [@vmuliana](https://www.tiktok.com/@vmuliana/video/7271229213259107589) | Pain-point question as hook: "Kerja mau dapet pengalaman, kok malah ditanya pengalamannya apa?" |
| 2.6M | 69 s | [@raymondchins](https://www.tiktok.com/@raymondchins/video/7231518528283725082) | Question to the viewer: "Kerjaan kalian bisa digantikan sama AI apa ngga?" |
| 1.4M | 56 s | [@josessca](https://www.tiktok.com/@josessca/video/7377349799412108550) | Skit, "Karyawan VS Bos", hashtag #plottwist |
| 1.2M | 777 s | [@raymondchins](https://www.tiktok.com/@raymondchins/video/7627352702829268232) | 13-minute upload, topic hook with a big number ("Rp 29 Trilliun") |
| 1.1M | 128 s | [@obrolanpodcast](https://www.tiktok.com/@obrolanpodcast/video/7511697054213704981) | Third-party podcast clip, conflict angle, source credited in caption ("sc: Yt CURHAT BANG Denny Sumargo") |
| 742K | 99 s | [@irwanprasetiyo](https://www.tiktok.com/@irwanprasetiyo/video/7531566924698864904) | "Kalo kalian merasa susah cari kerja di Indonesia, mungkin jalan kalian ada di luar negeri" |
| 486K | 178 s | [@yoel.tristan](https://www.tiktok.com/@yoel.tristan/video/7541368542688496952) | Question as caption: "bener gakk lulusan s1 itu susah cari kerjaa?" |
| 474K | 20 s | [@itsmasnu](https://www.tiktok.com/@itsmasnu/video/7469675076296740101) | Numbered series ("Bagian 8"), #FreshGradTalks |
| 76K | 42 s | [@coachican](https://www.tiktok.com/@coachican/video/7520209987935669560) | Gen Z-specific habit (lying about the resign reason) with a fix |
| 25K | 41 s | [@junarasunyi](https://www.tiktok.com/@junarasunyi/video/7546522526378102072) | Caption is a link-in-bio sales pitch; low reach |
| 2.5K | 9 s | [@linovhr1](https://www.tiktok.com/@linovhr1/video/7415626040334191878) | Text-heavy do/don't list in the caption; low reach |

### Verified data points, YouTube Shorts (views from the search result)

| Views | Channel | Title (shortened) | Note |
|---|---|---|---|
| 8.0M | chaszaleo | [CARA MELAMAR KERJA AUTO BERHASIL](https://www.youtube.com/shorts/cpGcdX9GioQ) | ALL CAPS, promise |
| 5.3M | Vina Muliana | [Trik Nego Gaji Kalo Ditawarin Jumlah Ga Pas](https://www.youtube.com/shorts/8MJUbEmndQU) | Concrete scenario |
| 3.8M | Timothy Ronald | [Cara investasi pemula modal kecil](https://www.youtube.com/shorts/zdCcDPk-BMs) | Beginner + small capital |
| 3.2M | Raymond Chin | [Resign Karena Lingkungan Kerja Toxic?](https://www.youtube.com/shorts/V8rq6E9kO9A) | Question title |
| 3.2M | Mags Film | [Kata kata Dr tirta relate tentang uang MUNAFIK](https://www.youtube.com/shorts/mOF6NqpFWHE) | Third-party podcast clip, #podcast |
| 2.4M | Felicia Putri Tjiasaka | [Investasi untuk Anak Sekolahan!](https://www.youtube.com/shorts/jp3UVNHz7io) | Audience-label title |
| 1.8M | Avar Ibrahim | [Raymond Salting Karena Veren](https://www.youtube.com/shorts/EI8ViMFnZyY) | Third-party clip, personality drama |
| 1.2M | Issa Garcia | [BKENT KERJA DI AUSTRALIA DAPAT GAJI 80 JUTA!](https://www.youtube.com/shorts/161RZXFxasA) | Third-party podcast clip, big salary number |
| 723K | Firman Bagus | [Reza Arap Ketika Di Singgung Soal Gaji Karyawan](https://www.youtube.com/shorts/XMhqWFGE69c) | Third-party podcast clip |
| 716K | Rayyy CLIPS | [Andry hakim ketemu Timothy Ronald!!](https://www.youtube.com/shorts/s8YytgG0RV4) | Clip account of the Timothy Ronald podcast scene |

Third-party clip accounts (Mags Film, Avar Ibrahim, Issa Garcia, Firman Bagus, Rayyy CLIPS) reach 0.7M to 3.2M views. Their titles and hashtags (#podcast, #clips) point to cuts from podcasts; I did not watch them, so how much each adds is unknown. If they are mostly raw cuts, after 2026-10-01 those raw cuts are the exact pattern YouTube says it will down-rank on Shorts, so older view counts may overstate what a new clip account gets (impression).

### Impressions drawn from the samples above

- **Length:** TikTok hits in the sample cluster at 40 to 70 seconds, with a 20-second series format and some 100 to 180 second outliers. YouTube Shorts titles are short and hashtag-light. I did not measure completion rate, so I cannot say which length retains best.
- **Hook styles that repeat:**
  1. A pain point phrased as a complaint or question ("Kerja mau dapet pengalaman, kok malah ditanya pengalamannya apa?").
  2. A direct number or salary ("Gaji fresh graduate ...", "Rp 29 Triliun", "gaji 80 juta").
  3. A choice or versus ("Pilih dipecat tapi gaji dikali 75x atau ...", "Mental Miskin vs Mental Kaya", "Fresh Graduate VS Berpengalaman").
  4. A warning or promise ("Jangan lakukan ini saat cari kerja", "auto berhasil").
  5. A named personality plus conflict in podcast clips.
- **Caption patterns:** one sentence that repeats or sharpens the hook, 1 to 5 hashtags, a numbered series label ("Bagian 8"), and "Nonton full-nya di youtube" as the follow path ([@raymondchins](https://www.tiktok.com/@raymondchins/video/7487167604151291144), 550K plays). Clip accounts credit the source in the caption ("sc: ..."). Long hashtag lists and link-in-bio pitches did poorly in the small sample (25K and 2.5K plays), but that is two videos, so it is a hint only.
- **Hashtags seen on videos with real reach:** `#tipskerja`, `#freshgraduate`, `#interviewtips`, `#carikerja`, `#gaji`, `#karyawan`, `#resign`, `#tipskeuangan`, `#investasipemula`, `#podcast`, `#clips`, `#fyp`, `#plottwist`, `#serunyabelajar`, `#longervideos`. I found no data tying any hashtag to reach.
- **Topic clusters with the most reach in the sample:** interview answers and salary negotiation, "susah cari kerja" (job scarcity, going abroad), resign and toxic workplace, UMR budgeting, beginner investing, and money mindset. The first two are the most career-specific and the least occupied by big podcast names.
- **Saturation (verified count, interpretation is mine):** Timothy Ronald and Raymond Chin material is already clipped by many accounts. Reported: [Threads](https://www.threads.com/@kean.shihab/post/DLSFr-ez3MH/strategi-clipper-marketing-konten-terbaik-justru-bukan-dibuat-sendirikonten-timo) describes "puluhan akun clipper" for Timothy Ronald, and I saw a dedicated "Clipper Timothy Ronald" channel and a "ClipofTimothyRonald" channel. A new account competes with them for the same moments.
- **Where original creators beat clippers:** the biggest career numbers above (43.6M, 5.6M, 5.3M) come from first-party talking-head creators (Vina Muliana), not from podcast clips. That fits the 2026-10-01 YouTube change. A clip account that adds its own framing (a one-line context card, a salary table, a reply to a common question) sits closer to that format than a raw cut.

## 4. Recommendation: three channels to start with

Ranked for "views and followers first" while keeping permission risk low.

1. **Young On Top (Muda Sukses Podcast).** The only channel here with a license I could verify in writing (CC BY on the latest uploads). Pure career topics. Clip accounts for it barely exist, and its own videos get ~2.5K views, so a good clip is the main distribution it has. Weakness: guests are not famous, so hooks must come from the topic, not the name. Credit each clip (title, channel, URL, license) per the YouTube CC page.
2. **Timothy Ronald.** Highest proven clip demand in money content for under-25s, and a public statement (reported) that he welcomes clippers. Weakness: no written terms, heavy competition, and the channel funnels to a crypto academy, so check that each clip is education and not a sales pitch. Send a message to confirm in writing before publishing at scale.
3. **Fellexandro Ruby (Underdog Podcast).** Long guest interviews on career, AI at work and money give the most clippable moments per episode, with 626K subscribers and a contact route (PA, plus a podcast email reported by search). Weakness: permission is unknown, so this one needs outreach first.

Why not the others: Samuel & Claudya is a good fit, but permission is unknown and views are mid (median ~19K); it is a sensible fourth. Raymond Chin has reach but little career focus and no clip terms. Ngomongin Uang prohibits reuploads. Satu Persen is mostly psychology and its terms lean against copying. Close The Door is general and huge, so niche fit is low.

## 5. What I could not verify

- **Written clip permission** for Timothy Ronald, Raymond Chin, Ternak Uang, Fellexandro Ruby, Samuel & Claudya, Muda Cuma Sekali and Close The Door. YouTube's channel About pages (including the business-email block) did not load for me without a browser session, so a clip policy there would not have shown up. Channel descriptions and the latest 15 video descriptions per channel were checked instead.
- **The Timothy Ronald clipper statement at source.** Only a news article and a fan TikTok repeat it; the episode is unavailable.
- **Whether the CC label on Young On Top is the commercial-use variant, and whether any episode includes third-party music or footage.** I read the license flag on six watch pages and the search filter, not the license text, and I did not watch the episodes.
- **License status of older videos** on every channel. Only the latest uploads were checked, and YouTube sets the license per video.
- **TikTok and Reels data beyond ~12 videos.** TikTok search and profile pages did not load without a login, so the TikTok sample came from web search results. Instagram Reels and Threads were not sampled at all.
- **Retention, completion rate and follower conversion** for any format. Public pages only show plays and likes.
- **Subscriber counts and cadence** are point-in-time and rounded. Cadence for Raymond Chin and Timothy Ronald is dominated by Shorts, so long-form cadence is a guess.
- **Which clips would pass "original content" review** on Shorts after the 2026-10-01 change. YouTube gave no threshold, start date or way to check channel status ([PPC Land](https://ppc.land/re-uploaded-shorts-lose-reach-as-youtube-favours-original-clips/)).
- **Legal exposure** under Indonesian copyright law. The Hukumonline link is commentary, not legal advice.
