<?php

namespace Tests\Feature;

use App\Jobs\PublishToMetaJob;
use App\Jobs\UploadToYouTubeJob;
use App\Models\ClipProject;
use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Models\PlatformSetting;
use App\Models\ScheduledPost;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\YoutubeAccount;
use App\Services\FacebookReels;
use App\Services\MetaPublishing;
use App\Services\MetaStatusPoller;
use App\Services\PublicMediaUrl;
use App\Services\ScheduledPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class FacebookReelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.facebook.app_id' => 'app-1',
            'services.facebook.app_secret' => 'secret',
            'services.facebook.redirect_uri' => 'https://clip.test/facebook/callback',
            'services.facebook.graph_version' => 'v26.0',
        ]);
        Storage::fake();
        Queue::fake();
        // Tuesday 10:00 in Jakarta: today's 09:00 slot has passed
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Jakarta'));
    }

    private function page(User $user, array $attrs = []): MetaTarget
    {
        $account = MetaAccount::firstOrCreate(
            ['user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => 'fb'.$user->id],
            ['name' => 'Rina', 'access_token' => 'user-long'],
        );

        return MetaTarget::create($attrs + [
            'user_id' => $user->id, 'meta_account_id' => $account->id, 'platform' => 'facebook',
            'external_id' => '111', 'name' => 'Toko Rina', 'access_token' => 'page-token',
        ]);
    }

    private function clip(User $user, array $attrs = [], bool $review = false): GeneratedClip
    {
        $source = $review ? VideoSource::create([
            'user_id' => $user->id, 'type' => 'channel', 'value' => '@chan',
            'max_per_run' => 1, 'min_video_seconds' => 300, 'max_video_seconds' => 7200, 'settings' => [],
        ]) : null;
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $source?->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'video_title' => 'Podcast', 'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $path = "clips/{$user->id}/".uniqid().'.mp4';
        Storage::put($path, 'video'); // not a real video: the Reels checks fall back to end - start

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 40,
            'topic' => 'Rahasia bisnis kecil', 'reason' => 'r', 'status' => 'done', 'review_status' => $review ? 'pending' : null,
            'output_path' => $path, 'caption' => 'Dengar ini sampai habis.', 'hashtags_json' => ['#bisnis', '#umkm'],
        ]);
    }

    private function fakeReelUpload(): void
    {
        Http::fake([
            'graph.facebook.com/v26.0/111/video_reels' => fn (Request $r) => Http::response(
                $r->data()['upload_phase'] === 'start'
                    ? ['video_id' => 'v-77', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/v-77']
                    : ['success' => true]
            ),
            'rupload.facebook.com/*' => Http::response(['success' => true]),
        ]);
    }

    private function runJob(PublishToMetaJob $job): void
    {
        try {
            $job->handle(app(MetaPublishing::class));
        } catch (Throwable $e) {
            $job->failed($e);
        }
    }

    // ── calendar ────────────────────────────────────────────────────────

    public function test_calendar_schedules_a_reel_and_the_publisher_hands_it_to_the_job(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['approved_at' => now()])->save();
        $page = $this->page($user);
        $clip = $this->clip($user);

        $this->actingAs($user)->postJson('/api/schedule', [
            'clip_id' => $clip->id, 'platform' => 'facebook', 'account_id' => $page->id,
            'scheduled_at' => now()->addHour()->toISOString(), 'description' => 'Caption FB #bisnis',
        ])->assertCreated()
            ->assertJsonPath('post.platform', 'facebook')
            ->assertJsonPath('post.account_label', 'Toko Rina')
            ->assertJsonPath('post.options.description', 'Caption FB #bisnis');

        // Someone else's Page
        $other = $this->page(User::factory()->create(), ['external_id' => '999']);
        $this->actingAs($user)->postJson('/api/schedule', [
            'clip_id' => $this->clip($user)->id, 'platform' => 'facebook', 'account_id' => $other->id, 'scheduled_at' => now()->addHour()->toISOString(),
        ])->assertNotFound();

        $this->travel(61)->minutes();
        $this->assertSame(1, app(ScheduledPublisher::class)->publishDue());
        $this->assertSame(ScheduledPost::STATUS_QUEUED, ScheduledPost::where('platform', 'facebook')->sole()->status);
        Queue::assertPushed(PublishToMetaJob::class, fn ($job) => $job->clipId === $clip->id && $job->platform === 'facebook'
            && $job->targetId === $page->id && $job->text === 'Caption FB #bisnis');

        $this->actingAs($user)->getJson('/api/schedule?from=2026-11-01&to=2026-12-01')->assertOk()
            ->assertJsonPath('posts.0.delivery', 'queued')
            ->assertJsonPath('posts.0.reconnect_url', null);
    }

    public function test_calendar_refuses_a_clip_longer_than_a_reel_with_the_reason(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);

        $this->actingAs($user)->postJson('/api/schedule', [
            'clip_id' => $this->clip($user, ['end_seconds' => 120])->id, 'platform' => 'facebook', 'account_id' => $page->id,
            'scheduled_at' => now()->addHour()->toISOString(),
        ])->assertUnprocessable()->assertJsonPath('message', 'Reels Facebook maksimal 90 detik, clip ini 120 detik. Pendekkan clip dulu.');

        $this->actingAs($user)->postJson('/api/schedule', [
            'clip_id' => $this->clip($user, ['end_seconds' => 2])->id, 'platform' => 'facebook', 'account_id' => $page->id,
            'scheduled_at' => now()->addHour()->toISOString(),
        ])->assertUnprocessable()->assertJsonPath('message', 'Reels Facebook minimal 3 detik, clip ini 2 detik.');
    }

    public function test_a_disconnected_page_fails_the_post_visibly(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['approved_at' => now()])->save();
        $page = $this->page($user);
        $clip = $this->clip($user);
        ScheduledPost::create([
            'user_id' => $user->id, 'generated_clip_id' => $clip->id, 'platform' => 'facebook', 'account_id' => $page->id,
            'scheduled_at' => now()->subMinute(), 'status' => 'scheduled', 'options' => ['description' => 'x'],
        ]);
        $page->delete();

        app(ScheduledPublisher::class)->publishDue();

        $post = ScheduledPost::sole();
        $this->assertSame('failed', $post->status);
        $this->assertSame('Page Facebook sudah diputus. Hubungkan lagi lalu jadwalkan ulang.', $post->error);
        Queue::assertNotPushed(PublishToMetaJob::class);
    }

    // ── upload job ──────────────────────────────────────────────────────

    public function test_job_runs_the_three_reel_phases_and_leaves_it_processing(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        $clip = $this->clip($user);
        $this->fakeReelUpload();

        $this->runJob(new PublishToMetaJob($clip->id, 'facebook', $page->id, "Caption\n\n#bisnis"));

        $pub = ClipPublication::sole();
        $this->assertSame(['facebook', 'processing', 'v-77', $page->id, null], [$pub->platform, $pub->status, $pub->external_id, $pub->target_id, $pub->error]);
        $this->assertNotNull($pub->uploaded_at);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(3, $sent);
        [$start, $upload, $finish] = $sent->all();
        $this->assertSame(['start', 'page-token'], [$start->data()['upload_phase'], $start->data()['access_token']]);
        $this->assertSame(hash_hmac('sha256', 'page-token', 'secret'), $start->data()['appsecret_proof']);
        $this->assertSame('https://rupload.facebook.com/video-upload/v26.0/v-77', $upload->url());
        $this->assertSame(['OAuth page-token', '0', '5'], [$upload->header('Authorization')[0], $upload->header('offset')[0], $upload->header('file_size')[0]]);
        $this->assertSame('video', $upload->body());
        $this->assertSame(['finish', 'v-77', 'PUBLISHED', "Caption\n\n#bisnis"], [
            $finish->data()['upload_phase'], $finish->data()['video_id'], $finish->data()['video_state'], $finish->data()['description'],
        ]);
    }

    public function test_an_expired_page_token_fails_with_a_reconnect_link_in_calendar_and_review(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        $clip = $this->clip($user, [], review: true);
        $clip->update(['review_status' => 'approved']);
        ScheduledPost::create([
            'user_id' => $user->id, 'generated_clip_id' => $clip->id, 'platform' => 'facebook', 'account_id' => $page->id,
            'scheduled_at' => now()->addMinutes(5), 'status' => 'queued', 'options' => ['description' => 'x'],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]], 400)]);

        $this->runJob(new PublishToMetaJob($clip->id, 'facebook', $page->id, 'x'));

        $pub = ClipPublication::sole();
        $this->assertSame('failed', $pub->status);
        $this->assertStringContainsString('Hubungkan ulang Facebook', $pub->error);

        $this->actingAs($user)->getJson('/api/schedule?from=2026-11-01&to=2026-12-01')->assertOk()
            ->assertJsonPath('posts.0.delivery', 'failed')
            ->assertJsonPath('posts.0.reconnect_url', '/settings/accounts?reconnect=facebook')
            ->assertJsonPath('posts.0.error', $pub->error);
        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.facebook.delivery', 'failed')
            ->assertJsonPath('data.0.facebook.reconnect_url', '/settings/accounts?reconnect=facebook');
    }

    public function test_job_refuses_another_users_page_and_a_missing_file(): void
    {
        $user = User::factory()->create();
        $foreign = $this->page(User::factory()->create(), ['external_id' => '999']);
        $clip = $this->clip($user);
        Http::fake();

        $this->runJob(new PublishToMetaJob($clip->id, 'facebook', $foreign->id, 'x'));
        $this->assertSame('Page Facebook bukan milik pemilik clip.', ClipPublication::sole()->error);

        Storage::delete($clip->output_path);
        $this->runJob(new PublishToMetaJob($clip->id, 'facebook', $this->page($user)->id, 'x'));
        $this->assertStringStartsWith('File clip tidak tersedia', ClipPublication::sole()->error);
        Http::assertNothingSent();
    }

    // ── status poll ─────────────────────────────────────────────────────

    private function processing(GeneratedClip $clip, MetaTarget $page, array $attrs = []): ClipPublication
    {
        return ClipPublication::create($attrs + [
            'generated_clip_id' => $clip->id, 'platform' => 'facebook', 'target_id' => $page->id,
            'status' => 'processing', 'external_id' => 'v-77', 'uploaded_at' => now(),
        ]);
    }

    public function test_poller_settles_published_and_failed_reels(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        $done = $this->processing($this->clip($user), $page);
        $bad = $this->processing($this->clip($user), $page, ['external_id' => 'v-88']);
        Http::fake([
            'graph.facebook.com/v26.0/v-77*' => Http::response(['id' => 'v-77', 'permalink_url' => '/reel/v-77/', 'status' => [
                'video_status' => 'ready', 'publishing_phase' => ['status' => 'complete', 'publish_status' => 'published'],
            ]]),
            'graph.facebook.com/v26.0/v-88*' => Http::response(['id' => 'v-88', 'status' => [
                'video_status' => 'error', 'processing_phase' => ['status' => 'error', 'errors' => [['code' => 1, 'message' => 'Rasio video tidak didukung']]],
            ]]),
        ]);

        $this->assertSame(['checked' => 2, 'settled' => 2], app(MetaStatusPoller::class)->run());

        $this->assertSame(['published', 'https://www.facebook.com/reel/v-77/'], [$done->fresh()->status, $done->fresh()->url]);
        $this->assertSame(['failed', 'Facebook gagal memproses Reel: Rasio video tidak didukung.'], [$bad->fresh()->status, $bad->fresh()->error]);

        // Settled ones are not asked again
        Http::fake();
        $this->assertSame(['checked' => 0, 'settled' => 0], app(MetaStatusPoller::class)->run());
    }

    public function test_poller_keeps_processing_on_api_errors_backs_off_and_gives_up(): void
    {
        $user = User::factory()->create();
        $pub = $this->processing($this->clip($user), $this->page($user));
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Server busy', 'code' => 2]], 500)]);

        app(MetaStatusPoller::class)->run();
        $pub->refresh();
        $this->assertSame('processing', $pub->status);
        $this->assertStringStartsWith('Status Facebook belum bisa dicek:', $pub->error);

        // Two hours in: every 15 minutes
        $pub->update(['uploaded_at' => now()->subHours(2), 'checked_at' => now()->subMinutes(5)]);
        $this->assertFalse(app(MetaStatusPoller::class)->isDue($pub->fresh()));
        $pub->update(['checked_at' => now()->subMinutes(16)]);
        $this->assertTrue(app(MetaStatusPoller::class)->isDue($pub->fresh()));

        // Still processing two days later: taken as published, like YouTube, with a note that it is unconfirmed
        Http::fake(['graph.facebook.com/*' => Http::response(['status' => ['video_status' => 'processing']])]);
        $pub->update(['uploaded_at' => now()->subHours(49), 'checked_at' => null]);
        app(MetaStatusPoller::class)->run();
        $this->assertSame('published', $pub->fresh()->status);
        $this->assertStringContainsString('belum terkonfirmasi', $pub->fresh()->error);

        // Shown as a note on a published post, not as an error
        $user->forceFill(['approved_at' => now()])->save();
        ScheduledPost::create([
            'user_id' => $user->id, 'generated_clip_id' => $pub->generated_clip_id, 'platform' => 'facebook', 'account_id' => $pub->target_id,
            'scheduled_at' => now()->addMinutes(5), 'status' => 'queued', 'options' => [],
        ]);
        $this->actingAs($user)->getJson('/api/schedule?from=2026-11-01&to=2026-12-01')->assertOk()
            ->assertJsonPath('posts.0.delivery', 'published')
            ->assertJsonPath('posts.0.error', null)
            ->assertJsonPath('posts.0.note', $pub->fresh()->error);
    }

    // ── autopilot ───────────────────────────────────────────────────────

    private function autopost(User $user, string $platform, int $accountId, array $attrs = []): PlatformSetting
    {
        return PlatformSetting::create($attrs + [
            'user_id' => $user->id, 'platform' => $platform, 'account_id' => $accountId, 'enabled' => true,
            'max_per_day' => 3, 'slot_times' => ['09:00', '15:00', '20:00'], 'timezone' => 'Asia/Jakarta',
        ]);
    }

    public function test_facebook_autopost_settings_save_and_validate(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);

        $this->actingAs($user)->getJson('/api/autopilot/facebook')->assertOk()
            ->assertJson(['platform' => 'facebook', 'configured' => true, 'enabled' => false, 'saved' => false])
            ->assertJsonPath('accounts.0', ['id' => $page->id, 'name' => 'Toko Rina']);

        $this->actingAs($user)->putJson('/api/autopilot/facebook', [
            'enabled' => true, 'account_id' => $page->id, 'max_per_day' => 2, 'slot_times' => ['21:00', '07:00'], 'timezone' => 'Asia/Jakarta',
        ])->assertOk()->assertJson(['enabled' => true, 'account_id' => $page->id, 'slot_times' => ['07:00', '21:00'], 'saved' => true]);
        $this->assertSame(['facebook'], PlatformSetting::pluck('platform')->all());

        // Saving without account_id keeps the default Page
        $this->actingAs($user)->putJson('/api/autopilot/facebook', [
            'enabled' => true, 'max_per_day' => 2, 'slot_times' => ['07:00'], 'timezone' => 'Asia/Jakarta',
        ])->assertOk()->assertJson(['account_id' => $page->id]);

        $foreign = $this->page(User::factory()->create(), ['external_id' => '999']);
        $this->actingAs($user)->putJson('/api/autopilot/facebook', [
            'enabled' => true, 'account_id' => $foreign->id, 'max_per_day' => 2, 'slot_times' => ['07:00'], 'timezone' => 'Asia/Jakarta',
        ])->assertNotFound();
        $this->actingAs($user)->getJson('/api/autopilot/linkedin')->assertNotFound();
    }

    public function test_approve_books_youtube_and_facebook_slots_independently(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['approved_at' => now()])->save();
        $page = $this->page($user);
        $channel = YoutubeAccount::create([
            'user_id' => $user->id, 'channel_id' => 'UC1', 'title' => 'Channel', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(),
        ]);
        $this->autopost($user, 'youtube', $channel->id);
        $this->autopost($user, 'facebook', $page->id, ['slot_times' => ['12:00']]);
        $clip = $this->clip($user, [], review: true);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk()
            ->assertJsonPath('youtube.scheduled_at', '2026-11-10T08:00:00.000000Z') // 15:00 WIB
            ->assertJsonPath('facebook.scheduled_at', '2026-11-10T05:00:00.000000Z'); // 12:00 WIB

        $fb = ScheduledPost::where('platform', 'facebook')->sole();
        $this->assertSame([$page->id, "Dengar ini sampai habis.\n\n#bisnis #umkm", true], [$fb->account_id, $fb->options['description'], $fb->options['autopilot']]);

        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.facebook.status', 'scheduled')
            ->assertJsonPath('data.0.facebook.post_id', $fb->id)
            ->assertJsonPath('data.0.youtube.status', 'scheduled');

        // Both go out through the calendar at their own time
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00', 'Asia/Jakarta'));
        $this->assertSame(2, app(ScheduledPublisher::class)->publishDue());
        Queue::assertPushed(PublishToMetaJob::class, fn ($job) => $job->clipId === $clip->id && $job->platform === 'facebook' && $job->targetId === $page->id);
        Queue::assertPushed(UploadToYouTubeJob::class);

        // Sent: the approval stays
        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/unapprove")->assertUnprocessable();
    }

    public function test_facebook_alone_is_enough_to_approve_and_a_long_clip_keeps_its_reason(): void
    {
        $user = User::factory()->create();
        $this->autopost($user, 'facebook', $this->page($user)->id);

        $long = $this->clip($user, ['end_seconds' => 150], review: true);
        $reason = 'Tidak dijadwalkan ke Facebook: Reels Facebook maksimal 90 detik, clip ini 150 detik. Pendekkan clip dulu.';
        $this->actingAs($user)->postJson("/api/autopilot/review/{$long->id}/approve")->assertOk()
            ->assertJsonPath('facebook.error', $reason)
            ->assertJsonPath('youtube', null);
        $this->assertSame(['approved', 0], [$long->fresh()->review_status, ScheduledPost::count()]);

        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.facebook.status', 'skipped')
            ->assertJsonPath('data.0.facebook.error', $reason);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$long->id}/unapprove")->assertOk();
        $this->assertSame(0, ClipPublication::count());

        // Page disconnected after the settings were saved
        MetaTarget::query()->delete();
        $this->actingAs($user)->postJson('/api/autopilot/review/'.$this->clip($user, [], review: true)->id.'/approve')->assertOk()
            ->assertJsonPath('facebook.error', fn ($m) => str_contains($m, 'Belum ada Page Facebook yang terhubung'));
    }

    // ── signed media URL ────────────────────────────────────────────────

    public function test_signed_media_url_serves_the_clip_without_login_until_it_expires(): void
    {
        config(['app.url' => 'https://clip.test']);
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $url = app(PublicMediaUrl::class)->clip($clip, 30);
        $this->assertStringStartsWith("https://clip.test/media/clips/{$clip->id}?expires=", $url);
        $path = substr($url, strlen('https://clip.test'));

        $res = $this->get($path);
        $res->assertOk()->assertHeader('Content-Type', 'video/mp4')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame('video', $res->streamedContent());

        // Range requests (video fetchers) get a partial answer
        $this->get($path, ['Range' => 'bytes=0-1'])->assertStatus(206);

        // Tampered, unsigned, or another clip's id under this signature
        $this->get($path.'x')->assertForbidden();
        $this->get("/media/clips/{$clip->id}")->assertForbidden();
        $other = $this->clip($user);
        $this->get(str_replace($clip->id, $other->id, $path))->assertForbidden();

        $this->travel(31)->minutes();
        $this->get($path)->assertForbidden();
    }

    public function test_signed_media_url_hides_clips_that_are_not_rendered(): void
    {
        config(['app.url' => 'https://clip.test']);
        $clip = $this->clip(User::factory()->create());
        $path = substr(app(PublicMediaUrl::class)->clip($clip), strlen('https://clip.test'));

        Storage::delete($clip->output_path);
        $this->get($path)->assertNotFound();
        $clip->update(['status' => 'processing']);
        $this->get($path)->assertNotFound();
    }

    // ── Reels rules ─────────────────────────────────────────────────────

    public function test_reel_rules_read_the_rendered_file(): void
    {
        exec('ffmpeg -version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg is not installed');
        }
        $clip = $this->clip(User::factory()->create(), ['end_seconds' => 200]); // the file's length wins over end - start
        $make = function (string $size, int $seconds) use ($clip) {
            exec(sprintf('ffmpeg -y -v error -f lavfi -i testsrc=size=%s:rate=24 -t %d -pix_fmt yuv420p %s', $size, $seconds, escapeshellarg(Storage::path($clip->output_path))));
        };
        $reels = app(FacebookReels::class);

        $make('540x960', 4);
        $this->assertNull($reels->refusal($clip));
        $make('640x360', 4);
        $this->assertSame('Reels Facebook harus vertikal 9:16, clip ini 640×360.', $reels->refusal($clip));
        $make('360x640', 4);
        $this->assertSame('Resolusi Reels Facebook minimal 540×960, clip ini 360×640.', $reels->refusal($clip));
    }

    public function test_status_mapping_from_the_video_node(): void
    {
        $this->assertSame('processing', FacebookReels::statusFromItem(['status' => ['video_status' => 'processing', 'publishing_phase' => ['status' => 'not_started']]], 'v')['status']);
        $this->assertSame(['status' => 'published', 'reason' => null, 'url' => 'https://www.facebook.com/reel/v'],
            FacebookReels::statusFromItem(['status' => ['publishing_phase' => ['status' => 'completed', 'publish_status' => 'published']]], 'v'));
        $this->assertSame('Upload Reel kedaluwarsa sebelum selesai.', FacebookReels::statusFromItem(['status' => ['video_status' => 'expired']], 'v')['reason']);
        $this->assertSame('failed', FacebookReels::statusFromItem(['status' => ['video_status' => 'upload_failed']], 'v')['status']);
    }
}
