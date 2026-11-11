<?php

namespace Tests\Feature;

use App\Jobs\UploadToTikTokJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\PlatformSetting;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\Autopost;
use App\Services\ScheduledPublisher;
use App\Services\TiktokAutopost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TiktokAutopostModeTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = ['enabled' => true, 'max_per_day' => 3, 'slot_times' => ['09:00', '15:00'], 'timezone' => 'Asia/Jakarta'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Storage::fake();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Jakarta'));
    }

    private function account(User $user, string $scope = 'user.info.basic,video.upload,video.publish'): TiktokAccount
    {
        return TiktokAccount::create([
            'user_id' => $user->id, 'open_id' => 'open-'.$user->id, 'display_name' => 'Creator', 'scope' => $scope,
            'access_token' => 'at', 'refresh_token' => 'rt', 'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(300),
        ]);
    }

    private function setting(User $user, TiktokAccount $account, array $attrs = []): PlatformSetting
    {
        return PlatformSetting::create($attrs + self::SETTINGS + ['user_id' => $user->id, 'platform' => 'tiktok', 'account_id' => $account->id]);
    }

    private function reviewClip(User $user): GeneratedClip
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
        Storage::put($path, 'video');

        return GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 40,
            'topic' => 'Momen lucu', 'reason' => 'r', 'status' => 'done', 'review_status' => 'pending',
            'output_path' => $path, 'caption' => 'Dengar ini sampai habis.', 'hashtags_json' => ['#bisnis', '#umkm'],
        ]);
    }

    public function test_mode_is_inbox_by_default_then_saved_and_returned(): void
    {
        $user = User::factory()->create();
        $this->account($user);

        $this->actingAs($user)->getJson('/api/autopilot/tiktok')->assertOk()->assertJson(['mode' => 'inbox', 'saved' => false]);

        $this->actingAs($user)->putJson('/api/autopilot/tiktok', self::SETTINGS + ['mode' => 'direct'])
            ->assertOk()->assertJson(['mode' => 'direct', 'saved' => true]);
        $this->assertSame('direct', PlatformSetting::where('platform', 'tiktok')->value('tiktok_mode'));
        $this->actingAs($user)->getJson('/api/autopilot/tiktok')->assertJson(['mode' => 'direct']);
        $this->actingAs($user)->getJson('/api/accounts')->assertJsonPath('platforms.0.autopost.mode', 'direct');

        // A save without mode (the old client) leaves the choice alone
        $this->actingAs($user)->putJson('/api/autopilot/tiktok', self::SETTINGS)->assertOk()->assertJson(['mode' => 'direct']);

        $this->actingAs($user)->putJson('/api/autopilot/tiktok', self::SETTINGS + ['mode' => 'inbox'])->assertOk()->assertJson(['mode' => 'inbox']);
        $this->assertSame('inbox', PlatformSetting::where('platform', 'tiktok')->value('tiktok_mode'));
    }

    public function test_mode_must_be_inbox_or_direct_and_other_platforms_ignore_it(): void
    {
        $user = User::factory()->create();
        $this->account($user);

        $this->actingAs($user)->putJson('/api/autopilot/tiktok', self::SETTINGS + ['mode' => 'public'])
            ->assertUnprocessable()->assertJsonValidationErrors(['mode']);
        $this->assertDatabaseCount('platform_settings', 0);

        $this->actingAs($user)->putJson('/api/autopilot/youtube', ['enabled' => false] + self::SETTINGS + ['mode' => 'direct'])
            ->assertOk()->assertJson(['mode' => null]);
        $this->assertSame('inbox', PlatformSetting::where('platform', 'youtube')->value('tiktok_mode'));
    }

    public function test_existing_rows_without_a_mode_behave_as_inbox(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        // A row written before the column existed: the migration default fills it in
        DB::table('platform_settings')->insert([
            'user_id' => $user->id, 'platform' => 'tiktok', 'account_id' => $account->id, 'enabled' => true,
            'max_per_day' => 3, 'slot_times' => json_encode(['09:00']), 'timezone' => 'Asia/Jakarta',
        ]);

        $setting = PlatformSetting::firstOrFail();
        $this->assertSame('inbox', $setting->tiktokMode());
        $this->assertSame('inbox', app(TiktokAutopost::class)->options($this->reviewClip($user), 'Cap', [], null, $setting)['mode']);
        $this->actingAs($user)->getJson('/api/autopilot/tiktok')->assertJson(['mode' => 'inbox']);
    }

    public function test_options_shape_per_mode(): void
    {
        $user = User::factory()->create();
        $clip = $this->reviewClip($user);
        $tiktok = app(TiktokAutopost::class);
        $shape = fn (string $mode) => ['mode' => $mode, 'title' => "Cap\n\n#a #b", 'allow_comment' => false, 'allow_duet' => false, 'allow_stitch' => false, 'autopilot' => true];

        $this->assertSame($shape('inbox'), $tiktok->options($clip, 'Cap', ['#a', '#b']));
        $this->assertSame($shape('inbox'), $tiktok->options($clip, 'Cap', ['#a', '#b'], null, PlatformSetting::for($user->id, 'tiktok')));

        $direct = $tiktok->options($clip, 'Cap', ['#a', '#b'], null, new PlatformSetting(['tiktok_mode' => 'direct']));
        $this->assertSame($shape('direct'), $direct);
        $this->assertSame([
            'title' => "Cap\n\n#a #b", 'privacy_level' => 'SELF_ONLY', 'disable_comment' => true, 'disable_duet' => true,
            'disable_stitch' => true, 'brand_organic_toggle' => false, 'brand_content_toggle' => false,
        ], ScheduledPublisher::tiktokPostInfo($direct));
    }

    public function test_approve_books_a_direct_post_that_goes_out_as_direct(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $this->setting($user, $account, ['tiktok_mode' => 'direct']);
        $clip = $this->reviewClip($user);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk()
            ->assertJsonPath('tiktok.scheduled_at', '2026-11-10T08:00:00.000000Z'); // 15:00 WIB

        $post = ScheduledPost::sole();
        $this->assertSame('direct', $post->options['mode']);
        $this->assertSame("Dengar ini sampai habis.\n\n#bisnis #umkm", $post->options['title']);
        Queue::assertNotPushed(UploadToTikTokJob::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:01', 'Asia/Jakarta'));
        $this->assertSame(1, app(ScheduledPublisher::class)->publishDue());

        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->mode === 'direct'
            && $job->postInfo['title'] === "Dengar ini sampai habis.\n\n#bisnis #umkm"
            && $job->postInfo['privacy_level'] === 'SELF_ONLY');
        $this->assertSame('queued', $clip->fresh()->tiktok_status);
    }

    public function test_inbox_booking_keeps_the_inbox_mode(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'user.info.basic,video.upload'); // no video.publish needed
        $this->setting($user, $account);
        $clip = $this->reviewClip($user);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk();

        $this->assertSame('inbox', ScheduledPost::sole()->options['mode']);
    }

    public function test_direct_is_refused_when_the_account_lacks_video_publish(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'user.info.basic,video.upload');
        $this->setting($user, $account, ['tiktok_mode' => 'direct']);
        $clip = $this->reviewClip($user);
        $message = TiktokAccount::scopeMessage('video.publish');

        // Approving by hand is refused outright
        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")
            ->assertUnprocessable()->assertJsonPath('message', $message);
        $this->assertSame(0, ScheduledPost::count());

        // The booking itself refuses too, so autopilot never queues a post that can only fail at its slot
        $booked = app(Autopost::class)->scheduleAll($clip);
        $this->assertSame("Tidak dijadwalkan ke TikTok: {$message}", $booked['tiktok']['error']);
        $this->assertSame(0, ScheduledPost::count());
        $this->assertSame(['skipped', "Tidak dijadwalkan ke TikTok: {$message}"], [$clip->fresh()->tiktok_status, $clip->fresh()->tiktok_error]);
    }
}
