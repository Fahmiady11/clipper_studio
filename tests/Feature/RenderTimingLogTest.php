<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\RenderUsage;
use App\Models\User;
use App\Services\FFmpegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RenderTimingLogTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/clipper-test-timing-'.getmypid();
        @mkdir($this->storage.'/app', 0755, true);
        $this->app->useStoragePath($this->storage);
        config([
            'queue.default' => 'database',
            'logging.channels.clipper_jobs' => ['driver' => 'single', 'path' => $this->storage.'/jobs.log', 'level' => 'debug'],
            'services.ffmpeg.preset' => 'veryfast',
            'services.ffmpeg.crf' => '20',
            'services.ffmpeg.threads' => '3',
        ]);
        Storage::fake();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));
        parent::tearDown();
    }

    /** Context of the first log line with $message */
    private function logged(string $message): ?array
    {
        foreach (file($this->storage.'/jobs.log') ?: [] as $line) {
            if (str_contains($line, ': '.$message.' {')) {
                return json_decode(substr($line, strpos($line, $message.' ') + strlen($message) + 1), true);
            }
        }

        return null;
    }

    public function test_a_finished_render_logs_its_queue_wait_total_and_step_times(): void
    {
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'gaussian', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $clip = GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 5, 'end_seconds' => 20,
            'topic' => 't', 'reason' => 'r', 'status' => 'pending',
        ]);
        RenderUsage::create(['user_id' => $user->id, 'generated_clip_id' => $clip->id, 'period' => now()->format('Y-m'), 'status' => 'reserved', 'seconds' => 15]);
        @mkdir(storage_path('app/temp/'.$project->id), 0755, true);
        file_put_contents(storage_path('app/temp/'.$project->id.'/video.mp4'), 'src');

        $ffmpeg = $this->mock(FFmpegService::class);
        $ffmpeg->shouldReceive('applyLayout')->once()->andReturnUsing(function ($in, $layout, $out) {
            usleep(50_000);
            file_put_contents($out, 'laid out');
        });
        $ffmpeg->shouldReceive('normalizeVoice')->once()->andReturnUsing(function ($in, $out) {
            file_put_contents($out, 'levelled');

            return true;
        });

        RenderClipJob::dispatch($clip->id);
        $this->travel(42)->seconds(); // waits in the queue before a worker takes it
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'render', '--once' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertSame('done', $clip->fresh()->status);
        $this->assertSame(42, $this->logged('RenderClipJob started')['queue_wait_s']);

        $timings = $this->logged('RenderClipJob timings');
        $this->assertNotNull($timings);
        $this->assertSame($clip->id, $timings['clip_id']);
        $this->assertSame(1, $timings['attempt']);
        $this->assertSame(42, $timings['queue_wait_s']);
        $this->assertEquals(15, $timings['clip_s']);
        $this->assertSame('veryfast/20 threads=3', $timings['encode']);
        $this->assertSame(['apply_layout', 'normalize_voice', 'store'], array_keys($timings['steps']));
        $this->assertGreaterThanOrEqual(0.05, $timings['steps']['apply_layout']);
        $this->assertGreaterThanOrEqual($timings['steps']['apply_layout'], $timings['total_s']);
    }
}
