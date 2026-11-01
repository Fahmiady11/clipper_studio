<?php

namespace Tests\Feature;

use App\Jobs\UploadToTikTokJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Services\TikTokService;
use App\Services\TikTokStatusPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TikTokStatusPollerTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS = 'open.tiktokapis.com/v2/post/publish/status/fetch/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    private function account(User $user, string $openId = 'open-1'): TiktokAccount
    {
        return TiktokAccount::create([
            'user_id' => $user->id, 'open_id' => $openId, 'display_name' => 'Creator',
            'access_token' => 'at', 'refresh_token' => 'rt', 'scope' => 'video.upload',
            'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(300),
        ]);
    }

    private function processing(TiktokAccount $account, string $publishId, array $attrs = []): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => $account->user_id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'review_status' => 'approved', 'output_path' => 'clips/x.mp4',
            'tiktok_account_id' => $account->id, 'tiktok_publish_id' => $publishId, 'tiktok_status' => 'processing',
            'tiktok_uploaded_at' => now()->subMinutes(5),
        ]);
    }

    /** status/fetch answers per publish_id: [status, fail_reason] */
    private function fakeStatuses(array $byId): void
    {
        Http::fake([self::STATUS => function (Request $request) use ($byId) {
            [$status, $reason] = $byId[$request->data()['publish_id']] + [1 => null];

            return Http::response(['data' => array_filter(['status' => $status, 'fail_reason' => $reason]), 'error' => ['code' => 'ok']]);
        }]);
    }

    public function test_final_states_are_recorded_and_processing_keeps_waiting(): void
    {
        $account = $this->account(User::factory()->create());
        $inbox = $this->processing($account, 'p-inbox');
        $posted = $this->processing($account, 'p-posted');
        $failed = $this->processing($account, 'p-failed');
        $busy = $this->processing($account, 'p-busy', ['tiktok_error' => 'Status TikTok belum bisa dicek: timeout']);
        $this->fakeStatuses([
            'p-inbox' => ['SEND_TO_USER_INBOX'],
            'p-posted' => ['PUBLISH_COMPLETE'],
            'p-failed' => ['FAILED', 'spam_risk_too_many_posts'],
            'p-busy' => ['PROCESSING_UPLOAD'],
        ]);

        $this->assertSame(['checked' => 4, 'settled' => 3], app(TikTokStatusPoller::class)->run());

        $this->assertSame('inbox', $inbox->refresh()->tiktok_status);
        $this->assertNull($inbox->tiktok_error);
        $this->assertNotNull($inbox->tiktok_checked_at);
        $this->assertSame('published', $posted->refresh()->tiktok_status);
        $this->assertSame('failed', $failed->refresh()->tiktok_status);
        $this->assertSame('Batas posting harian TikTok tercapai. Kirim ulang besok. (spam_risk_too_many_posts)', $failed->tiktok_error);
        $this->assertSame('processing', $busy->refresh()->tiktok_status);
        $this->assertNull($busy->tiktok_error); // TikTok answered: the old check error goes
    }

    public function test_checks_back_off_with_age(): void
    {
        $account = $this->account(User::factory()->create());
        $poller = app(TikTokStatusPoller::class);

        $fresh = $this->processing($account, 'p1', ['tiktok_checked_at' => now()->subSeconds(30)]);
        $this->assertTrue($poller->isDue($fresh)); // first hour: every run

        $hours = $this->processing($account, 'p2', ['tiktok_uploaded_at' => now()->subHours(2), 'tiktok_checked_at' => now()->subMinutes(5)]);
        $this->assertFalse($poller->isDue($hours));
        $hours->update(['tiktok_checked_at' => now()->subMinutes(16)]);
        $this->assertTrue($poller->isDue($hours));

        $old = $this->processing($account, 'p3', ['tiktok_uploaded_at' => now()->subHours(8), 'tiktok_checked_at' => now()->subMinutes(30)]);
        $this->assertFalse($poller->isDue($old));

        Http::fake();
        $poller->run();
        Http::assertSentCount(2); // the fresh one and the one 16 minutes past its last check
    }

    public function test_fetch_error_is_shown_then_given_up_after_the_window(): void
    {
        $account = $this->account(User::factory()->create());
        $young = $this->processing($account, 'p-young');
        $stale = $this->processing($account, 'p-stale', ['tiktok_uploaded_at' => now()->subHours(TikTokStatusPoller::GIVE_UP_HOURS + 1)]);
        Http::fake([self::STATUS => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'slow down']], 429)]);

        $this->assertSame(['checked' => 2, 'settled' => 1], app(TikTokStatusPoller::class)->run());

        $this->assertSame('processing', $young->refresh()->tiktok_status);
        $this->assertStringStartsWith('Status TikTok belum bisa dicek: ', $young->tiktok_error);
        $this->assertStringContainsString('rate_limit_exceeded', $young->tiktok_error);
        $this->assertSame('unknown', $stale->refresh()->tiktok_status); // the draft may be in the inbox: not a failure
        $this->assertSame(TikTokStatusPoller::NO_ANSWER, $stale->tiktok_error);
    }

    public function test_rows_stuck_before_the_poller_existed_are_settled(): void
    {
        $account = $this->account(User::factory()->create());
        // Left by the old job: no upload or check time, last touched days ago
        $this->travelTo(now()->subDays(3));
        $arrived = $this->processing($account, 'p-arrived', ['tiktok_uploaded_at' => null]);
        $lost = $this->processing($account, 'p-lost', ['tiktok_uploaded_at' => null]);
        $this->travelBack();
        $this->fakeStatuses(['p-arrived' => ['SEND_TO_USER_INBOX'], 'p-lost' => ['PROCESSING_UPLOAD']]);

        $this->artisan('clipper:tiktok-status')->expectsOutput('Checked 2 TikTok uploads, 2 settled')->assertSuccessful();

        $this->assertSame('inbox', $arrived->refresh()->tiktok_status);
        $this->assertSame('unknown', $lost->refresh()->tiktok_status);
        $this->assertSame('TikTok tidak memberi status akhir dalam 24 jam.', $lost->tiktok_error);
    }

    public function test_disconnected_or_foreign_account_is_never_asked(): void
    {
        $owner = User::factory()->create();
        $mine = $this->account($owner);
        $foreign = $this->account(User::factory()->create(), 'open-2');
        $gone = $this->processing($mine, 'p-gone', ['tiktok_account_id' => 999]);
        $stolen = $this->processing($mine, 'p-stolen', ['tiktok_account_id' => $foreign->id]);
        Http::fake();

        $this->assertSame(['checked' => 2, 'settled' => 2], app(TikTokStatusPoller::class)->run());

        Http::assertNothingSent();
        foreach ([$gone, $stolen] as $clip) {
            $this->assertSame('unknown', $clip->refresh()->tiktok_status);
            $this->assertSame(TikTokStatusPoller::ACCOUNT_GONE, $clip->tiktok_error);
        }
    }

    public function test_each_account_stays_under_the_rate_limit_per_run(): void
    {
        $account = $this->account(User::factory()->create());
        foreach (range(1, 25) as $i) {
            $this->processing($account, "p{$i}");
        }
        $this->fakeStatuses(collect(range(1, 25))->mapWithKeys(fn ($i) => ["p{$i}" => ['SEND_TO_USER_INBOX']])->all());

        $this->assertSame(['checked' => 20, 'settled' => 20], app(TikTokStatusPoller::class)->run());
        $this->assertSame(['checked' => 5, 'settled' => 5], app(TikTokStatusPoller::class)->run());
    }

    public function test_job_leaves_the_clip_processing_when_the_status_check_fails(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        Storage::put('clips/test.mp4', str_repeat('v', 2048));
        $clip = GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 10,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'output_path' => 'clips/test.mp4',
        ]);

        $svc = $this->mock(TikTokService::class);
        $svc->shouldReceive('uploadToInbox')->once()->andReturn('pub-3');
        $svc->shouldReceive('publishStatus')->once()->andThrow(new RuntimeException('TikTok post/publish/status/fetch/ gagal (500)'));

        $job = new UploadToTikTokJob($clip->id, $account->id);
        $job->pollSeconds = 0;
        $this->app->call([$job, 'handle']);

        $clip->refresh();
        $this->assertSame('processing', $clip->tiktok_status); // the draft did reach TikTok; the poller takes over
        $this->assertSame('pub-3', $clip->tiktok_publish_id);
        $this->assertNotNull($clip->tiktok_uploaded_at);
    }

    public function test_studio_status_endpoint_settles_through_the_poller(): void
    {
        $user = User::factory()->create();
        $clip = $this->processing($this->account($user), 'p-studio');
        $this->fakeStatuses(['p-studio' => ['FAILED', 'duration_check_failed']]);

        $this->actingAs($user)->getJson("/api/clips/{$clip->id}/tiktok-status")
            ->assertOk()
            ->assertJson(['status' => 'failed', 'error' => 'Durasi video di luar batas TikTok. (duration_check_failed)']);
    }

    public function test_unknown_is_final_so_the_clip_can_be_sent_again_and_cleaned(): void
    {
        Queue::fake();
        Storage::fake();
        $user = User::factory()->create();
        $account = $this->account($user);
        Storage::put('clips/x.mp4', 'v');
        $clip = $this->processing($account, 'p-old', ['tiktok_status' => 'unknown', 'tiktok_error' => TikTokStatusPoller::NO_ANSWER]);

        Http::fake();
        $this->assertSame(['checked' => 0, 'settled' => 0], app(TikTokStatusPoller::class)->run());

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/tiktok", ['account_id' => $account->id])
            ->assertOk()->assertJson(['status' => 'queued']);
        Queue::assertPushed(UploadToTikTokJob::class);

        $clip->refresh()->update(['tiktok_status' => 'unknown']); // as if that send also never settled
        $this->actingAs($user)->postJson('/api/autopilot/review/clean-approved')->assertOk()->assertJson(['removed' => 1]);
        $this->assertModelMissing($clip);
    }

    public function test_migration_turns_the_first_pollers_give_ups_into_unknown(): void
    {
        $account = $this->account(User::factory()->create());
        $noAnswer = $this->processing($account, 'p1', ['tiktok_status' => 'failed',
            'tiktok_error' => 'TikTok belum memberi status akhir setelah 24 jam. Cek inbox TikTok di aplikasi; kalau draftnya tidak ada, kirim ulang.']);
        $gone = $this->processing($account, 'p2', ['tiktok_status' => 'failed',
            'tiktok_error' => 'Akun TikTok sudah diputus, status tidak bisa dicek lagi. Cek inbox TikTok di aplikasi.']);
        $realFailure = $this->processing($account, 'p3', ['tiktok_status' => 'failed', 'tiktok_error' => 'TikTok menolak video ini.']);

        $migration = require database_path('migrations/2026_11_14_000001_mark_given_up_tiktok_uploads_unknown.php');
        $migration->up();

        $this->assertSame(['unknown', TikTokStatusPoller::NO_ANSWER], [$noAnswer->refresh()->tiktok_status, $noAnswer->tiktok_error]);
        $this->assertSame(['unknown', TikTokStatusPoller::ACCOUNT_GONE], [$gone->refresh()->tiktok_status, $gone->tiktok_error]);
        $this->assertSame('failed', $realFailure->refresh()->tiktok_status);

        $migration->down();
        $this->assertSame('failed', $noAnswer->refresh()->tiktok_status);
    }
}
