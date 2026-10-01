# Clipper Studio

Ubah video YouTube panjang jadi klip vertikal 9:16 siap TikTok/Reels/Shorts — otomatis.
AI memilih momen paling menarik, lalu aplikasi memotong, me-reframe, menambah subtitle karaoke,
hook pembuka, dan musik latar, sampai mengirimnya ke TikTok.

- **Studio** — tempel link YouTube, atur tampilan dalam 7 langkah, pilih klip, render, unduh/kirim ke TikTok.
- **Autopilot** — pantau channel atau keyword YouTube; video baru otomatis dipotong jadi klip dan masuk antrian review.
- **Hook AI** — teks pembuka 3–5 kata yang bikin penasaran, kata kunci disorot warna, animasi pop.
- **Subtitle karaoke** — kata yang diucapkan menyala; posisi bisa diseret di preview.
- **Library musik** — musik latar per mood, kredit CC BY otomatis masuk caption.
- **TikTok** — kirim ke inbox (draft) atau posting langsung.

---

## Kebutuhan

| Program | Versi | Catatan |
|---|---|---|
| PHP | 8.3+ | ekstensi `pdo_pgsql`, `gd` (dengan FreeType) |
| Composer | 2.x | |
| Node.js | 20.19+ / 22.12+ | untuk build CSS (Vite) |
| PostgreSQL | 14+ | |
| FFmpeg | 6+ (`ffmpeg` dan `ffprobe`) | `brew install ffmpeg` / `apt install ffmpeg` |
| yt-dlp | terbaru | `brew install yt-dlp` / `pipx install yt-dlp` — perbarui rutin |

Akun/API yang dibutuhkan: **Gemini API key** (gratis) atau gateway OpenAI-compatible untuk AI,
**Groq API key** (gratis) untuk transkrip video tanpa subtitle, dan aplikasi **TikTok for Developers** kalau mau upload ke TikTok.

---

## Instalasi cepat

```bash
# 1. Ambil kode
git clone <repo> clipper && cd clipper

# 2. Install semua (composer, npm, .env, APP_KEY, migrate, build CSS)
createdb clipper            # buat database PostgreSQL dulu
cp .env.example .env        # lalu isi DB_* dan API key (lihat tabel di bawah)
composer setup

# 3. Jalankan (web + worker + scheduler + log + vite sekaligus)
composer dev
```

Buka **http://localhost:8000**, klik **Daftar**, buat akun — selesai.

> `composer dev` harus tetap jalan: worker di dalamnya yang mengunduh, menganalisis, dan merender.
> Tanpa worker, project akan berhenti di "Menunggu antrian…".

---

## Konfigurasi `.env`

Yang **wajib** hanya database dan satu sumber AI. Sisanya sesuai fitur yang dipakai.

| Variabel | Wajib? | Fungsi / cara dapat |
|---|---|---|
| `APP_URL` | ya | URL tempat aplikasi dibuka. Kalau lewat ngrok, isi URL ngrok-nya. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | ya | Koneksi PostgreSQL. |
| `GEMINI_API_KEY` | salah satu* | AI analisis & caption. Gratis di [aistudio.google.com/apikey](https://aistudio.google.com/apikey). Free tier ±20 request/hari per model; aplikasi otomatis pindah ke model lain saat kuota habis. |
| `LLM_GATEWAY_URL`, `LLM_GATEWAY_KEY`, `LLM_GATEWAY_MODEL` | salah satu* | Gateway OpenAI-compatible (mis. OmniRoute), dicoba **sebelum** Gemini. URL diakhiri `/v1`. `MODEL` boleh beberapa dipisah koma = urutan cadangan. |
| `GROQ_API_KEY` | disarankan | Transkrip suara (Whisper) untuk video yang tidak punya subtitle YouTube. Gratis di [console.groq.com/keys](https://console.groq.com/keys). |
| `YTDLP_COOKIES_BROWSER` | disarankan | Ambil cookies YouTube dari browser (`chrome`, `firefox`, `safari`, …) untuk mengatasi "Sign in to confirm you're not a bot". |
| `YTDLP_COOKIES` | alternatif | Path file `cookies.txt` (format Netscape). Dipakai hanya kalau `YTDLP_COOKIES_BROWSER` kosong. |
| `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` | untuk upload TikTok | Dari app di [developers.tiktok.com](https://developers.tiktok.com) (produk *Content Posting API* + *Login Kit*). |
| `TIKTOK_REDIRECT_URI` | untuk upload TikTok | `<APP_URL>/tiktok/callback`, harus terdaftar persis sama di app TikTok (wajib HTTPS). |
| `TIKTOK_SCOPES` | opsional | Default `user.info.basic,video.upload`. Tambah `video.publish` untuk posting langsung. |
| `GEMINI_MODELS` | opsional | Urutan model Gemini (dipisah koma). Kosongkan untuk default. |
| `MUSIC_LIBRARY_PATH` | opsional | Lokasi library musik. Default `storage/app/music`. |
| `CLIPPER_TEMP_RETENTION_HOURS` | opsional | Video sumber yang diunduh dihapus otomatis setelah sekian jam (default 72). |
| `YTDLP_PATH`, `FFMPEG_PATH` | opsional | Kalau `yt-dlp` / `ffmpeg` tidak ada di PATH. |

\* Isi minimal satu: `GEMINI_API_KEY` atau `LLM_GATEWAY_*`. Kalau dua-duanya diisi, gateway dipakai dulu dan Gemini jadi cadangan.

Setelah mengubah `.env`, restart `composer dev` (atau worker) supaya nilai baru terbaca.

---

## Cara pakai

### Studio (buat klip manual)
1. **Link** — tempel URL YouTube, klik *Proses*.
2. **Layout** — *Reframe wajah* (crop 9:16 mengikuti wajah) atau *Gaussian* (video utuh + latar blur).
3. **Clip** — jumlah klip (1/3/5) dan durasi (otomatis atau atur sendiri).
4. **Subtitle** — font, warna, highlight, background. **Seret subtitle di preview** untuk menaruhnya persis di posisi yang kamu mau.
5. **Hook** — tulis sendiri atau biarkan AI. Apit kata dengan `*bintang*` untuk menyorotnya, mis. `Gaji 50 juta, tetap *bokek*`. Pilih gaya Label / Kotak / Outline. Musik latar juga diatur di sini.
6. **Generate** — AI menganalisis video. Bisa **Batalkan**, dan kalau gagal ada tombol **Coba lagi**.
7. **Pilih** — pilih klip, klik *Render*. Setelah selesai: unduh MP4, salin caption + hashtag, atau kirim ke TikTok.

Semua project tersimpan di **Riwayat** (pojok kanan atas). Kamu boleh pindah-pindah project
saat analisis/render masih berjalan — prosesnya tetap jalan di server dan statusnya muncul lagi saat dibuka.

### Autopilot
1. Buka **Autopilot**, tambah sumber: nama channel (`@namachannel`) atau keyword.
2. Klik **Atur tampilan** untuk memilih layout, gaya hook, subtitle, dan musik khusus sumber itu.
3. Tiap beberapa jam aplikasi mencari video baru, membuat klip, dan menaruhnya di **Antrian review**.
4. Tonton klipnya, lalu **Approve → TikTok** atau **Reject**.

### Library musik
Klik nama kamu (kanan atas) → **Library musik**.

1. Di YouTube Studio → *Audio Library*, filter **Attribution required** (Creative Commons) dan mood.
   Lagu berlisensi standar YouTube **tidak boleh** dipakai di TikTok.
2. Unduh lagunya dan salin teks atribusinya (ikon CC).
3. Upload banyak file sekaligus, pilih mood, tempel kredit tiap lagu.

Kredit lagu otomatis ditambahkan ke caption klip (`🎵 …`). Volume: 15% = jelas di belakang suara, 30% = sejajar suara.

---

## Membuka dari HP / untuk TikTok (ngrok)

TikTok mewajibkan redirect HTTPS publik. Cara paling mudah:

```bash
ngrok http 8000            # atau port web server kamu
```

Lalu di `.env`: `APP_URL=https://xxxx.ngrok-free.app` dan
`TIKTOK_REDIRECT_URI=https://xxxx.ngrok-free.app/tiktok/callback` — daftarkan URI yang sama di app TikTok.
URL ngrok gratis berubah tiap restart, jadi kedua nilai ini (dan setelan TikTok) perlu diperbarui.

---

## Deploy ke server Ubuntu (dengan domain)

Folder `deploy/` berisi semua yang dibutuhkan: `setup-ubuntu.sh` (sekali saja), `deploy.sh` (tiap update),
`nginx.conf`, `supervisor.conf`, dan `php-clipper.ini`.

**Sebelum mulai:** server Ubuntu 22.04/24.04 (disarankan ≥ 2 vCPU, 4 GB RAM — render ffmpeg cukup berat),
dan DNS domain (record `A`) sudah mengarah ke IP server.

```bash
# 1. Di server (sebagai user biasa yang punya sudo)
sudo mkdir -p /var/www && sudo chown $USER /var/www
git clone <repo> /var/www/clipper && cd /var/www/clipper
sudo usermod -aG www-data $USER        # supaya kamu & web server sama-sama bisa menulis storage/

# 2. Install semua (PHP, nginx, PostgreSQL, ffmpeg, yt-dlp + Deno, supervisor, HTTPS)
sudo DOMAIN=clipper.domainkamu.com EMAIL=kamu@email.com bash deploy/setup-ubuntu.sh
#    → catat DB password yang dicetak di akhir

# 3. Isi .env
cp .env.example .env && nano .env
```

Yang wajib diisi di `.env` server:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://clipper.domainkamu.com
SESSION_SECURE_COOKIE=true
REGISTRATION_ENABLED=false

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=clipper
DB_USERNAME=clipper
DB_PASSWORD=<dari langkah 2>

# Server tidak punya browser: pakai file cookies YouTube (export dari browser,
# format Netscape, mis. ekstensi "Get cookies.txt LOCALLY") lalu upload ke server
YTDLP_COOKIES_BROWSER=
YTDLP_COOKIES=/var/www/clipper/storage/app/youtube-cookies.txt

TIKTOK_REDIRECT_URI=https://clipper.domainkamu.com/tiktok/callback
# + GEMINI_API_KEY / LLM_GATEWAY_*, GROQ_API_KEY, TIKTOK_CLIENT_KEY/SECRET
```

```bash
# 4. Install aplikasi, migrate, build, jalankan worker
bash deploy/deploy.sh

# 5. Buka https://clipper.domainkamu.com/register → buat akunmu (setelah itu pendaftaran tertutup)
```

Jangan lupa ganti Redirect URI di app TikTok ke `https://<domain>/tiktok/callback`.

**Update aplikasi** setelah ada perubahan kode: `cd /var/www/clipper && bash deploy/deploy.sh`.
Worker yang sedang merender menyelesaikan job-nya dulu, baru pindah ke kode baru.

**Cek & kelola di server:**

| Perintah | Fungsi |
|---|---|
| `sudo supervisorctl status` | Status 2 worker + scheduler |
| `sudo supervisorctl restart all` | Restart worker & scheduler |
| `tail -f storage/logs/clipper/jobs-*.log` | Log download/analisis/render |
| `sudo /usr/local/bin/yt-dlp -U` | Update yt-dlp (lakukan rutin — YouTube sering berubah) |
| `sudo certbot renew --dry-run` | Cek perpanjangan HTTPS otomatis |

Jumlah worker diatur di `/etc/supervisor/conf.d/clipper.conf` (`numprocs`), lalu `sudo supervisorctl update`.

### Keamanan yang sudah aktif

- Pendaftaran hanya untuk akun pertama (`REGISTRATION_ENABLED=false`); login dibatasi 5 percobaan/menit.
- Semua halaman & API butuh login, dicek kepemilikan datanya, dan dilindungi CSRF.
- Link YouTube divalidasi ketat dan dibangun ulang sebelum diberikan ke yt-dlp (mencegah injeksi perintah).
- Header keamanan (anti-clickjacking, nosniff, HSTS), cookie sesi `Secure`/`HttpOnly`, API key tidak pernah ditulis di URL/log.
- Hanya proxy lokal yang dipercaya. Kalau server ada di belakang **Cloudflare (proxy oranye)** atau load balancer,
  tambahkan IP-nya di `trustProxies` pada `bootstrap/app.php` — kalau tidak, URL bisa jadi `http://` dan rate limit memakai IP proxy.

---

## Troubleshooting

| Gejala | Penyebab & solusi |
|---|---|
| "Gemini analisa gagal — Kuota harian Gemini habis" | Free tier habis di semua model. Tunggu reset (harian), pakai API key berbayar, atau isi `LLM_GATEWAY_*`. Lalu klik **Coba lagi**. |
| "Server Gemini sedang sibuk (503)" | Gangguan sementara di Google. Klik **Coba lagi** beberapa menit kemudian. |
| "Sign in to confirm you're not a bot" / download gagal | Isi `YTDLP_COOKIES_BROWSER` (login YouTube di browser itu), dan perbarui yt-dlp. |
| "Transcript kosong" | Video tanpa subtitle dan `GROQ_API_KEY` belum diisi. |
| Project berhenti di "Menunggu antrian…" | Worker tidak jalan. Jalankan `composer dev` atau `php artisan queue:work`. |
| Musik tidak terdengar | Library musik kosong (muncul peringatan kuning). Upload lagu di **Library musik**, lalu render ulang. |
| Upload TikTok gagal `scope_not_authorized` | Aktifkan scope di app TikTok, isi `TIKTOK_SCOPES`, lalu hubungkan ulang akun. |
| Tampilan berantakan setelah update | Jalankan `npm run build`. |

---

## Untuk developer

```bash
composer test          # jalankan semua test (SQLite in-memory, tidak menyentuh database asli)
```

| Bagian | Lokasi |
|---|---|
| Halaman (Blade + Alpine.js) | `resources/views/studio/*`, `autopilot.blade.php`, `music.blade.php` |
| Job antrian | `app/Jobs/AnalyzeVideoJob.php`, `RenderClipJob.php`, `DiscoverVideosJob.php`, `UploadToTikTokJob.php` |
| AI (gateway + Gemini) | `app/Services/GeminiService.php` |
| Render video | `app/Services/FFmpegService.php`, hook: `HookRenderer.php` |
| Download / transkrip | `app/Services/YtDlpService.php`, `TranscriptService.php` |
| Pengaturan tampilan klip | `app/Support/ClipSettings.php` |
