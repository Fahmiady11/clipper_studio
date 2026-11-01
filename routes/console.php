<?php

use App\Exceptions\TokenLimitReached;
use App\Jobs\DiscoverVideosJob;
use App\Models\ApiToken;
use App\Models\InviteCode;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\HookTestService;
use App\Services\JobWatchdog;
use App\Services\LoadMonitor;
use App\Services\ScheduledPublisher;
use App\Services\StorageCleanup;
use App\Services\TikTokStatsService;
use App\Services\YouTubeStatusPoller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Autopilot: check each enabled video source whose interval has elapsed
Schedule::call(function () {
    // Sources of accounts whose access was revoked stay idle
    VideoSource::where('enabled', true)->whereHas('user', fn ($q) => $q->whereNotNull('approved_at'))->get()
        ->filter->isDue()
        ->each(function ($source) {
            $source->update(['last_run_at' => now()]); // don't re-dispatch while this run is queued
            DiscoverVideosJob::dispatch($source->id);
        });
})->everyTenMinutes()->name('autopilot-discover')->withoutOverlapping();

// Learning loop: collect one lifetime snapshot per published TikTok clip
Schedule::call(function (TikTokStatsService $stats) {
    $stats->collectPublishedStats();
})->hourly()->name('clip-stats-tiktok')->withoutOverlapping();

// Hook A/B tests: pick a winner once every variant has reached the comparison age (runs after the stats snapshot)
Artisan::command('clipper:judge-hook-tests', function (HookTestService $tests) {
    $this->info('Judged '.$tests->judgeDue().' hook tests');
})->purpose('Pick the winning hook of finished A/B tests');

Schedule::command('clipper:judge-hook-tests')->hourlyAt(20)->name('judge-hook-tests')->withoutOverlapping();

// Calendar: hand posts whose time has come to the upload jobs
Artisan::command('clipper:publish-scheduled', function (ScheduledPublisher $publisher) {
    $this->info('Picked up '.$publisher->publishDue().' scheduled posts');
})->purpose('Send due scheduled posts to TikTok / YouTube');

Schedule::command('clipper:publish-scheduled')->everyMinute()->name('publish-scheduled')->withoutOverlapping();

// Uploaded YouTube videos: follow processing until published or failed
Artisan::command('clipper:youtube-status', function (YouTubeStatusPoller $poller) {
    $r = $poller->run();
    $this->info("Checked {$r['checked']} YouTube videos, {$r['settled']} settled");
})->purpose('Refresh the status of YouTube uploads still processing');

Schedule::command('clipper:youtube-status')->everyFiveMinutes()->name('youtube-status')->withoutOverlapping();

// Free disk from downloaded source videos; rendered clips are kept
Artisan::command('clipper:cleanup {--hours= : Keep files touched within this many hours}', function (StorageCleanup $cleanup) {
    $hours = (int) ($this->option('hours') ?: config('services.clipper.temp_retention_hours'));
    $stats = $cleanup->run($hours);
    $this->info(sprintf(
        'Removed %d temp dirs, %d cached videos, %.1f MB (older than %dh)',
        $stats['temp_dirs'], $stats['cache_files'], $stats['bytes'] / 1048576, $hours
    ));
})->purpose('Delete old downloaded source videos');

Schedule::command('clipper:cleanup')->dailyAt('03:17')->name('clipper-cleanup');

// Closed beta: CPU load and render queue sampled every minute for /status and the logs
Artisan::command('clipper:monitor', function (LoadMonitor $monitor) {
    $s = $monitor->sample();
    $this->info(sprintf('%s: load/core %.2f, %d queued, %d running, %d failed in 24h', $s['level'], $s['load_per_core'], $s['queued'], $s['running'], $s['failed_24h']));
})->purpose('Sample CPU load and render queue depth');

Schedule::command('clipper:monitor')->everyMinute()->name('clipper-monitor')->withoutOverlapping();

// Work stuck in 'processing' after a worker died past its timeout: fail it and tell the user
Artisan::command('clipper:watchdog', function (JobWatchdog $watchdog) {
    $r = $watchdog->run();
    $this->info("Failed {$r['projects']} stuck analyses, {$r['clips']} stuck renders");
})->purpose('Fail jobs stuck in processing past their timeout');

Schedule::command('clipper:watchdog')->everyFiveMinutes()->name('clipper-watchdog')->withoutOverlapping();

Artisan::command('clipper:invite {--uses=1 : How many people can sign up with it} {--days= : Expire after this many days} {--note= : Who it is for}', function () {
    $invite = InviteCode::generate((int) $this->option('uses'), $this->option('days') ? (int) $this->option('days') : null, $this->option('note'));
    $this->info($invite->code);
    $this->line(url('/register').'?invite='.$invite->code);
})->purpose('Create a closed-beta invite code');

Artisan::command('clipper:token {email : Account that owns the token} {name=agent : Label, e.g. the agent using it} {--ability=* : read, render and/or publish (default read + render)} {--file= : Write the token to this new file (mode 0600) instead of printing it}', function (string $email, string $name) {
    $user = User::where('email', $email)->first();
    if (! $user) {
        $this->error("Tidak ada akun dengan email {$email}.");

        return self::FAILURE;
    }

    $abilities = $this->option('ability') ?: ['read', 'render'];
    if ($bad = array_diff($abilities, ApiToken::ABILITIES)) {
        $this->error('Ability tidak dikenal: '.implode(', ', $bad).'. Pilih dari: '.implode(', ', ApiToken::ABILITIES).'.');

        return self::FAILURE;
    }

    $file = $this->option('file');
    if ($file && file_exists($file)) {
        $this->error("{$file} sudah ada; tidak ditimpa.");

        return self::FAILURE;
    }

    try {
        [$token, $plain] = ApiToken::issueLimited($user, $name, $abilities);
    } catch (TokenLimitReached $e) {
        $this->error($e->getMessage());

        return self::FAILURE;
    }

    // Plaintext goes to stdout alone (capture it with $(...)); the notice goes to stderr
    $notice = fn (string $line) => $this->getOutput()->getErrorStyle()->writeln($line);
    if ($file) {
        $old = umask(0077);
        $written = @file_put_contents($file, $plain."\n", LOCK_EX);
        umask($old);
        if ($written === false) {
            $token->delete();
            $this->error("Tidak bisa menulis {$file}.");

            return self::FAILURE;
        }
        $notice("Token disimpan di {$file} (izin: ".implode(', ', $token->abilities).'). Tidak bisa ditampilkan lagi.');

        return self::SUCCESS;
    }

    $this->line($plain);
    $notice('Izin: '.implode(', ', $token->abilities).'. Simpan sekarang, token tidak bisa ditampilkan lagi.');
})->purpose('Create an API token for the REST API / MCP server');
