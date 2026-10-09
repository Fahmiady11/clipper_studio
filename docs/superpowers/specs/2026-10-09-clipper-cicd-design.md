# Clipper Studio — CI/CD ke VPS (Design Spec)

Tanggal: 2026-10-09
Status: menunggu review

## 1. Tujuan

Setiap push ke `main` di `Fahmiady11/clipper_studio` otomatis dites di GitHub
Actions, lalu — kalau lolos — di-deploy ke VPS `46.250.228.35` dan live di
`https://clipper.asfahmidev.com`. Tujuan sampingan: pemilik repo belajar CI/CD
dari nol, jadi setiap langkah didokumentasikan di `docs/DEPLOYMENT.md`.

**Kriteria sukses**

1. PR ke `main` menjalankan job `test` (PostgreSQL) dan menampilkan hasilnya.
2. Merge/push ke `main` → job `deploy` berjalan → `curl https://clipper.asfahmidev.com/up` = 200.
3. Perubahan teks kecil yang di-push terlihat live tanpa langkah manual.
4. Deploy gagal (build/health) tidak mematikan versi yang sedang jalan
   (kecuali gagal di migrate/health setelah `up`, lihat §7) dan membuat workflow merah.
5. Job video yang sedang berjalan tidak terpotong oleh deploy.

**Di luar cakupan:** wildcard TLS cert, staging environment, registry image
(GHCR), zero-downtime blue/green, monitoring/alerting, perubahan logika app.

## 2. Keputusan yang sudah disepakati

| Topik | Keputusan |
|---|---|
| Mekanisme deploy | GitHub Actions → SSH → `deploy.sh` di VPS; build image di VPS |
| Domain | `clipper.asfahmidev.com` (wildcard DNS `*` → `46.250.228.35` sudah aktif di Hostinger) |
| TLS | sertifikat per-subdomain via certbot webroot (seperti situs yang ada) |
| Database | PostgreSQL 17 (prod dan CI); test lokal tetap SQLite |
| Job panjang saat deploy | tunggu selesai (`stop_grace_period: 65m`) |
| Secret app | hanya di `/srv/clipper/.env` di VPS, tidak di GitHub |

## 3. Arsitektur

```
git push main
   │
   ▼
GitHub Actions
   job test   : postgres:17 service · composer install · npm ci && build
                · php artisan migrate · php artisan test
   job deploy : needs test · hanya ref main · concurrency deploy-prod
                ssh deploy@46.250.228.35 "/srv/clipper/deploy.sh <sha>"
   │
   ▼
VPS /srv/clipper  (git clone HTTPS, owner: deploy)
   deploy.sh <sha>
   │
   ▼
docker compose -p clipper -f docker-compose.prod.yml
   app        nginx + php-fpm (supervisord)   127.0.0.1:8095 → :80
   queue      php artisan queue:work          stop_grace_period 65m
   scheduler  php artisan schedule:work
   postgres   postgres:17, tanpa port host
   │
   ▼
Nginx host  clipper.asfahmidev.com:443 → http://127.0.0.1:8095
```

`app`, `queue`, dan `scheduler` memakai image yang sama: `clipper-app:<sha>`.

## 4. File yang ditambahkan ke repo

Tidak ada perubahan pada kode aplikasi (`app/`, `routes/`, `config/`, dsb.).

```
.github/workflows/deploy.yml
docker/Dockerfile
docker/nginx.conf
docker/supervisord.conf
docker/entrypoint.sh
docker-compose.prod.yml
deploy.sh
.dockerignore
docs/DEPLOYMENT.md
```

### 4.1 `docker/Dockerfile` (multi-stage)

1. **assets** — `node:22-alpine`: `npm ci && npm run build` → `public/build`.
2. **vendor** — `composer:2`: `composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader`.
3. **whisper** — build stage `debian:bookworm`: kompilasi `whisper.cpp` (tag rilis
   tetap) → binary `whisper-cli`.
4. **runtime** — `php:8.3-fpm` (Debian, dipilih daripada Alpine supaya
   whisper.cpp dan yt-dlp tidak bermasalah dengan musl):
   - ekstensi: `pdo_pgsql`, `intl`, `zip`, `opcache`, `pcntl` (graceful SIGTERM untuk `queue:work`)
   - paket: `nginx`, `supervisor`, `ffmpeg`, `curl`, `postgresql-client` (untuk `pg_dump`)
   - `yt-dlp` binary standalone di `/usr/local/bin/yt-dlp` + `deno` sebagai JS runtime
     (dibutuhkan yt-dlp modern untuk YouTube)
   - `whisper-cli` dari stage 3 di `/usr/local/bin/whisper-cli` (lokasi yang sudah dicari
     `WhisperTranscriber::findBinary`)
   - salin source + hasil stage 1 & 2; user runtime `www-data`.

Model whisper tidak masuk image. Volume `whisper-models` di-mount ke
`/usr/local/share/whisper-cpp/` (path yang sudah dicari
`WhisperTranscriber::findWhisperModel`). `deploy.sh` mengunduh
`ggml-base.bin` sekali kalau belum ada.

### 4.2 `docker/entrypoint.sh`

Memastikan subfolder `storage/` ada dan dimiliki `www-data`, lalu
`php artisan config:cache route:cache view:cache event:cache`, lalu `exec "$@"`.
Migrasi **tidak** dijalankan di sini (lihat `deploy.sh`), supaya tiga container
tidak migrate bersamaan.

### 4.3 `docker-compose.prod.yml`

- Project name `clipper`; image `clipper-app:${APP_SHA}`.
- `env_file: .env` untuk app/queue/scheduler.
- Volume:
  - `storage` → `/var/www/html/storage` (dipakai bersama app, queue, scheduler)
  - `whisper-models` → `/usr/local/share/whisper-cpp`
  - `pgdata` → `/var/lib/postgresql/data`
- `app`: port `127.0.0.1:8095:80`, healthcheck `curl -fsS http://localhost/up`.
- `queue`: `php artisan queue:work --sleep=3`. `--tries`/`--timeout` sengaja tidak
  di-set di CLI supaya `$timeout`/`$tries` per job tetap berlaku (`retry_after` database
  queue sudah 3700 detik di `config/queue.php`). `stop_grace_period: 65m`,
  `stop_signal: SIGTERM`.
- `scheduler`: `php artisan schedule:work`.
- `postgres`: `postgres:17`, healthcheck `pg_isready`, tanpa `ports`.
- `restart: unless-stopped` untuk semua.

### 4.4 `deploy.sh <sha>` (dijalankan di VPS sebagai `deploy`)

```
set -euo pipefail
cd /srv/clipper
git fetch origin && git checkout --force <sha>
export APP_SHA=<sha>
[ model whisper belum ada ] → unduh ggml-base.bin ke volume
docker compose build app                 # gagal → stop, versi lama tetap jalan
docker compose up -d postgres            # tunggu healthy
pg_dump → backups/<timestamp>-<sha>.sql.gz ; hapus selain 7 terbaru
docker compose up -d app scheduler       # web langsung versi baru
docker compose run --rm app php artisan migrate --force
health check: curl http://127.0.0.1:8095/up, maks 30 detik
             → gagal: tampilkan 50 baris log app, exit 1
docker compose up -d queue               # menunggu job aktif selesai (≤65m)
docker image prune: simpan 3 tag clipper-app terbaru
```

Urutan `migrate` setelah `up app` dipilih karena app sudah butuh image baru;
`/up` di Laravel tidak menyentuh DB sehingga health check tetap valid. Jendela
singkat di mana kode baru jalan sebelum migrate selesai diterima (deploy
belajar, satu instance).

### 4.5 `.github/workflows/deploy.yml`

- `on: push (main)`, `pull_request (main)`, `workflow_dispatch`.
- **test**: `ubuntu-latest`, service `postgres:17` (user/db `clipper`/`clipper_test`),
  `shivammathur/setup-php` (8.3, ext `pdo_pgsql`), `actions/setup-node` (22, cache npm),
  cache composer. Env `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, dsb. — env proses
  menang atas `<env>` di `phpunit.xml` (tanpa `force`), jadi `phpunit.xml` tidak diubah.
  Langkah: `composer install`, `cp .env.example .env`, `php artisan key:generate`,
  `npm ci && npm run build`, `php artisan test`.
- **deploy**: `needs: test`, `if: github.ref == 'refs/heads/main' && github.event_name != 'pull_request'`,
  `concurrency: { group: deploy-prod, cancel-in-progress: false }`,
  `timeout-minutes: 80` (grace period queue + build).
  Tulis `SSH_PRIVATE_KEY` & `SSH_KNOWN_HOSTS` ke `~/.ssh`, lalu
  `ssh deploy@$SSH_HOST /srv/clipper/deploy.sh $GITHUB_SHA`.

**GitHub Secrets:** `SSH_HOST`, `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`.

## 5. Setup satu kali di VPS

1. `useradd -m -s /bin/bash deploy`, tambah ke grup `docker`, tanpa sudo/password.
   Catatan: grup `docker` praktis setara root; ini membatasi, bukan mengisolasi.
2. Keypair `ed25519` baru; public key ke `~deploy/.ssh/authorized_keys` dengan
   prefix `restrict`. Private key diberikan ke pemilik untuk GitHub Secret, lalu
   dihapus dari VPS. `SSH_KNOWN_HOSTS` dari `ssh-keyscan 46.250.228.35`.
3. `git clone https://github.com/Fahmiady11/clipper_studio.git /srv/clipper`, owner `deploy`.
4. `/srv/clipper/.env` (mode 600, owner deploy) dari `.env.example` dengan:
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://clipper.asfahmidev.com`,
   `APP_KEY` baru, `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, password DB acak,
   `SESSION_SECURE_COOKIE=true`, `YTDLP_COOKIES_BROWSER=` (kosong),
   `YTDLP_COOKIES=/var/www/html/storage/app/cookies.txt`,
   `TIKTOK_REDIRECT_URI=https://clipper.asfahmidev.com/tiktok/callback`.
   API key (Gemini/Groq/LLM gateway/TikTok) diisi pemilik.
5. `/etc/nginx/sites-available/clipper` + symlink ke `sites-enabled`:
   HTTP→HTTPS redirect, `proxy_pass http://127.0.0.1:8095`, header
   `X-Forwarded-*`, `client_max_body_size 2g`, `proxy_read_timeout 300s`.
   `nginx -t` wajib lolos sebelum `systemctl reload nginx`. Site lain tidak diubah.
6. `certbot certonly --webroot` untuk `clipper.asfahmidev.com`, mengikuti
   konfigurasi renewal yang ada.
7. Laravel `TrustProxies`: set `trustProxies(at: '*')` hanya kalau URL/HTTPS terdeteksi
   salah saat verifikasi — keputusan dibuat saat implementasi berdasarkan perilaku nyata.

## 6. Data & backup

- Data persisten hanya di volume `pgdata`, `storage`, `whisper-models` dan di `.env`.
- `docker compose down` aman; **`down -v` menghapus data** — ditulis jelas di DEPLOYMENT.md.
- Backup `pg_dump` sebelum setiap migrate, 7 terakhir disimpan di `/srv/clipper/backups`
  (di-`.gitignore`). File video di `storage` tidak di-backup (di luar cakupan).

## 7. Error handling & rollback

| Gagal di | Akibat |
|---|---|
| test CI | deploy tidak jalan |
| `docker compose build` | versi lama tetap jalan, workflow merah |
| pg_dump | deploy berhenti sebelum `up`, versi lama tetap jalan |
| migrate | app baru sudah jalan, backup tersedia, workflow merah |
| health `/up` | log ditampilkan, workflow merah; rollback manual |

**Rollback:** jalankan ulang workflow run lama (Re-run) atau
`ssh deploy@VPS /srv/clipper/deploy.sh <sha-lama>`; image 3 terakhir masih ada
sehingga cepat. Restore DB: perintah `gunzip | psql` di DEPLOYMENT.md.

## 8. Risiko yang diketahui

- **Kompatibilitas PostgreSQL** migrasi/query belum pernah dites. Kegagalan di CI
  diperbaiki sebagai bagian dari pekerjaan ini (perubahan kode minimal, dicatat di PR).
- **yt-dlp & YouTube** sering berubah; versi dikunci saat build, update = rebuild.
  Cookies YouTube perlu diunggah manual ke `storage/app/cookies.txt`.
- **Deploy bisa lama** (≤65 menit) kalau ada job video aktif — disengaja.
- **Build pertama lama** (kompilasi whisper.cpp); layer cache Docker mempercepat build berikutnya.

## 9. Verifikasi

1. PR `ci-cd-setup` → job `test` hijau.
2. Setup VPS + deploy manual pertama `deploy.sh <sha>` → `/up` 200 via HTTPS.
3. Merge PR → deploy otomatis hijau.
4. Di container: `ffmpeg -version`, `yt-dlp --version`, `whisper-cli --help`, model ada.
5. `docker compose ps`: app, queue, scheduler, postgres `running/healthy`.
6. Commit perubahan teks kecil → terlihat live.
7. Situs tldraw & omniroute tetap 200 setelah perubahan Nginx.
