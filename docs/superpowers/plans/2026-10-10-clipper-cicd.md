# Clipper CI/CD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Push ke `main` → test di GitHub Actions (PostgreSQL) → deploy otomatis via SSH ke VPS → live di `https://clipper.asfahmidev.com`.

**Architecture:** Image Docker tunggal (nginx + php-fpm + ffmpeg + yt-dlp + whisper-cli) dipakai oleh container `app`, `queue`, `scheduler`; `postgres` terpisah. `deploy.sh` di VPS melakukan build → backup → up → migrate → health check. Nginx host mem-proxy subdomain ke `127.0.0.1:8095`.

**Tech Stack:** Docker Compose v2, PHP 8.3-fpm (Debian), Node 22, PostgreSQL 17, GitHub Actions, Nginx host + certbot webroot.

**Spec:** `docs/superpowers/specs/2026-10-09-clipper-cicd-design.md`

## Global Constraints

- **Tidak push.** Semua file repo di-commit ke branch lokal `ci-cd-setup` di `/root/clipper_studio`; pemilik menarik ke lokal, review, dan push sendiri.
- **Kode app tidak diubah** (`app/`, `routes/`, `config/`, `database/`, `resources/`, `bootstrap/`, `phpunit.xml`). Masalah di sana → catat di `docs/superpowers/feedback/2026-10-10-app-feedback.md` (file, gejala, saran perbaikan).
- Kredensial & `.env` produksi hanya dibuat langsung di VPS (`/srv/clipper/.env`, mode 600), tidak pernah di-commit.
- Port host app: `127.0.0.1:8095`. Domain: `clipper.asfahmidev.com`. Compose project: `clipper`. Image: `clipper-app:<sha>`.
- `queue` `stop_grace_period: 65m`; workflow deploy `timeout-minutes: 80`.
- Backup: 7 dump terakhir di `/srv/clipper/backups`. Image: 3 tag terakhir.
- Perubahan Nginx host: `nginx -t` wajib lolos sebelum reload; file site lain tidak disentuh.

## Review Focus

1. Volume `storage` kosong saat pertama kali di-mount → subfolder Laravel (`framework/cache`, `framework/sessions`, `framework/views`, `logs`, `app/public`) harus dibuat entrypoint, kalau tidak app 500. (Task 1 test: run dengan volume kosong → `/up` 200.)
2. Permission: file yang ditulis `queue` (www-data) harus bisa dibaca `app` dan sebaliknya. (Task 1 test: `touch` sebagai www-data di volume dari dua container.)
3. Deploy pertama: model whisper belum ada, DB kosong, `backups/` belum ada → `deploy.sh` tetap jalan. (Task 4 test.)
4. Deploy ulang dengan SHA yang sama (Re-run) harus idempoten. (Task 4 test.)
5. SIGTERM ke `queue` saat job aktif → worker menyelesaikan job dulu (butuh `pcntl`). (Task 1 test: `php -m | grep pcntl`.)

---

### Task 1: Image Docker produksi

**Files:**
- Create: `docker/Dockerfile`, `docker/nginx.conf`, `docker/supervisord.conf`, `docker/entrypoint.sh`, `.dockerignore`

**Interfaces:**
- Produces: image yang dibangun dengan `docker build -f docker/Dockerfile -t clipper-app:<tag> .`; `WORKDIR /var/www/html`; port 80; default `CMD` = supervisord (nginx + php-fpm); `ENTRYPOINT docker/entrypoint.sh` yang menerima perintah lain (`php artisan queue:work`, dsb.).

- [ ] **Step 1:** Tulis 4 stage sesuai spec §4.1. whisper.cpp dikunci ke tag rilis tetap (cek tag terbaru saat implementasi, catat di Dockerfile). `whisper-cli` → `/usr/local/bin/whisper-cli`. yt-dlp standalone binary versi dikunci → `/usr/local/bin/yt-dlp`; deno → `/usr/local/bin/deno`.
- [ ] **Step 2:** `entrypoint.sh`: `mkdir -p` subfolder storage (Review Focus #1), `chown -R www-data:www-data storage bootstrap/cache`, `php artisan config:cache route:cache view:cache event:cache` (hanya kalau `APP_KEY` ada), `exec "$@"`. `nginx.conf`: root `public/`, `fastcgi_pass 127.0.0.1:9000`, `client_max_body_size 2g`, `fastcgi_read_timeout 300`.
- [ ] **Step 3: Build**
  Run: `docker build -f docker/Dockerfile -t clipper-app:test .`
  Expected: sukses.
- [ ] **Step 4: Verifikasi tools**
  Run: `docker run --rm --entrypoint sh clipper-app:test -c 'ffmpeg -version | head -1 && ffprobe -version | head -1 && yt-dlp --version && deno --version | head -1 && whisper-cli --help >/dev/null && php -m | grep -E "pdo_pgsql|pcntl|intl|zip|Zend OPcache"'`
  Expected: semua baris tampil, exit 0.
- [ ] **Step 5: Verifikasi app dengan volume kosong** (Review Focus #1, #2)
  Run: `docker volume create clipper-test-storage && docker run -d --name ct -p 127.0.0.1:18095:80 -e APP_KEY=base64:$(openssl rand -base64 32) -e APP_ENV=production -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/x.sqlite -v clipper-test-storage:/var/www/html/storage clipper-app:test && sleep 5 && curl -fsS -o /dev/null -w '%{http_code}' http://127.0.0.1:18095/up && docker exec -u www-data ct touch storage/app/perm-check`
  Expected: `200`, touch sukses. Bersihkan: `docker rm -f ct && docker volume rm clipper-test-storage`.
- [ ] **Step 6: Commit** — `git add docker .dockerignore && git commit -m "build: production Docker image"`

### Task 2: Cek kompatibilitas PostgreSQL (tanpa ubah kode)

**Files:**
- Create: `docs/superpowers/feedback/2026-10-10-app-feedback.md`

**Interfaces:**
- Consumes: tidak ada (pakai PHP dari container `php:8.3-cli` sementara atau host PHP 8.3 + `pdo_pgsql`).
- Produces: daftar feedback untuk pemilik; Task 3 memakai hasilnya untuk tahu apakah job `test` di CI akan merah.

- [ ] **Step 1:** Jalankan `postgres:17` sementara (`docker run -d --name pgtest -e POSTGRES_USER=clipper -e POSTGRES_PASSWORD=clipper -e POSTGRES_DB=clipper_test -p 127.0.0.1:15432:5432 postgres:17`).
- [ ] **Step 2:** Di salinan sementara repo (scratchpad, bukan `/root/clipper_studio`): `composer install`, `.env` dari example + key, lalu `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=15432 DB_DATABASE=clipper_test DB_USERNAME=clipper DB_PASSWORD=clipper php artisan migrate:fresh && php artisan test`.
  Expected: catat setiap kegagalan; bandingkan dengan `php artisan test` default (SQLite) untuk memisahkan kegagalan khas Postgres dari yang memang sudah merah.
- [ ] **Step 3:** Tulis feedback (per item: file:line, error, saran perbaikan). Kalau semua hijau, tulis itu.
- [ ] **Step 4:** Bersihkan container & salinan; commit file feedback.

### Task 3: Workflow GitHub Actions

**Files:**
- Create: `.github/workflows/deploy.yml`

**Interfaces:**
- Consumes: secrets `SSH_HOST`, `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`; perintah remote `/srv/clipper/deploy.sh <sha>` (Task 4).

- [ ] **Step 1:** Tulis workflow sesuai spec §4.5 (jobs `test`, `deploy`; nama job tepat seperti itu).
- [ ] **Step 2: Lint**
  Run: `actionlint .github/workflows/deploy.yml` (unduh binary rilis actionlint ke scratchpad).
  Expected: tanpa output, exit 0.
- [ ] **Step 3: Commit** — `git commit -m "ci: test and deploy workflow"`

### Task 4: Compose produksi + `deploy.sh`

**Files:**
- Create: `docker-compose.prod.yml`, `deploy.sh` (executable)
- Modify: `.gitignore` (tambah `/backups`)

**Interfaces:**
- Consumes: image Task 1.
- Produces: `deploy.sh <sha>` — exit 0 = deploy sehat; non-zero = gagal dengan log. Dijalankan dari `/srv/clipper` sebagai `deploy`. Variabel opsional `CLIPPER_DIR` (default `/srv/clipper`), `CLIPPER_PORT` (default `8095`) dan `COMPOSE_PROJECT_NAME` (default `clipper`) supaya bisa dites di direktori lain.

- [ ] **Step 1:** Tulis compose sesuai spec §4.3 (port dari `${CLIPPER_PORT:-8095}`), `deploy.sh` sesuai spec §4.4.
- [ ] **Step 2: Lint** — `shellcheck deploy.sh docker/entrypoint.sh` → bersih; `APP_SHA=x docker compose -f docker-compose.prod.yml config -q` → exit 0.
- [ ] **Step 3: Uji deploy pertama di direktori uji** (Review Focus #3)
  Run: clone branch ke `/tmp/.../clipper-dryrun`, buat `.env` uji (DB pgsql acak, `APP_KEY` baru), jalankan `CLIPPER_DIR=<dir> CLIPPER_PORT=18095 COMPOSE_PROJECT_NAME=clipper-dryrun ./deploy.sh $(git rev-parse HEAD)`.
  Expected: exit 0; `curl 127.0.0.1:18095/up` = 200; `docker compose ps` 4 service running; model `ggml-base.bin` ada; 1 file di `backups/`.
- [ ] **Step 4: Uji idempoten** (Review Focus #4) — jalankan perintah yang sama lagi → exit 0, 2 file backup.
- [ ] **Step 5: Uji gagal health** — kosongkan `APP_KEY` di `.env` uji, jalankan `deploy.sh` lagi → expected exit non-zero dan 50 baris log app tampil. Kembalikan `APP_KEY`.
- [ ] **Step 6:** Bersihkan: `docker compose -p clipper-dryrun down -v`, hapus direktori uji.
- [ ] **Step 7: Commit** — `git commit -m "deploy: compose and deploy script"`

### Task 5: Setup VPS (tanpa file repo)

Mengubah server; tidak ada commit.

- [ ] **Step 1:** User `deploy` + grup docker + keypair `ed25519` + `authorized_keys` dengan `restrict` (spec §5.1–2). Simpan private key sementara di scratchpad untuk diserahkan ke pemilik.
- [ ] **Step 2:** Verifikasi: `ssh -i <key> deploy@127.0.0.1 'docker ps >/dev/null && echo ok'` → `ok`; `ssh ... -L 1:localhost:1` ditolak (restrict).
- [ ] **Step 3:** `/srv/clipper` clone HTTPS (branch `main`), owner deploy; `.env` produksi (spec §5.4), mode 600.
- [ ] **Step 4:** Site Nginx `clipper` (spec §5.5) awalnya HTTP-only dengan `location /.well-known/acme-challenge/` menuju webroot yang sama dengan konfigurasi renewal yang ada; `nginx -t && systemctl reload nginx`.
- [ ] **Step 5:** `certbot certonly --webroot -w <webroot> -d clipper.asfahmidev.com`; tambah blok 443 + redirect; `nginx -t && reload`.
- [ ] **Step 6:** Verifikasi situs lain: `curl -so /dev/null -w '%{http_code}' https://tldraw.asfahmidev.com` dan omniroute → kode sama seperti sebelum perubahan (catat sebelum Step 4).

### Task 6: Dokumentasi belajar

**Files:**
- Create: `docs/DEPLOYMENT.md`

- [ ] **Step 1:** Tulis (Bahasa Indonesia): alur CI/CD dengan diagram dari spec, isi tiap file & alasannya, cara menambah 3 GitHub Secrets, cara deploy manual & rollback, restore backup (`gunzip -c f.sql.gz | docker compose -p clipper exec -T postgres psql -U ...`), unggah `cookies.txt` YouTube, peringatan `down -v`, cara update yt-dlp (rebuild).
- [ ] **Step 2: Commit** — `git commit -m "docs: deployment guide"`

### Task 7: Serah terima & deploy pertama (bersama pemilik)

- [ ] **Step 1:** Beri pemilik: cara menarik branch (`git fetch root@46.250.228.35:/root/clipper_studio ci-cd-setup:ci-cd-setup`), isi 3 secret, isi feedback Task 2.
- [ ] **Step 2:** Pemilik push branch + buka PR → job `test` jalan (hijau, atau merah sesuai feedback Task 2).
- [ ] **Step 3:** Setelah pemilik merge ke `main` → job `deploy` hijau.
- [ ] **Step 4: Verifikasi akhir** (spec §9): `curl -fsS https://clipper.asfahmidev.com/up` = 200; `docker compose -p clipper ps` 4 service; tools di container; situs lain tetap normal; pemilik push perubahan teks kecil → terlihat live.
