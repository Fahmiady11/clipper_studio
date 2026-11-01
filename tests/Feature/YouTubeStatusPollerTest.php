<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\ScheduledPost;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\YoutubeAccount;
use App\Services\YouTubeService;
use App\Services\YouTubeStatusPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeStatusPollerTest extends TestCase
{
    use RefreshDatabase;

    private const VIDEOS = 'www.googleapis.com/youtube/v3/videos*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    private function channel(User $user, string $id = 'UC1', array $attrs = []): YoutubeAccount
    {
        return YoutubeAccount::create($attrs + [
            'user_id' => $user->id, 'channel_id' => $id, 'title' => 'Channel',
            'access_token' => 'at', 'refresh_token' => 'rt', 'access_expires_at' => now()->addHour(),
        ]);
    }

    private function uploaded(YoutubeAccount $account, string $videoId, array $attrs = []): GeneratedClip
    {
        $source = VideoSource::create([
            'user_id' => $account->user_id, 'type' => 'channel', 'value' => '@chan',
            'max_per_run' => 1, 'min_video_seconds' => 300, 'max_video_seconds' => 7200, 'settings' => [],
        ]);
        $project = ClipProject::create([
            'user_id' => $account->user_id, 'video_source_id' => $source->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'review_status' => 'approved', 'output_path' => 'clips/x.mp4',
            'youtube_account_id' => $account->id, 'youtube_video_id' => $videoId, 'youtube_status' => 'processing',
            'youtube_uploaded_at' => now()->subMinutes(10),
        ]);
    }

    private function item(string $id, array $status, ?string $processing = null): array
    {
        return array_filter(['id' => $id, 'status' => $status, 'processingDetails' => $processing ? ['processingStatus' => $processing] : null]);
    }

    public function test_one_call_per_channel_settles_each_video(): void
    {
        $user = User::factory()->create();
        $account = $this->channel($user);
        $public = $this->uploaded($account, 'vPub');
        $private = $this->uploaded($account, 'vPriv');
        $dup = $this->uploaded($account, 'vDup');
        $busy = $this->uploaded($account, 'vBusy');
        $gone = $this->uploaded($account, 'vGone');
        $other = $this->uploaded($this->channel(User::factory()->create(), 'UC2'), 'vOther');

        Http::fake([self::VIDEOS => function (Request $request) {
            $ids = explode(',', $request->data()['id']);

            return Http::response(['items' => array_values(array_filter([
                in_array('vPub', $ids) ? $this->item('vPub', ['uploadStatus' => 'processed', 'privacyStatus' => 'public']) : null,
                // Unaudited project: done processing but locked private, still a success
                in_array('vPriv', $ids) ? $this->item('vPriv', ['uploadStatus' => 'uploaded', 'privacyStatus' => 'private'], 'succeeded') : null,
                in_array('vDup', $ids) ? $this->item('vDup', ['uploadStatus' => 'rejected', 'rejectionReason' => 'duplicate', 'privacyStatus' => 'private']) : null,
                in_array('vBusy', $ids) ? $this->item('vBusy', ['uploadStatus' => 'uploaded', 'privacyStatus' => 'private'], 'processing') : null,
                in_array('vOther', $ids) ? $this->item('vOther', ['uploadStatus' => 'failed', 'failureReason' => 'codec', 'privacyStatus' => 'private']) : null,
            ]))]);
        }]);

        $this->assertSame(['checked' => 6, 'settled' => 5], app(YouTubeStatusPoller::class)->run());

        Http::assertSentCount(2); // one per channel
        Http::assertSent(fn (Request $r) => $r->data()['id'] === 'vPub,vPriv,vDup,vBusy,vGone' && $r->data()['part'] === 'status,processingDetails');

        $this->assertSame(['published', 'public', null], [$public->fresh()->youtube_status, $public->fresh()->youtube_privacy, $public->fresh()->youtube_error]);
        $this->assertSame(['published', 'private'], [$private->fresh()->youtube_status, $private->fresh()->youtube_privacy]);
        $this->assertSame(['failed', 'YouTube menolak video: duplikat video yang sudah ada.'], [$dup->fresh()->youtube_status, $dup->fresh()->youtube_error]);
        $this->assertSame('processing', $busy->fresh()->youtube_status);
        $this->assertNotNull($busy->fresh()->youtube_checked_at);
        $this->assertSame(['failed', 'Video tidak ditemukan di YouTube (mungkin dihapus).'], [$gone->fresh()->youtube_status, $gone->fresh()->youtube_error]);
        $this->assertSame(['failed', 'YouTube gagal memproses video: codec video tidak didukung.'], [$other->fresh()->youtube_status, $other->fresh()->youtube_error]);
    }

    public function test_checks_back_off_as_the_upload_ages_and_stop_after_the_window(): void
    {
        $account = $this->channel(User::factory()->create());
        $poller = app(YouTubeStatusPoller::class);
        $clip = fn (int $uploadedMinAgo, ?int $checkedMinAgo) => $this->uploaded($account, 'v'.uniqid(), [
            'youtube_uploaded_at' => now()->subMinutes($uploadedMinAgo),
            'youtube_checked_at' => $checkedMinAgo === null ? null : now()->subMinutes($checkedMinAgo),
        ]);

        $this->assertTrue($poller->isDue($clip(10, null)));
        $this->assertTrue($poller->isDue($clip(10, 1)));            // first hour: every run
        $this->assertFalse($poller->isDue($clip(180, 5)));          // then every 15 minutes
        $this->assertTrue($poller->isDue($clip(180, 16)));
        $this->assertFalse($poller->isDue($clip(600, 30)));         // after 6 hours, hourly
        $this->assertTrue($poller->isDue($clip(600, 61)));
        $this->assertTrue($poller->isDue($clip(49 * 60, 120)));     // one last check past the window
        $this->assertFalse($poller->isDue($clip(49 * 60, 10)));     // that check happened: given up
    }

    public function test_still_uploaded_after_the_window_counts_as_published(): void
    {
        $account = $this->channel(User::factory()->create());
        $clip = $this->uploaded($account, 'vSlow', ['youtube_uploaded_at' => now()->subHours(49), 'youtube_checked_at' => now()->subHours(2)]);
        Http::fake([self::VIDEOS => Http::response(['items' => [$this->item('vSlow', ['uploadStatus' => 'uploaded', 'privacyStatus' => 'private'])]])]);

        app(YouTubeStatusPoller::class)->run();

        $this->assertSame(['published', 'private'], [$clip->fresh()->youtube_status, $clip->fresh()->youtube_privacy]);
    }

    public function test_expired_session_keeps_processing_with_a_visible_reason(): void
    {
        $account = $this->channel(User::factory()->create(), 'UC1', ['access_expires_at' => now()->subMinute()]);
        $clip = $this->uploaded($account, 'vX');
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertSame(['checked' => 1, 'settled' => 0], app(YouTubeStatusPoller::class)->run());

        $clip->refresh();
        $this->assertSame('processing', $clip->youtube_status);
        $this->assertSame('Status YouTube belum bisa dicek: Sesi YouTube kedaluwarsa atau dicabut. Hubungkan ulang channel YouTube.', $clip->youtube_error);
        $this->assertNotNull($clip->youtube_checked_at);
    }

    public function test_command_settles_a_scheduled_post_for_calendar_and_autopilot(): void
    {
        $user = User::factory()->create();
        $account = $this->channel($user);
        $clip = $this->uploaded($account, 'vCal');
        ScheduledPost::create([
            'user_id' => $user->id, 'generated_clip_id' => $clip->id, 'platform' => 'youtube', 'account_id' => $account->id,
            'scheduled_at' => now()->subMinutes(15), 'status' => ScheduledPost::STATUS_QUEUED, 'handed_off_at' => now()->subMinutes(15), 'options' => [],
        ]);
        Http::fake([self::VIDEOS => Http::response(['items' => [$this->item('vCal', ['uploadStatus' => 'processed', 'privacyStatus' => 'private'])]])]);

        $this->artisan('clipper:youtube-status')->expectsOutput('Checked 1 YouTube videos, 1 settled')->assertSuccessful();

        $this->actingAs($user)->getJson('/api/schedule?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk()
            ->assertJsonPath('posts.0.delivery', 'published')
            ->assertJsonPath('posts.0.privacy', 'private')
            ->assertJsonPath('posts.0.url', 'https://youtube.com/shorts/vCal');
        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.youtube.delivery', 'published')
            ->assertJsonPath('data.0.youtube.privacy', 'private');

        // Settled: nothing left to poll
        $this->artisan('clipper:youtube-status')->expectsOutput('Checked 0 YouTube videos, 0 settled');
    }

    public function test_rejection_and_failure_reasons_read_as_sentences(): void
    {
        $this->assertSame(
            ['status' => 'failed', 'privacy' => 'public', 'reason' => 'YouTube menolak video: video terlalu panjang.'],
            YouTubeService::statusFromItem(['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'length', 'privacyStatus' => 'public']])
        );
        $this->assertSame('YouTube menolak video: somethingNew.',
            YouTubeService::statusFromItem(['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'somethingNew']])['reason']);
        $this->assertSame('failed', YouTubeService::statusFromItem(['status' => ['uploadStatus' => 'uploaded'], 'processingDetails' => ['processingStatus' => 'terminated']])['status']);
    }
}
