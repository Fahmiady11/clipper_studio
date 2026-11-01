<?php

namespace Tests\Feature;

use App\Jobs\UploadToYouTubeJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use App\Models\YoutubeAccount;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class YouTubeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.youtube.client_id' => 'cid',
            'services.youtube.client_secret' => 'cs',
            'services.youtube.redirect_uri' => 'https://example.test/youtube/callback',
        ]);
    }

    private function account(User $user, array $attrs = []): YoutubeAccount
    {
        return YoutubeAccount::create($attrs + [
            'user_id' => $user->id,
            'channel_id' => 'UC1',
            'title' => 'Channel',
            'access_token' => 'at-old',
            'refresh_token' => 'rt-old',
            'access_expires_at' => now()->addHour(),
        ]);
    }

    private function doneClip(User $user, float $seconds = 30): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        Storage::put('clips/test.mp4', str_repeat('v', 2048)); // not a real video: the length check falls back to end - start

        return GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => $seconds,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'output_path' => 'clips/test.mp4',
        ]);
    }

    public function test_connect_asks_for_offline_access_and_callback_stores_the_channel(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->get('/youtube/connect');
        $res->assertRedirect();
        parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('cid', $q['client_id']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertStringContainsString('youtube.upload', $q['scope']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3599, 'scope' => YouTubeService::SCOPES]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UCabc', 'snippet' => ['title' => 'Podcast Klip', 'thumbnails' => ['default' => ['url' => 'https://yt3.example/a.jpg']]]]]]),
        ]);

        $this->actingAs($user)->get('/youtube/callback?state='.$q['state'].'&code=c1')->assertRedirect('/?youtube=connected');

        $account = YoutubeAccount::sole();
        $this->assertSame(['UCabc', 'Podcast Klip', 'rt'], [$account->channel_id, $account->title, $account->refresh_token]);
        $this->assertNotSame('rt', YoutubeAccount::query()->toBase()->value('refresh_token')); // encrypted at rest
    }

    public function test_callback_rejects_a_wrong_state(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/youtube/connect');

        $this->actingAs($user)->get('/youtube/callback?state=nope&code=c1')->assertRedirect('/?youtube=invalid_state');
        $this->assertSame(0, YoutubeAccount::count());
    }

    public function test_accounts_list_only_mine_and_without_tokens(): void
    {
        $me = User::factory()->create();
        $this->account($me);
        $this->account(User::factory()->create(), ['channel_id' => 'UC2']);

        $res = $this->actingAs($me)->getJson('/api/youtube/accounts')->assertOk();
        $this->assertTrue($res->json('configured'));
        $this->assertCount(1, $res->json('accounts'));
        $this->assertStringNotContainsString('at-old', $res->getContent());
    }

    public function test_upload_queues_the_job_with_the_metadata(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $this->account($user);
        $clip = $this->doneClip($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/youtube", [
            'account_id' => $account->id, 'title' => 'Judul', 'description' => 'Desk', 'privacy' => 'unlisted', 'tags' => ['podcast'],
        ])->assertOk()->assertJson(['status' => 'queued']);

        Queue::assertPushed(UploadToYouTubeJob::class, fn ($job) => $job->clipId === $clip->id
            && $job->meta === ['title' => 'Judul', 'description' => 'Desk', 'privacy' => 'unlisted', 'tags' => ['podcast']]);
        $this->assertSame('queued', $clip->fresh()->youtube_status);
    }

    public function test_upload_refuses_other_users_channels_long_clips_and_bad_titles(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->doneClip($user);
        $foreign = $this->account(User::factory()->create(), ['channel_id' => 'UC9']);
        $mine = $this->account($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/youtube", ['account_id' => $foreign->id, 'title' => 'x', 'privacy' => 'public'])->assertNotFound();
        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/youtube", ['account_id' => $mine->id, 'title' => 'a <b>', 'privacy' => 'public'])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/youtube", ['account_id' => $mine->id, 'title' => str_repeat('a', 101), 'privacy' => 'public'])->assertUnprocessable();

        $long = $this->doneClip($user, 200);
        $this->actingAs($user)->postJson("/api/clips/{$long->id}/youtube", ['account_id' => $mine->id, 'title' => 'x', 'privacy' => 'public'])
            ->assertUnprocessable()->assertJsonFragment(['message' => 'YouTube Shorts maksimal 3 menit, clip ini 200 detik. Pendekkan clip dulu.']);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson("/api/clips/{$clip->id}/youtube", ['account_id' => $this->account($stranger, ['channel_id' => 'UC7'])->id, 'title' => 'x', 'privacy' => 'public'])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_job_refreshes_an_expired_token_and_uploads_resumably(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, ['access_expires_at' => now()->subMinute()]);
        $clip = $this->doneClip($user);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-new', 'expires_in' => 3599]),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.example/session-1']),
            'upload.example/*' => Http::response(['id' => 'vid123']),
        ]);

        (new UploadToYouTubeJob($clip->id, $account->id, ['title' => 'Judul', 'description' => 'Desk', 'privacy' => 'public']))->handle(app(YouTubeService::class));

        $this->assertSame(['processing', 'vid123'], [$clip->fresh()->youtube_status, $clip->fresh()->youtube_video_id]);
        $this->assertSame('at-new', $account->fresh()->access_token);
        $this->assertSame('rt-old', $account->fresh()->refresh_token); // Google does not send a new one on refresh

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'uploadType=resumable')) {
                return false;
            }
            $body = json_decode($r->body(), true);

            return $r->hasHeader('Authorization', 'Bearer at-new')
                && $r->header('X-Upload-Content-Length')[0] === '2048'
                && $body['snippet']['title'] === 'Judul'
                && str_contains($body['snippet']['description'], '#Shorts')
                && $body['status'] === ['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false, 'embeddable' => true];
        });
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://upload.example/session-1' && strlen($r->body()) === 2048);
    }

    public function test_failed_upload_shows_a_readable_reason(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $clip = $this->doneClip($user);
        Http::fake(['www.googleapis.com/upload/*' => Http::response(['error' => ['code' => 403, 'message' => 'quota', 'errors' => [['reason' => 'quotaExceeded']]]], 403)]);

        $job = new UploadToYouTubeJob($clip->id, $account->id, ['title' => 'x', 'description' => '', 'privacy' => 'public']);
        try {
            $job->handle(app(YouTubeService::class));
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $job->failed($e);
        }

        $this->assertSame('failed', $clip->fresh()->youtube_status);
        $this->assertStringContainsString('Kuota harian YouTube API habis', $clip->fresh()->youtube_error);
    }

    public function test_status_follows_processing_until_published(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $clip = $this->doneClip($user);
        $clip->update(['youtube_account_id' => $account->id, 'youtube_video_id' => 'vid123', 'youtube_status' => 'processing']);

        Http::fakeSequence('www.googleapis.com/youtube/v3/videos*')
            ->push(['items' => [['status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => 'private']]]])
            ->push(['items' => [['status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'private']]]]);

        $this->actingAs($user)->getJson("/api/clips/{$clip->id}/youtube-status")->assertJson(['status' => 'processing']);
        $this->actingAs($user)->getJson("/api/clips/{$clip->id}/youtube-status")
            ->assertJson(['status' => 'published', 'privacy' => 'private', 'url' => 'https://youtube.com/shorts/vid123']);
    }

    public function test_video_resource_keeps_youtube_limits(): void
    {
        $r = YouTubeService::videoResource(['title' => str_repeat('a', 120).'<x>', 'description' => 'halo #shorts', 'privacy' => 'everyone', 'tags' => ['#podcast', ' ', 'bisnis']]);

        $this->assertSame(100, mb_strlen($r['snippet']['title']));
        $this->assertSame('halo #shorts', $r['snippet']['description']); // already tagged: no second #Shorts
        $this->assertSame(['podcast', 'bisnis'], $r['snippet']['tags']);
        $this->assertSame('private', $r['status']['privacyStatus']); // unknown privacy never goes public
    }
}
