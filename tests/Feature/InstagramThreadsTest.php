<?php

namespace Tests\Feature;

use App\Jobs\PublishToMetaJob;
use App\Models\ClipProject;
use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Models\PlatformSetting;
use App\Models\ScheduledPost;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\MetaPublishing;
use App\Services\MetaStatusPoller;
use App\Services\PublicMediaUrl;
use App\Services\ScheduledPublisher;
use App\Services\ThreadsLogin;
use App\Services\ThreadsPosts;
use App\Support\CaptionCredit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class InstagramThreadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://clip.example.com',
            'services.facebook.app_id' => 'app-1',
            'services.facebook.app_secret' => 'secret',
            'services.facebook.redirect_uri' => 'https://clip.example.com/facebook/callback',
            'services.facebook.graph_version' => 'v26.0',
            'services.threads.app_id' => 'th-app',
            'services.threads.app_secret' => 'th-secret',
            'services.threads.redirect_uri' => 'https://clip.example.com/threads/callback',
            'services.threads.graph_url' => 'https://graph.threads.com',
            'services.threads.authorize_url' => 'https://threads.com/oauth/authorize',
        ]);
        Storage::fake();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Jakarta'));
    }

    private function fbLogin(User $user): MetaAccount
    {
        return MetaAccount::firstOrCreate(
            ['user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => 'fb'.$user->id],
            ['name' => 'Rina', 'access_token' => 'user-long', 'scopes' => 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish'],
        );
    }

    private function instagram(User $user, array $attrs = []): MetaTarget
    {
        return MetaTarget::create($attrs + [
            'user_id' => $user->id, 'meta_account_id' => $this->fbLogin($user)->id, 'platform' => 'instagram',
            'external_id' => '1789', 'name' => 'Toko Rina', 'username' => 'tokorina', 'access_token' => 'page-token',
        ]);
    }

    private function threads(User $user, array $attrs = []): MetaTarget
    {
        $account = MetaAccount::create([
            'user_id' => $user->id, 'provider' => 'threads', 'provider_user_id' => 'th'.$user->id,
            'name' => 'tokorina', 'access_token' => 'threads-token', 'token_expires_at' => now()->addDays(50),
        ]);

        return MetaTarget::create($attrs + [
            'user_id' => $user->id, 'meta_account_id' => $account->id, 'platform' => 'threads',
            'external_id' => '5005', 'name' => 'Rina', 'username' => 'tokorina',
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
        Storage::put($path, 'video');

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 40,
            'topic' => 'Rahasia bisnis kecil', 'reason' => 'r', 'status' => 'done', 'review_status' => $review ? 'pending' : null,
            'output_path' => $path, 'caption' => 'Dengar ini sampai habis.', 'hashtags_json' => ['#bisnis', '#umkm'],
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

    private function quota(int $used, int $total): array
    {
        return ['data' => [['quota_usage' => $used, 'config' => ['quota_total' => $total, 'quota_duration' => 86400]]]];
    }

    // ── public media URL ────────────────────────────────────────────────

    public function test_public_media_url_must_be_public_https(): void
    {
        $media = app(PublicMediaUrl::class);
        foreach (['https://clip.example.com', 'https://abc.ngrok-free.app', 'https://203.0.113.9'] as $url) {
            config(['app.url' => $url]);
            $this->assertNull($media->problem(), $url);
        }
        foreach (['http://clip.example.com', 'https://localhost', 'http://127.0.0.1:8000', 'https://clipper.test', 'https://192.168.1.5', 'https://10.0.0.2'] as $url) {
            config(['app.url' => $url]);
            $this->assertStringContainsString('bukan alamat HTTPS publik', (string) $media->problem(), $url);
        }
    }

    // ── connecting ──────────────────────────────────────────────────────

    public function test_the_instagram_account_linked_to_a_page_is_picked_with_the_page_token(): void
    {
        $user = User::factory()->create();
        $login = $this->fbLogin($user);
        Http::fake(['graph.facebook.com/v26.0/me/accounts*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Toko Rina', 'access_token' => 'page-111', 'tasks' => ['CREATE_CONTENT'],
                'instagram_business_account' => ['id' => '1789', 'username' => 'tokorina', 'name' => 'Toko Rina', 'profile_picture_url' => 'https://cdn.test/ig.jpg']],
            ['id' => '222', 'name' => 'Lain', 'access_token' => 'page-222', 'tasks' => ['ANALYZE'], 'instagram_business_account' => ['id' => '2000', 'username' => 'lain']],
        ]])]);

        $this->actingAs($user)->getJson("/api/meta/accounts/{$login->id}/pages")->assertOk()
            ->assertJsonPath('pages.0.instagram', ['id' => '1789', 'username' => 'tokorina', 'picture_url' => 'https://cdn.test/ig.jpg', 'connected' => false]);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'instagram_business_account{id,username,name,profile_picture_url}'));

        // Instagram alone, without the Facebook Page itself
        $this->actingAs($user)->putJson("/api/meta/accounts/{$login->id}/pages", ['page_ids' => [], 'instagram_ids' => ['1789']])
            ->assertOk()->assertJson(['connected' => 1]);
        $ig = MetaTarget::sole();
        $this->assertSame(['instagram', '1789', 'tokorina', 'page-111', '111'], [$ig->platform, $ig->external_id, $ig->username, $ig->access_token, $ig->details['page_id']]);

        // The linked Page gives no content rights
        $this->actingAs($user)->putJson("/api/meta/accounts/{$login->id}/pages", ['page_ids' => [], 'instagram_ids' => ['2000']])
            ->assertUnprocessable()->assertJsonPath('message', 'Kamu tidak punya izin membuat konten di Page Lain.');

        $this->actingAs($user)->getJson('/api/meta/targets/instagram')->assertOk()
            ->assertJson(['configured' => true, 'blocked' => null])
            ->assertJsonPath('accounts.0.username', 'tokorina')
            ->assertJsonMissingPath('accounts.0.access_token');

        // A login that did not grant Instagram publishing is flagged once it has an Instagram account
        $login->update(['scopes' => 'pages_show_list,pages_read_engagement,pages_manage_posts']);
        $this->actingAs($user)->getJson('/api/meta/accounts')->assertOk()
            ->assertJsonPath('accounts.0.missing_scopes', ['instagram_basic', 'instagram_content_publish']);
    }

    public function test_threads_login_stores_a_long_lived_token_and_one_profile(): void
    {
        $user = User::factory()->create();

        $url = $this->actingAs($user)->get('/threads/connect')->headers->get('Location');
        $this->assertStringStartsWith('https://threads.com/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['th-app', 'https://clip.example.com/threads/callback', 'threads_basic,threads_content_publish', 'code'], [$q['client_id'], $q['redirect_uri'], $q['scope'], $q['response_type']]);

        Http::fake([
            'graph.threads.com/oauth/access_token' => Http::response(['access_token' => 'short', 'user_id' => 5005]),
            'graph.threads.com/access_token*' => Http::response(['access_token' => 'long', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            'graph.threads.com/v1.0/me*' => Http::response(['id' => '5005', 'username' => 'tokorina', 'name' => 'Rina', 'threads_profile_picture_url' => 'https://cdn.test/th.jpg']),
        ]);
        $this->actingAs($user)->withSession(['threads_oauth_state' => 's'])->get('/threads/callback?state=nope&code=c')->assertRedirect('/settings/accounts?threads=invalid_state');
        $this->actingAs($user)->withSession(['threads_oauth_state' => 's'])->get('/threads/callback?state=s&code=the-code')->assertRedirect('/settings/accounts?threads=connected');

        $account = MetaAccount::sole();
        $target = MetaTarget::sole();
        $this->assertSame(['threads', '5005', 'long'], [$account->provider, $account->provider_user_id, $account->access_token]);
        $this->assertTrue($account->token_expires_at->between(now()->addDays(59), now()->addDays(61)));
        $this->assertSame(['threads', '5005', 'tokorina', null, 'long'], [$target->platform, $target->external_id, $target->username, $target->access_token, $target->token()]);
        $this->assertNotSame('long', DB::table('meta_accounts')->value('access_token'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.threads.com/oauth/access_token'
            && $r->data()['code'] === 'the-code' && $r->data()['grant_type'] === 'authorization_code');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'grant_type=th_exchange_token'));
    }

    public function test_threads_tokens_are_refreshed_before_they_expire(): void
    {
        $user = User::factory()->create();
        $soon = $this->threads($user)->account;
        $soon->update(['token_expires_at' => now()->addDays(10)]);
        $fresh = MetaAccount::create(['user_id' => $user->id, 'provider' => 'threads', 'provider_user_id' => 'x', 'access_token' => 'fresh', 'token_expires_at' => now()->addDays(55)]);
        $dead = MetaAccount::create(['user_id' => $user->id, 'provider' => 'threads', 'provider_user_id' => 'y', 'access_token' => 'dead', 'token_expires_at' => now()->subDay()]);
        Http::fake(['graph.threads.com/refresh_access_token*' => Http::response(['access_token' => 'renewed', 'expires_in' => 5184000])]);

        $this->assertSame(['refreshed' => 1, 'failed' => 0], app(ThreadsLogin::class)->refreshDue());

        $this->assertSame('renewed', $soon->fresh()->access_token);
        $this->assertTrue($soon->fresh()->token_expires_at->gt(now()->addDays(59)));
        $this->assertSame(['fresh', 'dead'], [$fresh->fresh()->access_token, $dead->fresh()->access_token]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'grant_type=th_refresh_token'));
    }

    // ── calendar ────────────────────────────────────────────────────────

    public function test_calendar_refuses_instagram_and_threads_when_app_url_is_not_public(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);
        $user = User::factory()->create();
        $ig = $this->instagram($user);
        $th = $this->threads($user);

        foreach ([['instagram', $ig], ['threads', $th]] as [$platform, $target]) {
            $this->actingAs($user)->postJson('/api/schedule', [
                'clip_id' => $this->clip($user)->id, 'platform' => $platform, 'account_id' => $target->id, 'scheduled_at' => now()->addHour()->toISOString(),
            ])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'bukan alamat HTTPS publik'));
        }
        $this->actingAs($user)->getJson('/api/meta/targets/threads')->assertOk()
            ->assertJsonPath('blocked', fn ($m) => str_contains($m, 'APP_URL'));
        $this->assertSame(0, ScheduledPost::count());
    }

    public function test_calendar_checks_caption_limits_and_hands_both_to_the_job(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['approved_at' => now()])->save();
        $ig = $this->instagram($user);
        $th = $this->threads($user);
        $clip = $this->clip($user);
        $at = now()->addHour()->toISOString();

        $tags = implode(' ', array_map(fn ($i) => "#t{$i}", range(1, 31)));
        $this->actingAs($user)->postJson('/api/schedule', ['clip_id' => $clip->id, 'platform' => 'instagram', 'account_id' => $ig->id, 'scheduled_at' => $at, 'description' => $tags])
            ->assertUnprocessable()->assertJsonPath('errors.description.0', 'Caption Instagram maksimal 30 hashtag, caption ini punya 31.');
        $this->actingAs($user)->postJson('/api/schedule', ['clip_id' => $clip->id, 'platform' => 'threads', 'account_id' => $th->id, 'scheduled_at' => $at, 'description' => str_repeat('😀', 126)])
            ->assertUnprocessable()->assertJsonPath('errors.description.0', fn ($m) => str_starts_with($m, 'Teks Threads maksimal 500 karakter'));
        // A Threads profile is not an Instagram account
        $this->actingAs($user)->postJson('/api/schedule', ['clip_id' => $clip->id, 'platform' => 'instagram', 'account_id' => $th->id, 'scheduled_at' => $at])->assertNotFound();

        $this->actingAs($user)->postJson('/api/schedule', ['clip_id' => $clip->id, 'platform' => 'instagram', 'account_id' => $ig->id, 'scheduled_at' => $at, 'description' => 'IG caption #bisnis'])
            ->assertCreated()->assertJsonPath('post.options.caption', 'IG caption #bisnis')->assertJsonPath('post.account_label', 'Toko Rina');
        $this->actingAs($user)->postJson('/api/schedule', ['clip_id' => $clip->id, 'platform' => 'threads', 'account_id' => $th->id, 'scheduled_at' => $at, 'description' => 'Thread teks'])
            ->assertCreated()->assertJsonPath('post.options.text', 'Thread teks');

        $this->travel(61)->minutes();
        $this->assertSame(2, app(ScheduledPublisher::class)->publishDue());
        Queue::assertPushed(PublishToMetaJob::class, fn ($j) => $j->platform === 'instagram' && $j->targetId === $ig->id && $j->text === 'IG caption #bisnis');
        Queue::assertPushed(PublishToMetaJob::class, fn ($j) => $j->platform === 'threads' && $j->targetId === $th->id && $j->text === 'Thread teks');
        $this->assertSame(['queued', 'queued'], ClipPublication::orderBy('platform')->pluck('status')->all());
    }

    // ── Instagram publishing ────────────────────────────────────────────

    public function test_instagram_job_creates_a_reels_container_from_the_signed_url(): void
    {
        $user = User::factory()->create();
        $ig = $this->instagram($user);
        $clip = $this->clip($user);
        Http::fake([
            'graph.facebook.com/v26.0/1789/content_publishing_limit*' => Http::response($this->quota(3, 50)),
            'graph.facebook.com/v26.0/1789/media' => Http::response(['id' => 'cont-1']),
        ]);

        $this->runJob(new PublishToMetaJob($clip->id, 'instagram', $ig->id, "Caption\n\n#bisnis"));

        $pub = ClipPublication::sole();
        $this->assertSame(['instagram', 'processing', 'cont-1', null], [$pub->platform, $pub->status, $pub->container_id, $pub->external_id]);
        Http::assertSent(function (Request $r) use ($clip) {
            if (! str_ends_with($r->url(), '/1789/media')) {
                return false;
            }
            $d = $r->data();
            $this->assertSame(['REELS', "Caption\n\n#bisnis", 'true', 'page-token'], [$d['media_type'], $d['caption'], $d['share_to_feed'], $d['access_token']]);
            $this->assertStringStartsWith("https://clip.example.com/media/clips/{$clip->id}?expires=", $d['video_url']);
            // The URL Meta gets really serves the clip
            $this->get(substr($d['video_url'], strlen('https://clip.example.com')))->assertOk();

            return true;
        });
    }

    public function test_instagram_daily_limit_fails_the_post_with_the_reason(): void
    {
        $user = User::factory()->create();
        $ig = $this->instagram($user);
        Http::fake(['graph.facebook.com/v26.0/1789/content_publishing_limit*' => Http::response($this->quota(50, 50))]);

        $this->runJob(new PublishToMetaJob($this->clip($user)->id, 'instagram', $ig->id, 'x'));

        $pub = ClipPublication::sole();
        $this->assertSame('failed', $pub->status);
        $this->assertSame('Batas 50 posting Instagram lewat API dalam 24 jam sudah tercapai (50/50). Coba lagi besok.', $pub->error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/media'));
    }

    public function test_instagram_container_is_published_once_finished(): void
    {
        $user = User::factory()->create();
        $ig = $this->instagram($user);
        $pub = ClipPublication::create(['generated_clip_id' => $this->clip($user)->id, 'platform' => 'instagram', 'target_id' => $ig->id, 'status' => 'processing', 'container_id' => 'cont-1', 'uploaded_at' => now()]);
        $broken = ClipPublication::create(['generated_clip_id' => $this->clip($user)->id, 'platform' => 'instagram', 'target_id' => $ig->id, 'status' => 'processing', 'container_id' => 'cont-2', 'uploaded_at' => now()]);
        $state = 'IN_PROGRESS';
        Http::fake([
            'graph.facebook.com/v26.0/cont-1*' => function () use (&$state) {
                return Http::response(['status_code' => $state, 'id' => 'cont-1']);
            },
            'graph.facebook.com/v26.0/cont-2*' => Http::response(['status_code' => 'ERROR', 'status' => 'Error: Media upload has failed with error code 2207026']),
            'graph.facebook.com/v26.0/1789/content_publishing_limit*' => Http::response($this->quota(4, 50)),
            'graph.facebook.com/v26.0/1789/media_publish' => Http::response(['id' => 'media-9']),
            'graph.facebook.com/v26.0/media-9*' => Http::response(['permalink' => 'https://www.instagram.com/reel/abc/']),
        ]);

        app(MetaStatusPoller::class)->run();
        $this->assertSame('processing', $pub->fresh()->status);
        $this->assertSame(['failed', 'Instagram gagal memproses video: Error: Media upload has failed with error code 2207026.'], [$broken->fresh()->status, $broken->fresh()->error]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media_publish'));

        $state = 'FINISHED';
        $this->assertSame(['checked' => 1, 'settled' => 1], app(MetaStatusPoller::class)->run());
        $pub->refresh();
        $this->assertSame(['published', 'media-9', 'https://www.instagram.com/reel/abc/', null], [$pub->status, $pub->external_id, $pub->url, $pub->error]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1789/media_publish') && $r->data()['creation_id'] === 'cont-1');
    }

    // ── Threads publishing ──────────────────────────────────────────────

    public function test_threads_post_goes_through_container_then_publish(): void
    {
        $user = User::factory()->create();
        $th = $this->threads($user);
        $clip = $this->clip($user);
        Http::fake([
            'graph.threads.com/v1.0/5005/threads_publishing_limit*' => Http::response($this->quota(10, 250)),
            'graph.threads.com/v1.0/5005/threads' => Http::response(['id' => 'tc-1']),
            'graph.threads.com/v1.0/tc-1*' => Http::response(['id' => 'tc-1', 'status' => 'FINISHED']),
            'graph.threads.com/v1.0/5005/threads_publish' => Http::response(['id' => 'tp-1']),
            'graph.threads.com/v1.0/tp-1*' => Http::response(['permalink' => 'https://www.threads.com/@tokorina/post/abc']),
        ]);

        $this->runJob(new PublishToMetaJob($clip->id, 'threads', $th->id, 'Teks thread'));
        $this->assertSame(['processing', 'tc-1'], [ClipPublication::sole()->status, ClipPublication::sole()->container_id]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/5005/threads') && $r->data()['media_type'] === 'VIDEO'
            && $r->data()['text'] === 'Teks thread' && $r->data()['access_token'] === 'threads-token'
            && str_starts_with($r->data()['video_url'], 'https://clip.example.com/media/clips/'));

        app(MetaStatusPoller::class)->run();
        $pub = ClipPublication::sole();
        $this->assertSame(['published', 'tp-1', 'https://www.threads.com/@tokorina/post/abc'], [$pub->status, $pub->external_id, $pub->url]);
    }

    public function test_threads_errors_expired_token_and_limit_are_visible(): void
    {
        $user = User::factory()->create();
        $th = $this->threads($user);
        $clip = $this->clip($user, [], review: true);
        $clip->update(['review_status' => 'approved']);
        ScheduledPost::create([
            'user_id' => $user->id, 'generated_clip_id' => $clip->id, 'platform' => 'threads', 'account_id' => $th->id,
            'scheduled_at' => now()->addMinutes(5), 'status' => 'queued', 'options' => ['text' => 'x'],
        ]);

        // One fake whose answer the steps below swap
        $answer = Http::response(['error' => ['message' => 'Session expired', 'type' => 'OAuthException', 'code' => 190]], 400);
        Http::fake(['graph.threads.com/*' => function () use (&$answer) {
            return $answer;
        }]);
        $this->runJob(new PublishToMetaJob($clip->id, 'threads', $th->id, 'x'));
        $this->assertStringContainsString('Hubungkan ulang Threads', ClipPublication::sole()->error);
        $this->actingAs($user)->getJson('/api/schedule?from=2026-11-01&to=2026-12-01')->assertOk()
            ->assertJsonPath('posts.0.delivery', 'failed')->assertJsonPath('posts.0.reconnect_url', '/settings/accounts?reconnect=threads');
        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.threads.reconnect_url', '/settings/accounts?reconnect=threads');

        $answer = Http::response($this->quota(250, 250));
        $this->runJob(new PublishToMetaJob($clip->id, 'threads', $th->id, 'x'));
        $this->assertSame('Batas 250 posting Threads lewat API dalam 24 jam sudah tercapai (250/250). Coba lagi besok.', ClipPublication::sole()->error);

        // Meta could not download the video; a container that never finishes is given up after a day
        $pub = ClipPublication::sole();
        $pub->update(['status' => 'processing', 'container_id' => 'tc-1', 'error' => null, 'uploaded_at' => now()]);
        $answer = Http::response(['status' => 'ERROR', 'error_message' => 'FAILED_DOWNLOADING_VIDEO']);
        app(MetaStatusPoller::class)->run();
        $this->assertStringContainsString('tidak bisa mengunduh video', $pub->fresh()->error);

        $pub->refresh()->update(['status' => 'processing', 'error' => null, 'uploaded_at' => now()->subHours(25), 'checked_at' => null]);
        $answer = Http::response(['status' => 'IN_PROGRESS']);
        app(MetaStatusPoller::class)->run();
        $this->assertSame('failed', $pub->fresh()->status);
        $this->assertStringContainsString('dalam 24 jam', $pub->fresh()->error);
    }

    public function test_threads_text_is_cut_at_a_word_within_500_bytes(): void
    {
        $text = CaptionCredit::compose(str_repeat('kata 😀 ', 200), null, '', ThreadsPosts::TEXT_MAX_BYTES, bytes: true);
        $this->assertLessThanOrEqual(500, strlen($text));
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringEndsWith('…', $text);
        $this->assertSame('pendek', CaptionCredit::compose('  pendek ', null, '', ThreadsPosts::TEXT_MAX_BYTES, bytes: true));
    }

    // ── autopilot ───────────────────────────────────────────────────────

    public function test_autopilot_books_instagram_and_threads_with_their_own_text(): void
    {
        $user = User::factory()->create();
        $ig = $this->instagram($user);
        $th = $this->threads($user);
        foreach ([['instagram', $ig->id, '12:00'], ['threads', $th->id, '18:00']] as [$platform, $account, $slot]) {
            $this->actingAs($user)->putJson("/api/autopilot/{$platform}", [
                'enabled' => true, 'account_id' => $account, 'max_per_day' => 2, 'slot_times' => [$slot], 'timezone' => 'Asia/Jakarta',
            ])->assertOk()->assertJsonPath('account_id', $account);
        }
        $this->actingAs($user)->getJson('/api/autopilot/instagram')->assertOk()->assertJsonPath('accounts.0.name', 'Toko Rina (@tokorina)');

        $clip = $this->clip($user, ['hashtags_json' => array_map(fn ($i) => "#t{$i}", range(1, 35))], review: true);
        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk()
            ->assertJsonPath('instagram.scheduled_at', '2026-11-10T05:00:00.000000Z')
            ->assertJsonPath('threads.scheduled_at', '2026-11-10T11:00:00.000000Z')
            ->assertJsonPath('youtube', null);

        $igPost = ScheduledPost::where('platform', 'instagram')->sole();
        $this->assertSame(30, substr_count($igPost->options['caption'], '#'));
        $this->assertStringStartsWith("Dengar ini sampai habis.\n\n#t1 #t2", $igPost->options['caption']);
        $this->assertSame("Dengar ini sampai habis.\n\n#t1", ScheduledPost::where('platform', 'threads')->sole()->options['text']);

        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.instagram.status', 'scheduled')
            ->assertJsonPath('data.0.threads.status', 'scheduled');
    }

    public function test_autopilot_keeps_why_a_clip_could_not_go(): void
    {
        config(['app.url' => 'http://localhost']);
        $user = User::factory()->create();
        PlatformSetting::create([
            'user_id' => $user->id, 'platform' => 'threads', 'account_id' => $this->threads($user)->id, 'enabled' => true,
            'max_per_day' => 3, 'slot_times' => ['09:00'], 'timezone' => 'Asia/Jakarta',
        ]);
        $clip = $this->clip($user, [], review: true);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk()
            ->assertJsonPath('threads.error', fn ($m) => str_starts_with($m, 'Tidak dijadwalkan ke Threads: Threads mengambil video lewat link publik'));
        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()->assertJsonPath('data.0.threads.status', 'skipped');

        config(['app.url' => 'https://clip.example.com']);
        $long = $this->clip($user, ['end_seconds' => 320], review: true);
        $this->actingAs($user)->postJson("/api/autopilot/review/{$long->id}/approve")->assertOk()
            ->assertJsonPath('threads.error', 'Tidak dijadwalkan ke Threads: Video Threads maksimal 5 menit, clip ini 320 detik.');
    }
}
