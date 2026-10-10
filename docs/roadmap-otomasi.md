# Roadmap: Clipper End-to-End Otomatis

Target: dari mencari video YouTube → potong jadi beberapa klip → subtitle → musik → auto upload TikTok, tanpa langkah manual (dengan opsi approval).

```
[Discovery] → [Download+Transcript] → [AI pilih klip] → [Render: cut+layout+subtitle+hook]
     → [Music] → [QC / Approval] → [Upload TikTok] → [Tracking performa]
```

## Fase 0 — Subtitle akurat (selesai)

Penyebab subtitle kadang cepat / pas / lambat:

1. **Cut tidak presisi** — `ffmpeg -ss … -c copy` mundur ke keyframe sebelumnya (0–5 dtk di video YouTube), jadi klip mulai lebih awal dari `start_seconds` dan subtitle telat dengan selisih berbeda-beda per klip.
   → Cut sekarang digabung ke pass `applyLayout` (re-encode, seek frame-accurate).
2. **Timing per kata ditebak rata** — durasi segmen dibagi rata ke jumlah kata, padahal ada jeda di tengah segmen.
   → Transcript sekarang menyimpan `words: [{start, end, text}]` per segmen (dari `tOffsetMs` caption otomatis YouTube, atau Groq `timestamp_granularities=word`). Cue disusun dari timestamp kata asli; hanya kata yang sedang diucapkan diberi warna highlight. Segmen tanpa `words` (caption manual / transcript lama) tetap pakai cara proporsional.
3. **Bug pembulatan waktu ASS** — `1.996s` jadi `0:00:01.100`. Diperbaiki.

Catatan: project yang transcript-nya sudah tersimpan sebelum perubahan ini tidak punya `words`; buat project baru untuk dapat timing per kata.

Opsional berikutnya: transkripsi ulang per klip + forced alignment (WhisperX / stable-ts) untuk akurasi maksimal.

## Fase 1 — Musik otomatis (selesai)

- Library musik bebas royalti di disk, satu folder per mood:
  ```
  storage/app/music/
    energetic/  chill/  inspiring/  dramatic/  funny/  sad/
  ```
  Format: mp3, m4a, aac, wav, ogg. Lokasi bisa diganti lewat `MUSIC_LIBRARY_PATH` di `.env`.
  Folder mood kosong → diambil dari track mana saja di library. Library kosong → musik dilewati (tercatat di log).
- Gemini mengisi `music_mood` per klip; user bisa pilih "Otomatis (AI)" atau paksa satu mood di langkah 5 studio.
- Track dipilih stabil per klip (render ulang = lagu yang sama), tersebar antar klip.
- `FFmpegService::mixMusic`: musik di-loop, fade in 1 dtk / fade out 1.5 dtk, di-*duck* (~10 dB) saat ada suara orang, video tidak di-encode ulang.
- Hanya pakai lagu yang kamu punya haknya (royalty-free / berlisensi). Sound trending TikTok **tidak bisa** dipasang via API.

Setelah pull: `php artisan migrate`.

## Fase 2 — Upload TikTok (draft / inbox, selesai)

Alur: klip selesai dirender → pilih akun TikTok → **Kirim ke TikTok (draft)** → video masuk inbox/notifikasi app TikTok → tambah sound trending & posting dari app.

Setup:
1. Buat app di developers.tiktok.com, aktifkan **Login Kit** dan **Content Posting API**, scope `user.info.basic` + `video.upload`.
2. TikTok mewajibkan redirect URI **HTTPS**. Di lokal pakai tunnel (mis. `ngrok http 8000` / `cloudflared tunnel`), lalu daftarkan `https://<tunnel>/tiktok/callback` di app TikTok dan buka studio lewat URL tunnel itu juga (supaya cookie login sama).
3. Isi `.env`:
   ```
   TIKTOK_CLIENT_KEY=...
   TIKTOK_CLIENT_SECRET=...
   TIKTOK_REDIRECT_URI=https://<tunnel>/tiktok/callback
   ```
4. Selama app masih Sandbox: tambahkan akun TikTok penguji sebagai *target user* di pengaturan sandbox.
5. `php artisan migrate` lalu `php artisan queue:restart`.

Teknis:
- `TikTokService`: OAuth v2 (`/v2/oauth/token/`), refresh otomatis sebelum access token habis, upload `inbox/video/init` + PUT per chunk (≤64 MB satu chunk, lebih besar dipecah 10 MB), cek `status/fetch`.
- Token disimpan terenkripsi (`tiktok_accounts`), satu user bisa menghubungkan beberapa akun.
- `UploadToTikTokJob` tidak di-retry otomatis (retry = draft dobel). Status: queued → uploading → processing → inbox / failed.
- Endpoint & format API ditulis dari dokumentasi v2 tanpa bisa dicek ulang dari environment ini; kalau TikTok mengembalikan error, pesan lengkapnya tampil di UI & log `clipper_jobs`.

Mode **Posting langsung (Direct Post)** — selesai:
- Aktifkan scope `video.publish` di app TikTok, set `TIKTOK_SCOPES=user.info.basic,video.upload,video.publish` di `.env`, lalu hubungkan ulang akun.
- Form mengikuti syarat TikTok: nama akun dari `creator_info`, pilihan privasi tanpa default, toggle komentar/duet/stitch (mati jika dimatikan creator), caption (diisi dari caption Gemini, bisa diedit), teks persetujuan musik.
- Sebelum app lolos audit TikTok, hanya privasi **Hanya saya** (`SELF_ONLY`) yang diterima.

## Fase 3 — Autopilot: discovery otomatis + antrian review (selesai)

Halaman **/autopilot** (link "Autopilot" di topbar studio):

1. **Sumber video** — tambah *channel* (`@handle` atau URL channel) atau *keyword*. Per sumber: interval cek (jam), maks video per cek, rentang durasi video.
   Tampilan klip (layout, jumlah klip, subtitle, hook, musik) di-*snapshot* dari project manual terakhir saat sumber dibuat; kalau belum ada project, hook ditulis AI.
2. Scheduler (`routes/console.php`, tiap 10 menit) mengirim `DiscoverVideosJob` untuk sumber yang sudah jatuh tempo → `yt-dlp --flat-playlist` (channel: tab `/videos`; keyword: `ytsearch`) → video baru yang lolos filter durasi dibuatkan project.
   Video yang pernah diproses tidak akan diproses lagi (`discovered_videos`, unik per user).
3. Analisis Gemini seperti biasa, lalu **semua klip langsung dirender** dan masuk **Antrian review** (`review_status = pending`).
4. **Approve** → upload ke TikTok (draft/inbox) ke akun yang dipilih. **Reject** → file klip dihapus.

Menjalankan di lokal (selain `php artisan serve`):
```bash
php artisan migrate
php artisan queue:work --timeout=3600   # analisis video panjang bisa lama
php artisan schedule:work               # menjalankan pengecekan sumber berkala
```
"Cek sekarang" di kartu sumber menjalankan pencarian tanpa menunggu jadwal.

Catatan biaya: setiap video = 1 panggilan Gemini + render N klip. Mulai dengan `maks 1 video/cek` dan interval longgar.

## Stabilitas queue & disk (selesai)

- **Job kembali ke awal terus-menerus**: penyebabnya `queue:listen` (dijalankan otomatis oleh RenderController/ClipController) yang mematikan job setelah 60 dtk (default `--timeout`), terlepas dari `$timeout` job. Job yang dimatikan tetap *reserved*, lalu diambil lagi setelah `retry_after` dan mulai dari awal.
  Sekarang: satu trait `EnsuresQueueWorker` (hanya `queue:work --timeout=3600`, tidak menambah worker jika sudah ada), `retry_after` 3700 dtk (> timeout job terlama), `WithoutOverlapping` di AnalyzeVideoJob/RenderClipJob, timeout render 30 menit.
  Jika ada worker `queue:listen` lama yang masih hidup: `pkill -f "artisan queue:listen"` sekali.
- **Pembersihan disk** `php artisan clipper:cleanup` (terjadwal harian 03:17): hapus `storage/app/temp/<project>` yang tidak aktif dan `video_cache` yang tidak tersentuh lebih dari `CLIPPER_TEMP_RETENTION_HOURS` (default 72 jam). Klip hasil render tidak dihapus. Kalau klip lama dirender lagi, video sumber di-download ulang otomatis.

## Fase 4 — Autopilot & tracking

- Auto-approve jika skor di atas threshold.
- Tarik statistik views/likes → umpan balik ke prompt & pemilihan sumber.

## Infrastruktur

- SQLite → MySQL/Postgres (sudah ada workaround `database is locked`).
- Redis + Horizon untuk queue.
- State machine per klip: `discovered → analyzed → rendered → approved → uploaded`, retry per tahap.
