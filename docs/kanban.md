# 📋 Kanban — Jalan Menuju Rilis Publik

> Disusun dari sesi grill-with-docs (brainstorming kelanjutan Clipper Studio).
> Prioritas: **P0** = harus sebelum publik · **P1** = segera · **P2** = fase 2 · **P3** = nanti.
> Labels: area (`ui` `render` `ai` `integrasi` `api` `infra`) × jenis (`quick-win` `feature` `legal` `monetisasi` `agentic`).

---

## ✅ DONE — Mesin produksi (sudah ada di kode)

| Fitur | Bukti |
|---|---|
| Face-tracking crop | `app/Services/FaceTracker.php` — `plan()` ikut wajah + speaker two-shot |
| AI B-roll | `app/Services/StockFootage.php` + `BrollCues` + `broll_json` |
| Silence/filler removal | `app/Services/SmartCut.php` — jump cuts + punch-in |
| Seleksi momen viral | Prompt Gemini `viral_score ≥ 7` + kriteria hook/flow |
| Autopilot discovery | Pantau channel/keyword YouTube → antrian review |
| TikTok publish | Draft/inbox + direct post (`TikTokService.php`) |
| Musik mood + kredit CC | `MusicService.php` |

---

## 🎯 READY — Rilis publik pertama

### 🃏 Card 1 — Tampilkan Virality Score per Klip
- **Priority:** P0
- **Labels:** `quick-win` `ai` `ui` `phase-1`
- **Prompt:**
  > Persist skor viral ke database dan tampilkan ke user. 1) Tambah kolom `viral_score` (int 0–10) dan `viral_reasons` (json: hook/flow/value) ke `generated_clips`; isi saat `AnalyzeVideoJob`/`GeminiService` memilih segmen — prompt sudah menghitung `viral_score ≥ 7`, tinggal minta Gemini mengembalikan angkanya secara eksplisit lalu simpan. 2) Tampilkan badge skor + alasan singkat di halaman Studio saat memilih klip (mis. "8/10 — hook kuat, flow lancar"). 3) Urutkan daftar klip berdasarkan skor. 4) Tulis test unit untuk mapping skor.

### 🃏 Card 2 — Brand Kit
- **Priority:** P1
- **Labels:** `feature` `ui` `render` `phase-1`
- **Prompt:**
  > Buat fitur Brand Kit per user. 1) Migration `brand_kits` (user_id, logo_path, primary_color, secondary_color, font_family, hook_style default). 2) Halaman pengaturan: upload logo, pilih warna & font (font sudah tersedia di `resources/fonts` — lihat `VideoLayoutService::resolveFontByFamily`). 3) Integrasi sebagai nilai default saat membuat project: logo → watermark/title, warna → warna kata penting di hook & subtitle, font → font subtitle/hook (`VideoLayoutService`, `HookRenderer`). 4) Per-project tetap bisa override. 5) Test: render sekali dengan kit, verifikasi filter drawtext memakai font & warna kit.

### 🃏 Card 3 — Publish Multi-Platform (Reels + YouTube Shorts)
- **Priority:** P1
- **Labels:** `integrasi` `publishing` `phase-1`
- **Status:** Dikerjakan 2026-11-02. Lihat `docs/social-publishing.md`.
- **Prompt:**
  > Generalisasi pola `TikTokService` ke Instagram Reels (Instagram Graph API) dan YouTube Shorts (YouTube Data API v3). 1) Abstraksi `SocialPublisher` interface: `isConfigured()`, `authorizeUrl()`, `callback()`, `publish($clip, $mode)`. 2) Migration `social_accounts` (user_id, platform, token, expires_at) menggantikan tabel khusus TikTok. 3) Alur connect OAuth per platform di halaman pengaturan. 4) Di hasil render: tombol publish per platform + status per platform (`queued/published/failed`). 5) Rate-limit & retry dengan backoff ala TikTok. 6) Test feature untuk callback dan status.

---

## 📥 BACKLOG — Fase 2

### 🃏 Card 4 — Analytics Loop (Belajar dari Performa)
- **Priority:** P2
- **Labels:** `ai` `integrasi` `phase-2`
- **Prompt:**
  > Tarik statistik performa klip yang sudah dipublish (views/likes via TikTok Display API, IG Insights, YouTube Analytics) per klip; simpan di tabel `clip_stats` (cron/jadwal). Tampilkan ringkasan di halaman Autopilot. Gunakan data itu untuk menyesuaikan bobot kriteria viral di prompt Gemini (hook/flow/value) agar Autopilot makin akurat memilih momen.

### 🃏 Card 5 — Dubbing / Translate Multi-Bahasa
- **Priority:** P2
- **Labels:** `ai` `render` `phase-2`
- **Prompt:**
  > Tambah opsi bahasa target per klip: terjemahkan transcript via Gemini, generate voice-over TTS (mulai dari provider TTS murah), lalu mux audio dubbing menggantikan/mencampur audio asli (`FFmpegService::mixMusic` sebagai referensi mixing). Subtitle ikut pakai terjemahan dengan timing kata yang sama. Toggle per project: subtitle-only, dubbing, atau keduanya.

### 🃏 Card 6 — REST API + MCP Server
- **Priority:** P2
- **Labels:** `api` `agentic` `phase-2`
- **Prompt:**
  > Buka Clipper untuk agent AI. 1) REST API dengan token auth: buat project, cek status, daftar klip, render, download. 2) MCP server dengan tools (`create_project`, `get_status`, `list_clips`, `render_clip`, `publish`) mengikuti pola Klap. 3) Dokumentasi + contoh pemakaian dari Claude/Codex. Ini diferensiator: Clipper jadi infrastruktur yang bisa dipanggil agent, bukan hanya dashboard.

### 🃏 Card 7 — Content Calendar & Scheduling
- **Priority:** P3
- **Labels:** `ui` `publishing` `phase-2`
- **Prompt:**
  > Setelah multi-platform jalan (Card 3): halaman kalender untuk menjadwalkan publish klip (tanggal + jam + platform), queue worker yang mengeksekusi tepat waktu, badge status terjadwal di Library.

---

## ⛔ BLOCKED — Menunggu keputusan owner (sesi grill masih terbuka)

### 🃏 Card 8 — Verifikasi Kepemilikan Channel (Legalitas Sumber Video)
- **Priority:** P0 (hambatan rilis publik)
- **Labels:** `legal` `integrasi` `blocked:Q3`
- **Prompt:**
  > Riset dan tulis rekomendasi (1 halaman di `docs/`): opsi memastikan user hanya mengolah video miliknya sendiri — verifikasi kepemilikan channel via OAuth YouTube (scope tambahan), batasi Autopilot ke channel terverifikasi, fallback untuk upload file langsung. Sertakan risiko ToS & biaya implementasi tiap opsi. **Blocked oleh Q3 (keputusan legalitas sumber video).**

### 🃏 Card 9 — Billing & Kuota Render
- **Priority:** P0 (hambatan monetisasi)
- **Labels:** `monetisasi` `infra` `blocked:Q2`
- **Prompt:**
  > Riset dan tulis rekomendasi: model freemium vs pay-per-render vs kredit (refrensi harga: OpusClip $29/bln 300 menit, Klap $39/bln 300 klip). Hitung biaya per render (CPU FFmpeg + panggilan Gemini/Groq) dari log server. Rancang tabel kuota + metering. **Blocked oleh Q2 (keputusan monetisasi).**

### 🃏 Card 10 — Multi-Tenant / Mode Agensi
- **Priority:** P3
- **Labels:** `feature` `monetisasi` `blocked:Q1`
- **Prompt:**
  > Jika target pasar melebar ke agensi: rancang workspace/team (owner + member), isolasi project per workspace, dan pembagian akun sosmed per klien. **Blocked oleh Q1 (keputusan target pengguna).**

### 🃏 Card 11 — Infrastruktur Closed Beta
- **Priority:** P1
- **Labels:** `infra` `blocked:Q5`
- **Prompt:**
  > Siapkan closed beta: limit kuota render per user, timeout job + notifikasi gagal, monitoring beban CPU antrian render (`StorageCleanup` sudah ada sebagai referensi pola), halaman status. **Blocked oleh Q5 (keputusan skala rilis).**

---

## 🔍 Catatan Keputusan Terbuka (dari grill-with-docs)

| Q | Keputusan | Status |
|---|---|---|
| Q1 | Target pengguna (creator / agensi / internal) | ❓ Menunggu jawaban |
| Q2 | Monetisasi (freemium / kredit / open-source) | ❓ Menunggu jawaban |
| Q3 | Legalitas sumber video (milik sendiri / bebas) | ❓ Menunggu jawaban |
| Q5 | Skala rilis (closed beta / public langsung) | ❓ Menunggu jawaban |
