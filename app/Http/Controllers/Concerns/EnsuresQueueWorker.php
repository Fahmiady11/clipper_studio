<?php

namespace App\Http\Controllers\Concerns;

trait EnsuresQueueWorker
{
    /**
     * Start one background queue:work if no worker is running yet.
     *
     * Never queue:listen: it runs each job in a child process killed after
     * --timeout (default 60s) whatever the job's own $timeout says. A killed
     * job stays reserved, is picked up again after retry_after and restarts
     * from the beginning — the "job keeps going back to the start" loop.
     */
    private function ensureQueueWorkerRunning(): void
    {
        // sync (tests, or no background queue): jobs already ran inline
        if (config('queue.default') === 'sync') {
            return;
        }

        // Any worker counts (queue:work, or queue:listen from `composer dev`),
        // so we never stack a second worker onto the same jobs.
        exec("pgrep -f 'artisan queue:(work|listen)' 2>/dev/null", $pids);
        if (! empty($pids)) {
            return;
        }

        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $log = storage_path('logs/queue-worker.log');

        // --timeout covers the longest job (AnalyzeVideoJob, 1h); jobs also set their own
        exec("nohup {$php} {$artisan} queue:work --timeout=3600 --memory=512 --sleep=1 >> {$log} 2>&1 &");
    }
}
