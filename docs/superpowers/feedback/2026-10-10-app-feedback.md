# App feedback (found while building the production image, 2026-10-10)

1. composer.json / composer.lock: `composer.json` declares `"php": "^8.3"` but `composer.lock` resolves
   Symfony 8.1 packages that require PHP >= 8.4.1; on PHP 8.3 the app dies in
   `vendor/composer/platform_check.php`. Symptom: fatal on any artisan/http boot under 8.3.
   Suggested fix: set `"php": "^8.4"` (and `config.platform.php`) in composer.json. The Docker image uses PHP 8.4 meanwhile.
2. app/Providers/AppServiceProvider.php `boot()`: when `database.default` is `sqlite` it runs `DB::statement('PRAGMA ...')`
   on every boot, including `artisan package:discover`/`config:cache`. If the sqlite file does not exist the command
   crashes. Not an issue with pgsql (production). Suggested fix: guard with a file-exists check or run in a try/catch.

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
4. **(Sama di SQLite dan pgsql)** app/Services/HookRenderer.php:301 (`imagettfbbox()`), dipanggil dari baris 294.
   Error: `Call to undefined function App\Services\imagettfbbox()`: ekstensi PHP GD (dengan FreeType) tidak ada. 4 tes gagal:
   `Tests\Unit\HookRendererTest` (short hook uses the largest size, long hook shrinks to fit four lines, lines are balanced not greedy,
   renders cropped png with placement on the frame).
   PENTING: `docker/Dockerfile:58` hanya memasang `pdo_pgsql intl zip opcache pcntl`, tanpa `gd`. Jadi image produksi
   juga tidak punya GD dan fitur render hook akan error saat runtime, bukan hanya di tes. (`deploy/setup-ubuntu.sh:22` memasang `php-gd`,
   jadi jalur non-Docker lama aman.)
   Saran: tambahkan `libfreetype6-dev libjpeg62-turbo-dev libpng-dev` (build) dan `libfreetype6 libjpeg62-turbo libpng16-16`
   (runtime) lalu `docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install gd` di Dockerfile;
   di CI pakai `shivammathur/setup-php` dengan `extensions: gd`. Tambahkan juga `"ext-gd": "*"` di composer.json agar kekurangan ini terdeteksi lebih awal.
5. **(Sama di SQLite dan pgsql)** tests/Feature/AuthFlowTest.php, `test_studio_pages_are_not_cached_by_the_browser` (baris 82-86, `GET /autopilot`).
   Error: status 500 bukan 200; penyebabnya `ViteManifestNotFoundException: Vite manifest not found at public/build/manifest.json`.
   Tes me-render halaman yang memakai `@vite`, jadi butuh aset hasil build.
   Saran: di CI jalankan `npm ci && npm run build` sebelum `php artisan test` (rencana CI sudah perlu langkah ini),
   atau panggil `$this->withoutVite()` di tes tersebut agar tidak bergantung pada build front-end.
6. Catatan: `AppServiceProvider::boot()` (sudah dicatat di butir 2) juga membuat `composer install` gagal di
   skrip `package:discover` bila `DB_CONNECTION=sqlite` tanpa file DB. Saat menjalankan CI/tes, pastikan `DB_CONNECTION` diset ke pgsql (atau file sqlite dibuat) sebelum `composer install`.

Kesimpulan untuk CI: dengan Postgres 17, job `test` akan merah karena butir 3, 4, 5. Butir 4 dan 5 dapat diatasi di konfigurasi CI
(ekstensi gd + build Vite); butir 3 butuh perbaikan kode 1 baris di `StorageCleanup`. Butir 4 juga perlu perbaikan di Dockerfile produksi.
