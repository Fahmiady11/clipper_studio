<?php

namespace Tests\Feature;

use App\Jobs\UploadToTikTokJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Models\PlatformSetting;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\YoutubeAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConnectedAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.tiktok.client_key' => 'tk', 'services.tiktok.client_secret' => 'ts', 'services.tiktok.redirect_uri' => 'https://clip.test/tiktok/callback',
            'services.tiktok.scopes' => 'user.info.basic,video.upload',
            'services.youtube.client_id' => 'yc', 'services.youtube.client_secret' => 'ys', 'services.youtube.redirect_uri' => 'https://clip.test/youtube/callback',
            'services.facebook.app_id' => null,
            'services.threads.app_id' => null,
        ]);
        Storage::fake();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Jakarta'));
    }

    private function tiktok(User $user, string $name, array $attrs = []): TiktokAccount
    {
        return TiktokAccount::create($attrs + [
            'user_id' => $user->id, 'open_id' => 'o-'.$name, 'display_name' => $name, 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(300), 'scope' => 'user.info.basic,video.upload',
        ]);
    }

    private function channel(User $user, string $title): YoutubeAccount
    {
        return YoutubeAccount::create([
            'user_id' => $user->id, 'channel_id' => 'UC-'.$title, 'title' => $title,
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(),
        ]);
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
        Storage::put($path = 'clips/'.uniqid().'.mp4', 'video');

        return GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 40, 'topic' => 'Topik', 'reason' => 'r',
            'status' => 'done', 'review_status' => 'pending', 'output_path' => $path, 'caption' => 'Caption', 'hashtags_json' => ['#a'],
        ]);
    }

    public function test_settings_page_lists_every_platform_with_its_default(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['approved_at' => now()])->save();
        $first = $this->tiktok($user, 'Satu');
        $this->tiktok($user, 'Dua');

        $this->actingAs($user)->get('/settings/accounts')->assertOk()->assertSee('Akun terhubung');
        $this->actingAs($user)->get('/')->assertOk()->assertSee('/settings/accounts', false); // account menu

        $res = $this->actingAs($user)->getJson('/api/accounts')->assertOk();
        $this->assertSame(['tiktok', 'youtube', 'facebook', 'instagram', 'threads'], array_column($res->json('platforms'), 'id'));
        $res->assertJsonPath('platforms.0.configured', true)
            ->assertJsonPath('platforms.0.connect_url', '/tiktok/connect')
            ->assertJsonPath('platforms.0.default_id', $first->id) // nothing chosen yet: the oldest
            ->assertJsonPath('platforms.0.accounts.1.name', 'Dua')
            ->assertJsonPath('platforms.0.autopost', ['enabled' => false, 'max_per_day' => 3, 'slot_times' => ['09:00', '15:00', '20:00'], 'timezone' => 'Asia/Jakarta', 'saved' => false])
            ->assertJsonPath('platforms.2.configured', false)
            ->assertJsonPath('platforms.3.connect_url', '/facebook/connect')
            ->assertJsonMissingPath('platforms.0.accounts.0.access_token');
    }

    public function test_the_default_is_chosen_once_and_used_everywhere(): void
    {
        $user = User::factory()->create();
        $this->tiktok($user, 'Satu');
        $two = $this->tiktok($user, 'Dua');
        $this->channel($user, 'A');
        $b = $this->channel($user, 'B');

        $this->actingAs($user)->putJson('/api/accounts/tiktok/default', ['account_id' => $two->id])->assertOk();
        $this->actingAs($user)->putJson('/api/accounts/youtube/default', ['account_id' => $b->id])->assertOk();
        // Not this user's, or no such account
        $this->actingAs($user)->putJson('/api/accounts/tiktok/default', ['account_id' => $this->tiktok(User::factory()->create(), 'X')->id])->assertNotFound();
        $this->actingAs($user)->putJson('/api/accounts/tiktok/default', ['account_id' => 9999])->assertNotFound();

        // Studio and the calendar start from it
        $this->actingAs($user)->getJson('/api/tiktok/accounts')->assertOk()->assertJsonPath('default_id', $two->id);
        $this->actingAs($user)->getJson('/api/youtube/accounts')->assertOk()->assertJsonPath('default_id', $b->id);

        // Autopilot books on it
        PlatformSetting::where('platform', 'youtube')->update(['enabled' => true]);
        $clip = $this->reviewClip($user);
        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk();
        $this->assertSame($b->id, ScheduledPost::sole()->account_id);
    }

    public function test_disconnecting_the_default_falls_back_and_threads_takes_its_login_along(): void
    {
        $user = User::factory()->create();
        $one = $this->tiktok($user, 'Satu');
        $two = $this->tiktok($user, 'Dua');
        $this->actingAs($user)->putJson('/api/accounts/tiktok/default', ['account_id' => $two->id])->assertOk();

        $this->actingAs($user)->deleteJson("/api/accounts/tiktok/{$two->id}")->assertOk()->assertJson(['default_id' => $one->id]);
        $this->assertModelMissing($two);

        $fb = MetaAccount::create(['user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => 'f', 'access_token' => 't']);
        $page = MetaTarget::create(['user_id' => $user->id, 'meta_account_id' => $fb->id, 'platform' => 'facebook', 'external_id' => '1', 'access_token' => 'p']);
        $th = MetaAccount::create(['user_id' => $user->id, 'provider' => 'threads', 'provider_user_id' => 't', 'access_token' => 't']);
        $profile = MetaTarget::create(['user_id' => $user->id, 'meta_account_id' => $th->id, 'platform' => 'threads', 'external_id' => '5']);

        $this->actingAs($user)->deleteJson("/api/accounts/instagram/{$page->id}")->assertNotFound(); // a Page is not an Instagram account
        $this->actingAs($user)->deleteJson("/api/accounts/facebook/{$page->id}")->assertOk();
        $this->assertModelExists($fb); // the login stays for its other Pages
        $this->actingAs($user)->deleteJson("/api/accounts/threads/{$profile->id}")->assertOk();
        $this->assertModelMissing($th);
    }

    public function test_accounts_that_need_a_new_login_are_flagged(): void
    {
        $user = User::factory()->create();
        $expired = $this->tiktok($user, 'Lama', ['refresh_expires_at' => now()->subDay()]);
        $narrow = $this->tiktok($user, 'Sempit', ['scope' => 'user.info.basic']);
        $channel = $this->channel($user, 'Kanal');
        $this->travel(5)->minutes();
        $clip = $this->reviewClip($user);
        $clip->update(['youtube_account_id' => $channel->id, 'youtube_status' => 'failed', 'youtube_error' => 'Sesi YouTube kedaluwarsa atau dicabut. Hubungkan ulang channel YouTube.']);

        $res = $this->actingAs($user)->getJson('/api/accounts')->assertOk();
        $tiktok = collect($res->json('platforms.0.accounts'))->keyBy('id');
        $this->assertSame([true, 'Sesi TikTok habis.'], [$tiktok[$expired->id]['reconnect'], $tiktok[$expired->id]['problem']]);
        $this->assertStringContainsString('video.upload', $tiktok[$narrow->id]['problem']);
        $res->assertJsonPath('platforms.1.accounts.0.reconnect', true)
            ->assertJsonPath('platforms.1.accounts.0.problem', 'Sesi YouTube kedaluwarsa atau dicabut. Hubungkan ulang channel YouTube.');

        // Connecting the channel again (the callback updates its tokens) clears it
        $this->travel(1)->minutes();
        $channel->update(['access_token' => 'baru']);
        $this->actingAs($user)->getJson('/api/accounts')->assertOk()
            ->assertJsonPath('platforms.1.accounts.0.reconnect', false)
            ->assertJsonPath('platforms.1.accounts.0.problem', null);
    }

    public function test_approve_sends_to_the_default_tiktok_account_when_tiktok_is_on(): void
    {
        $user = User::factory()->create();
        $this->tiktok($user, 'Satu');
        $two = $this->tiktok($user, 'Dua');

        // Off (no row): nowhere to send it
        $clip = $this->reviewClip($user);
        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertUnprocessable()
            ->assertJsonPath('message', 'Belum ada tujuan. Nyalakan TikTok atau upload otomatis di Akun terhubung.');

        // TikTok has only a switch: no slots needed
        $this->actingAs($user)->putJson('/api/autopilot/tiktok', ['enabled' => true, 'account_id' => $two->id])->assertOk()
            ->assertJson(['platform' => 'tiktok', 'enabled' => true, 'account_id' => $two->id])
            ->assertJsonPath('accounts.1.name', 'Dua');

        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk();
        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->clipId === $clip->id && $job->accountId === $two->id);
        $this->assertSame([$two->id, 'queued'], [$clip->fresh()->tiktok_account_id, $clip->fresh()->tiktok_status]);
    }

    public function test_old_entry_points_lead_to_the_settings_page(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['approved_at' => now()])->save();

        $this->actingAs($user)->get('/settings/meta')->assertRedirect('/settings/accounts');
        // The OAuth starts stay where the platforms' apps expect them; their callbacks land on the page
        $this->actingAs($user)->get('/tiktok/callback?state=x&code=y')->assertRedirect('/settings/accounts?tiktok=invalid_state');
        $this->actingAs($user)->get('/youtube/callback?state=x&code=y')->assertRedirect('/settings/accounts?youtube=invalid_state');
        $this->actingAs($user)->get('/facebook/callback?state=x&code=y')->assertRedirect('/settings/accounts?facebook=invalid_state');
        $this->actingAs($user)->get('/threads/callback?state=x&code=y')->assertRedirect('/settings/accounts?threads=invalid_state');
    }
}
