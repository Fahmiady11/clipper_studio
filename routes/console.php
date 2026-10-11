<?php

use App\Jobs\DiscoverVideosJob;
use App\Models\VideoSource;
use App\Services\StorageCleanup;
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
