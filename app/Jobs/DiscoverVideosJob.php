<?php

namespace App\Jobs;

use App\Models\VideoSource;
use App\Services\DiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiscoverVideosJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $sourceId) {}

    public function handle(DiscoveryService $discovery): void
    {
        $source = VideoSource::find($this->sourceId);
        if (! $source) {
            return;
        }
        $discovery->run($source);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('Autopilot discovery failed', ['source_id' => $this->sourceId, 'error' => $e->getMessage()]);

        // Record the failure and push the next attempt to the next interval
        VideoSource::where('id', $this->sourceId)->update([
            'last_run_at' => now(),
            'last_error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
