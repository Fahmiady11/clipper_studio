# 🎬 Clipper Studio

**Ubah video YouTube panjang jadi klip pendek vertikal (9:16) siap TikTok, Reels, dan Shorts — otomatis.**

Kamu cukup tempel link YouTube. AI akan mencari momen paling menarik, lalu aplikasi memotong video,
menyesuaikan ke format vertikal, menambah subtitle, teks pembuka (hook), dan musik latar —
sampai mengirimnya langsung ke TikTok.

```
 Link YouTube  ──►  AI pilih momen terbaik  ──►  Potong + subtitle + hook + musik  ──►  Unduh MP4 / kirim ke TikTok
```

---

## 📑 Daftar isi

1. [Fitur](#-fitur)
2. [Mulai cepat (di laptop sendiri)](#-mulai-cepat-di-laptop-sendiri)
3. [Mengisi file `.env`](#️-mengisi-file-env)
4. [Cara pakai](#-cara-pakai)
5. [Membuka dari HP / menghubungkan TikTok (ngrok)](#-membuka-dari-hp--menghubungkan-tiktok-ngrok)
6. [Pasang di server (supaya bisa dipakai banyak orang)](#-pasang-di-server-supaya-bisa-dipakai-banyak-orang)
7. [Masalah yang sering muncul](#-masalah-yang-sering-muncul)
8. [Pertanyaan umum (FAQ)](#-pertanyaan-umum-faq)
9. [Untuk developer](#-untuk-developer)

---

## ✨ Fitur

| Fitur | Penjelasan singkat |
|---|---|
| **Studio** | Tempel link YouTube, atur tampilan dalam 7 langkah, pilih klip, render, lalu unduh atau kirim ke TikTok. |
| **Autopilot** | Pantau channel atau kata kunci YouTube. Video baru otomatis dijadikan klip dan masuk antrian untuk kamu cek. |
| **Hook AI** | Teks pembuka 3–5 kata yang bikin penasaran. Kata penting diberi warna, muncul dengan animasi. |
| **Subtitle karaoke** | Kata yang sedang diucapkan menyala. Posisi subtitle bisa digeser langsung di preview. |
| **Library musik** | Musik latar sesuai mood. Kredit lagu (lisensi CC BY) otomatis ditulis di caption. |
| **TikTok** | Kirim ke inbox TikTok sebagai draft, atau langsung posting. |
| **Riwayat** | Semua project tersimpan. Proses tetap jalan di server walaupun kamu pindah halaman. |

---

## 🚀 Mulai cepat (di laptop sendiri)

> ⏱️ Kira-kira 15–30 menit untuk pertama kali.

### Langkah 1 — Install program yang dibutuhkan

| Program | Versi minimal | Untuk apa |
|---|---|---|
| PHP | 8.3 | Menjalankan aplikasi. Butuh ekstensi `pdo_pgsql` dan `gd` (dengan FreeType). |
| Composer | 2.x | Install library PHP. |
| Node.js | 20.19 / 22.12 | Build tampilan (CSS). |
| PostgreSQL | 14 | Database. |
| FFmpeg | 6 | Memotong dan merender video (`ffmpeg` + `ffprobe`). |
| yt-dlp | versi terbaru | Mengunduh video YouTube. **Perbarui rutin**, karena YouTube sering berubah. |

**macOS** (pakai [Homebrew](https://brew.sh)):

```bash
brew install php composer node postgresql@16 ffmpeg yt-dlp
brew services start postgresql@16
```

**Ubuntu / Debian:** paling mudah pakai script server, lihat [Pasang di server](#-pasang-di-server-supaya-bisa-dipakai-banyak-orang).

**Windows:** disarankan pakai [WSL2](https://learn.microsoft.com/windows/wsl/install) (Ubuntu), lalu ikuti cara Ubuntu.

### Langkah 2 — Siapkan API key (gratis)

| Key | Wajib? | Ambil di |
|---|---|---|
| **Gemini API key** | ✅ Wajib (atau pakai gateway, lihat [`.env`](#️-mengisi-file-env)) | [aistudio.google.com/apikey](https://aistudio.google.com/apikey) |
| **Groq API key** | 👍 Sangat disarankan | [console.groq.com/keys](https://console.groq.com/keys) — untuk video yang tidak punya subtitle |
| **TikTok app** | Hanya kalau mau upload ke TikTok | [developers.tiktok.com](https://developers.tiktok.com) |

### Langkah 3 — Download dan install aplikasi

```bash
git clone git@github.com:Fahmiady11/clipper-sosmed.git clipper
cd clipper

createdb clipper           # buat database kosong
cp .env.example .env       # buat file pengaturan
```

Buka file `.env`, lalu isi minimal 3 hal berikut:

```dotenv
APP_URL=http://localhost:8000
DB_USERNAME=postgres        # user PostgreSQL kamu
DB_PASSWORD=                # password-nya (kosongkan kalau tidak ada)
GEMINI_API_KEY=isi_key_kamu
GROQ_API_KEY=isi_key_kamu   # disarankan
```

Lalu install semuanya dengan satu perintah:

```bash
composer setup
```

### Langkah 4 — Jalankan

```bash
composer dev
```

Buka **http://localhost:8000**, klik **Daftar**, lalu buat akun. Selesai! 🎉

> ⚠️ **Biarkan `composer dev` tetap jalan.** Perintah ini sekaligus menjalankan *worker* yang mengunduh,
> menganalisis, dan merender video. Kalau ditutup, project akan berhenti di "Menunggu antrian…".

---

## ⚙️ Mengisi file `.env`

File `.env` berisi semua pengaturan. **Setiap kali `.env` diubah, restart `composer dev`** supaya pengaturan baru terbaca.

### Wajib

| Variabel | Isi dengan |
|---|---|
| `APP_URL` | Alamat aplikasi dibuka. Lokal: `http://localhost:8000`. Kalau lewat ngrok/domain: URL itu. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Data koneksi PostgreSQL. |
| `GEMINI_API_KEY` **atau** `LLM_GATEWAY_*` | Minimal salah satu, untuk AI. Lihat bagian AI di bawah. |

### AI

| Variabel | Penjelasan |
|---|---|
| `GEMINI_API_KEY` | Key gratis dari Google. Kuota gratis ±20 request/hari per model. Kalau satu model habis, aplikasi otomatis pindah ke model lain. |
| `GEMINI_MODELS` | (Opsional) Urutan model Gemini, dipisah koma. Kosongkan untuk pakai urutan bawaan. |
| `LLM_GATEWAY_URL`, `LLM_GATEWAY_KEY`, `LLM_GATEWAY_MODEL` | (Opsional) Gateway yang kompatibel dengan OpenAI. Kalau diisi, gateway dicoba **lebih dulu**, lalu Gemini jadi cadangan. URL diakhiri `/v1`. Model boleh lebih dari satu, dipisah koma. |
| `GROQ_API_KEY` | Membuat transkrip dari suara video (Whisper) kalau video tidak punya subtitle YouTube. |

### Download YouTube

| Variabel | Penjelasan |
|---|---|
| `YTDLP_COOKIES_BROWSER` | Nama browser tempat kamu login YouTube (`chrome`, `firefox`, `safari`, …). Mengatasi error *"Sign in to confirm you're not a bot"*. |
| `YTDLP_COOKIES` | Alternatif: path ke file `cookies.txt` (format Netscape). Dipakai hanya kalau `YTDLP_COOKIES_BROWSER` kosong. Cocok untuk server. |
| `YTDLP_PATH`, `FFMPEG_PATH` | (Opsional) Isi kalau `yt-dlp` / `ffmpeg` tidak dikenali di terminal. |

### TikTok

| Variabel | Penjelasan |
|---|---|
| `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` | Dari app kamu di developers.tiktok.com (aktifkan *Content Posting API* + *Login Kit*). |
| `TIKTOK_REDIRECT_URI` | `<APP_URL>/tiktok/callback`. Harus **persis sama** dengan yang didaftarkan di app TikTok, dan wajib HTTPS. |
| `TIKTOK_SCOPES` | Bawaan `user.info.basic,video.upload` (kirim sebagai draft). Tambahkan `video.publish` untuk posting langsung. |

### Akun & lain-lain

| Variabel | Penjelasan |
|---|---|
| `REGISTRATION_ENABLED` | `false` = hanya akun pertama yang bisa daftar (aman). `true` = siapa pun boleh daftar. |
| `SESSION_SECURE_COOKIE` | `true` kalau aplikasi dibuka lewat HTTPS (ngrok/domain). |
| `MUSIC_LIBRARY_PATH` | Lokasi file musik. Bawaan `storage/app/music`. |
| `CLIPPER_TEMP_RETENTION_HOURS` | Video sumber yang sudah diunduh dihapus otomatis setelah sekian jam (bawaan 72). |

---

## 📖 Cara pakai

### 🎨 Studio — membuat klip

| Langkah | Yang dilakukan |
|---|---|
| **1. Link** | Tempel URL YouTube, klik **Proses**. |
| **2. Layout** | **Reframe wajah**: video dipotong vertikal mengikuti wajah. **Gaussian**: video utuh dengan latar blur. |
| **3. Clip** | Pilih jumlah klip (1 / 3 / 5) dan durasi (otomatis atau atur sendiri). |
| **4. Subtitle** | Atur font, warna, sorotan, dan latar. **Geser subtitle di preview** untuk memindahkan posisinya. |
| **5. Hook** | Tulis sendiri atau biarkan AI. Apit kata dengan bintang untuk memberi warna, misalnya `Gaji 50 juta, tetap *bokek*`. Pilih gaya Label / Kotak / Outline, lalu atur musik latar. |
| **6. Generate** | AI menganalisis video. Bisa **Batalkan**. Kalau gagal, klik **Coba lagi**. |
| **7. Pilih** | Pilih klip, klik **Render**. Setelah selesai: unduh MP4, salin caption + hashtag, atau kirim ke TikTok. |

💡 Semua project tersimpan di **Riwayat** (pojok kanan atas). Kamu boleh pindah project saat proses masih berjalan.

### 🤖 Autopilot — membuat klip otomatis

1. Buka menu **Autopilot**, lalu tambahkan sumber: nama channel (`@namachannel`) atau kata kunci.
2. Klik **Atur tampilan** untuk memilih layout, gaya hook, subtitle, dan musik untuk sumber itu.
3. Secara berkala aplikasi mencari video baru, membuat klip, lalu menaruhnya di **Antrian review**.
4. Tonton klipnya, lalu pilih **Approve → TikTok** atau **Reject**.

> Autopilot butuh *scheduler* yang menyala. Di laptop, `composer dev` sudah menjalankannya.

### 🎵 Library musik

Klik nama kamu (kanan atas) → **Library musik**.

1. Buka YouTube Studio → **Audio Library**, lalu filter **Attribution required** (Creative Commons) dan mood.
   ⚠️ Lagu berlisensi standar YouTube **tidak boleh** dipakai di TikTok.
2. Unduh lagunya dan salin teks atribusinya (klik ikon CC).
3. Upload beberapa file sekaligus, pilih mood, lalu tempel kredit untuk tiap lagu.

Kredit lagu otomatis ditambahkan ke caption (`🎵 …`).
Panduan volume: **15%** = terdengar di belakang suara, **30%** = sejajar dengan suara.

---

## 📱 Membuka dari HP / menghubungkan TikTok (ngrok)

TikTok mewajibkan alamat HTTPS publik. Cara paling mudah untuk mencoba di laptop:

```bash
ngrok http 8000
```

Salin URL yang muncul (misalnya `https://xxxx.ngrok-free.app`), lalu isi di `.env`:

```dotenv
APP_URL=https://xxxx.ngrok-free.app
TIKTOK_REDIRECT_URI=https://xxxx.ngrok-free.app/tiktok/callback
SESSION_SECURE_COOKIE=true
```

Daftarkan Redirect URI yang sama di app TikTok, lalu restart `composer dev`.

> URL ngrok gratis **berubah setiap kali ngrok di-restart**, jadi nilai di atas (dan setelan app TikTok) perlu diperbarui.
> Untuk pemakaian rutin, lebih baik pasang di server dengan domain sendiri.

---

## 🌐 Pasang di server (supaya bisa dipakai banyak orang)

Folder `deploy/` sudah berisi semua yang dibutuhkan:

| File | Fungsi |
|---|---|
| `setup-ubuntu.sh` | Install semua program di server. **Cukup sekali.** |
| `deploy.sh` | Install/update aplikasi. Jalankan **setiap ada update kode**. |
| `nginx.conf`, `supervisor.conf`, `php-clipper.ini` | Konfigurasi web server, worker, dan PHP (dipasang otomatis). |

### Sebelum mulai

- [ ] Server **Ubuntu 22.04 atau 24.04**. Disarankan minimal **2 vCPU, 4 GB RAM**, karena render video cukup berat.
- [ ] Domain dengan record **A** yang sudah mengarah ke IP server.
- [ ] Login ke server sebagai user biasa yang punya akses `sudo`.

### Langkah-langkah

**1. Ambil kode**

```bash
sudo mkdir -p /var/www && sudo chown $USER /var/www
git clone git@github.com:Fahmiady11/clipper-sosmed.git /var/www/clipper
cd /var/www/clipper
sudo usermod -aG www-data $USER
```

**2. Install semua program** (PHP, nginx, PostgreSQL, ffmpeg, yt-dlp, supervisor, sertifikat HTTPS)

```bash
sudo DOMAIN=clipper.domainkamu.com EMAIL=kamu@email.com bash deploy/setup-ubuntu.sh
```

📝 **Catat password database** yang muncul di akhir proses.

**3. Isi `.env`**

```bash
cp .env.example .env && nano .env
```

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://clipper.domainkamu.com
SESSION_SECURE_COOKIE=true
REGISTRATION_ENABLED=false     # ubah ke true kalau orang lain boleh daftar sendiri

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=clipper
DB_USERNAME=clipper
DB_PASSWORD=<password dari langkah 2>

# Server tidak punya browser, jadi pakai file cookies YouTube.
# Export dari browser (misalnya ekstensi "Get cookies.txt LOCALLY"), lalu upload ke server.
YTDLP_COOKIES_BROWSER=
YTDLP_COOKIES=/var/www/clipper/storage/app/youtube-cookies.txt

TIKTOK_REDIRECT_URI=https://clipper.domainkamu.com/tiktok/callback

# Jangan lupa: GEMINI_API_KEY (atau LLM_GATEWAY_*), GROQ_API_KEY, TIKTOK_CLIENT_KEY, TIKTOK_CLIENT_SECRET
```

**4. Install aplikasi dan jalankan worker**

```bash
bash deploy/deploy.sh
```

**5. Buat akun admin**

Buka `https://clipper.domainkamu.com/register`, lalu buat akun pertama.
Jangan lupa ubah Redirect URI di app TikTok ke `https://clipper.domainkamu.com/tiktok/callback`.

### 👥 Menambah pengguna lain

Secara bawaan hanya akun pertama yang bisa mendaftar. Untuk membuka pendaftaran:

1. Ubah `REGISTRATION_ENABLED=true` di `.env`.
2. Jalankan `bash deploy/deploy.sh`.
3. Minta teman membuka `/register`. Setelah semua terdaftar, kembalikan ke `false` dan jalankan `deploy.sh` lagi.

Setiap pengguna hanya bisa melihat project, klip, dan akun TikTok miliknya sendiri.

### 🔄 Update aplikasi

```bash
cd /var/www/clipper && bash deploy/deploy.sh
```

Worker yang sedang merender akan menyelesaikan pekerjaannya dulu sebelum memakai kode baru.

### 🛠️ Perintah berguna di server

| Perintah | Fungsi |
|---|---|
| `sudo supervisorctl status` | Lihat status worker dan scheduler. |
| `sudo supervisorctl restart all` | Restart worker dan scheduler. |
| `tail -f storage/logs/clipper/jobs-*.log` | Lihat log download, analisis, dan render. |
| `sudo /usr/local/bin/yt-dlp -U` | Update yt-dlp. **Lakukan rutin.** |
| `sudo certbot renew --dry-run` | Cek perpanjangan HTTPS otomatis. |

Jumlah worker (render paralel) diatur di `/etc/supervisor/conf.d/clipper.conf` (`numprocs`), lalu jalankan `sudo supervisorctl update`.

### 🔒 Keamanan yang sudah aktif

- Pendaftaran bisa ditutup (`REGISTRATION_ENABLED=false`). Login dibatasi 5 percobaan per menit.
- Semua halaman dan API wajib login, kepemilikan datanya dicek, dan semua form dilindungi CSRF.
- Link YouTube divalidasi ketat sebelum diproses, sehingga tidak bisa disisipi perintah berbahaya.
- Header keamanan (anti-clickjacking, nosniff, HSTS), cookie `Secure`/`HttpOnly`, dan API key tidak pernah ditulis ke URL atau log.
- Hanya proxy lokal yang dipercaya. Kalau server ada di belakang **Cloudflare (proxy oranye)** atau load balancer,
  tambahkan IP-nya di `trustProxies` pada `bootstrap/app.php`.

---

## 🧯 Masalah yang sering muncul

| Gejala | Penyebab & solusi |
|---|---|
| Project berhenti di **"Menunggu antrian…"** | Worker tidak jalan. Di laptop: jalankan `composer dev`. Di server: `sudo supervisorctl restart all`. |
| **"Kuota harian Gemini habis"** | Kuota gratis habis di semua model. Tunggu besok, pakai API key berbayar, atau isi `LLM_GATEWAY_*`. Lalu klik **Coba lagi**. |
| **"Server Gemini sedang sibuk (503)"** | Gangguan sementara dari Google. Klik **Coba lagi** beberapa menit kemudian. |
| **"Sign in to confirm you're not a bot"** / download gagal | Isi `YTDLP_COOKIES_BROWSER` (pastikan sudah login YouTube di browser itu) atau `YTDLP_COOKIES`, lalu update yt-dlp. |
| **"Transcript kosong"** | Video tidak punya subtitle dan `GROQ_API_KEY` belum diisi. |
| Musik tidak terdengar | Library musik masih kosong. Upload lagu di **Library musik**, lalu render ulang. |
| Upload TikTok gagal `scope_not_authorized` | Aktifkan scope di app TikTok, sesuaikan `TIKTOK_SCOPES`, lalu hubungkan ulang akun TikTok. |
| Tombol "Daftar" tidak ada / pendaftaran ditutup | Sudah ada akun. Ubah `REGISTRATION_ENABLED=true` kalau mau menambah pengguna. |
| Tampilan berantakan setelah update | Jalankan `npm run build`. |
| Pengaturan `.env` tidak berubah | Restart `composer dev` (lokal) atau jalankan `bash deploy/deploy.sh` (server). |

---

## ❓ Pertanyaan umum (FAQ)

**Apakah gratis?**
Aplikasinya gratis. Gemini dan Groq punya kuota gratis yang cukup untuk pemakaian ringan. Biaya utamanya hanya server (kalau dipasang online).

**Berapa lama satu video diproses?**
Tergantung panjang video dan kekuatan server. Analisis biasanya beberapa menit, dan render tiap klip sekitar 1–3 menit.

**Apakah video yang diunduh memenuhi disk?**
Tidak. Video sumber dihapus otomatis setiap hari (bawaan setelah 72 jam, atur lewat `CLIPPER_TEMP_RETENTION_HOURS`).

**Bolehkah saya memakai video orang lain?**
Pastikan kamu punya izin dari pemilik video, dan patuhi aturan hak cipta YouTube dan TikTok.

**Bisakah satu server dipakai beramai-ramai?**
Bisa. Lihat [Menambah pengguna lain](#-menambah-pengguna-lain). Untuk banyak pengguna, tambah `numprocs` worker dan pakai server yang lebih besar.

---

## 👩‍💻 Untuk developer

Dibangun dengan **Laravel 13**, PostgreSQL, Blade + Alpine.js, CSS biasa (Vite), FFmpeg, dan yt-dlp.

```bash
composer dev     # web + worker + scheduler + log + vite sekaligus
composer test    # jalankan semua test (SQLite in-memory, tidak menyentuh database asli)
composer lint    # rapikan gaya kode (Laravel Pint) — jalankan sebelum commit
```

### Struktur kode

| Bagian | Lokasi |
|---|---|
| Halaman (Blade + Alpine.js) | `resources/views/studio/*`, `autopilot.blade.php`, `music.blade.php` |
| Job antrian | `app/Jobs/` — `AnalyzeVideoJob`, `RenderClipJob`, `DiscoverVideosJob`, `UploadToTikTokJob` |
| AI (gateway + Gemini) | `app/Services/GeminiService.php` |
| Render video & hook | `app/Services/FFmpegService.php`, `app/Services/HookRenderer.php` |
| Download & transkrip | `app/Services/YtDlpService.php`, `app/Services/TranscriptService.php` |
| Autopilot | `app/Services/DiscoveryService.php`, `app/Models/VideoSource.php` |
| TikTok | `app/Services/TikTokService.php`, `app/Http/Controllers/TikTokController.php` |
| Pengaturan tampilan klip | `app/Support/ClipSettings.php` |
| Jadwal otomatis | `routes/console.php` — hapus klip kedaluwarsa, autopilot (tiap 10 menit), bersih-bersih file (03:17) |

### Alur proses

```
Tempel link ─► AnalyzeVideoJob (download + transkrip + AI pilih momen)
            ─► pilih klip ─► RenderClipJob (ffmpeg: potong, reframe, subtitle, hook, musik)
            ─► unduh MP4 / UploadToTikTokJob
```
