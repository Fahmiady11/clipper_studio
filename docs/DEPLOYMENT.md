# Panduan Deployment (CI/CD) Clipper Studio

Dokumen ini punya dua fungsi: **bahan belajar** CI/CD dari nol, dan **buku operasi** (runbook) untuk produksi.
Situs live: <https://clipper.asfahmidev.com> (VPS `46.250.228.35`).

Istilah singkat:

- **CI** (Continuous Integration): setiap kode baru otomatis dites oleh mesin, bukan hanya "jalan di laptop saya".
- **CD** (Continuous Deployment): kalau tes lolos, kode otomatis dipasang ke server.
- **Workflow / job / step**: GitHub Actions menjalankan *workflow* (file YAML). Isinya *job*, isi job adalah *step*.
- **SHA**: ID unik 40 karakter untuk satu commit git. Dipakai sebagai nama versi image Docker (`clipper-app:<sha>`).

---

## 1. Gambaran besar

```
git push main
   |
   v
GitHub Actions  (.github/workflows/deploy.yml)
   job test   : postgres:17 service, composer install, npm ci && build, php artisan test
   job deploy : needs test, hanya ref main, concurrency deploy-prod
                ssh deploy@46.250.228.35 "/srv/clipper/deploy.sh <sha>"
   |
   v
VPS /srv/clipper  (git clone, owner: deploy)
   deploy.sh <sha>
   |
   v
docker compose -p clipper -f docker-compose.prod.yml
   app        nginx + php-fpm (supervisord)   127.0.0.1:8095 -> :80
   queue      php artisan queue:work          stop_grace_period 65m
   scheduler  php artisan schedule:work
   postgres   postgres:17, tanpa port ke host
   |
   v
Nginx di host  clipper.asfahmidev.com:443 -> http://127.0.0.1:8095
```

`app`, `queue`, dan `scheduler` memakai **image yang sama**: `clipper-app:<sha>`. Image dibangun di VPS (tidak ada registry).

Aturan pemicu:

| Kejadian | Job `test` | Job `deploy` |
|---|---|---|
| Pull request ke `main` | jalan | **tidak** jalan |
| Push / merge ke `main` | jalan | jalan, **hanya jika test hijau** |
| Tombol "Run workflow" (`workflow_dispatch`) | jalan | jalan jika dijalankan dari `main` |

## 2. Alur sekali push

1. Kamu `git push` ke `main` (atau merge PR).
2. GitHub menyalakan mesin Ubuntu sementara dan menjalankan job **test**: pasang PHP 8.4 + Node 22, jalankan PostgreSQL 17 sebagai service, `composer install`, `npm ci && npm run build`, lalu `php artisan test`.
3. Test merah: berhenti. Tidak ada yang berubah di server.
4. Test hijau: job **deploy** menulis kunci SSH dari Secrets ke mesin itu, lalu `ssh deploy@<SSH_HOST> "/srv/clipper/deploy.sh <sha>"`.
5. `deploy.sh` di VPS, berurutan:
   1. cek `.env` ada, `DB_PASSWORD` dan `APP_KEY` terisi;
   2. ambil kunci `flock` (hanya satu deploy sekaligus), `git fetch`, `git checkout --force <sha>`, lalu menjalankan ulang dirinya sendiri dari versi `<sha>`;
   3. unduh model whisper `ggml-base.bin` ke volume kalau belum ada;
   4. `docker compose build app` (dilewati kalau image `clipper-app:<sha>` sudah ada). **Gagal di sini = versi lama tetap jalan.**
   5. jalankan `postgres`, tunggu sehat;
   6. `pg_dump` ke `backups/<waktu>-<sha>.sql.gz`, simpan 7 terbaru. **Gagal = berhenti, versi lama tetap jalan.**
   7. `up -d app scheduler` (web langsung versi baru);
   8. `php artisan migrate --force`;
   9. health check `http://127.0.0.1:8095/up` maksimal 30 detik; gagal = tampilkan 50 baris log `app`, workflow merah;
   10. `up -d queue`, yang me-restart worker dan **menunggu job video yang sedang jalan selesai** (sampai 65 menit);
   11. hapus image lama, simpan 3 tag `clipper-app` terbaru.
6. Workflow hijau = versi baru live.

## 3. Penjelasan tiap file (dan kenapa)

| File | Isi | Kenapa begini |
|---|---|---|
| `.github/workflows/deploy.yml` | Definisi CI/CD di GitHub. Job `test` dan `deploy`. | `deploy` memakai `needs: test` supaya kode yang gagal tes tidak pernah sampai produksi. `concurrency: deploy-prod` + `cancel-in-progress: false` membuat deploy antre, tidak saling memotong. `timeout-minutes: 80` = 65 menit menunggu queue + waktu build. Memakai **PHP 8.4** (bukan 8.3) karena `composer.lock` mensyaratkan PHP >= 8.4.1. |
| `docker/Dockerfile` | Resep image, bertahap (multi-stage): `vendor` (composer), `assets` (Vite), `whisper` (kompilasi whisper.cpp), `runtime` (PHP 8.4-fpm + nginx + supervisor + ffmpeg + yt-dlp + deno + whisper-cli). | Tahap build tidak ikut ke image akhir, jadi image lebih kecil. Versi whisper.cpp, yt-dlp, dan deno dikunci lewat `ARG` di bagian atas file agar build bisa diulang dengan hasil sama. Model whisper **tidak** dimasukkan ke image (besar), tapi ke volume. |
| `docker/entrypoint.sh` | Dijalankan setiap container start: buat folder `storage/`, `chown` ke `www-data`, lalu `config:cache`, `route:cache`, `view:cache`, `event:cache`. | Volume `storage` bisa kosong/milik root saat pertama kali. **Karena config di-cache saat container start, mengubah `.env` baru berlaku setelah container dibuat ulang.** Migrasi sengaja *tidak* di sini supaya 3 container tidak migrate bersamaan. |
| `docker/nginx.conf` | Nginx di dalam container `app`: root `public/`, teruskan `.php` ke php-fpm `127.0.0.1:9000`, upload sampai 2 GB, timeout 300 detik. | Ini Nginx *kedua*, bukan Nginx host. Yang di host hanya reverse proxy + TLS. |
| `docker/supervisord.conf` | Menjalankan `php-fpm` dan `nginx` bersamaan dalam satu container, log ke stdout/stderr. | Docker idealnya satu proses per container; supervisor adalah jalan pintas praktis agar `app` berisi web server lengkap. Log ke stdout supaya terbaca lewat `docker compose logs`. |
| `docker-compose.prod.yml` | Mendefinisikan 4 service (`app`, `queue`, `scheduler`, `postgres`), 3 volume (`storage`, `whisper-models`, `pgdata`), port `127.0.0.1:8095`. Ada service bantu `whisper-model` (profile `tools`) yang hanya dipakai `deploy.sh`. | Port hanya di `127.0.0.1` sehingga tidak terbuka ke internet; hanya Nginx host yang bisa mengakses. `queue` punya `stop_grace_period: 65m` agar job yang berjalan tidak dibunuh. Data hidup di *volume*, terpisah dari container. |
| `deploy.sh` | Skrip deploy yang diuraikan di bagian 2. Argumen wajib: SHA 40 karakter. | Dipakai oleh CI **dan** untuk deploy/rollback manual: satu jalur untuk keduanya. Seluruh isi dibungkus fungsi `main()` supaya aman saat `git checkout` mengganti file ini di tengah jalan. Aman dijalankan ulang dengan SHA yang sama. |
| `.dockerignore` | Daftar file yang tidak boleh masuk ke image: `.git`, `.env`, `vendor`, `node_modules`, `tests`, `docs`, `deploy`, `backups`, dll. | Mencegah `.env` (rahasia) ikut tertanam di image, dan mempercepat build. |
| `docs/superpowers/feedback/2026-10-10-app-feedback.md` | Catatan masalah di kode aplikasi yang ditemukan saat menyiapkan CI/CD. | Kode app tidak diubah oleh pekerjaan CI/CD ini; masalahnya dicatat untuk kamu perbaiki. |

### Folder `deploy/` (lama) vs `deploy.sh` (baru)

Repo ini **sudah punya folder `deploy/`** (`deploy/deploy.sh`, `nginx.conf`, `php-clipper.ini`, `setup-ubuntu.sh`, `supervisor.conf`). Itu setup lama kamu untuk server *bare-metal* (tanpa Docker: PHP, nginx, supervisor dipasang langsung di OS).

**Folder `deploy/` TIDAK dipakai oleh pipeline ini.** Jalur Docker memakai `deploy.sh` di **akar repo** (`/srv/clipper/deploy.sh`). Jangan tertukar. Folder `deploy/` juga sengaja dikeluarkan dari image lewat `.dockerignore`.

## 4. Setup awal (sudah dikerjakan di VPS)

Bagian ini sudah selesai; ditulis sebagai referensi dan agar kamu paham isi server.

| Hal | Nilai |
|---|---|
| User deploy | `deploy`: anggota grup `docker`, **tanpa sudo**, login hanya dengan kunci SSH, `authorized_keys` berawalan `restrict` (tanpa port forwarding, agent, tty) |
| Clone repo | `/srv/clipper` (owner `deploy`) |
| Rahasia app | `/srv/clipper/.env` (mode **600**) |
| Backup DB | `/srv/clipper/backups` (7 terakhir) |
| Site Nginx host | `/etc/nginx/sites-available/clipper` (symlink di `sites-enabled`) |
| Sertifikat TLS | nama cert `clipper`, certbot *webroot* `/var/www/letsencrypt`, perpanjangan otomatis |
| Port app | `127.0.0.1:8095` |
| Compose project | `clipper` (volume jadi `clipper_storage`, `clipper_pgdata`, `clipper_whisper-models`) |

Catatan: grup `docker` pada praktiknya setara akses root. Pembatasan `restrict` + tanpa sudo mengurangi risiko, tapi bukan isolasi penuh.

### 4.1 GitHub Secrets (kamu yang mengisi)

Ada 3 secret. Nilainya diberikan padamu oleh orang yang menyiapkan VPS (controller).

| Secret | Isi |
|---|---|
| `SSH_HOST` | `46.250.228.35` |
| `SSH_PRIVATE_KEY` | **seluruh** isi file kunci privat (termasuk baris `-----BEGIN ...` dan `-----END ...`) yang kamu terima |
| `SSH_KNOWN_HOSTS` | isi keluaran `ssh-keyscan 46.250.228.35` (satu atau beberapa baris `46.250.228.35 ssh-ed25519 AAAA...`). Fungsinya: memastikan workflow benar-benar bicara dengan VPS-mu, bukan penipu. |

Langkah (ulangi untuk tiap secret):

1. Buka repo `Fahmiady11/clipper_studio` di GitHub.
2. **Settings** → **Secrets and variables** → **Actions**.
3. Klik **New repository secret**.
4. Isi **Name** persis seperti tabel (huruf besar, underscore), tempel nilainya di **Secret**.
5. Klik **Add secret**.

Setelah tersimpan, nilai secret tidak bisa dilihat lagi (hanya bisa ditimpa). Setelah ketiganya terpasang, **hapus file kunci privat dari komputermu dan dari chat/catatan** mana pun. Jangan pernah commit kunci ke repo.

## 5. Deploy pertama (bootstrap)

Masalah ayam-dan-telur: clone di `/srv/clipper` berada di branch `main`, dan `main` **belum punya `deploy.sh`** (file itu ada di branch `ci-cd-setup`). Workflow yang menjalankan `/srv/clipper/deploy.sh` akan gagal karena file itu belum ada di disk VPS.

Urutan:

1. Isi 3 GitHub Secrets (bagian 4.1).
2. Perbaiki masalah aplikasi yang membuat test merah (lihat peringatan di bawah), push ke `ci-cd-setup`, buka PR, pastikan job `test` hijau.
3. Merge `ci-cd-setup` ke `main`. Ini memicu workflow; job `deploy` akan gagal (file belum ada di VPS) atau tidak jalan karena test merah. **Itu wajar.**
4. Seseorang dengan akses **root** di VPS menjalankan **sekali** (dari root, satu baris):

   ```bash
   sudo -u deploy git -C /srv/clipper pull --ff-only
   ```

   Ini membawa `deploy.sh` dan `docker-compose.prod.yml` dari `main` ke disk VPS. Dijalankan sebagai `deploy` (bukan root) agar file tidak jadi milik root. Setelah ini, `deploy.sh` mengurus `git fetch/checkout` sendiri pada deploy berikutnya.
5. Di GitHub: **Actions** → pilih run terakhir di `main` → **Re-run jobs** (Re-run all jobs). Build pertama lama (kompilasi whisper.cpp, unduh model ~140 MB); build berikutnya jauh lebih cepat karena cache layer Docker.
6. Cek hasil: `curl -i https://clipper.asfahmidev.com/up` harus `200`.

> **Test akan MERAH sampai kamu memperbaiki masalah aplikasi** yang dicatat di [`docs/superpowers/feedback/2026-10-10-app-feedback.md`](superpowers/feedback/2026-10-10-app-feedback.md), terutama butir 3: `app/Services/StorageCleanup.php:46` (`ClipProject::find($dirName)` dengan nama folder non-UUID meledak di PostgreSQL: `invalid input syntax for type uuid`). Saran perbaikan 1 baris ada di file itu. Job `deploy` hanya jalan setelah `test` hijau, jadi **tidak ada deploy otomatis sampai ini beres**. Di bagian atas file feedback ada daftar "Yang perlu kamu perbaiki": butir 3 wajib, sisanya opsional (`composer.json` php `^8.4`, `ext-gd`). Masalah GD dan build Vite sudah ditangani di Dockerfile dan workflow, tidak perlu aksi dari kamu.
>
> Kalau kamu ingin deploy dulu sebelum tes beres, jalankan manual (bagian 6.1).

## 6. Operasional harian

### Menjadi user `deploy`

User `deploy` tidak bisa dipakai login interaktif: kuncinya berawalan `restrict` (tanpa pty) dan ia tidak punya sudo. Kuncinya hanya untuk GitHub Actions. Jadi **cara kamu bekerja** adalah:

1. SSH ke VPS sebagai **root**: `ssh root@46.250.228.35`.
2. Pindah ke `deploy`: `sudo -u deploy -i` (atau `su - deploy`). Prompt berubah; sekarang kamu `deploy`.
3. **Di dalam sesi `deploy` itu, jalankan perintah TANPA `sudo`.** (`sudo` di sana gagal karena `deploy` tidak punya sudo.)
4. `exit` untuk kembali menjadi root.

Konvensi dokumen ini: **semua perintah di bagian 6 dan 7 dijalankan di dalam sesi `deploy`, tanpa `sudo`**, kecuali ditandai "(dari root)". Perintah satu baris dari root dibungkus `sudo -u deploy bash -c '...'` supaya git juga berjalan sebagai `deploy` (kalau git jalan sebagai root, muncul "dubious ownership").

Agar singkat, di sesi `deploy` pakai ini:

```bash
cd /srv/clipper
export APP_SHA=$(git rev-parse HEAD)          # compose butuh APP_SHA terisi
dc() { docker compose -p clipper -f /srv/clipper/docker-compose.prod.yml --env-file /srv/clipper/.env "$@"; }
```

(`dc` sama dengan fungsi `dc` di dalam `deploy.sh`. Contoh: `dc ps`.)

### 6.1 Deploy manual

Di dalam sesi `deploy`, jalankan dengan SHA 40 karakter:

```bash
/srv/clipper/deploy.sh <40-char-sha>
# contoh: SHA terbaru di main
/srv/clipper/deploy.sh "$(git -C /srv/clipper ls-remote origin refs/heads/main | cut -f1)"
```

Satu baris langsung dari root (git dan deploy.sh tetap berjalan sebagai `deploy`):

```bash
sudo -u deploy bash -c '/srv/clipper/deploy.sh "$(git -C /srv/clipper ls-remote origin refs/heads/main | cut -f1)"'
```

> **JANGAN PERNAH menjalankan `deploy.sh` sebagai root.** Root akan membuat file kunci `/tmp/clipper-deploy-clipper.lock` milik root (deploy berikutnya dari CI sebagai `deploy` gagal), dan file/folder di `/srv/clipper/.git` jadi milik root sehingga git menolak dengan *"dubious ownership"*. Selalu lewat sesi `deploy` (`sudo -u deploy -i`) atau `sudo -u deploy bash -c '...'`.
>
> Kalau sudah terlanjur: `chown -R deploy:deploy /srv/clipper` dan `rm -f /tmp/clipper-deploy-clipper.lock` (hanya jika tidak ada deploy yang sedang berjalan).

### 6.2 Rollback

Dua cara:

1. **Lewat GitHub (disarankan):** Actions → pilih run lama yang dulu hijau → **Re-run all jobs**. Tes dijalankan lagi dulu, baru deploy SHA lama itu.
2. **Manual lewat SSH (cepat, tanpa tes):**
   ```bash
   /srv/clipper/deploy.sh <sha-lama>      # di sesi deploy; dari root: sudo -u deploy /srv/clipper/deploy.sh <sha-lama>
   ```

Image dari 3 deploy terakhir masih tersimpan, jadi rollback ke versi itu melewati build (`image ... already exists, skipping build`) dan cepat. Versi yang lebih lama akan dibangun ulang.

Peringatan: rollback **kode** tidak mengembalikan **database**. Kalau deploy yang gagal sudah menjalankan migrasi, pertimbangkan restore (6.3).

### 6.3 Backup dan restore database

Backup otomatis dibuat setiap deploy (sebelum migrate): `/srv/clipper/backups/<UTC-waktu>-<sha>.sql.gz`, 7 terbaru. File video di volume `storage` **tidak** di-backup.

Backup manual:

```bash
dc exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' | gzip > /srv/clipper/backups/manual-$(date -u +%Y%m%dT%H%M%SZ).sql.gz
```

Restore (menimpa isi database sekarang):

```bash
cd /srv/clipper && export APP_SHA=$(git rev-parse HEAD)
dc() { docker compose -p clipper -f /srv/clipper/docker-compose.prod.yml --env-file /srv/clipper/.env "$@"; }

# 1. hentikan yang memakai DB (queue menunggu job selesai, bisa lama; tambahkan -t 10 kalau ikhlas job dibatalkan)
dc stop app queue scheduler

# 2. kosongkan skema (dump tidak berisi DROP, jadi harus dikosongkan dulu)
dc exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"'

# 3. isi dari backup
gunzip -c /srv/clipper/backups/<file>.sql.gz | dc exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1'

# 4. nyalakan lagi
dc up -d app scheduler queue
```

Nama service di compose adalah `postgres`; user dan nama database dibaca dari environment container (`POSTGRES_USER` = `DB_USERNAME`, `POSTGRES_DB` = `DB_DATABASE` di `.env`), jadi perintah di atas tidak perlu diedit.

### 6.4 Mengubah `.env`, mengisi API key

1. Di sesi `deploy`: `nano /srv/clipper/.env` (mode 600, jangan diubah). Jangan edit sebagai root, supaya pemilik file tetap `deploy`.
2. Isi `GEMINI_API_KEY`, `GROQ_API_KEY`, `LLM_GATEWAY_URL` / `LLM_GATEWAY_KEY` / `LLM_GATEWAY_MODEL`, `TIKTOK_CLIENT_KEY` / `TIKTOK_CLIENT_SECRET`.
3. **Config di-cache saat container start**, jadi perubahan belum terlihat sebelum container dibuat ulang:

   ```bash
   cd /srv/clipper && export APP_SHA=$(git rev-parse HEAD)
   dc up -d --no-deps --force-recreate app scheduler queue
   ```

   (Membuat ulang `queue` menunggu job aktif selesai, sampai 65 menit.) Alternatif: deploy ulang SHA yang sama dengan `/srv/clipper/deploy.sh $APP_SHA` (di sesi `deploy`); compose otomatis membuat ulang container yang config/env-nya berubah.

Aturan nilai di `.env`:

- **Nilai yang mengandung `$` harus dibungkus kutip tunggal**, mis. `DB_PASSWORD='ab$cd'`. Compose melakukan interpolasi `$...` dan akan merusak nilainya (`$cd` dianggap variabel kosong).
- **Mengganti `DB_PASSWORD` atau `DB_USERNAME` setelah Postgres pernah diinisialisasi TIDAK mengubah role di database.** Variabel `POSTGRES_*` hanya dibaca saat volume `pgdata` masih kosong. Akibatnya app tidak bisa login. Cara benar mengganti password:

  ```bash
  # 1. ubah di database dulu (peran dipilih lewat POSTGRES_USER lama yang masih ada di container)
  dc exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "ALTER ROLE \"$POSTGRES_USER\" PASSWORD '"'"'PASSWORD_BARU'"'"';"'
  # 2. baru ubah DB_PASSWORD di .env (kutip tunggal bila ada $), lalu recreate app queue scheduler + postgres
  ```

  Ganti `DB_USERNAME` jauh lebih rumit (role yang sedang dipakai tidak bisa di-rename saat login sebagai dirinya). Sebaiknya jangan diganti.

### 6.5 Cookies YouTube

YouTube sering menolak unduhan tanpa login. Aplikasi membaca `YTDLP_COOKIES=/var/www/html/storage/app/cookies.txt` (sudah diset di `.env` produksi, `YTDLP_COOKIES_BROWSER` kosong).

1. Di browser yang sudah login YouTube, ekspor cookies ke file **`cookies.txt` format Netscape** (pakai ekstensi seperti "Get cookies.txt LOCALLY").
2. Kirim file ke VPS sebagai root dan serahkan ke `deploy`: dari laptop `scp cookies.txt root@46.250.228.35:/tmp/cookies.txt`, lalu (dari root) `chown deploy:deploy /tmp/cookies.txt && chmod 600 /tmp/cookies.txt`. Setelah itu masuk sesi `deploy` untuk langkah 3.
3. Salin ke volume storage lewat container `app`, lalu betulkan kepemilikan ke `www-data`:

   ```bash
   cd /srv/clipper && export APP_SHA=$(git rev-parse HEAD)
   dc cp /tmp/cookies.txt app:/var/www/html/storage/app/cookies.txt
   dc exec -u root app chown www-data:www-data /var/www/html/storage/app/cookies.txt
   dc exec -u root app chmod 600 /var/www/html/storage/app/cookies.txt
   rm /tmp/cookies.txt        # jangan tinggalkan cookies di /tmp
   ```

   Isi `storage` ada di volume `clipper_storage`, jadi file bertahan melewati deploy. Cookies kedaluwarsa; ulangi bila unduhan YouTube mulai gagal.

### 6.6 Update yt-dlp, whisper.cpp, deno

Versi dikunci di bagian atas `docker/Dockerfile`:

```
ARG WHISPER_CPP_VERSION=...
ARG YTDLP_VERSION=...
ARG DENO_VERSION=...
```

Ubah angka versinya, commit, push. Karena SHA berubah, `deploy.sh` membangun image baru, jadi update **memerlukan rebuild** (otomatis lewat pipeline). Tidak ada update "di tempat" tanpa rebuild. yt-dlp perlu sering diperbarui karena YouTube sering berubah; versi rilis ada di <https://github.com/yt-dlp/yt-dlp/releases>.

Cek versi di container:

```bash
dc exec app yt-dlp --version
dc exec app whisper-cli --help | head -3
dc exec app deno --version
```

### 6.7 Kenapa deploy bisa sampai ~65 menit

Itu **disengaja**. Pada langkah terakhir, `docker compose up -d queue` mengganti container `queue` lama. Compose mengirim `SIGTERM`; worker Laravel menyelesaikan job yang sedang berjalan baru berhenti (butuh ekstensi `pcntl`, sudah ada). `stop_grace_period: 65m` memberi waktu sampai 65 menit sebelum dibunuh paksa, karena job pemrosesan video (unduh, transkripsi, render) bisa sangat lama dan dikembalikan ke antrean kalau terpotong (`retry_after` = 3700 detik). Lalu workflow diberi `timeout-minutes: 80`. Selama menunggu, web sudah versi baru; hanya worker lama yang menyelesaikan pekerjaannya. Jika tidak ada job aktif, deploy selesai dalam hitungan menit.

## 7. Troubleshooting

### 7.1 Di mana log

```bash
cd /srv/clipper && export APP_SHA=$(git rev-parse HEAD)
dc ps                                   # status semua service (app harus healthy)
dc logs --tail 100 app                  # nginx + php-fpm
dc logs --tail 100 queue                # worker (job video)
dc logs --tail 100 scheduler
dc logs --tail 100 postgres
dc logs -f queue                        # ikuti log langsung (Ctrl+C untuk keluar)
dc exec app ls /var/www/html/storage/logs          # log Laravel (di volume storage)
dc exec app tail -n 100 /var/www/html/storage/logs/laravel.log
```

Log aplikasi Laravel ada di `storage/logs/` (subfolder `clipper/` juga ada) di dalam volume `storage`. Log workflow ada di tab **Actions** pada GitHub.

### 7.2 Cek health

```bash
curl -i http://127.0.0.1:8095/up          # langsung ke container (dari VPS)
curl -i https://clipper.asfahmidev.com/up # lewat Nginx host + TLS
```

`200` = sehat. Catatan: `/up` tidak menyentuh database dan tidak butuh `APP_KEY`, jadi `200` belum menjamin halaman lain bekerja; buka juga halaman login. (Itu sebabnya `deploy.sh` menolak jalan bila `APP_KEY` kosong.)

### 7.3 Arti kegagalan di workflow

| Gagal di | Artinya | Kondisi server | Yang dilakukan |
|---|---|---|---|
| **test** (merah) | Tes otomatis gagal | Tidak berubah, deploy tidak jalan | Baca log step "Run tests", perbaiki kode. Lihat [feedback aplikasi](superpowers/feedback/2026-10-10-app-feedback.md). |
| **build** (`docker compose build`, di dalam step Deploy) | Image gagal dibangun (unduhan yt-dlp/deno/whisper gagal, error composer/npm) | Versi lama tetap jalan | Baca log, perbaiki Dockerfile atau coba lagi (Re-run). |
| **backup** (`pg_dump failed`) | Postgres tidak bisa di-dump | Deploy berhenti sebelum `up`; versi lama tetap jalan | Cek `dc logs postgres`, ruang disk (`df -h`), lalu Re-run. |
| **migrate** | Migrasi database error | **App baru sudah jalan**, skema mungkin setengah berubah; backup tersedia | Baca pesan error; perbaiki migrasi dan deploy ulang, atau restore (6.3) + rollback (6.2). |
| **health** (`did not return 200 within 30s`) | `/up` tidak menjawab 200 | App baru sudah jalan tapi rusak; queue lama belum diganti | Lihat 50 baris log `app` yang ditampilkan, `dc logs app`; perbaiki lalu deploy ulang, atau rollback. |
| step SSH (`Permission denied`, `Host key verification failed`) | Secret salah | - | Periksa 3 GitHub Secrets (4.1). |
| `deploy.sh: No such file` | Bootstrap belum dikerjakan | - | Lihat bagian 5, langkah 4. |
| `another deploy of clipper is running` | Ada deploy lain / lock macet | - | Tunggu. Jika tidak ada proses `deploy.sh` yang jalan (`pgrep -fa deploy.sh`), hapus `/tmp/clipper-deploy-clipper.lock`. |
| `dubious ownership` | `deploy.sh` pernah dijalankan sebagai root | - | `chown -R deploy:deploy /srv/clipper` |
| job `deploy` timeout 80 menit | Job video panjang | - | Wajar bila ada job aktif; periksa `dc logs queue`. |

## 8. Peringatan

> ### ⚠ JANGAN jalankan `docker compose ... down -v`
> Flag `-v` **MENGHAPUS SEMUA DATA**: volume `pgdata` (database), `storage` (video, cookies, hasil render), dan `whisper-models`. Tidak bisa dikembalikan, kecuali dari backup `.sql.gz` (database saja; file di `storage` tidak ada backup-nya).
> `docker compose ... down` tanpa `-v` aman (data tetap di volume). Begitu juga `docker volume rm clipper_*` dan `docker system prune --volumes`: jangan.

- **Server ini juga melayani situs lain** (tldraw, omniroute, dll.). Jangan `docker stop`/`rm`/`prune` sembarangan; hanya sentuh container, image, dan volume berawalan `clipper`.
- **Jangan commit `.env`** atau kunci SSH. `.env` produksi hanya ada di VPS dengan mode 600.
- **Jangan jalankan `deploy.sh` sebagai root** (lihat 6.1).
- **Perubahan Nginx host**: wajib `nginx -t` lolos sebelum `systemctl reload nginx`; jangan ubah file site lain.
- **Test merah = tidak ada deploy.** Perbaiki dulu butir wajib di file feedback (`StorageCleanup` uuid); sisanya opsional.
- **Folder `deploy/` adalah setup lama bare-metal**, tidak ada hubungannya dengan pipeline ini.
- Setelah mengubah `.env`, container harus dibuat ulang (6.4); nilai dengan `$` pakai kutip tunggal.
- Backup hanya mencakup database. File video di `storage` tidak di-backup.
