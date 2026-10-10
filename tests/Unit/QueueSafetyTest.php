<?php

namespace Tests\Unit;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\RenderClipJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class QueueSafetyTest extends TestCase
{
    /**
     * A job running longer than retry_after is handed to another worker while
     * still running and starts over from the beginning.
     */
    public function test_retry_after_exceeds_every_job_timeout(): void
    {
        $retryAfter = config('queue.connections.database.retry_after');

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            $timeout = (new \ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 60;
            $this->assertGreaterThan($timeout, $retryAfter, "{$class} timeout {$timeout}s >= retry_after {$retryAfter}s");
        }
    }

    public function test_heavy_jobs_never_run_twice_in_parallel(): void
    {
        $analyze = (new AnalyzeVideoJob('p-1'))->middleware()[0];
        $render = (new RenderClipJob('c-1'))->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $analyze);
        $this->assertSame('analyze:p-1', $analyze->key);
        $this->assertSame(3600, $analyze->expiresAfter);
        $this->assertNull($analyze->releaseAfter); // dontRelease(): duplicate is dropped, not retried
        $this->assertSame('render:c-1', $render->key);
    }
}
