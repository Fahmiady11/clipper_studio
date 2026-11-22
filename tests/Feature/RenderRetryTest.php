<?php

namespace Tests\Feature;

use App\Exceptions\FFmpegFailed;
use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\RenderUsage;
use App\Models\User;
use App\Services\FFmpegService;
use App\Services\JobWatchdog;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Runs the job through a real database-queue worker, so tries, release and failed() all happen for real */
class RenderRetryTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/clipper-test-retry-'.getmypid();
        @mkdir($this->storage.'/app', 0755, true);
        $this->app->useStoragePath($this->storage);
        config(['queue.default' => 'database']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));
        parent::tearDown();
    }

    private function queuedClip(bool $withSource = true): GeneratedClip
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
        if ($withSource) {
            @mkdir(storage_path('app/temp/'.$project->id), 0755, true);
            file_put_contents(storage_path('app/temp/'.$project->id.'/video.mp4'), 'src');
        }

        RenderClipJob::dispatch($clip->id);

        return $clip;
    }

    private function work(): void
    {
        // Only the render queue: the job has to be there to be picked up at all
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'render', '--once' => true, '--sleep' => 0])->assertSuccessful();
    }

    public function test_an_ffmpeg_error_fails_the_render_at_once_with_ffmpegs_message(): void
    {
        $clip = $this->queuedClip();
        $this->mock(FFmpegService::class)->shouldReceive('applyLayout')->once()
            ->andThrow(new FFmpegFailed("FFmpeg applyLayout failed:\nCannot select channel layout"));
        $this->mock(JobWatchdog::class)->shouldReceive('notifyClip')->once()
            ->withArgs(fn ($c, $reason) => $c->id === $clip->id && str_contains($reason, 'Cannot select channel layout'));

        $this->work();

        $this->assertSame(0, DB::table('jobs')->count()); // not released for a second attempt
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $clip->refresh();
        $this->assertSame('failed', $clip->status);
        $this->assertStringContainsString('Cannot select channel layout', $clip->error_msg);
        $this->assertSame(0, RenderUsage::where('generated_clip_id', $clip->id)->count()); // quota handed back
    }

    public function test_a_transient_error_still_gets_the_second_attempt(): void
    {
        $clip = $this->queuedClip(withSource: false);
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        // The section fails, then the whole-video fallback too
        $ytdlp->shouldReceive('downloadSection')->once()->andThrow(new RuntimeException('Connection reset by peer'));
        $ytdlp->shouldReceive('download')->once()->andThrow(new RuntimeException('Connection reset by peer'));
        $this->mock(JobWatchdog::class)->shouldNotReceive('notifyClip');

        $this->work();

        $this->assertSame(1, DB::table('jobs')->where('queue', 'render')->where('attempts', 1)->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNotSame('failed', $clip->fresh()->status);
        $this->assertSame(1, RenderUsage::where('generated_clip_id', $clip->id)->count());
    }
}
