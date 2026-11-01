<?php

namespace Tests\Feature;

use App\Jobs\UploadToTikTokJob;
use App\Jobs\UploadToYouTubeJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\YoutubeAccount;
use App\Models\YoutubeAutopostSetting;
use App\Services\ScheduledPublisher;
use App\Services\YouTubeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class YouTubeAutopostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Storage::fake();
        Queue::fake();
        // Tuesday 10:00 in Jakarta: today's 09:00 slot has passed
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Jakarta'));
    }

    private function channel(User $user, array $attrs = []): YoutubeAccount
    {
        return YoutubeAccount::create($attrs + [
            'user_id' => $user->id, 'channel_id' => 'UC'.$user->id, 'title' => 'Channel Saya',
            'access_token' => 'at', 'refresh_token' => 'rt', 'access_expires_at' => now()->addHour(),
        ]);
    }

    private function autopost(User $user, YoutubeAccount $account, array $attrs = []): YoutubeAutopostSetting
    {
        return YoutubeAutopostSetting::create($attrs + [
            'user_id' => $user->id, 'youtube_account_id' => $account->id, 'enabled' => true,
            'max_per_day' => 3, 'slot_times' => ['09:00', '15:00', '20:00'], 'timezone' => 'Asia/Jakarta',
        ]);
    }

    private function reviewClip(User $user, array $attrs = []): GeneratedClip
    {
        $source = VideoSource::create([
            'user_id' => $user->id, 'type' => 'channel', 'value' => '@chan',
            'max_per_run' => 1, 'min_video_seconds' => 300, 'max_video_seconds' => 7200, 'settings' => [],
        ]);
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $source->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'video_title' => 'Podcast', 'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $path = 'clips/'.uniqid().'.mp4';
        Storage::put($path, 'video'); // not a real video: the Shorts length check falls back to end - start

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 40,
            'topic' => 'Rahasia <bisnis> kecil', 'reason' => 'r', 'status' => 'done', 'review_status' => 'pending',
            'output_path' => $path, 'caption' => 'Dengar ini sampai habis.', 'hashtags_json' => ['#bisnis', '#umkm'],
        ]);
    }

    private function approve(User $user, GeneratedClip $clip, array $body = [])
    {
        return $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve", $body);
    }

    public function test_settings_default_then_save_and_validate(): void
    {
        $user = User::factory()->create();
        $account = $this->channel($user);

        $this->actingAs($user)->getJson('/api/autopilot/youtube')->assertOk()
            ->assertJson(['enabled' => false, 'max_per_day' => 3, 'slot_times' => ['09:00', '15:00', '20:00'], 'saved' => false])
            ->assertJsonPath('accounts.0.title', 'Channel Saya');

        $this->actingAs($user)->putJson('/api/autopilot/youtube', [
            'enabled' => true, 'youtube_account_id' => $account->id, 'max_per_day' => 2,
            'slot_times' => ['20:00', '08:30'], 'timezone' => 'Asia/Makassar',
        ])->assertOk()->assertJson(['enabled' => true, 'slot_times' => ['08:30', '20:00'], 'timezone' => 'Asia/Makassar', 'saved' => true]);

        $this->actingAs($user)->putJson('/api/autopilot/youtube', [
            'enabled' => true, 'youtube_account_id' => null, 'max_per_day' => 2, 'slot_times' => ['25:00', '08:30', '08:30'], 'timezone' => 'Mars/Base',
        ])->assertUnprocessable()->assertJsonValidationErrors(['youtube_account_id', 'slot_times.0', 'slot_times.1', 'timezone']);

        // Someone else's channel
        $other = $this->channel(User::factory()->create());
        $this->actingAs($user)->putJson('/api/autopilot/youtube', [
            'enabled' => true, 'youtube_account_id' => $other->id, 'max_per_day' => 2, 'slot_times' => ['08:30'], 'timezone' => 'Asia/Jakarta',
        ])->assertNotFound();
    }

    public function test_approve_books_the_next_free_slot_with_calendar_metadata(): void
    {
        $user = User::factory()->create();
        $account = $this->channel($user);
        $this->autopost($user, $account);
        $clip = $this->reviewClip($user);

        $this->approve($user, $clip)->assertOk()
            ->assertJsonPath('youtube.scheduled_at', '2026-11-10T08:00:00.000000Z'); // 15:00 WIB

        $post = ScheduledPost::sole();
        $this->assertSame('approved', $clip->fresh()->review_status);
        $this->assertSame(['youtube', $account->id, 'scheduled'], [$post->platform, $post->account_id, $post->status]);
        $this->assertSame('Rahasia bisnis kecil', $post->options['title']);
        $this->assertSame("Dengar ini sampai habis.\n\n#bisnis #umkm", $post->options['description']);
        $this->assertSame(['bisnis', 'umkm'], $post->options['tags']);
        $this->assertSame('public', $post->options['privacy']);
        Queue::assertNotPushed(UploadToTikTokJob::class); // YouTube only: no TikTok account was picked

        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.youtube.status', 'scheduled')
            ->assertJsonPath('data.0.youtube.post_id', $post->id);

        // It is an ordinary calendar post
        $this->actingAs($user)->getJson('/api/schedule?from=2026-11-01&to=2026-12-01')->assertOk()
            ->assertJsonPath('posts.0.id', $post->id);

        // Approving again keeps the slot
        $this->approve($user, $clip)->assertOk();
        $this->assertSame(1, ScheduledPost::count());
    }

    public function test_slots_fill_up_to_the_daily_cap_then_roll_to_the_next_day(): void
    {
        $user = User::factory()->create();
        $account = $this->channel($user);
        $this->autopost($user, $account, ['max_per_day' => 2]);
        // A post the user put on the calendar by hand counts toward today's cap
        $manual = $this->reviewClip($user, ['review_status' => null]);
        ScheduledPost::create([
            'user_id' => $user->id, 'generated_clip_id' => $manual->id, 'platform' => 'youtube', 'account_id' => $account->id,
            'scheduled_at' => CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Jakarta'), 'status' => 'scheduled', 'options' => [],
        ]);

        $times = collect(range(1, 3))->map(function () use ($user) {
            $this->approve($user, $this->reviewClip($user))->assertOk();

            return CarbonImmutable::parse(ScheduledPost::latest('id')->first()->scheduled_at)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i');
        });

        // Today: 09:00 passed, 12:00 manual + 15:00 = cap of 2. Tomorrow: 09:00, 15:00
        $this->assertSame(['2026-11-10 15:00', '2026-11-11 09:00', '2026-11-11 15:00'], $times->all());
    }

    public function test_a_failed_post_frees_its_slot_and_another_channel_does_not_count(): void
    {
        $user = User::factory()->create();
        $account = $this->channel($user);
        $other = $this->channel($user, ['channel_id' => 'UC-other']);
        $this->autopost($user, $account, ['max_per_day' => 1, 'slot_times' => ['15:00']]);
        $old = $this->reviewClip($user);
        foreach ([[$account->id, 'failed'], [$other->id, 'scheduled']] as [$accountId, $status]) {
            ScheduledPost::create([
                'user_id' => $user->id, 'generated_clip_id' => $old->id, 'platform' => 'youtube', 'account_id' => $accountId,
                'scheduled_at' => CarbonImmutable::parse('2026-11-10 15:00', 'Asia/Jakarta'), 'status' => $status, 'options' => [],
            ]);
        }

        $this->approve($user, $this->reviewClip($user))->assertOk()
            ->assertJsonPath('youtube.scheduled_at', '2026-11-10T08:00:00.000000Z');
    }

    public function test_approve_still_sends_to_tiktok_and_needs_a_destination(): void
    {
        $user = User::factory()->create();
        $tiktok = TiktokAccount::create([
            'user_id' => $user->id, 'open_id' => 'o', 'access_token' => 'x', 'refresh_token' => 'y',
            'access_expires_at' => now()->addDay(), 'scope' => 'user.info.basic,video.upload',
        ]);
        $clip = $this->reviewClip($user);

        // Autopost off and no TikTok account: nowhere to send it
        $this->approve($user, $clip)->assertUnprocessable();
        $this->assertSame('pending', $clip->fresh()->review_status);

        $this->approve($user, $clip, ['account_id' => $tiktok->id])->assertOk()->assertJsonPath('youtube', null);
        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->clipId === $clip->id);
        $this->assertSame(0, ScheduledPost::count());

        // Both: TikTok right away, YouTube on the calendar
        $this->autopost($user, $this->channel($user));
        $both = $this->reviewClip($user);
        $this->approve($user, $both, ['account_id' => $tiktok->id])->assertOk()->assertJsonPath('youtube.scheduled_at', '2026-11-10T08:00:00.000000Z');
        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->clipId === $both->id);
    }

    public function test_a_clip_youtube_cannot_take_is_approved_with_the_reason(): void
    {
        $user = User::factory()->create();
        $settings = $this->autopost($user, $this->channel($user));

        $long = $this->reviewClip($user, ['end_seconds' => 240]);
        $this->approve($user, $long)->assertOk()->assertJsonPath('youtube.error', 'Tidak dijadwalkan ke YouTube: Shorts maksimal 3 menit, clip ini 240 detik.');
        $this->assertSame('approved', $long->fresh()->review_status);
        // The reason survives a reload, and goes once the approval is undone
        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.youtube.status', 'skipped')
            ->assertJsonPath('data.0.youtube.error', 'Tidak dijadwalkan ke YouTube: Shorts maksimal 3 menit, clip ini 240 detik.');
        $this->actingAs($user)->postJson("/api/autopilot/review/{$long->id}/unapprove")->assertOk();
        $this->assertNull($long->fresh()->youtube_autopost_error);

        // Channel disconnected after the settings were saved
        $settings->account->delete();
        $this->approve($user, $this->reviewClip($user))->assertOk()->assertJsonPath('youtube.error', fn ($msg) => str_contains($msg, 'Channel YouTube untuk autopilot'));
        $this->assertSame(0, ScheduledPost::count());
    }

    public function test_unapprove_reject_and_delete_cancel_the_waiting_post(): void
    {
        $user = User::factory()->create();
        $this->autopost($user, $this->channel($user));

        $a = $this->reviewClip($user);
        $this->approve($user, $a)->assertOk();
        $this->actingAs($user)->postJson("/api/autopilot/review/{$a->id}/unapprove")->assertOk();
        $this->assertSame('pending', $a->fresh()->review_status);
        $this->assertSame(0, $a->scheduledPosts()->count());

        $b = $this->reviewClip($user);
        $this->approve($user, $b)->assertOk();
        $this->actingAs($user)->postJson("/api/autopilot/review/{$b->id}/reject")->assertOk();
        $this->assertSame(0, $b->scheduledPosts()->count());

        $c = $this->reviewClip($user);
        $this->approve($user, $c)->assertOk();
        $c->clipProject->delete();
        $this->assertSame(0, ScheduledPost::count());

        // Once the post went out, the approval stays
        $d = $this->reviewClip($user);
        $this->approve($user, $d)->assertOk();
        $d->scheduledPosts()->update(['status' => ScheduledPost::STATUS_QUEUED]);
        $this->actingAs($user)->postJson("/api/autopilot/review/{$d->id}/unapprove")->assertUnprocessable();
        $this->assertSame('approved', $d->fresh()->review_status);

        $this->actingAs(User::factory()->create())->postJson("/api/autopilot/review/{$d->id}/unapprove")->assertForbidden();
    }

    public function test_clean_approved_keeps_clips_waiting_for_their_youtube_slot(): void
    {
        $user = User::factory()->create();
        $this->autopost($user, $this->channel($user));
        $waiting = $this->reviewClip($user);
        $this->approve($user, $waiting)->assertOk();

        $this->actingAs($user)->postJson('/api/autopilot/review/clean-approved')->assertOk()->assertJson(['removed' => 0]);
        $this->assertModelExists($waiting);
    }

    public function test_due_post_goes_through_the_scheduled_publisher_as_public(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['approved_at' => now()])->save();
        $account = $this->channel($user);
        $this->autopost($user, $account);
        $clip = $this->reviewClip($user);
        $this->approve($user, $clip)->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00', 'Asia/Jakarta'));
        $this->assertSame(1, app(ScheduledPublisher::class)->publishDue());

        $this->assertSame(ScheduledPost::STATUS_QUEUED, ScheduledPost::sole()->status);
        Queue::assertPushed(UploadToYouTubeJob::class, fn ($job) => $job->clipId === $clip->id
            && $job->accountId === $account->id && $job->meta['privacy'] === 'public' && $job->meta['title'] === 'Rahasia bisnis kecil');
    }

    public function test_expired_youtube_session_fails_visibly_in_review_and_calendar(): void
    {
        $user = User::factory()->create();
        $account = $this->channel($user, ['access_expires_at' => now()->subMinute()]);
        $this->autopost($user, $account);
        $clip = $this->reviewClip($user);
        $this->approve($user, $clip)->assertOk();
        $post = ScheduledPost::sole();
        $post->update(['status' => ScheduledPost::STATUS_QUEUED, 'handed_off_at' => now()]);
        $clip->update(['youtube_account_id' => $account->id, 'youtube_status' => 'queued']);

        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $job = new UploadToYouTubeJob($clip->id, $account->id, $post->options);
        try {
            $job->handle(app(YouTubeService::class));
            $this->fail('expected the upload to fail');
        } catch (Throwable $e) {
            $job->failed($e);
        }

        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.youtube.delivery', 'failed')
            ->assertJsonPath('data.0.youtube.error', 'Sesi YouTube kedaluwarsa atau dicabut. Hubungkan ulang channel YouTube.');
        $this->actingAs($user)->getJson('/api/schedule?from=2026-11-01&to=2026-12-01')->assertOk()
            ->assertJsonPath('posts.0.delivery', 'failed')
            ->assertJsonPath('posts.0.error', 'Sesi YouTube kedaluwarsa atau dicabut. Hubungkan ulang channel YouTube.');
    }
}
