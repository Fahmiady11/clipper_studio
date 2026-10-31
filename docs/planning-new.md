Kamu adalah senior full-stack developer expert di Laravel dan FFmpeg.
Bantu saya membangun sebuah aplikasi web bernama "YouTube TikTok Clipper"
dengan spesifikasi lengkap berikut.

Tech Stack:
- Backend  : Laravel 11 (PHP 8.2+)
- Frontend : Blade + Tailwind CSS + Alpine.js
- Database : PostgreSQL
- Queue    : Laravel Queue + Supervisor (untuk job berat)
- AI       : Gemini API (analisis clip) + GROQ (subtitle)
- Video    : yt-dlp (download) + FFmpeg (manipulasi video)
- Auth     : Laravel Breeze (email/password, siap extend ke Google OAuth)

─────────────────────────────────────────────────────────────
## 1. AUTHENTICATION
─────────────────────────────────────────────────────────────

Fitur:
- Register dengan nama, email, password (Laravel Breeze)
- Login / Logout
- Middleware auth melindungi semua route kecuali register & login
- Siapkan struktur untuk Google OAuth (Socialite) tapi belum aktif,
  cukup beri komentar TODO di route dan controller-nya

Table: users (default Laravel + kolom `avatar` nullable)

─────────────────────────────────────────────────────────────
## 2. ALUR UTAMA APLIKASI (Step-by-step Wizard)
─────────────────────────────────────────────────────────────

Buat wizard multi-step di satu halaman menggunakan Alpine.js (step 1–7).
Simpan state antar step di Alpine data, jangan reload halaman.
Progress bar di atas menampilkan step aktif.

### STEP 1 — Input Link YouTube

- Input field untuk paste URL YouTube
- Tombol "Proses Link"
- Backend validasi URL YouTube (regex), kemudian:
  - Panggil yt-dlp untuk ambil metadata video:
    title, durasi, thumbnail, bahasa audio
  - Tampilkan preview: thumbnail + judul + durasi video
- Simpan ke table `clip_projects`:
  id, user_id, youtube_url, video_title, duration_seconds,
  thumbnail_url, status (draft/processing/done), created_at

### STEP 2 — Pilih Tipe Layout

Tampilkan 4 pilihan layout dalam bentuk card dengan ilustrasi SVG
dan deskripsi singkat. User harus memilih satu.

Layout yang tersedia:

1. REFRAME WAJAH (Face Tracking)
   - Video di-crop jadi 9:16, AI deteksi wajah, wajah selalu di tengah
   - Cocok untuk: talking head, vlog, monolog, edukasi
   - FFmpeg: crop + scale, koordinat wajah dari deteksi AI (atau
     gunakan pendekatan center-crop jika face detection tidak tersedia)

2. GAUSSIAN (Blurred Background)
   - Video asli 16:9 diletakkan di tengah layar vertikal 9:16
   - Area kosong atas-bawah diisi duplikat video yang di-blur + zoom
   - Cocok untuk: gameplay, screen recording, tutorial, olahraga
   - FFmpeg filter: [0:v]scale=1080:607,setsar=1[mid];
     [0:v]scale=1080:1920,crop=1080:1920,boxblur=20:20[bg];
     [bg][mid]overlay=(W-w)/2:(H-h)/2

Simpan pilihan ke kolom `layout_type` di `clip_projects`.

### STEP 3 — Pengaturan Clip

Tampilkan form dengan opsi:

a) Jumlah clip yang di-generate AI (pilihan: 1 / 3 / 5)
   → Simpan ke `clip_projects.clip_count`

b) Durasi per clip:
   - Otomatis (AI yang tentukan, 15–60 detik)
   - Manual (user set min dan max detik)
   → Simpan ke `clip_projects.duration_mode`, `min_duration`, `max_duration`

### STEP 4 — Pengaturan Subtitle

Toggle on/off untuk aktifkan subtitle.
Jika ON, tampilkan opsi:

a) Pilihan font (dropdown):
   - Arial, Roboto, Montserrat, Oswald, Bebas Neue
   
b) Ukuran font: slider 24px–72px

c) Warna teks utama: color picker

d) Warna highlight (kata yang sedang diucapkan): color picker
   → Ini adalah fitur karaoke-style: kata aktif beda warna/bold

e) Posisi subtitle: Atas / Tengah / Bawah

f) Background subtitle: Transparan / Semi-transparan hitam / Kotak penuh

Simpan semua ke table `subtitle_settings`:
id, clip_project_id, font_family, font_size, text_color,
highlight_color, position, background_style

### STEP 5 — Pengaturan Hook

Toggle on/off untuk aktifkan hook pembuka.
Jika ON, tampilkan:

a) Teks hook (textarea, maks 100 karakter)
   Atau: centang "Generate otomatis oleh AI"

b) Durasi tampil hook: slider 2–5 detik

c) Posisi hook di layar: Atas / Tengah / Bawah

d) Warna teks hook: color picker

e) Background hook: sama pilihan dengan subtitle

Simpan ke table `hook_settings`:
id, clip_project_id, hook_text, is_ai_generated,
duration_seconds, position, text_color, background_style

### STEP 6 — Generate (AI Analysis)

Tombol besar "Generate Clip dengan AI".
Saat diklik:

1. Dispatch Laravel Job: `AnalyzeVideoJob`
2. Tampilkan loading state dengan Alpine.js polling setiap 3 detik
   ke endpoint GET /projects/{id}/status
3. Job melakukan:
   a. Download video dengan yt-dlp ke storage/temp/
   b. Ambil transcript YouTube (via youtube-transcript-api Python
      atau library PHP, simpan ke `transcripts` table)
   c. Kirim transcript ke Gemini API dengan prompt:

      "Kamu adalah editor TikTok profesional. Berikut transcript
       video YouTube dengan timestamp. Pilih [N] bagian terbaik
       untuk dijadikan konten TikTok viral berdurasi [min]–[max]
       detik. Pertimbangkan: momen mengejutkan, insight kuat,
       emosi tinggi, atau informasi yang bermanfaat.
       
       Untuk setiap clip berikan JSON:
       {
         ranking: int,
         start_seconds: float,
         end_seconds: float,
         topic: string,
         reason: string,
         viral_potential: 'tinggi'|'sedang'|'rendah',
         hook_suggestion: string (jika hook AI diaktifkan),
         subtitle_segments: [
           { start: float, end: float, text: string }
         ]
       }"

4. Simpan hasil ke table `generated_clips`:
   id, clip_project_id, ranking, start_seconds, end_seconds,
   topic, reason, viral_potential, hook_text, status

### STEP 7 — Pilih & Render Clip

Tampilkan daftar clip yang di-generate dalam bentuk card:
- Nomor ranking + badge potensi viral
- Timestamp (MM:SS → MM:SS), durasi
- Topik dan alasan AI
- Tombol "Pilih Clip Ini"

Saat user memilih clip, dispatch Job `RenderClipJob` yang:

1. Cut video dengan FFmpeg sesuai start_seconds & end_seconds
2. Terapkan layout yang dipilih (filter FFmpeg sesuai tipe)
3. Jika subtitle ON:
   - Buat file .ass atau .srt dari subtitle_segments
   - Karaoke-style: gunakan FFmpeg subtitle filter dengan
     highlight warna berbeda per kata (ASS format mendukung ini
     dengan tag {\k} untuk karaoke timing)
   - Burn subtitle ke video dengan FFmpeg subtitles filter
4. Jika hook ON:
   - Buat video hook pendek (slate hitam + teks) dengan FFmpeg
     drawtext filter atau overlay image
   - Concat hook + video utama dengan FFmpeg concat filter
5. Output: MP4 H.264, audio AAC, resolusi 1080x1920
6. Simpan path ke `generated_clips.output_path`
7. Update status → 'done'

─────────────────────────────────────────────────────────────
## 3. HALAMAN HASIL (Result Page)
─────────────────────────────────────────────────────────────

Setelah render selesai, tampilkan halaman hasil dengan section:

### A. Preview (Phone Frame)
- Tampilkan video dalam frame berbentuk smartphone (CSS)
- Video bisa diplay langsung di browser
- Ukuran preview: lebar ~320px dengan aspek rasio 9:16

### B. Hook Pembuka
- Tampilkan teks hook yang digunakan
- Badge durasi tampil
- Tombol "Edit Hook" → buka modal edit, re-render jika diubah

### C. Subtitle Preview
- Tampilkan daftar subtitle dengan timestamp
- Highlight kata aktif ditunjukkan dengan warna berbeda
- Format: [MM:SS → MM:SS] teks subtitle

### D. Caption & Hashtag
- Teks caption siap copy untuk TikTok
- Hashtag yang relevan (generate dari Gemini)
- Tombol copy ke clipboard

### E. Tombol Download
- Tombol "Download MP4" → download file hasil render
- Tombol "Pilih Clip Lain" → kembali ke step 7

─────────────────────────────────────────────────────────────
## 4. DATABASE SCHEMA LENGKAP
─────────────────────────────────────────────────────────────

users
  id, name, email, password, avatar, remember_token, timestamps

clip_projects
  id, user_id (FK), youtube_url, video_title, duration_seconds,
  thumbnail_url, layout_type (enum: reframe|gaussian),
  clip_count (1|3|5), duration_mode (auto|manual),
  min_duration, max_duration, status (draft|processing|done|failed),
  timestamps

subtitle_settings
  id, clip_project_id (FK), enabled (bool), font_family,
  font_size, text_color, highlight_color, position
  (top|center|bottom), background_style (none|semi|full), timestamps

hook_settings
  id, clip_project_id (FK), enabled (bool), hook_text,
  is_ai_generated (bool), duration_seconds, position
  (top|center|bottom), text_color, background_style, timestamps

transcripts
  id, clip_project_id (FK), language, content (text JSON), timestamps

generated_clips
  id, clip_project_id (FK), ranking, start_seconds, end_seconds,
  topic, reason, viral_potential (tinggi|sedang|rendah),
  hook_text, subtitle_json (JSON), output_path, status
  (pending|processing|done|failed), timestamps

─────────────────────────────────────────────────────────────
## 5. STRUKTUR FOLDER LARAVEL
─────────────────────────────────────────────────────────────

app/
  Http/Controllers/
    Auth/          → RegisterController, LoginController
    ProjectController.php   → CRUD clip_projects
    ClipController.php      → pilih clip, trigger render
    DownloadController.php  → serve file hasil render
  Jobs/
    AnalyzeVideoJob.php     → download + transcript + Gemini
    RenderClipJob.php       → FFmpeg render pipeline
  Services/
    GeminiService.php       → wrapper Gemini API call
    FFmpegService.php       → wrapper semua FFmpeg command
    YtDlpService.php        → wrapper yt-dlp CLI
    TranscriptService.php   → ambil & parse transcript YouTube
  Models/
    User, ClipProject, SubtitleSetting, HookSetting,
    Transcript, GeneratedClip

resources/views/
  auth/           → login.blade.php, register.blade.php
  projects/
    wizard.blade.php        → halaman wizard step 1–7
    result.blade.php        → halaman hasil
  components/
    phone-frame.blade.php   → frame smartphone untuk preview
    clip-card.blade.php     → card clip hasil AI
    step-indicator.blade.php

─────────────────────────────────────────────────────────────
## 6. FFMPEG COMMAND REFERENCE PER LAYOUT
─────────────────────────────────────────────────────────────

GAUSSIAN:
ffmpeg -i input.mp4 \
  -filter_complex \
  "[0:v]scale=1080:1920,boxblur=20:20[bg];
   [0:v]scale=-1:607[fg];
   [bg][fg]overlay=(W-w)/2:(H-h)/2[out]" \
  -map "[out]" -map 0:a output.mp4

REFRAME (center crop):
ffmpeg -i input.mp4 \
  -vf "crop=607:1080:(iw-607)/2:0,scale=1080:1920" \
  output.mp4

SUBTITLE KARAOKE (ASS format):
- Gunakan format ASS dengan tag {\k<durasi>} untuk highlight
  per kata secara berurutan
- Contoh baris ASS:
  Dialogue: 0,0:00:02.00,0:00:05.00,Default,,0,0,0,,
  {\k50}kata {\k60}pertama {\k70}kedua
- Burn ke video:
  ffmpeg -i input.mp4 -vf "ass=subtitle.ass" output.mp4

HOOK CONCAT:
ffmpeg -i hook.mp4 -i main.mp4 \
  -filter_complex "[0:v][0:a][1:v][1:a]concat=n=2:v=1:a=1[v][a]" \
  -map "[v]" -map "[a]" output_final.mp4

─────────────────────────────────────────────────────────────
## 7. ENVIRONMENT VARIABLES (.env)
─────────────────────────────────────────────────────────────

GEMINI_API_KEY=your_key_here
YTDLP_PATH=/usr/local/bin/yt-dlp
FFMPEG_PATH=/usr/bin/ffmpeg
CLIP_STORAGE_PATH=storage/app/clips
TEMP_STORAGE_PATH=storage/app/temp
QUEUE_CONNECTION=database

─────────────────────────────────────────────────────────────
## 8. INSTRUKSI UNTUK AI
─────────────────────────────────────────────────────────────

Saat saya meminta implementasi bagian tertentu, ikuti aturan:

1. Selalu gunakan Laravel best practice (Service class, Job, Model)
2. Semua proses berat (FFmpeg, Gemini, yt-dlp) wajib masuk Queue
3. Alpine.js untuk interaktivitas frontend, hindari jQuery
4. Tailwind CSS untuk semua styling, tidak ada CSS custom kecuali
   benar-benar diperlukan
5. Validasi semua input di FormRequest class
6. Error handling lengkap: jika Job gagal, update status ke 'failed'
   dan tampilkan pesan error yang jelas ke user
7. Semua FFmpeg command jalankan via proc_open atau shell_exec
   dengan sanitasi input (escapeshellarg)
8. File hasil render disimpan di storage/app/clips/{user_id}/{project_id}/
9. Beri response JSON untuk semua AJAX/polling request
10. Kode harus clean, ada komentar di bagian yang kompleks

Mulai dengan bagian yang saya minta, jangan generate semua
sekaligus kecuali diminta.
