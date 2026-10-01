<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\YoutubeUrl;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
    }

    public function test_responses_carry_security_headers(): void
    {
        $res = $this->get('/login')->assertOk();

        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $res->headers->get('Content-Security-Policy'));
    }

    public function test_forwarded_headers_are_only_trusted_from_the_local_tunnel(): void
    {
        $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '1.2.3.4'];

        // ngrok/cloudflared agent on this machine: trusted → https + real client IP
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://localhost/login', $headers)
            ->assertHeader('Strict-Transport-Security');

        // Anyone else can't spoof scheme or IP with headers
        $res = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('http://localhost/login', $headers);
        $this->assertFalse($res->headers->has('Strict-Transport-Security'));
    }

    public function test_api_writes_need_a_csrf_token(): void
    {
        $this->actingAs(User::factory()->create());

        // Tests normally skip CSRF; switch the real check back on for this request
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests(): bool { return false; }
        });

        $this->postJson('/api/projects', [])->assertStatus(419);
    }

    public function test_sign_up_closes_after_the_first_account(): void
    {
        config(['auth.registration_enabled' => false]);

        // Fresh install: the first account can be created
        $this->get('/register')->assertOk();
        $this->post('/register', ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])
            ->assertRedirect(route('home'));
        $this->post('/logout');

        // After that, strangers can't
        $this->get('/register')->assertRedirect(route('login'));
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])
            ->assertForbidden();
        $this->assertSame(1, User::count());

        // Unless the owner opens it on purpose
        config(['auth.registration_enabled' => true]);
        $this->get('/register')->assertOk();
    }

    public function test_legacy_unauthenticated_clip_api_is_gone(): void
    {
        $this->postJson('/api/clips', ['youtube_url' => 'https://youtu.be/abcdefghijk'])->assertNotFound();
        $this->getJson('/api/clip/1/download')->assertNotFound();
        $this->postJson('/api/test/subtitle')->assertNotFound();
        $this->get('/storage/clips/1/x.mp4')->assertNotFound();
    }

    public function test_youtube_links_cannot_smuggle_yt_dlp_options(): void
    {
        foreach ([
            '--exec=touch /tmp/x #youtube.com/watch?v=abcdefghijk',
            'https://evil.test/youtube.com/watch?v=abcdefghijk',
            'https://www.youtube.com.evil.test/watch?v=abcdefghijk',
            'https://user:pw@www.youtube.com/watch?v=abcdefghijk',
            'https://www.youtube.com/watch?v=abc',
            'file:///etc/passwd',
        ] as $bad) {
            $this->assertNull(YoutubeUrl::canonical($bad), $bad);
        }

        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s',
            'youtu.be/dQw4w9WgXcQ',
            'https://m.youtube.com/shorts/dQw4w9WgXcQ',
        ] as $good) {
            $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', YoutubeUrl::canonical($good), $good);
        }

        $this->assertSame('https://www.youtube.com/@berkelaspodcast', YoutubeUrl::channel('@berkelaspodcast'));
        $this->assertSame('https://www.youtube.com/@berkelaspodcast', YoutubeUrl::channel('https://www.youtube.com/@berkelaspodcast/videos'));
        $this->assertNull(YoutubeUrl::channel('http://169.254.169.254/latest/meta-data'));
        $this->assertNull(YoutubeUrl::channel('--exec=id'));
    }

    public function test_trailing_newline_cannot_ride_along_in_an_id(): void
    {
        $this->assertNull(YoutubeUrl::canonical('https://www.youtube.com/watch?v=dQw4w9WgXcQ%0A'));
        $this->assertNull(YoutubeUrl::channel("@berkelas\npodcast"));
        $this->assertNull(YoutubeUrl::channel('https://www.youtube.com/c/../videos'));
    }

    public function test_music_paths_with_nul_bytes_are_not_found_not_500(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/api/music/file?path=' . urlencode("chill/a.mp3\0.png"))->assertNotFound();
    }

    public function test_rejected_clip_cannot_be_approved_and_job_rechecks_ownership(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = \App\Models\ClipProject::create([
            'user_id' => $owner->id, 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $clip = \App\Models\GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 'T', 'reason' => 'R', 'viral_potential' => 'tinggi', 'status' => 'done', 'review_status' => 'rejected',
        ]);
        $account = \App\Models\TiktokAccount::create([
            'user_id' => $owner->id, 'open_id' => 'o1', 'access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'video.upload', 'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addYear(),
        ]);

        $this->actingAs($owner)->postJson("/api/autopilot/review/{$clip->id}/approve", ['account_id' => $account->id])
            ->assertStatus(422);
        Queue::assertNothingPushed();

        // A job built by hand with someone else's account refuses to upload
        $foreign = \App\Models\TiktokAccount::create([
            'user_id' => $other->id, 'open_id' => 'o2', 'access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'video.upload', 'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addYear(),
        ]);
        $clip->update(['review_status' => 'approved', 'output_path' => 'clips/x.mp4']);
        $this->expectException(\RuntimeException::class);
        (new \App\Jobs\UploadToTikTokJob($clip->id, $foreign->id))->handle($this->mock(\App\Services\TikTokService::class));
    }

    public function test_project_and_meta_reject_injected_links(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/projects', [
            'youtube_url' => '--exec=touch /tmp/x #youtube.com/watch?v=abcdefghijk',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto',
        ])->assertUnprocessable()->assertJsonValidationErrors('youtube_url');

        $this->getJson('/api/meta?url=' . urlencode('--exec=id #youtube.com/watch?v=abcdefghijk'))->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_project_stores_the_canonical_url_only(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/projects', [
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ?si=tracking',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto',
        ])->assertCreated();

        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', \App\Models\ClipProject::first()->youtube_url);
    }
}
