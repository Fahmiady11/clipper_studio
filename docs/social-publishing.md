# Publish multi-platform

Clip yang sudah dirender bisa dikirim ke TikTok, Instagram Reels dan YouTube Shorts. Ketiganya memakai kontrak yang sama, `App\Services\Social\SocialPublisher`:

| Method | Isi |
|---|---|
| `isConfigured()` | Kredensial OAuth ada di `.env` |
| `connectUrl()` / `authorizeUrl($state)` | Memulai OAuth, dan layar izin penyedia |
| `callback($userId, $code)` | Menukar kode, menyimpan akun |
| `accounts()` / `disconnect()` | Daftar akun milik user, dan memutuskan |
| `publish($clip, $accountId, $mode, $options)` | Menandai `queued` dan memasang job upload |
| `status($clip)` | `queued`, `uploading`, `processing`, `inbox`, `published` atau `failed`, plus error dan URL |

`SocialPublishers` memegang ketiga implementasi. Halaman `/settings/connections` dibangun dari daftar itu, jadi platform baru muncul di sana tanpa mengubah view.

## Di mana akun disimpan

- Instagram: tabel `social_accounts` (`user_id`, `platform`, `external_id`, token terenkripsi, `access_expires_at`, ...). Status per clip ada di kolom `instagram_*` pada `generated_clips`.
- TikTok dan YouTube: tetap di `tiktok_accounts` dan `youtube_accounts`, dibungkus `TikTokPublisher` dan `YouTubePublisher`. Tidak dipindah karena redirect URI-nya sudah terdaftar di provider dan verifikasi kepemilikan channel YouTube membaca `youtube_accounts`. Memindahkannya adalah langkah terpisah.

## Instagram Reels

Memakai "Instagram API with Instagram Login" (akun Business atau Creator, tanpa Facebook Page). Setup di developers.facebook.com: tambahkan produk Instagram, isi `INSTAGRAM_APP_ID`, `INSTAGRAM_APP_SECRET`, dan daftarkan `<APP_URL>/instagram/callback` sebagai Valid OAuth Redirect URI. Scope: `instagram_business_basic`, `instagram_business_content_publish`.

Alur upload (`UploadToInstagramJob`):

1. Cek sisa kuota 24 jam (`content_publishing_limit`). Habis berarti gagal sebelum upload.
2. Buat container `REELS` dengan `upload_type=resumable`, lalu kirim file ke URL upload. Server tidak perlu URL publik.
3. Tanya status container dengan jeda 3, 5, 8, 12, 20, lalu 30 detik (sekitar 7 menit total) sampai `FINISHED`. `ERROR` dan `EXPIRED` menggagalkan clip dengan pesan Instagram.
4. `media_publish`, lalu ambil permalink.

Rate limit dan retry:

- Route kirim dibatasi `throttle:10,1`.
- Panggilan yang aman diulang (token, status, container, upload) dicoba ulang sampai 4 kali dengan jeda 1, 3, 9 detik pada 429, 5xx dan gagal koneksi. Error 4xx lain tidak diulang.
- `media_publish` tidak pernah diulang, dan job `tries = 1`, karena jawaban yang hilang bisa berarti Reel sudah tayang.
- Token berumur 60 hari diperpanjang otomatis pada 7 hari terakhir. Yang sudah kedaluwarsa meminta user menghubungkan ulang.

Endpoint Instagram belum diuji ke server Meta asli (sandbox pengembangan tidak bisa menjangkaunya). Semua tes memakai `Http::fake`. Periksa nama field dan versi Graph API (`v21.0`) saat app Meta pertama kali dihubungkan.
