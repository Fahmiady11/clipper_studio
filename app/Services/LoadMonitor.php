<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Samples CPU load and the render queue once a minute (clipper:monitor) so the
 * status page and the logs show when the box is the bottleneck.
 */
class LoadMonitor
{
    private const CACHE_KEY = 'clipper.load';

    /** @return array{level: string, load_per_core: float, queued: int, running: int, failed_24h: int, sampled_at: string} */
    public function sample(): array
    {
        $cores = max(1, (int) config('services.clipper.cpu_cores'));
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0.0) : 0.0;

        $queued = DB::table('jobs')->whereNull('reserved_at')->count();
        $running = DB::table('jobs')->whereNotNull('reserved_at')->count();

        $snapshot = [
            'level' => 'ok',
            'load_per_core' => round($load / $cores, 2),
            'queued' => $queued,
            'running' => $running,
            'failed_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            'sampled_at' => now()->toIso8601String(),
        ];
        $snapshot['level'] = $this->level($snapshot['load_per_core'], $queued);

        Cache::put(self::CACHE_KEY, $snapshot, now()->addMinutes(10));
        if ($snapshot['level'] !== 'ok') {
            Log::channel('clipper_jobs')->warning('Render queue under load', $snapshot);
        }

        return $snapshot;
    }

    /** Last sample, or a fresh one when the scheduler has not run lately. */
    public function current(): array
    {
        return Cache::get(self::CACHE_KEY) ?? $this->sample();
    }

    public function level(float $loadPerCore, int $queued): string
    {
        $cfg = config('services.clipper');
        if ($loadPerCore >= $cfg['load_overloaded'] || $queued >= $cfg['queue_overloaded']) {
            return 'overloaded';
        }
        if ($loadPerCore >= $cfg['load_busy'] || $queued >= $cfg['queue_busy']) {
            return 'busy';
        }

        return 'ok';
    }
}
