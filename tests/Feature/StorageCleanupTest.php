<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use App\Services\FFmpegService;
use App\Services\StorageCleanup;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageCleanupTest extends TestCase
{
    use RefreshDatabase;

    private array $paths = [];

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // The cleanup sweeps every folder under storage/app/temp and video_cache. Point
        // storage_path() at a throwaway dir, or a test run deletes the developer's real
        // source videos (the test DB doesn't know their projects, so they look orphaned).
        $this->storage = sys_get_temp_dir().'/clipper-test-storage-'.getmypid();
        @mkdir($this->storage.'/app', 0755, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $p) {
            exec('rm -rf '.escapeshellarg($p));
        }
        exec('rm -rf '.escapeshellarg($this->storage));
        parent::tearDown();
    }

    private function project(string $status = 'done'): ClipProject
    {
        return ClipProject::create([
            'user_id' => User::factory()->create()->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => $status,
        ]);
    }

    private function tempDir(string $name, int $ageHours): string
    {
        $dir = storage_path('app/temp/'.$name);
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/video.mp4', str_repeat('v', 1024));
        touch($dir, time() - $ageHours * 3600);
        $this->paths[] = $dir;

        return $dir;
    }

    public function test_removes_old_idle_sources_and_keeps_busy_or_recent(): void
    {
        $old = $this->project();
        $busy = $this->project('processing');
        $recent = $this->project();
        $queued = $this->project();
        GeneratedClip::create([
            'clip_project_id' => $queued->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 10,
            'topic' => 't', 'reason' => 'r', 'status' => 'pending', 'review_status' => 'pending',
        ]);

        $oldDir = $this->tempDir($old->id, 100);
        $busyDir = $this->tempDir($busy->id, 100);
        $recentDir = $this->tempDir($recent->id, 1);
        $queuedDir = $this->tempDir($queued->id, 100);
        $orphanDir = $this->tempDir('orphan-dir', 100);

        @mkdir(storage_path('app/video_cache'), 0755, true);
        $oldCache = storage_path('app/video_cache/old_test_vid.mp4');
        $newCache = storage_path('app/video_cache/new_test_vid.mp4');
        file_put_contents($oldCache, 'x');
        file_put_contents($newCache, 'x');
        touch($oldCache, time() - 100 * 3600);
        $this->paths[] = $oldCache;
        $this->paths[] = $newCache;

        $stats = app(StorageCleanup::class)->run(72);

        $this->assertDirectoryDoesNotExist($oldDir);
        $this->assertDirectoryDoesNotExist($orphanDir);
        $this->assertDirectoryExists($busyDir);
        $this->assertDirectoryExists($recentDir);
        $this->assertDirectoryExists($queuedDir);
        $this->assertFileExists($newCache);
        $this->assertSame(2, $stats['temp_dirs']);
        $this->assertGreaterThanOrEqual(2048, $stats['bytes']);

        // ctime can't be backdated with touch(); the cache file only goes once both times are old
        clearstatcache();
        $this->assertSame(filectime($oldCache) < time() - 72 * 3600, ! file_exists($oldCache));
    }

    public function test_cleanup_command_runs(): void
    {
        $this->artisan('clipper:cleanup', ['--hours' => 72])->assertSuccessful();
    }

    public function test_render_redownloads_missing_source_video(): void
    {
        Storage::fake();
        $project = $this->project();
        $clip = GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 5, 'end_seconds' => 20,
            'topic' => 't', 'reason' => 'r', 'status' => 'pending',
        ]);
        $this->paths[] = storage_path('app/temp/'.$project->id);

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('download')->once()
            ->with('https://youtu.be/abcdefg', storage_path("app/temp/{$project->id}/video.mp4"))
            ->andReturnUsing(fn ($url, $path) => file_put_contents($path, 'src'));

        $ffmpeg = $this->mock(FFmpegService::class);
        $ffmpeg->shouldReceive('applyLayout')->once()
            ->andReturnUsing(fn ($in, $layout, $out) => file_put_contents($out, 'rendered'));
        $ffmpeg->shouldReceive('normalizeVoice')->once()->andReturn(false); // the fake file has no audio

        $this->app->call([new RenderClipJob($clip->id), 'handle']);

        $clip->refresh();
        $this->assertSame('done', $clip->status);
        Storage::assertExists($clip->output_path);
    }
}
