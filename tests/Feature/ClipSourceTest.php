<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\Transcript;
use App\Models\User;
use App\Services\ClipSource;
use App\Services\FFmpegService;
use App\Services\SmartCut;
use App\Services\YtDlpService;
use App\Support\JobCancelled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ClipSourceTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/clipper-test-source-'.getmypid();
        @mkdir($this->storage.'/app', 0755, true);
        $this->app->useStoragePath($this->storage);
        Storage::fake();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));
        parent::tearDown();
    }

    private function project(array $attrs = []): ClipProject
    {
        return ClipProject::create($attrs + [
            'user_id' => User::factory()->create()->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'gaussian', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
            'duration_seconds' => 2700,
        ]);
    }

    private function clip(ClipProject $project, float $start, float $end, string $status = 'pending'): GeneratedClip
    {
        return GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => $start, 'end_seconds' => $end,
            'topic' => 't', 'reason' => 'r', 'status' => $status,
        ]);
    }

    private function dir(ClipProject $project): string
    {
        return storage_path('app/temp/'.$project->id);
    }

    /** applyLayout writes the output and records what it was asked to read */
    private function expectLayout(?array &$seen): void
    {
        $ffmpeg = $this->mock(FFmpegService::class);
        $ffmpeg->shouldReceive('applyLayout')->once()->andReturnUsing(function ($in, $layout, $out, $start, $end) use (&$seen) {
            $seen = [$in, $start, $end];
            file_put_contents($out, 'rendered');
        });
        $ffmpeg->shouldReceive('normalizeVoice')->andReturn(false);
        $ffmpeg->shouldReceive('outputFps')->andReturn(30.0);
    }

    public function test_a_missing_source_downloads_only_the_clip_with_a_margin_and_seeks_inside_it(): void
    {
        $project = $this->project();
        $clip = $this->clip($project, 1200, 1260);
        $section = $this->dir($project).'/section_'.$clip->id.'.mp4';

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldReceive('download')->never();
        $ytdlp->shouldReceive('downloadSection')->once()
            ->with('https://youtu.be/abcdefg', $section, 1197.0, 1263.0)
            ->andReturnUsing(fn ($url, $path) => file_put_contents($path, 'section'));
        $this->expectLayout($seen);

        $this->app->call([new RenderClipJob($clip->id), 'handle']);

        $this->assertSame('done', $clip->fresh()->status);
        // Source second 1200 is second 3 of the section
        $this->assertSame([$section, 3.0, 63.0], $seen);
        $this->assertFileDoesNotExist($section);
        $this->assertFileDoesNotExist($this->dir($project).'/video.mp4');
    }

    public function test_a_clip_near_the_start_clamps_the_section_at_zero(): void
    {
        $project = $this->project();
        $clip = $this->clip($project, 1, 31);

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldReceive('downloadSection')->once()->with(\Mockery::any(), \Mockery::any(), 0.0, 34.0)
            ->andReturnUsing(fn ($url, $path) => file_put_contents($path, 'section'));
        $this->expectLayout($seen);

        $this->app->call([new RenderClipJob($clip->id), 'handle']);

        $this->assertSame([1.0, 31.0], [$seen[1], $seen[2]]);
    }

    public function test_jump_cuts_read_the_section_with_words_on_its_clock(): void
    {
        $project = $this->project(['jump_cut' => true]);
        $clip = $this->clip($project, 1200, 1210);
        Transcript::create(['clip_project_id' => $project->id, 'language' => 'en', 'content' => [
            ['start' => 1200, 'end' => 1210, 'text' => 'so um yes', 'words' => [
                ['text' => 'so', 'start' => 1200.5, 'end' => 1201.0],
                ['text' => 'um', 'start' => 1202.0, 'end' => 1202.5],
            ]],
        ]]);
        $section = $this->dir($project).'/section_'.$clip->id.'.mp4';

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldReceive('downloadSection')->once()->andReturnUsing(fn ($url, $path) => file_put_contents($path, 'section'));
        $this->mock(SmartCut::class)->shouldReceive('pieces')->once()
            ->with($section, 3.0, 13.0, [
                ['start' => 3.5, 'end' => 4.0, 'text' => 'so'],
                ['start' => 5.0, 'end' => 5.5, 'text' => 'um'],
            ])
            ->andReturn([[0.0, 2.0], [2.4, 10.0]]);
        $this->expectLayout($seen);

        $this->app->call([new RenderClipJob($clip->id), 'handle']);

        $this->assertSame([$section, 3.0, 13.0], $seen);
    }

    public function test_a_source_still_in_video_cache_is_used_whole(): void
    {
        $project = $this->project();
        $clip = $this->clip($project, 1200, 1260);
        $full = $this->dir($project).'/video.mp4';

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(true);
        $ytdlp->shouldReceive('downloadSection')->never();
        $ytdlp->shouldReceive('download')->once()->with('https://youtu.be/abcdefg', $full)
            ->andReturnUsing(fn ($url, $path) => file_put_contents($path, 'src'));
        $this->expectLayout($seen);

        $this->app->call([new RenderClipJob($clip->id), 'handle']);

        $this->assertSame([$full, 1200.0, 1260.0], $seen);
        $this->assertFileExists($full); // the next clip of the project reads it too
    }

    public function test_clips_waiting_on_half_the_video_download_it_whole(): void
    {
        $project = $this->project(['duration_seconds' => 500]);
        $clip = $this->clip($project, 0, 90);
        $this->clip($project, 100, 190, 'processing');
        $this->clip($project, 200, 290, 'processing');
        $this->clip($project, 300, 390, 'done'); // already rendered: needs nothing
        $full = $this->dir($project).'/video.mp4';

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldReceive('downloadSection')->never();
        $ytdlp->shouldReceive('download')->once()->with('https://youtu.be/abcdefg', $full)
            ->andReturnUsing(fn ($url, $path) => file_put_contents($path, 'src'));
        $this->expectLayout($seen);

        // 3 x (90 s + margins) = 288 s of a 500 s video
        $this->app->call([new RenderClipJob($clip->id), 'handle']);

        $this->assertSame($full, $seen[0]);
    }

    public function test_hook_test_arms_on_one_cut_count_once(): void
    {
        $project = $this->project(['duration_seconds' => 600]);
        $clip = $this->clip($project, 0, 90, 'processing');
        $this->clip($project, 0, 90, 'processing');
        $this->clip($project, 0, 90, 'processing');

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldReceive('downloadSection')->once()->andReturnUsing(fn ($url, $path) => file_put_contents($path, 'section'));

        $source = app(ClipSource::class)->fetch($clip, $this->dir($project));

        $this->assertTrue($source['section']);
    }

    public function test_a_failed_section_falls_back_to_the_whole_video(): void
    {
        $project = $this->project();
        $clip = $this->clip($project, 1200, 1260);
        $full = $this->dir($project).'/video.mp4';

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldReceive('downloadSection')->once()->andReturnUsing(function ($url, $path) {
            file_put_contents($path, 'half');
            throw new RuntimeException('yt-dlp download failed: ffmpeg exited with code 8');
        });
        $ytdlp->shouldReceive('download')->once()->with('https://youtu.be/abcdefg', $full)
            ->andReturnUsing(fn ($url, $path) => file_put_contents($path, 'src'));
        $this->expectLayout($seen);

        $this->app->call([new RenderClipJob($clip->id), 'handle']);

        $this->assertSame('done', $clip->fresh()->status);
        $this->assertSame([$full, 1200.0, 1260.0], $seen);
        $this->assertFileDoesNotExist($this->dir($project).'/section_'.$clip->id.'.mp4');
    }

    public function test_a_cancel_during_the_section_does_not_start_the_whole_download(): void
    {
        $project = $this->project();
        $clip = $this->clip($project, 1200, 1260, 'processing');

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldReceive('downloadSection')->once()->andThrow(new JobCancelled);
        $ytdlp->shouldReceive('download')->never();

        $this->expectException(JobCancelled::class);
        app(ClipSource::class)->fetch($clip, $this->dir($project));
    }
}
