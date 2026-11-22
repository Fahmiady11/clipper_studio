<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Models\ClipProject;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** Analysis reads captions and video info only; media is downloaded just for Whisper, and then only the sound. */
class AnalysisDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const META = ['title' => 'Podcast Ep. 39', 'duration_seconds' => 3433, 'thumbnail_url' => 'https://i.ytimg.com/vi/abcdefg/maxresdefault.jpg', 'channel' => 'Mario', 'language' => 'id'];

    private const SEGMENTS = [['start' => 0, 'end' => 40, 'text' => 'halo semua']];

    private ClipProject $project;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->project = ClipProject::create([
            'user_id' => User::factory()->create()->id, 'youtube_url' => 'https://youtu.be/abcdefg', 'layout_type' => 'reframe',
            'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'processing',
        ]);
        $this->dir = storage_path('app/temp/'.$this->project->id);
        $this->mock(GeminiService::class)->shouldReceive('analyzeWithTranscript')
            ->andReturn([['ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30, 'topic' => 'T', 'reason' => 'R']]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function analyze(): void
    {
        $this->app->call([new AnalyzeVideoJob($this->project->id), 'handle']);
    }

    public function test_with_captions_nothing_is_downloaded_and_the_video_info_is_stored(): void
    {
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('getMetadata')->once()->andReturn(self::META);
        $ytdlp->shouldNotReceive('download');
        $ytdlp->shouldNotReceive('downloadAudio');
        $this->mock(TranscriptService::class)->shouldReceive('fetch')->once()
            ->andReturn(['language' => 'id', 'segments' => self::SEGMENTS]);

        $this->analyze();

        $project = $this->project->fresh();
        $this->assertSame('done', $project->status);
        $this->assertSame(3433, $project->duration_seconds); // ClipSource weighs sections against this
        $this->assertSame('Podcast Ep. 39', $project->video_title);
        $this->assertSame('Mario', $project->source_channel);
        $this->assertSame(self::META['thumbnail_url'], $project->thumbnail_url);
        $this->assertFileDoesNotExist($this->dir.'/video.mp4');
    }

    public function test_autopilot_title_and_channel_from_the_listing_are_kept(): void
    {
        $this->project->update(['video_title' => 'Listed title', 'source_channel' => 'Listed channel']);
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('getMetadata')->andReturn(['title' => 'Unknown', 'channel' => 'Unknown'] + self::META);
        $this->mock(TranscriptService::class)->shouldReceive('fetch')->andReturn(['language' => 'id', 'segments' => self::SEGMENTS]);

        $this->analyze();

        $project = $this->project->fresh();
        $this->assertSame('Listed title', $project->video_title);
        $this->assertSame('Listed channel', $project->source_channel);
        $this->assertSame(3433, $project->duration_seconds);
    }

    public function test_video_info_failing_does_not_fail_the_analysis(): void
    {
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('getMetadata')->andThrow(new RuntimeException('yt-dlp metadata failed: HTTP Error 429'));
        $this->mock(TranscriptService::class)->shouldReceive('fetch')->andReturn(['language' => 'id', 'segments' => self::SEGMENTS]);

        $this->analyze();

        $project = $this->project->fresh();
        $this->assertSame('done', $project->status);
        $this->assertNull($project->duration_seconds);
        $this->assertNull($project->video_title);
    }

    public function test_without_captions_only_the_audio_is_downloaded_for_whisper_then_deleted(): void
    {
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('getMetadata')->andReturn(self::META);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldNotReceive('download');
        $ytdlp->shouldReceive('downloadAudio')->once()->with('https://youtu.be/abcdefg', $this->dir)
            ->andReturnUsing(function ($url, $dir) {
                file_put_contents($dir.'/audio.webm', 'opus');

                return $dir.'/audio.webm';
            });
        $handedToWhisper = null;
        $this->mock(TranscriptService::class)->shouldReceive('fetch')->once()
            ->andReturnUsing(function ($url, $audioFile) use (&$handedToWhisper) {
                $handedToWhisper = $audioFile();
                $this->assertFileExists($handedToWhisper);

                return ['language' => 'id', 'segments' => self::SEGMENTS];
            });

        $this->analyze();

        $this->assertSame($this->dir.'/audio.webm', $handedToWhisper);
        $this->assertSame('done', $this->project->fresh()->status);
        $this->assertSame([], glob($this->dir.'/*')); // the transcript is stored; the sound is not kept
    }

    public function test_whisper_reads_the_cached_full_video_instead_of_downloading(): void
    {
        $cached = storage_path('app/video_cache/abcdefg-1080p.mp4');
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('getMetadata')->andReturn(self::META);
        $ytdlp->shouldReceive('isCached')->andReturn(true);
        $ytdlp->shouldReceive('cachedPathFor')->andReturn($cached);
        $ytdlp->shouldNotReceive('downloadAudio');
        $ytdlp->shouldNotReceive('download');
        $handedToWhisper = null;
        $this->mock(TranscriptService::class)->shouldReceive('fetch')
            ->andReturnUsing(function ($url, $audioFile) use (&$handedToWhisper) {
                $handedToWhisper = $audioFile();

                return ['language' => 'id', 'segments' => self::SEGMENTS];
            });

        $this->analyze();

        $this->assertSame($cached, $handedToWhisper);
    }

    public function test_audio_download_failure_fails_the_analysis_with_the_yt_dlp_error(): void
    {
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('getMetadata')->andReturn(self::META);
        $ytdlp->shouldReceive('isCached')->andReturn(false);
        $ytdlp->shouldReceive('downloadAudio')->andThrow(new RuntimeException('yt-dlp download failed: HTTP Error 403: Forbidden'));
        $this->mock(TranscriptService::class)->shouldReceive('fetch')->andReturnUsing(fn ($url, $audioFile) => $audioFile());

        $this->expectExceptionMessage('HTTP Error 403');
        $this->analyze();
    }
}
