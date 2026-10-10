# App feedback (found while building the production image, 2026-10-10)

1. composer.json / composer.lock: `composer.json` declares `"php": "^8.3"` but `composer.lock` resolves
   Symfony 8.1 packages that require PHP >= 8.4.1; on PHP 8.3 the app dies in
   `vendor/composer/platform_check.php`. Symptom: fatal on any artisan/http boot under 8.3.
   Suggested fix: set `"php": "^8.4"` (and `config.platform.php`) in composer.json. The Docker image uses PHP 8.4 meanwhile.
2. app/Providers/AppServiceProvider.php `boot()`: when `database.default` is `sqlite` it runs `DB::statement('PRAGMA ...')`
   on every boot, including `artisan package:discover`/`config:cache`. If the sqlite file does not exist the command
   crashes. Not an issue with pgsql (production). Suggested fix: guard with a file-exists check or run in a try/catch.
