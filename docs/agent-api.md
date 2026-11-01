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
| `read` | Semua `GET` kecuali caption: daftar project, status, klip, `render-status`, unduh MP4/ZIP, `publish/accounts`, `youtube-status`, `tiktok-status` |
| `render` | Buat project, batalkan/ulangi analisis, render klip, batalkan render, `caption` (memanggil AI bila belum ada) |
| `publish` (publish) | Upload ke YouTube dan TikTok |

Default di halaman web dan `clipper:token`: `read` + `render` (publish harus dicentang/diminta). Token yang dibuat sebelum ada izin mendapat ketiganya. Request tanpa izin yang dibutuhkan dijawab `403` dengan `required_ability`. MCP `tools/list` hanya menampilkan tool yang boleh dipakai token itu; memanggil tool lain tetap `403` (`isError`).

## REST (`/api/v1`)

| Method | Path | Fungsi |
|---|---|---|
| POST | `/projects` | Buat project. Wajib `youtube_url`; opsional `clip_count` (1/3/5, default 3), `layout_type` (`reframe`/`gaussian`), `duration_mode`, `min_duration`, `max_duration` |
| GET | `/projects` | Riwayat project |
| GET | `/projects/{id}/status` | Progres analisis + klip |
| GET | `/projects/{id}/clips` | Klip berurut ranking: skor viral, hook, status render, `download_url`, status publish |
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
| `create_project` (render) | Mulai analisis video (`youtube_url`, opsional `clip_count`, `layout_type`, ...) |
| `get_status` (read) | Progres project + klip |
| `list_clips` (read) | Klip, skor viral, `download_url`, status publish |
| `render_clip` (render) | Render klip (opsional `hook_text`) |
| `get_render_status` (read) | Status render satu klip |
| `list_publish_accounts` (read) | `account_id` YouTube/TikTok |
| `publish` (publish) | Upload klip ke `youtube` atau `tiktok`. TikTok `mode=direct` wajib `privacy_level` (enum, lihat tabel REST) |

Alur khas agent: `create_project` -> ulangi `get_status` sampai `done` -> `list_clips` -> `render_clip` -> ulangi `get_render_status` sampai `done` -> `publish`.

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
