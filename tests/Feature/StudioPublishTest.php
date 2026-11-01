<?php

namespace Tests\Feature;

use App\Jobs\PublishToMetaJob;
use App\Jobs\UploadToTikTokJob;
use App\Jobs\UploadToYouTubeJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\YoutubeAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudioPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://clip.example.com',
            'services.tiktok.client_key' => 'tk', 'services.tiktok.client_secret' => 'ts', 'services.tiktok.redirect_uri' => 'https://clip.example.com/tiktok/callback',
            'services.youtube.client_id' => 'yc', 'services.youtube.client_secret' => 'ys', 'services.youtube.redirect_uri' => 'https://clip.example.com/youtube/callback',
            'services.facebook.app_id' => 'fa', 'services.facebook.app_secret' => 'fs', 'services.facebook.redirect_uri' => 'https://clip.example.com/facebook/callback',
            'services.threads.app_id' => 'ta', 'services.threads.app_secret' => 'tsec', 'services.threads.redirect_uri' => 'https://clip.example.com/threads/callback',
        ]);
        Storage::fake();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Jakarta'));
        $this->user = User::factory()->create();
        $this->user->forceFill(['approved_at' => now()])->save();
    }

    private function connectAll(): array
    {
        $u = $this->user;
        $tiktok = TiktokAccount::create(['user_id' => $u->id, 'open_id' => 'o', 'display_name' => 'rina.tt', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(300), 'scope' => 'user.info.basic,video.upload,video.publish']);
        $youtube = YoutubeAccount::create(['user_id' => $u->id, 'channel_id' => 'UC1', 'title' => 'Rina Clips', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour()]);
        $fbLogin = MetaAccount::create(['user_id' => $u->id, 'provider' => 'facebook', 'provider_user_id' => 'f', 'name' => 'Rina', 'access_token' => 't']);
        $page = MetaTarget::create(['user_id' => $u->id, 'meta_account_id' => $fbLogin->id, 'platform' => 'facebook', 'external_id' => '111', 'name' => 'Toko Rina', 'access_token' => 'p']);
        $ig = MetaTarget::create(['user_id' => $u->id, 'meta_account_id' => $fbLogin->id, 'platform' => 'instagram', 'external_id' => '1789', 'name' => 'Toko Rina', 'username' => 'tokorina', 'access_token' => 'p']);
        $thLogin = MetaAccount::create(['user_id' => $u->id, 'provider' => 'threads', 'provider_user_id' => 't', 'name' => 'rina', 'access_token' => 't', 'token_expires_at' => now()->addDays(50)]);
        $threads = MetaTarget::create(['user_id' => $u->id, 'meta_account_id' => $thLogin->id, 'platform' => 'threads', 'external_id' => '5005', 'name' => 'Rina', 'username' => 'rina']);

        return compact('tiktok', 'youtube', 'page', 'ig', 'threads');
    }

    private function clip(array $attrs = [], ?User $owner = null): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => ($owner ?? $this->user)->id, 'youtube_url' => 'https://youtu.be/abcdefg', 'video_title' => 'Podcast',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        Storage::put($path = 'clips/'.uniqid().'.mp4', 'video');

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 40, 'topic' => 'Rahasia <bisnis> kecil',
            'reason' => 'r', 'status' => 'done', 'output_path' => $path, 'caption' => 'Dengar ini.', 'hashtags_json' => ['#bisnis'],
        ]);
    }

    private function send(GeneratedClip $clip, array $body)
    {
        return $this->actingAs($this->user)->postJson("/api/clips/{$clip->id}/publish", $body + ['caption' => 'Dengar ini sampai habis.', 'hashtags' => ['#bisnis', 'umkm']]);
    }

    public function test_panel_shows_each_platform_with_its_default_account_and_why_it_cant_go(): void
    {
        ['page' => $page] = $this->connectAll();
        MetaTarget::where('platform', 'threads')->delete(); // Threads not connected
        $long = $this->clip(['end_seconds' => 120]);

        $res = $this->actingAs($this->user)->getJson("/api/clips/{$long->id}/publish")->assertOk()
            ->assertJsonPath('remembered', [])
            ->assertJsonPath('platforms.0.id', 'tiktok')
            ->assertJsonPath('platforms.0.account.name', 'rina.tt')
            ->assertJsonPath('platforms.0.refusal', null)
            ->assertJsonPath('platforms.1.refusal', null) // 120 s is fine for Shorts
            ->assertJsonPath('platforms.2.account', ['id' => $page->id, 'name' => 'Toko Rina'])
            ->assertJsonPath('platforms.2.refusal', 'Reels Facebook maksimal 90 detik, clip ini 120 detik. Pendekkan clip dulu.')
            ->assertJsonPath('platforms.4.account', null)
            ->assertJsonPath('platforms.4.needs_default', false)
            ->assertJsonPath('platforms.4.settings_url', '/settings/accounts#threads');
        $this->assertSame([null, null, null, null, null], array_column($res->json('platforms'), 'state'));

        $this->actingAs(User::factory()->create())->getJson("/api/clips/{$long->id}/publish")->assertForbidden();
    }

    public function test_send_now_makes_calendar_posts_and_hands_them_to_the_upload_jobs(): void
    {
        $a = $this->connectAll();
        $clip = $this->clip();

        $this->send($clip, ['platforms' => ['tiktok', 'youtube', 'facebook', 'threads'], 'youtube_title' => 'Judul pilihan', 'youtube_privacy' => 'unlisted'])
            ->assertOk()
            ->assertJsonPath('remembered', ['tiktok', 'youtube', 'facebook', 'threads'])
            ->assertJsonPath('platforms.0.state.status', 'queued')
            ->assertJsonPath('platforms.0.state.delivery', 'queued')
            ->assertJsonPath('platforms.2.state.delivery', 'queued')
            ->assertJsonPath('platforms.3.state', null); // Instagram was not ticked

        $posts = ScheduledPost::orderBy('platform')->get()->keyBy('platform');
        $this->assertSame(['facebook', 'threads', 'tiktok', 'youtube'], $posts->keys()->all());
        $this->assertSame(['queued'], $posts->pluck('status')->unique()->values()->all());
        $this->assertSame([$a['tiktok']->id, $a['youtube']->id, $a['page']->id, $a['threads']->id],
            [$posts['tiktok']->account_id, $posts['youtube']->account_id, $posts['facebook']->account_id, $posts['threads']->account_id]);

        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->clipId === $clip->id && $job->accountId === $a['tiktok']->id && $job->mode === 'inbox');
        Queue::assertPushed(UploadToYouTubeJob::class, fn ($job) => $job->meta['title'] === 'Judul pilihan' && $job->meta['privacy'] === 'unlisted'
            && $job->meta['description'] === "Dengar ini sampai habis.\n\n#bisnis #umkm" && $job->meta['tags'] === ['bisnis', 'umkm']);
        Queue::assertPushed(PublishToMetaJob::class, fn ($job) => $job->platform === 'facebook' && $job->text === "Dengar ini sampai habis.\n\n#bisnis #umkm");
        // Threads keeps one hashtag
        Queue::assertPushed(PublishToMetaJob::class, fn ($job) => $job->platform === 'threads' && $job->text === "Dengar ini sampai habis.\n\n#bisnis");

        // They are ordinary calendar posts
        $this->actingAs($this->user)->getJson('/api/schedule?from=2026-11-01&to=2026-12-01')->assertOk()->assertJsonCount(4, 'posts');
        // And the choice is remembered for next time
        $this->actingAs($this->user)->getJson("/api/clips/{$this->clip()->id}/publish")->assertJsonPath('remembered', ['tiktok', 'youtube', 'facebook', 'threads']);
    }

    public function test_schedule_books_posts_for_later_without_sending(): void
    {
        $this->connectAll();
        $clip = $this->clip(['hashtags_json' => []]);
        $tags = array_map(fn ($i) => "#t{$i}", range(1, 35));

        $this->send($clip, ['platforms' => ['instagram'], 'scheduled_at' => now()->addMinute()->toISOString()])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');

        $at = CarbonImmutable::parse('2026-11-11 19:00', 'Asia/Jakarta');
        $this->actingAs($this->user)->postJson("/api/clips/{$clip->id}/publish", [
            'platforms' => ['instagram', 'youtube'], 'scheduled_at' => $at->toISOString(), 'caption' => 'Caption', 'hashtags' => $tags,
        ])->assertOk()->assertJsonPath('platforms.3.state.status', 'scheduled');

        $ig = ScheduledPost::where('platform', 'instagram')->sole();
        $this->assertSame(['scheduled', $at->utc()->toISOString()], [$ig->status, $ig->scheduled_at->toISOString()]);
        $this->assertSame(30, substr_count($ig->options['caption'], '#')); // Instagram's cap
        $this->assertSame('Rahasia bisnis kecil', ScheduledPost::where('platform', 'youtube')->sole()->options['title']); // the topic, cleaned
        Queue::assertNothingPushed();

        // A second booking for the same platform waits for the first to go or be cancelled
        $this->actingAs($this->user)->postJson("/api/clips/{$clip->id}/publish", ['platforms' => ['instagram']])
            ->assertUnprocessable()->assertJsonPath('errors', ['platforms.instagram' => ['Clip ini sudah dijadwalkan ke Instagram. Ubah atau batalkan jadwalnya di kalender.']]);
    }

    public function test_nothing_is_sent_unless_every_ticked_platform_can_go(): void
    {
        $a = $this->connectAll();
        $long = $this->clip(['end_seconds' => 120]);
        TiktokAccount::create(['user_id' => $this->user->id, 'open_id' => 'o2', 'display_name' => 'dua', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(300), 'scope' => 'video.upload']);
        $this->actingAs($this->user)->getJson('/api/accounts')->assertOk(); // pins rina.tt as the TikTok default
        $a['tiktok']->delete();

        $this->send($long, ['platforms' => ['tiktok', 'youtube', 'facebook']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', [
                'platforms.tiktok' => ['Akun utama TikTok sudah diputus. Pilih akun utama di Akun terhubung.'],
                'platforms.facebook' => ['Reels Facebook maksimal 90 detik, clip ini 120 detik. Pendekkan clip dulu.'],
            ]);
        $this->assertSame(0, ScheduledPost::count());
        Queue::assertNothingPushed();

        $this->send($long, ['platforms' => []])->assertUnprocessable()->assertJsonPath('errors.platforms.0', 'Centang minimal satu platform.');
        $this->send($long, ['platforms' => ['youtube'], 'tiktok_branded' => true])->assertOk(); // branded only matters for TikTok
        $this->actingAs(User::factory()->create())->postJson("/api/clips/{$long->id}/publish", ['platforms' => ['youtube']])->assertForbidden();
    }

    public function test_a_failed_hand_off_shows_on_the_platform_line(): void
    {
        $this->connectAll();
        $clip = $this->clip();
        $clip->update(['tiktok_status' => 'uploading']); // already on its way from somewhere else

        $this->send($clip, ['platforms' => ['tiktok']])->assertOk()
            ->assertJsonPath('platforms.0.state.status', 'failed')
            ->assertJsonPath('platforms.0.state.error', 'Clip ini sedang diupload ke TikTok.');
    }
}
