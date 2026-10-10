<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\DiscoverVideosJob;
use App\Jobs\RenderClipJob;
use App\Jobs\UploadToTikTokJob;
use App\Models\ClipProject;
use App\Models\DiscoveredVideo;
use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\DiscoveryService;
use App\Services\GeminiService;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutopilotTest extends TestCase
{
    use RefreshDatabase;

    private string $fakeYtDlp;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        // Fake yt-dlp: prints flat-playlist JSON lines and records its arguments
        $this->fakeYtDlp = sys_get_temp_dir().'/fake-ytdlp-'.uniqid();
        $lines = [
            ['id' => 'vidNew1aaaa', 'title' => 'Video baru 1', 'channel' => 'Chan', 'duration' => 1200],
            ['id' => 'vidShortaaa', 'title' => 'Terlalu pendek', 'channel' => 'Chan', 'duration' => 60],
            ['id' => 'vidSeenaaaa',  'title' => 'Sudah diproses', 'channel' => 'Chan', 'duration' => 1500],
            ['id' => 'vidNew2aaaa', 'title' => 'Video baru 2', 'channel' => 'Chan', 'duration' => 900],
            ['id' => 'vidLiveaaaa',  'title' => 'Akan live', 'live_status' => 'is_upcoming'],
        ];
        $out = implode("\n", array_map('json_encode', $lines));
        file_put_contents($this->fakeYtDlp, "#!/bin/sh\necho \"\$@\" > {$this->fakeYtDlp}.args\ncat <<'JSON'\n{$out}\nJSON\n");
        chmod($this->fakeYtDlp, 0755);
        config(['services.ytdlp.path' => $this->fakeYtDlp, 'services.ytdlp.cookies_from_browser' => '', 'services.ytdlp.cookies_file' => '']);
    }

    protected function tearDown(): void
    {
        @unlink($this->fakeYtDlp);
        @unlink($this->fakeYtDlp.'.args');
        parent::tearDown();
    }

    private function source(User $user, array $attrs = []): VideoSource
    {
        return VideoSource::create($attrs + [
            'user_id' => $user->id, 'type' => 'channel', 'value' => '@chan',
            'max_per_run' => 5, 'min_video_seconds' => 300, 'max_video_seconds' => 7200,
            'settings' => ['layout_type' => 'gaussian', 'clip_count' => 1, 'music' => ['enabled' => true, 'mood' => 'chill', 'volume' => 20]],
        ]);
    }

    public function test_channel_url_normalization(): void
    {
        $this->assertSame('https://www.youtube.com/@chan/videos', YtDlpService::channelVideosUrl('@chan'));
        $this->assertSame('https://www.youtube.com/@chan/videos', YtDlpService::channelVideosUrl('chan'));
        $this->assertSame('https://www.youtube.com/@chan/videos', YtDlpService::channelVideosUrl('https://www.youtube.com/@chan/'));
        $this->assertSame('https://www.youtube.com/@chan/streams', YtDlpService::channelVideosUrl('https://www.youtube.com/@chan/streams'));
    }

    public function test_list_videos_parses_and_skips_upcoming(): void
    {
        $videos = (new YtDlpService)->listVideos('keyword', 'podcast bisnis', 20);

        $this->assertSame(['vidNew1aaaa', 'vidShortaaa', 'vidSeenaaaa', 'vidNew2aaaa'], array_column($videos, 'id'));
        $this->assertStringContainsString('ytsearchdate20:podcast bisnis', file_get_contents($this->fakeYtDlp.'.args'));
    }

    public function test_discovery_starts_projects_for_new_videos_only(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $source = $this->source($user);
        DiscoveredVideo::create(['user_id' => $user->id, 'video_id' => 'vidSeenaaaa']);

        $started = app(DiscoveryService::class)->run($source);

        $this->assertCount(2, $started); // new_1 + new_2; short, seen and upcoming skipped
        $this->assertSame(
            ['https://www.youtube.com/watch?v=vidNew1aaaa', 'https://www.youtube.com/watch?v=vidNew2aaaa'],
            array_map(fn ($p) => $p->youtube_url, $started)
        );
        $project = $started[0]->fresh();
        $this->assertSame($source->id, $project->video_source_id);
        $this->assertSame('gaussian', $project->layout_type);
        $this->assertTrue($project->music_enabled);
        $this->assertSame('Video baru 1', $project->video_title);
        $this->assertNotNull($project->subtitleSetting);
        Queue::assertPushed(AnalyzeVideoJob::class, 2);
        $this->assertNotNull($source->fresh()->last_run_at);

        // Second run: nothing new
        $this->assertCount(0, app(DiscoveryService::class)->run($source->fresh()));
    }

    public function test_discovery_respects_max_per_run(): void
    {
        Queue::fake();
        $source = $this->source(User::factory()->create(), ['max_per_run' => 1]);

        $this->assertCount(1, app(DiscoveryService::class)->run($source));
    }

    public function test_analysis_of_autopilot_project_renders_clips_for_review(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $source = $this->source($user);
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $source->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'processing',
        ]);

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('download')->andReturnUsing(function ($url, $path) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, 'video');
        });
        $this->mock(TranscriptService::class)->shouldReceive('fetch')
            ->andReturn(['language' => 'id', 'segments' => [['start' => 0, 'end' => 5, 'text' => 'halo']]]);
        $this->mock(GeminiService::class)->shouldReceive('analyzeWithTranscript')->andReturn([
            ['ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30, 'topic' => 'T', 'reason' => 'R', 'music_mood' => 'chill'],
        ]);

        $this->app->call([new AnalyzeVideoJob($project->id), 'handle']);

        $clip = GeneratedClip::where('clip_project_id', $project->id)->firstOrFail();
        $this->assertSame('pending', $clip->review_status);
        Queue::assertPushed(RenderClipJob::class, fn ($job) => $job->clipId === $clip->id);
        exec('rm -rf '.escapeshellarg(storage_path('app/temp/'.$project->id)));
    }

    public function test_source_crud_snapshots_latest_manual_project(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg', 'layout_type' => 'gaussian',
            'clip_count' => 5, 'duration_mode' => 'auto', 'status' => 'done', 'music_enabled' => true, 'music_volume' => 25,
        ]);

        $this->actingAs($user)->postJson('/api/autopilot/sources', ['type' => 'keyword', 'value' => ' podcast ', 'min_minutes' => 10, 'max_minutes' => 60])
            ->assertCreated();
        $source = VideoSource::firstOrFail();
        $this->assertSame('podcast', $source->value);
        $this->assertSame(600, $source->min_video_seconds);
        $this->assertSame('gaussian', $source->settings['layout_type']);
        $this->assertSame(5, $source->settings['clip_count']);
        $this->assertSame(25, $source->settings['music']['volume']);

        $this->actingAs($user)->getJson('/api/autopilot/sources')->assertOk()->assertJsonPath('0.value', 'podcast');
        $this->actingAs($user)->patchJson("/api/autopilot/sources/{$source->id}", ['enabled' => false])->assertOk();
        $this->assertFalse($source->fresh()->enabled);

        $this->actingAs($user)->postJson("/api/autopilot/sources/{$source->id}/run")->assertOk();
        Queue::assertPushed(DiscoverVideosJob::class);

        $other = User::factory()->create();
        $this->actingAs($other)->deleteJson("/api/autopilot/sources/{$source->id}")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/autopilot/sources/{$source->id}")->assertOk();
    }

    public function test_review_approve_and_reject(): void
    {
        Queue::fake();
        Storage::fake();
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $this->source($user)->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 2, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        Storage::put('clips/a.mp4', 'a');
        Storage::put('clips/b.mp4', 'b');
        $mk = fn ($path) => GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'review_status' => 'pending', 'output_path' => $path,
        ]);
        $a = $mk('clips/a.mp4');
        $b = $mk('clips/b.mp4');
        $account = TiktokAccount::create([
            'user_id' => $user->id, 'open_id' => 'o', 'access_token' => 'x', 'refresh_token' => 'y',
            'access_expires_at' => now()->addDay(), 'scope' => 'user.info.basic,video.upload',
        ]);

        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()->assertJsonCount(2);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$a->id}/approve", ['account_id' => $account->id])->assertOk();
        $this->assertSame('approved', $a->fresh()->review_status);
        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->clipId === $a->id && $job->mode === 'inbox');

        $this->actingAs($user)->postJson("/api/autopilot/review/{$b->id}/reject")->assertOk();
        $this->assertSame('rejected', $b->fresh()->review_status);
        Storage::assertMissing('clips/b.mp4');

        $this->actingAs(User::factory()->create())->postJson("/api/autopilot/review/{$a->id}/reject")->assertForbidden();
    }
}
