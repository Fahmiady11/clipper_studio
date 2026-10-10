# App feedback (found while building the production image, 2026-10-10)

## Yang perlu kamu perbaiki

- **Wajib (supaya job `test` di CI hijau):** butir 3, `app/Services/StorageCleanup.php` (uuid). Perbaikan 1 baris, saran ada di butir 3.
- Opsional: `composer.json` set `"php": "^8.4"` (butir 1); tambahkan `"ext-gd": "*"` ke `composer.json` (butir 4); guard PRAGMA sqlite di `AppServiceProvider::boot()` (butir 2).
- Butir 4 (GD) dan 5 (Vite) sudah ditangani di Dockerfile/workflow.

1. composer.json / composer.lock: `composer.json` declares `"php": "^8.3"` but `composer.lock` resolves
   Symfony 8.1 packages that require PHP >= 8.4.1; on PHP 8.3 the app dies in
   `vendor/composer/platform_check.php`. Symptom: fatal on any artisan/http boot under 8.3.
   Suggested fix: set `"php": "^8.4"` (and `config.platform.php`) in composer.json. The Docker image uses PHP 8.4 meanwhile.
2. app/Providers/AppServiceProvider.php `boot()`: when `database.default` is `sqlite` it runs `DB::statement('PRAGMA ...')`
   on every boot, including `artisan package:discover`/`config:cache`. If the sqlite file does not exist the command
   crashes. Not an issue with pgsql (production). Suggested fix: guard with a file-exists check or run in a try/catch.
   Also: this makes `composer install` fail in the `package:discover` script when `DB_CONNECTION=sqlite` and no DB file exists; make sure `DB_CONNECTION` is pgsql (or the sqlite file exists) before `composer install`. CI already sets pgsql.

## Cek kompatibilitas PostgreSQL 17 (Task 2, 2026-10-10)

Metode: PHP 8.4.26 di container sementara, Postgres 17 sementara, `composer install` (dengan dev deps) pada salinan repo.
Hasil ringkas:

| Skenario | Hasil |
|---|---|
| `php artisan migrate:fresh` di pgsql | **Semua 25 migrasi sukses** (termasuk `fix_clip_jobs_status_constraint`, yang punya cabang khusus pgsql) |
| `php artisan test` di SQLite (default phpunit.xml) | 5 gagal, 103 lulus |
| `php artisan test` di pgsql | 6 gagal, 102 lulus |

5 kegagalan sama persis di kedua DB (bukan masalah Postgres, tapi akan membuat job `test` di CI merah). 1 kegagalan khusus Postgres.

3. **(Khusus Postgres)** app/Services/StorageCleanup.php:46 (`ClipProject::find($dirName)` di `isBusy()`, dipanggil dari baris 23).
   Error: `SQLSTATE[22P02]: invalid input syntax for type uuid: "orphan-dir"`. Kolom `clip_projects.id` bertipe `uuid`
   (migrasi `2026_06_01_000001_create_clip_projects_table.php:12`). Nama folder di `storage/app/temp/*` yang bukan UUID
   (folder yatim/sampah, atau folder buatan manual) membuat query meledak di Postgres, sedangkan SQLite menerima string apa saja.
   Tes yang memicunya: `tests/Feature/StorageCleanupTest.php:64` (`tempDir('orphan-dir', 100)`) di test
   `test_removes_old_idle_sources_and_keeps_busy_or_recent`. Ini juga bug nyata di produksi: satu folder non-UUID di temp
   akan menghentikan seluruh pembersihan storage.
   Saran: di `isBusy()` lakukan `if (!\Illuminate\Support\Str::isUuid($dirName)) return false;` (anggap yatim) sebelum `find()`.
   Tes tidak perlu diubah; ia memang menguji kasus ini.
4. ✅ Sudah ditangani di Dockerfile/workflow (tidak perlu aksi dari kamu). **(Sama di SQLite dan pgsql)** app/Services/HookRenderer.php:301 (`imagettfbbox()`), dipanggil dari baris 294.
   Error: `Call to undefined function App\Services\imagettfbbox()`: ekstensi PHP GD (dengan FreeType) tidak ada. 4 tes gagal:
   `Tests\Unit\HookRendererTest` (short hook uses the largest size, long hook shrinks to fit four lines, lines are balanced not greedy,
   renders cropped png with placement on the frame).
   Temuan awal: Dockerfile hanya memasang `pdo_pgsql intl zip opcache pcntl`, tanpa `gd`, sehingga image produksi juga tidak punya GD.
   STATUS: sekarang Dockerfile memasang `gd` (freetype/jpeg/webp) dan workflow CI memakai `extensions: ... gd`. (`deploy/setup-ubuntu.sh:22` memasang `php-gd`,
   jadi jalur non-Docker lama aman.)
   Saran awal (sudah dilakukan): tambahkan `libfreetype6-dev libjpeg62-turbo-dev libpng-dev` (build) dan `libfreetype6 libjpeg62-turbo libpng16-16`
   (runtime) lalu `docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install gd` di Dockerfile;
   di CI pakai `shivammathur/setup-php` dengan `extensions: gd`. **Opsional, kamu yang mengerjakan:** tambahkan `"ext-gd": "*"` di composer.json agar kekurangan ini terdeteksi lebih awal.
5. ✅ Sudah ditangani di Dockerfile/workflow (tidak perlu aksi dari kamu): workflow menjalankan `npm ci && npm run build` sebelum `php artisan test`. **(Sama di SQLite dan pgsql)** tests/Feature/AuthFlowTest.php, `test_studio_pages_are_not_cached_by_the_browser` (baris 82-86, `GET /autopilot`).
   Error: status 500 bukan 200; penyebabnya `ViteManifestNotFoundException: Vite manifest not found at public/build/manifest.json`.
   Tes me-render halaman yang memakai `@vite`, jadi butuh aset hasil build.
   Saran: di CI jalankan `npm ci && npm run build` sebelum `php artisan test` (rencana CI sudah perlu langkah ini),
   atau panggil `$this->withoutVite()` di tes tersebut agar tidak bergantung pada build front-end.
Kesimpulan untuk CI: dengan Postgres 17, satu-satunya penyebab job `test` merah yang butuh kode kamu adalah butir 3 (`StorageCleanup`, perbaikan 1 baris). Butir 4 (GD) dan 5 (Vite) sudah ditangani di Dockerfile dan workflow.

## Proxy/HTTPS di produksi Docker (review akhir, 2026-10-10)

6. ✅ Sudah ditangani di infrastruktur (`docker/nginx.conf`), **opsional** untuk kamu. bootstrap/app.php:20
   `$middleware->trustProxies(at: ['127.0.0.1', '::1'])`. Di produksi, request datang lewat Nginx host → `127.0.0.1:8095`
   → proxy port Docker → container, sehingga yang terlihat oleh app adalah gateway jaringan compose (`172.x.0.1`), bukan
   `127.0.0.1`. Gejala sebelum diperbaiki: header `X-Forwarded-Proto: https` diabaikan, URL dibuat `http://` (mixed content,
   form login diblokir CSP `form-action`, tidak ada HSTS), dan semua pengguna terlihat dengan satu IP (rate limit bersama).
   Perbaikan yang sudah dipasang: Nginx di dalam container memakai `real_ip` (IP klien dari `X-Forwarded-For`) dan mengirim
   `HTTPS=on` ke PHP bila `X-Forwarded-Proto` = `https`. Kode app tidak diubah.
   Alternatif yang lebih bersih (pilihan kamu): percayai subnet compose di app, mis.
   `$middleware->trustProxies(at: ['127.0.0.1', '::1', '172.16.0.0/12'], headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | ...)`.
   Kalau itu dipasang, konfigurasi Nginx container tetap aman dipakai bersama (hasilnya sama).
