# REST API & MCP Server

Clipper bisa dipanggil agent AI (Claude, Codex) atau skrip: buat project dari link YouTube, tunggu analisis, render klip, unduh atau publish.

## Token

```bash
php artisan clipper:token email@kamu.com claude --ability=read --ability=render
```

Token berawalan `clp_`, hanya ditampilkan sekali (yang tersimpan hanya hash-nya). Token milik satu akun: semua project, kuota render, dan akun YouTube/TikTok yang tersambung mengikuti akun itu. Maksimal 10 token per akun (dicek atomik). Buat dan cabut token juga bisa dari halaman **Akses API** di web, atau dari sesi web: `GET/POST /api/tokens` (body `name`, `abilities`), `DELETE /api/tokens/{id}`.

`clipper:token` mencetak token ke stdout saja (pesan lain ke stderr), jadi aman ditangkap: `TOKEN=$(php artisan clipper:token email claude 2>/dev/null)`. Atau `--file=/path/token.txt` menulisnya ke file baru berizin 0600 tanpa mencetak apa pun ke terminal; file yang sudah ada tidak ditimpa.

Semua request memakai header `Authorization: Bearer clp_...`. Batas 120 request/menit per akun; satu panggilan tool MCP dihitung satu kali. Lewat batas: `429` dengan header `Retry-After` (detik). Endpoint tertentu punya batas sendiri (mis. buat project dan upload YouTube 10/menit, render 20/menit) dan balasannya sama.

### Izin token

| Izin | Mencakup |
|---|---|
| `read` | Selalu ikut bila token punya `render` atau `publish`. Semua `GET` kecuali caption: daftar project, status, klip, transkrip, preset, `render-status`, unduh MP4/ZIP, `publish/accounts`, `youtube-status`, `tiktok-status` |
| `render` | Buat project, batalkan/ulangi analisis, potong klip sendiri dari transkrip, render klip, batalkan render, `caption` (memanggil AI bila belum ada) |
| `publish` | Upload ke YouTube dan TikTok |

`render` dan `publish` otomatis menyertakan `read` (agent yang memulai kerja harus bisa memantaunya): `--ability=render` menyimpan `read, render`. Di halaman web, `read` selalu tercentang dan tidak bisa dimatikan. Token read-only tetap tidak bisa render atau publish. Default di halaman web dan `clipper:token`: `read` + `render` (publish harus dicentang/diminta). Token yang dibuat sebelum ada izin mendapat ketiganya. Request tanpa izin yang dibutuhkan dijawab `403` dengan `required_ability`. MCP `tools/list` hanya menampilkan tool yang boleh dipakai token itu; memanggil tool lain tetap `403` (`isError`).

## REST (`/api/v1`)

| Method | Path | Fungsi |
|---|---|---|
| POST | `/projects` | Buat project. Wajib `youtube_url`; opsional `clip_count` (1/3/5, default 3), `layout_type` (`reframe`/`gaussian`/`gameplay`; `gameplay` hanya jika pemilik token sudah mengunggah footage di Pengaturan > Footage gameplay, kalau belum jawabannya 422), `duration_mode`, `min_duration`, `max_duration`, `instruction`, `preset_id`, dan pengaturan tampilan seperti di Studio (`subtitle{}`, `hook{}`, `music{}`, `edit{}`) |
| GET | `/projects` | Riwayat project |
| GET | `/projects/{id}/status` | Progres analisis + klip, plus `instruction` |
| GET | `/projects/{id}/clips` | Klip berurut ranking: skor viral, hook, status render, `download_url`, status publish, `manual` (dipotong sendiri), plus `instruction` project |
| GET | `/projects/{id}/transcript` | Transkrip lengkap sebagai baris `{start, end, text}` (detik), untuk memilih rentang klip sendiri |
| POST | `/projects/{id}/clips` | Potong klip sendiri dari project yang analisisnya `done`. Wajib `start_seconds`, `end_seconds`; opsional `render` (default `true`). Awal dan akhir dirapikan ke batas kalimat, panjang 3 detik sampai 5 menit. AI menulis judul, hook dan skor, lalu klip dirender (memakai kuota; `402` bila habis). Balasan `201` berisi `clip` (`id`, `status: processing`); pantau dengan `render-status`. `render: false` hanya menulis judul dan hook. Maksimal 30 klip per project; project autopilot tidak bisa (`404`) |
| GET | `/presets` | Preset tampilan yang bisa dipakai: bawaan dan milik akun, `default_id`, tiap preset dengan `settings` siap pakai |
| POST | `/projects/{id}/cancel`, `/retry` | Batalkan / ulangi analisis |
| GET | `/projects/{id}/download` | Semua klip yang selesai dalam satu ZIP |
| POST | `/clips/{id}/render` | Render klip (memakai kuota). Body opsional `hook_text` |
| GET | `/clips/{id}/render-status` | `pending` / `processing` / `done` / `failed` + `download_url` |
| POST | `/clips/{id}/cancel` | Batalkan render |
| GET | `/clips/{id}/caption` | Caption + hashtag AI |
| GET | `/clips/{id}/download` | File MP4 |
| GET | `/publish/accounts` | Akun YouTube dan TikTok yang tersambung |
| POST | `/clips/{id}/youtube` | `account_id`, `title`, `privacy`, opsional `description`, `tags` |
| POST | `/clips/{id}/tiktok` | `account_id`, opsional `mode` (`inbox` default / `direct`). Mode `direct` wajib `privacy_level` (`PUBLIC_TO_EVERYONE`, `MUTUAL_FOLLOW_FRIENDS`, `FOLLOWER_OF_CREATOR`, `SELF_ONLY`), tetapi selama app TikTok belum lolos audit nilainya dipaksa jadi `SELF_ONLY`, dan konten branded ditolak. Mode `inbox` masuk sebagai draf di app TikTok |
| GET | `/clips/{id}/youtube-status`, `/tiktok-status` | Status upload |

### Instruksi untuk AI (`instruction`)

Teks bebas, maksimal 300 karakter, mis. `"ambil bagian tentang modal usaha"`, `"yang lucu-lucu"`, `"maks 30 detik"`. AI memilih klip yang sesuai instruksi itu dulu, baru kriteria viral. Panjang yang ditulis di instruksi (`maks 30 detik`, `di bawah 1 menit`, `minimal 20 detik`, `15-45 detik`, `sekitar 30 detik`, juga `under 1 minute`, `at least 20 seconds`) dibaca server: AI diminta rentang itu (menggantikan `duration_mode`/`min_duration`/`max_duration`), lalu klip yang masih kepanjangan dipotong di akhir kalimat (atau kata) terakhir yang muat, yang kependekan diperpanjang sampai akhir kalimat berikutnya, dan klip yang tidak bisa dibuat pas dibuang (jadi jumlah klip bisa kurang dari `clip_count`). Klip yang dipotong sendiri lewat `POST /projects/{id}/clips` tidak diubah; `GET /projects/{id}/transcript` mengembalikan `length_limits` sebagai petunjuk. Kalau momen yang cocok kurang dari `clip_count`, sisanya diisi yang paling mendekati dan alasannya ditulis di `reason`. Kosong atau tidak dikirim = perilaku lama (momen paling berpotensi viral). Instruksi tersimpan di project, ikut dipakai saat `retry`, dan juga dipakai AI saat menulis judul/hook klip yang dipotong sendiri.

### Efek suara (`edit.sfx`, `edit.sfx_style`)

`edit.sfx: true` menyalakan efek suara (default mati). Suara hanya menandai yang terlihat: whoosh saat B-roll masuk, pop saat emoji muncul, bunyi pendek di punchline; potongan dan zoom tanpa suara. `edit.sfx_style` memilih gayanya:

- `subtle` (Halus, default): paling banyak 4 suara per klip, minimal 4 detik antar suara, pelan di bawah suara pembicara.
- `energetic` (Energik): sampai 7 suara, minimal 2,5 detik antar suara, lebih keras, plus whoosh saat hook selesai.

Nilai lain dijawab `422`. Lewat MCP: argumen `edit` di `create_project`.

```bash
curl -X POST $CLIPPER/api/v1/projects -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"youtube_url":"https://youtu.be/VIDEO_ID","edit":{"sfx":true,"sfx_style":"energetic"}}'
```

### Preset tampilan (`preset_id`)

Preset menyimpan layout, efek (potong jeda, zoom, progress bar, judul atas, b-roll, efek suara dan gayanya), subtitle, gaya hook (bukan teks hook), musik, logo, dan apakah font/warna brand kit ikut dipakai. Jumlah klip, durasi dan instruksi tidak termasuk. Ada empat preset bawaan (Podcast bersih, Energik, Minimalis, Brand kit; efek suara hanya aktif di Energik, dengan gaya `energetic`); preset sendiri dibuat dan dikelola dari web (Studio langkah 5, Pengaturan > Preset tampilan).

Lewat token hanya bisa dibaca. Dari sesi web: `GET/POST /api/presets` (body `name`, `settings`), `PATCH /api/presets/{id}` (`name` dan/atau `settings`), `DELETE /api/presets/{id}`, `PUT /api/presets/default` (`preset_id`, `null` = tanpa default). Preset bawaan tidak bisa diubah atau dihapus (`403`); maksimal 20 preset per akun.

Urutan pada `POST /projects`: nilai yang dikirim di request > preset > default. Tanpa `preset_id`, preset default akun dipakai bila ada. Hook dari preset selalu ditulis AI per klip. `preset_id` yang bukan bawaan atau milik akun ini: `422`.

```bash
curl $CLIPPER/api/v1/presets -H "Authorization: Bearer $TOKEN"
curl -X POST $CLIPPER/api/v1/projects -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"youtube_url":"https://youtu.be/VIDEO_ID","preset_id":2,"instruction":"ambil bagian tentang modal usaha"}'

# Klip sendiri: lihat transkrip, lalu potong 01:30 sampai 02:05
curl $CLIPPER/api/v1/projects/PROJECT_ID/transcript -H "Authorization: Bearer $TOKEN"
curl -X POST $CLIPPER/api/v1/projects/PROJECT_ID/clips -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"start_seconds":90,"end_seconds":125}'
```

Error: `401` token salah, `403` akun belum disetujui, bukan pemilik, atau token tanpa izin yang dibutuhkan, `402` kuota render habis, `422` validasi, `429` terlalu banyak request (lihat `Retry-After`).

```bash
export CLIPPER=https://clipper.example.com
export TOKEN=clp_...

curl -X POST $CLIPPER/api/v1/projects -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"youtube_url":"https://youtu.be/VIDEO_ID","clip_count":3}'
# -> {"project_id":"...","status":"processing"}

curl $CLIPPER/api/v1/projects/PROJECT_ID/clips -H "Authorization: Bearer $TOKEN"
curl -X POST $CLIPPER/api/v1/clips/CLIP_ID/render -H "Authorization: Bearer $TOKEN"
curl -o clip.mp4 $CLIPPER/api/v1/clips/CLIP_ID/download -H "Authorization: Bearer $TOKEN"
```

## MCP

Endpoint `POST /api/v1/mcp` (Streamable HTTP, balasan JSON, tanpa sesi). Setiap tool memanggil REST di atas sebagai pemilik token, jadi kuota dan aturan kepemilikan sama.

| Tool | Fungsi |
|---|---|
| `create_project` (render) | Mulai analisis video (`youtube_url`, opsional `clip_count`, `layout_type`, `instruction`, `preset_id`, ...) |
| `list_presets` (read) | Preset tampilan dan `default_id`, untuk `preset_id` |
| `get_status` (read) | Progres project + klip |
| `list_clips` (read) | Klip, skor viral, `download_url`, status publish |
| `get_transcript` (read) | Transkrip project sebagai baris berwaktu |
| `create_clip` (render) | Potong klip sendiri (`project_id`, `start_seconds`, `end_seconds`, opsional `render`); AI menulis judul dan hook, lalu dirender |
| `render_clip` (render) | Render klip (opsional `hook_text`) |
| `get_render_status` (read) | Status render satu klip |
| `list_publish_accounts` (read) | `account_id` YouTube/TikTok |
| `publish` (publish) | Upload klip ke `youtube` atau `tiktok`. TikTok `mode=direct` wajib `privacy_level` (enum, lihat tabel REST) |

Alur khas agent: `create_project` -> ulangi `get_status` sampai `done` -> `list_clips` -> `render_clip` -> ulangi `get_render_status` sampai `done` -> `publish`. Momen yang tidak dipilih AI: `get_transcript` -> `create_clip` -> `get_render_status`.

Satu request batch JSON-RPC maksimal 20 pesan.

Tool tidak mengirim byte video. Ambil MP4 lewat `download_url` dengan token yang sama.

### Claude Code

```bash
# token perlu izin publish bila agent boleh upload
claude mcp add --transport http clipper https://clipper.example.com/api/v1/mcp \
  --header "Authorization: Bearer clp_..."
```

Lalu: "Buat 3 klip dari https://youtu.be/VIDEO_ID, render yang skor viralnya tertinggi, dan upload ke YouTube Shorts sebagai unlisted."

### Codex

`~/.codex/config.toml`:

```toml
[mcp_servers.clipper]
url = "https://clipper.example.com/api/v1/mcp"
bearer_token_env_var = "CLIPPER_TOKEN"
```

```bash
export CLIPPER_TOKEN=clp_...
codex "Buat klip dari https://youtu.be/VIDEO_ID dan render yang terbaik"
```

### Claude Desktop (via mcp-remote)

```json
{
  "mcpServers": {
    "clipper": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://clipper.example.com/api/v1/mcp",
               "--header", "Authorization: Bearer ${CLIPPER_TOKEN}"],
      "env": { "CLIPPER_TOKEN": "clp_..." }
    }
  }
}
```

### Tanpa MCP

Agent yang bisa menjalankan shell cukup memakai contoh `curl` di atas.
