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
use Illuminate\Support\Facades\File;
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
        $this->assertStringContainsString('ytsearch20:podcast bisnis', file_get_contents($this->fakeYtDlp.'.args'));
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
            ['ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30, 'topic' => 'T', 'reason' => 'R', 'music_mood' => 'chill',
                'broll' => [['time' => 12.5, 'query' => 'counting money'], 'junk', ['time' => 20]]],
        ]);

        $this->app->call([new AnalyzeVideoJob($project->id), 'handle']);

        $clip = GeneratedClip::where('clip_project_id', $project->id)->firstOrFail();
        $this->assertSame('pending', $clip->review_status);
        $this->assertSame([['time' => 12.5, 'query' => 'counting money']], $clip->broll_json); // ready for the render, no extra AI call
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

        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pending', 2);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$a->id}/approve", ['account_id' => $account->id])->assertOk();
        $this->assertSame('approved', $a->fresh()->review_status);
        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->clipId === $a->id && $job->mode === 'inbox');

        $this->actingAs($user)->postJson("/api/autopilot/review/{$b->id}/reject")->assertOk();
        $this->assertSame('rejected', $b->fresh()->review_status);
        Storage::assertMissing('clips/b.mp4');

        $this->actingAs(User::factory()->create())->postJson("/api/autopilot/review/{$a->id}/reject")->assertForbidden();
    }

    public function test_rejecting_the_last_unrendered_clip_frees_the_source_video(): void
    {
        Queue::fake();
        Storage::fake();
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $this->source($user)->id, 'youtube_url' => 'https://youtu.be/rejectTest1',
            'layout_type' => 'reframe', 'clip_count' => 2, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $mk = fn ($status) => GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => $status, 'review_status' => 'pending',
        ]);
        $done = $mk('done');
        $queued = $mk('pending'); // still waiting to render: needs the source

        $source = storage_path("app/temp/{$project->id}/video.mp4");
        $cache = (new YtDlpService)->cachedPathFor($project->youtube_url);
        @mkdir(dirname($source), 0755, true);
        @mkdir(dirname($cache), 0755, true);
        file_put_contents($source, 'video');
        link($source, $cache);

        try {
            $this->actingAs($user)->postJson("/api/autopilot/review/{$done->id}/reject")->assertOk();
            $this->assertFileExists($source);
            $this->assertFileExists($cache);

            $this->actingAs($user)->postJson("/api/autopilot/review/{$queued->id}/reject")->assertOk();
            $this->assertDirectoryDoesNotExist(dirname($source));
            $this->assertFileDoesNotExist($cache);
        } finally {
            File::deleteDirectory(dirname($source));
            @unlink($cache);
        }
    }

    public function test_review_lists_caption_to_copy_and_failed_caption_is_retried(): void
    {
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $this->source($user)->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 2, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $mk = fn (array $extra) => GeneratedClip::create($extra + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'review_status' => 'pending',
        ]);
        $ready = $mk(['caption' => 'Caption siap', 'hashtags_json' => ['#a', '#b']]);
        $fresh = $mk([]);

        $rows = collect($this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('Caption siap', $rows[$ready->id]['caption']);
        $this->assertSame(['#a', '#b'], $rows[$ready->id]['hashtags']);
        $this->assertNull($rows[$fresh->id]['caption']);

        // Gemini failed (empty caption): nothing is cached, so the next call tries again
        $gemini = $this->mock(GeminiService::class);
        $gemini->shouldReceive('generateCaption')->once()->andReturn(['caption' => '', 'hashtags' => []]);
        $gemini->shouldReceive('generateCaption')->once()->andReturn(['caption' => 'Baru', 'hashtags' => ['#c']]);

        $this->actingAs($user)->getJson("/api/clips/{$fresh->id}/caption")->assertOk()->assertJson(['caption' => '']);
        $this->assertNull($fresh->fresh()->caption);
        $this->actingAs($user)->getJson("/api/clips/{$fresh->id}/caption")->assertOk()->assertJson(['caption' => 'Baru']);
        $this->assertSame('Baru', $fresh->fresh()->caption);
    }

    public function test_review_is_paginated_with_newest_clips_first(): void
    {
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $this->source($user)->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 12, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        foreach (range(1, 12) as $i) {
            $clip = GeneratedClip::create([
                'clip_project_id' => $project->id, 'ranking' => $i, 'start_seconds' => 0, 'end_seconds' => 30,
                'topic' => "clip {$i}", 'reason' => 'r', 'status' => 'done',
                'review_status' => $i <= 2 ? 'pending' : 'rejected', // still-pending ones are the oldest
            ]);
            $clip->forceFill(['created_at' => now()->subHours(12 - $i), 'updated_at' => now()->subHours(20)])->save();
        }

        $first = $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonCount(9, 'data')
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 12)
            ->assertJsonPath('pending', 2);
        $this->assertSame(['clip 12', 'clip 11', 'clip 10'], array_slice(array_column($first->json('data'), 'topic'), 0, 3));

        $second = $this->actingAs($user)->getJson('/api/autopilot/review?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(['clip 3', 'clip 2', 'clip 1'], array_column($second->json('data'), 'topic'));
    }

    public function test_clean_approved_removes_finished_clips_and_empty_projects(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $mkProject = fn () => ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $this->source($user)->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 2, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $done = $mkProject();
        $mixed = $mkProject();
        $mk = fn ($project, $review, $tiktok, $path = null) => GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'review_status' => $review,
            'tiktok_status' => $tiktok, 'output_path' => $path,
        ]);
        Storage::put('clips/sent.mp4', 'a');
        Storage::put('clips/up.mp4', 'b');
        $sent = $mk($done, 'approved', 'inbox', 'clips/sent.mp4');
        $failed = $mk($mixed, 'approved', 'failed');
        $uploading = $mk($mixed, 'approved', 'uploading', 'clips/up.mp4');
        $pending = $mk($mixed, 'pending', null);

        $this->actingAs(User::factory()->create())->postJson('/api/autopilot/review/clean-approved')
            ->assertOk()->assertJson(['removed' => 0]);

        $this->actingAs($user)->postJson('/api/autopilot/review/clean-approved')->assertOk()->assertJson(['removed' => 2]);

        $this->assertModelMissing($sent);
        $this->assertModelMissing($failed);
        Storage::assertMissing('clips/sent.mp4');
        $this->assertModelMissing($done);               // nothing left in it
        $this->assertModelExists($uploading);           // TikTok still has it in flight
        Storage::assertExists('clips/up.mp4');
        $this->assertModelExists($pending);
        $this->assertModelExists($mixed);
    }

    public function test_clip_download_supports_range_requests_for_previews(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        Storage::put('clips/x.mp4', '0123456789');
        $clip = GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'output_path' => 'clips/x.mp4',
        ]);

        $preview = $this->actingAs($user)->get("/api/clips/{$clip->id}/download?inline=1", ['Range' => 'bytes=2-5']);
        $preview->assertStatus(206);
        $this->assertSame('2345', $preview->streamedContent());
        $this->assertFalse($preview->headers->has('Content-Disposition')); // played inline, not saved

        $this->actingAs($user)->get("/api/clips/{$clip->id}/download")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=clip_'.$clip->id.'.mp4');
    }

    public function test_review_filters_by_status_and_counts_each(): void
    {
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $this->source($user)->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 6, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        foreach (['pending', 'pending', 'pending', 'approved', 'approved', 'rejected'] as $i => $review) {
            GeneratedClip::create([
                'clip_project_id' => $project->id, 'ranking' => $i + 1, 'start_seconds' => 0, 'end_seconds' => 30,
                'topic' => "clip {$i}", 'reason' => 'r', 'status' => 'done', 'review_status' => $review,
            ]);
        }

        $counts = ['pending' => 3, 'approved' => 2, 'rejected' => 1];
        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonCount(6, 'data')->assertJsonPath('counts', $counts);

        foreach ($counts as $status => $n) {
            $res = $this->actingAs($user)->getJson("/api/autopilot/review?status={$status}")->assertOk()
                ->assertJsonCount($n, 'data')->assertJsonPath('total', $n)->assertJsonPath('counts', $counts);
            $this->assertSame([$status], array_values(array_unique(array_column($res->json('data'), 'review_status'))));
        }

        $this->actingAs($user)->getJson('/api/autopilot/review?status=bogus')->assertUnprocessable();
    }
}
