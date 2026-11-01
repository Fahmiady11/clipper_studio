<?php

use App\Jobs\DiscoverVideosJob;
use App\Models\ApiToken;
use App\Models\InviteCode;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\JobWatchdog;
use App\Services\LoadMonitor;
use App\Services\StorageCleanup;
use App\Services\TikTokStatsService;
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

Artisan::command('clipper:token {email : Account that owns the token} {name=agent : Label, e.g. the agent using it}', function (string $email, string $name) {
    $user = User::where('email', $email)->first();
    if (! $user) {
        $this->error("Tidak ada akun dengan email {$email}.");

        return self::FAILURE;
    }
    [, $plain] = ApiToken::issue($user, $name);
    $this->info($plain);
    $this->line('Simpan sekarang, token tidak bisa ditampilkan lagi.');
})->purpose('Create an API token for the REST API / MCP server');
