# Clipper Studio

Aplikasi Laravel yang mengubah video YouTube panjang jadi klip vertikal 9:16 dan mempublikasikannya ke TikTok, YouTube Shorts, Facebook Reels, Instagram Reels, dan Threads. AI memilih momen, FFmpeg memotong dan merender, lalu klip yang disetujui pengguna dikirim ke akun miliknya sendiri.

README ini untuk developer. Panduan deploy produksi ada di [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md), panduan REST API dan MCP di [`docs/agent-api.md`](docs/agent-api.md).

## Daftar isi

1. [Fitur](#fitur)
2. [Stack](#stack)
3. [Setup lokal](#setup-lokal)
4. [Queue worker dan scheduler](#queue-worker-dan-scheduler)
5. [Variabel `.env`](#variabel-env)
6. [Redirect URI OAuth](#redirect-uri-oauth)
7. [Menjalankan test](#menjalankan-test)
8. [Deploy](#deploy)
9. [Halaman publik dan review platform](#halaman-publik-dan-review-platform)
10. [Struktur kode](#struktur-kode)
11. [Masalah yang sering muncul](#masalah-yang-sering-muncul)

---

## Fitur

**Membuat klip**

- Input: link video YouTube. Transkrip diambil dari subtitle YouTube lewat yt-dlp; kalau tidak ada, audio ditranskrip dengan Groq Whisper (`whisper-large-v3-turbo`).
- Deteksi klip dengan AI (gateway OpenAI-compatible lebih dulu, Gemini sebagai cadangan): momen terbaik, skor viral beserta alasannya, awal dan akhir di batas kalimat. Awal dan akhir klip bisa digeser lewat transkrip.
- Layout: reframe yang mengikuti wajah (OpenCV YuNet, ganti pembicara pada two-shot), video utuh dengan latar blur, dan split-screen gameplay (pembicara di atas, footage gameplay milik pengguna berputar di bawah).
- Footage gameplay diunggah per pengguna di Pengaturan (maksimal 10 file, 200 MB per file, divalidasi dengan ffprobe). Tanpa footage, layout ini tidak bisa dipilih.
- Jump cut (jeda dan kata isian dipotong) dan zoom punch-in.
- Subtitle karaoke per kata, gaya bawaan atau font, warna, dan posisi sendiri, emoji di kata kunci.
- Hook pembuka, judul yang tetap tampil, progress bar.
- B-roll otomatis dari Pexels atau Pixabay.
- Musik latar dari library yang diunggah pengguna (per mood, kredit CC BY masuk ke caption), SFX otomatis (whoosh, pop, ding; opsional per project), loudness suara disamakan saat tanpa musik.
- Brand kit per pengguna: logo watermark dan bawaan untuk project baru.
- Render satu klip atau semua klip, unduh MP4 atau satu ZIP berisi semua klip dan `captions.txt`.

**Publikasi dan otomasi**

- Satu panel Publikasikan di Studio untuk TikTok (draf inbox atau posting langsung), YouTube Shorts, Facebook Reels (Page), Instagram Reels (akun profesional yang tertaut ke Page), dan Threads. Kirim sekarang atau jadwalkan.
- Satu halaman Pengaturan > Akun terhubung untuk semua akun publikasi, dengan akun utama per platform.
- Kalender: jadwal posting ke kelima platform, dikirim oleh scheduler.
- Autopilot: pantau channel atau kata kunci YouTube, klip masuk antrian review. Approve mengirim klip ke akun utama TikTok (bila aktif) dan menaruhnya di slot jam harian YouTube, Facebook, Instagram, dan Threads (bila autopost platform itu aktif). Autopost berhenti kalau akun utama diputus.
- Tes hook A/B: hook asli melawan 2 atau 3 hook baru, diposting ke satu akun TikTok di jam yang sama pada hari berurutan, pemenang dipilih dari views di umur posting yang sama.
- REST API dan server MCP untuk agent AI, dengan token berizin `read`, `render`, dan `publish` (publish API/MCP baru mencakup TikTok dan YouTube).

**Akses dan batas**

- Beta tertutup: akun pertama jadi admin, berikutnya lewat kode undangan (`clipper:invite`) atau dibuat admin. `REGISTRATION_ENABLED=true` membuka pendaftaran bebas, dengan persetujuan admin.
- Paket freemium dengan kuota menit render per bulan (seed: Free 30 menit, Pro 300 menit), admin yang memindahkan akun ke Pro. Biaya CPU, Gemini, dan Groq per render dicatat di `render_usages`.
- Halaman `/status` menampilkan beban server dan antrian, `JobWatchdog` menggagalkan pekerjaan yang macet.

## Stack

- Laravel 13 (`laravel/framework ^13.8`), PHP 8.3+ (CI dan image Docker memakai PHP 8.4)
- PostgreSQL untuk data, session, queue, dan cache (SQLite in-memory untuk test)
- Blade, Alpine.js, CSS biasa lewat Vite
- FFmpeg dan ffprobe untuk render, yt-dlp untuk unduhan, Python dengan `opencv-python-headless` untuk face tracking
- Gemini API atau gateway OpenAI-compatible untuk AI, Groq untuk Whisper

## Setup lokal

Butuh:

| Program | Versi | Untuk apa |
|---|---|---|
| PHP | 8.3+ dengan `pdo_pgsql`, `gd` (FreeType), `intl`, `zip`, `pcntl` | Aplikasi |
| Composer | 2.x | Library PHP |
| Node.js | 20.19+ atau 22.12+ | Build CSS dan JS |
| PostgreSQL | 14+ | Database |
| FFmpeg | 6+ (`ffmpeg` dan `ffprobe`) | Render, SFX, validasi footage |
| yt-dlp | terbaru | Unduh video. Perbarui rutin. Image Docker juga memasang deno sebagai JS runtime-nya |
| Python 3 + OpenCV | opsional | Face tracking. Tanpa ini reframe memakai crop tengah |

Tidak perlu Whisper lokal: tanpa subtitle YouTube, transkrip dibuat oleh Groq Whisper lewat `GROQ_API_KEY`. Image Docker memang membawa `whisper-cli` (whisper.cpp), tetapi kode aplikasi tidak memanggilnya.

```bash
brew install php composer node postgresql@16 ffmpeg yt-dlp   # macOS
createdb clipper

git clone git@github.com:Fahmiady11/clipper-sosmed.git clipper && cd clipper
cp .env.example .env            # isi minimal APP_URL, DB_*, dan GEMINI_API_KEY atau LLM_GATEWAY_*
composer setup                  # install, key:generate, migrate, npm build
```

Face tracking (opsional):

```bash
python3 -m venv ~/.local/share/clipper-facetrack
~/.local/share/clipper-facetrack/bin/pip install opencv-python-headless==4.10.0.84 numpy==1.26.4
# di .env: FACE_TRACK_PYTHON=/Users/<kamu>/.local/share/clipper-facetrack/bin/python
```

Jalankan semuanya:

```bash
composer dev
```

`composer dev` menjalankan `php artisan serve`, `queue:work --timeout=3600 --memory=512`, `schedule:work`, `pail`, dan `npm run dev` sekaligus. Buka `http://localhost:8000` dan daftarkan akun pertama (otomatis jadi admin). Tanpa queue worker, project berhenti di "Menunggu antrian"; tanpa scheduler, Autopilot, kalender, dan polling status tidak jalan.

Perintah lain: `composer lint` (Pint), `composer test`.

Instagram dan Threads mengambil video dari server lewat link bertanda tangan, jadi untuk mencobanya `APP_URL` harus alamat HTTPS publik (ngrok atau domain). TikTok juga mewajibkan redirect HTTPS.

## Queue worker dan scheduler

Worker: `php artisan queue:work --timeout=3600 --memory=512` (di produksi 2 proses, lihat `deploy/supervisor.conf` atau service `queue` di `docker-compose.prod.yml`). Scheduler: `php artisan schedule:work` (atau cron `schedule:run` tiap menit).

Jadwal di `routes/console.php`:

| Nama | Jadwal | Fungsi |
|---|---|---|
| `autopilot-discover` | tiap 10 menit | Cari video baru untuk sumber Autopilot yang jatuh tempo (hanya akun yang disetujui) |
| `clip-stats-tiktok` | tiap jam | Ambil snapshot views klip TikTok yang sudah diposting (butuh scope `video.list`) |
| `judge-hook-tests` (`clipper:judge-hook-tests`) | tiap jam menit ke-20 | Pilih pemenang tes hook A/B yang semua variannya sudah cukup umur |
| `publish-scheduled` (`clipper:publish-scheduled`) | tiap menit | Serahkan posting terjadwal yang waktunya tiba ke job upload |
| `youtube-status` (`clipper:youtube-status`) | tiap 5 menit | Ikuti status video YouTube yang masih diproses |
| `meta-status` (`clipper:meta-status`) | tiap menit | Ikuti Reel Facebook dan publikasikan container Instagram dan Threads yang sudah siap |
| `threads-refresh` (`clipper:threads-refresh`) | harian 04:23 | Perpanjang token Threads yang kedaluwarsa dalam 30 hari |
| `clipper-cleanup` (`clipper:cleanup`) | harian 03:17 | Hapus video sumber lama dan cache (klip hasil render tidak dihapus) |
| `clipper-monitor` (`clipper:monitor`) | tiap menit | Catat beban CPU dan antrian untuk `/status` |
| `clipper-watchdog` (`clipper:watchdog`) | tiap 5 menit | Gagalkan analisis dan render yang macet di `processing` melewati timeout |

Perintah manual:

```bash
php artisan clipper:invite --uses=1 --days=7 --note="untuk Budi"   # kode undangan beta
php artisan clipper:token email@kamu.com claude --ability=read --ability=render   # token API/MCP
```

## Variabel `.env`

Hanya nama dan fungsi; nilai rahasia jangan di-commit. Sumber: `.env.example` dan `config/services.php`.

**Aplikasi, database, akses**

| Variabel | Fungsi |
|---|---|
| `APP_URL` | Alamat publik aplikasi. Dipakai untuk redirect OAuth dan link media bertanda tangan |
| `APP_KEY`, `APP_ENV`, `APP_DEBUG` | Standar Laravel. Produksi: `APP_DEBUG=false` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL |
| `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE` | Semuanya `database` |
| `SESSION_SECURE_COOKIE` | `true` bila lewat HTTPS |
| `REGISTRATION_ENABLED` | `false` (bawaan): hanya akun pertama dan kode undangan. `true`: pendaftaran bebas dengan persetujuan admin |
| `CONTACT_EMAIL` | Ditampilkan di `/terms` dan `/privacy` |

**AI dan transkrip**

| Variabel | Fungsi |
|---|---|
| `LLM_GATEWAY_URL`, `LLM_GATEWAY_KEY`, `LLM_GATEWAY_MODEL` | Gateway OpenAI-compatible, dicoba lebih dulu. Model boleh beberapa, dipisah koma |
| `GEMINI_API_KEY`, `GEMINI_MODELS` | Gemini, cadangan bila gateway gagal. `GEMINI_MODELS` opsional (urutan model) |
| `GROQ_API_KEY` | Whisper untuk video tanpa subtitle YouTube |

**Unduh dan render**

| Variabel | Fungsi |
|---|---|
| `YTDLP_PATH`, `FFMPEG_PATH` | Path biner bila tidak ada di `PATH` |
| `YTDLP_COOKIES_BROWSER`, `YTDLP_COOKIES` | Cookies YouTube (nama browser, atau path `cookies.txt`; browser dipakai bila keduanya diisi) |
| `FACE_TRACK_PYTHON` | Python dengan OpenCV. Kosong: crop tengah |
| `PEXELS_API_KEY`, `PIXABAY_API_KEY` | B-roll. Keduanya kosong: B-roll mati |
| `MUSIC_LIBRARY_PATH` | Library musik, `<path>/<mood>/*.mp3` (bawaan `storage/app/music`) |
| `CLIPPER_TEMP_RETENTION_HOURS` | Umur video sumber sebelum dihapus harian (bawaan 72) |
| `PUBLIC_MEDIA_URL_MINUTES` | Masa berlaku link bertanda tangan untuk Instagram dan Threads (bawaan 120) |

**TikTok** (developers.tiktok.com: Login Kit dan Content Posting API)

| Variabel | Fungsi |
|---|---|
| `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` | Kredensial app |
| `TIKTOK_REDIRECT_URI` | Harus persis sama dengan yang didaftarkan |
| `TIKTOK_SCOPES` | Bawaan di config `user.info.basic,video.upload`. Tambah `video.publish` untuk posting langsung dan `video.list` untuk statistik (tes hook A/B, learning loop Autopilot) |

**YouTube** (Google Cloud: OAuth client Web application, YouTube Data API v3 aktif)

| Variabel | Fungsi |
|---|---|
| `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET` | Kredensial OAuth client. Scope: `youtube.upload` dan `youtube.readonly` |
| `YOUTUBE_REDIRECT_URI` | Harus persis sama dengan Authorized redirect URI |

**Facebook dan Instagram** (satu app Meta, tipe Business)

| Variabel | Fungsi |
|---|---|
| `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET` | App settings > Basic |
| `FACEBOOK_REDIRECT_URI` | Valid OAuth Redirect URI di Facebook Login for Business |
| `FACEBOOK_CONFIG_ID` | ID konfigurasi Facebook Login for Business (token user). Kosong: izin diminta lewat `FACEBOOK_SCOPES` |
| `FACEBOOK_SCOPES` | Bawaan `pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish` |
| `FACEBOOK_GRAPH_VERSION` | Bawaan `v26.0` |

**Threads** (use case Threads API di app Meta yang sama, App ID dan secret sendiri)

| Variabel | Fungsi |
|---|---|
| `THREADS_APP_ID`, `THREADS_APP_SECRET` | Threads app ID dan secret |
| `THREADS_REDIRECT_URI` | Redirect Callback URL di pengaturan Threads API |
| `THREADS_SCOPES` | Bawaan `threads_basic,threads_content_publish` |
| `THREADS_AUTHORIZE_URL`, `THREADS_GRAPH_URL`, `THREADS_GRAPH_VERSION` | Host dan versi API, hampir tidak perlu diubah |

**Beban server, tes hook, biaya** (semuanya opsional, ada nilai bawaan)

| Variabel | Fungsi |
|---|---|
| `CLIPPER_CPU_CORES`, `CLIPPER_LOAD_BUSY`, `CLIPPER_LOAD_OVERLOADED`, `CLIPPER_QUEUE_BUSY`, `CLIPPER_QUEUE_OVERLOADED` | Ambang status sibuk dan overload di `/status` |
| `CLIPPER_HOOK_TEST_WINDOW_HOURS`, `CLIPPER_HOOK_TEST_GRACE_HOURS`, `CLIPPER_HOOK_TEST_MIN_VIEWS`, `CLIPPER_HOOK_TEST_MARGIN` | Umur penilaian, masa tenggang, views minimum, dan selisih yang dibutuhkan pemenang |
| `BILLING_CPU_USD_PER_HOUR`, `BILLING_GEMINI_USD_PER_CALL`, `BILLING_GROQ_USD_PER_AUDIO_HOUR` | Tarif untuk log biaya per render |

## Redirect URI OAuth

Daftarkan persis seperti ini di dashboard tiap platform, dengan `<APP_URL>` yang sama dengan `.env`, dan samakan isi variabel `*_REDIRECT_URI`-nya:

| Platform | Redirect URI | Didaftarkan di | Variabel |
|---|---|---|---|
| TikTok | `<APP_URL>/tiktok/callback` | developers.tiktok.com, app Production, Login Kit | `TIKTOK_REDIRECT_URI` |
| YouTube | `<APP_URL>/youtube/callback` | Google Cloud, OAuth client, Authorized redirect URIs | `YOUTUBE_REDIRECT_URI` |
| Facebook dan Instagram | `<APP_URL>/facebook/callback` | Meta, Facebook Login for Business, Valid OAuth Redirect URIs | `FACEBOOK_REDIRECT_URI` |
| Threads | `<APP_URL>/threads/callback` | Meta, use case Threads API, Redirect Callback URL | `THREADS_REDIRECT_URI` |

Route-nya ada di `routes/web.php` (di balik `auth` dan `approved`); alur mulai dari `/<platform>/connect`. Instagram ikut login Facebook, jadi tidak punya redirect URI sendiri. Setelah login, Page dan akun Instagram dipilih di Pengaturan > Akun terhubung.

## Menjalankan test

```bash
composer test            # SQLite in-memory, tidak menyentuh database asli
php artisan test --filter=PublicPagesTest
```

Beberapa kolom dan constraint berperilaku beda di Postgres, dan CI memakai Postgres 17. Jalankan di Postgres sebelum push perubahan yang menyentuh migrasi atau query:

```bash
createdb clipper_test
DB_CONNECTION=pgsql DB_DATABASE=clipper_test php artisan test
# tambah DB_HOST, DB_USERNAME, DB_PASSWORD bila berbeda dari .env
```

Beberapa test memanggil `ffmpeg` sungguhan (mis. `MusicLibraryTest`), jadi FFmpeg harus terpasang. Workflow `.github/workflows/deploy.yml` menjalankan job `test` (PHP 8.4, Postgres 17, `npm run build`, `php artisan test`) pada setiap push dan pull request; deploy hanya jalan bila test lolos dan ref-nya `main`.

## Deploy

Produksi berjalan di Docker (`app`, `queue`, `scheduler`, `postgres`) lewat GitHub Actions; langkah, rollback, backup, dan `.env` produksi ada di [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md). Folder `deploy/` adalah jalur lama untuk server bare-metal Ubuntu (`setup-ubuntu.sh`, `deploy.sh`, `nginx.conf`, `supervisor.conf`, `php-clipper.ini`) dan tidak dipakai pipeline Docker.

Hal yang perlu diingat:

- **Migrasi**: `php artisan migrate --force` dijalankan oleh script deploy sebelum worker baru naik. Migrasi yang menghapus atau mengganti nama kolom dan tabel butuh dua deploy (lihat bagian 6.2 di DEPLOYMENT.md).
- **Kosongkan queue bila kelas job berubah**: payload di tabel `jobs` menyimpan nama kelas dan properti job. Kalau kelas job di `app/Jobs/` diganti nama, dipindah, atau konstruktornya berubah, job yang sudah antre gagal dengan kode baru. Tunggu sampai antrian kosong (`select count(*) from jobs`) sebelum deploy, atau jangan ubah bentuk job yang masih bisa antre.
- **Worker**: setelah deploy jalankan `php artisan queue:restart` (script `deploy/deploy.sh` sudah). Deploy Docker menunggu job yang sedang jalan sampai 65 menit (`stop_grace_period: 65m`).
- **Batas upload**: footage gameplay sampai 200 MB per file dan musik sampai 50 MB. `deploy/php-clipper.ini` mengatur `upload_max_filesize=205M`, `post_max_size=210M`, `memory_limit=512M`; `deploy/nginx.conf` mengatur `client_max_body_size 210M` dan `fastcgi_read_timeout 600`. Image Docker memakai batas 2 GB di `docker/nginx.conf` dan PHP; Nginx di host yang jadi reverse proxy juga harus mengizinkan body sebesar itu.
- **`.env`**: container men-cache config saat start, jadi perubahan `.env` baru berlaku setelah container dibuat ulang.
- **yt-dlp**: perbarui rutin, YouTube sering berubah. Server tanpa browser memakai `YTDLP_COOKIES`.

## Halaman publik dan review platform

`/welcome` (beranda aplikasi), `/privacy`, `/terms`, dan `/status` terbuka tanpa login. URL `/welcome`, `/privacy`, dan `/terms` didaftarkan di branding OAuth Google, listing app TikTok, dan listing app Meta. Saat mengubah halaman itu, pertahankan penjelasan fitur, cara data YouTube, TikTok, dan Meta dipakai (hanya klip yang disetujui atau dijadwalkan pengguna ke akunnya sendiri), serta link ke kebijakan privasi dan syarat layanan. `tests/Feature/PublicPagesTest.php` memeriksa sebagian di antaranya.

## Struktur kode

| Bagian | Lokasi |
|---|---|
| Studio (Blade + Alpine.js) | `resources/views/studio.blade.php`, `resources/views/studio/*` |
| Autopilot, kalender, musik, paket | `autopilot.blade.php`, `calendar.blade.php`, `music.blade.php`, `pricing.blade.php` |
| Pengaturan (akun terhubung, brand kit, gameplay, token API) | `resources/views/settings/*` |
| Job antrian | `app/Jobs/`: `AnalyzeVideoJob`, `RenderClipJob`, `DiscoverVideosJob`, `UploadToTikTokJob`, `UploadToYouTubeJob`, `PublishToMetaJob` |
| AI (gateway + Gemini) | `app/Services/GeminiService.php` |
| Render, hook, layout, SFX | `FFmpegService`, `HookRenderer`, `VideoLayoutService`, `FaceTracker`, `SmartCut`, `app/Support/SfxCues.php`, `resources/facetrack/`, `resources/sfx/` |
| Unduh dan transkrip | `YtDlpService`, `TranscriptService` |
| Publikasi | `TikTokService`, `YouTubeService`, `FacebookReels`, `InstagramReels`, `ThreadsPosts`, `ConnectedAccounts`, `ScheduledPublisher`, `Autopost` |
| Tes hook A/B | `HookTestService`, `ClipPerformanceSummary`, `TikTokStatsService` |
| Kuota dan paket | `RenderQuota`, `app/Models/Plan.php` |
| REST API dan MCP | `routes/agent.php`, `app/Http/Controllers/McpController.php`, [`docs/agent-api.md`](docs/agent-api.md) |
| Jadwal dan perintah artisan | `routes/console.php` |

Alur proses:

```
Link YouTube -> AnalyzeVideoJob (unduh, transkrip, AI memilih momen)
             -> pilih klip -> RenderClipJob (ffmpeg: potong, reframe/layout, subtitle, hook, musik, SFX)
             -> unduh MP4/ZIP, atau Publikasikan / Kalender / Autopilot
             -> UploadToTikTokJob, UploadToYouTubeJob, PublishToMetaJob
```

Issue tracking memakai [beads](https://github.com/gastownhall/beads): `bd ready`, `bd show <id>`, `bd prime`.

## Masalah yang sering muncul

| Gejala | Penyebab dan solusi |
|---|---|
| Project berhenti di "Menunggu antrian" | Queue worker tidak jalan. Lokal: `composer dev`. Produksi: restart service `queue` |
| Autopilot, kalender, atau status upload tidak bergerak | Scheduler tidak jalan (`schedule:work` atau cron `schedule:run`) |
| Kuota Gemini habis atau 503 | Isi `LLM_GATEWAY_*` atau key berbayar, lalu coba lagi dari project |
| "Sign in to confirm you're not a bot" | Isi `YTDLP_COOKIES_BROWSER` atau `YTDLP_COOKIES`, perbarui yt-dlp |
| "Transcript kosong" | Video tanpa subtitle dan `GROQ_API_KEY` kosong |
| Upload TikTok `scope_not_authorized` | Aktifkan scope di app TikTok, sesuaikan `TIKTOK_SCOPES`, hubungkan ulang akun |
| Instagram atau Threads gagal mengunduh video | `APP_URL` belum bisa dibuka dari internet (HTTPS publik) |
| Tampilan berantakan setelah update | `npm run build` |
