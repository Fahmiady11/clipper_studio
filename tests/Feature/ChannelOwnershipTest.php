<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\YoutubeAccount;
use App\Services\DiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\VerifiesYouTubeChannel;
use Tests\TestCase;

/**
 * Card 8: the service only processes videos from a channel the user proved
 * with OAuth (youtube.readonly at connect), or a file uploaded directly.
 */
class ChannelOwnershipTest extends TestCase
{
    use RefreshDatabase, VerifiesYouTubeChannel;

    private const CHANNEL = 'UCowned000000000000000';

    private array $settings = ['layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Queue::fake();
    }

    public function test_link_from_a_foreign_channel_is_refused(): void
    {
        // Google says the video lives on someone else's channel
        $user = User::factory()->create();
        $this->ownChannel($user, self::CHANNEL);
        $this->fakeChannelLookups('UCsomeoneElse00000000000');

        $this->actingAs($user)->postJson('/api/projects', $this->settings + [
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertUnprocessable()->assertJsonValidationErrors('youtube_url');

        $this->assertSame(0, ClipProject::count());
        Queue::assertNothingPushed();
    }

    public function test_link_from_own_verified_channel_is_accepted(): void
    {
        $user = $this->verifiedUser(self::CHANNEL);

        $this->actingAs($user)->postJson('/api/projects', $this->settings + [
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertCreated();

        $this->assertSame(self::CHANNEL, YoutubeAccount::sole()->channel_id);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', ClipProject::sole()->youtube_url);
    }

    public function test_user_without_a_connected_channel_cannot_send_a_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/projects', $this->settings + [
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertUnprocessable()->assertJsonValidationErrors('youtube_url');
    }

    public function test_direct_upload_needs_no_channel_and_keeps_the_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(); // no YouTube account at all

        $this->actingAs($user)->post('/api/projects/upload', $this->settings + [
            'video' => UploadedFile::fake()->create('podcast.mp4', 1024, 'video/mp4'),
            'title' => 'Podcast Sendiri',
        ], ['Accept' => 'application/json'])->assertCreated();

        $project = ClipProject::sole();
        $this->assertSame('upload://direct', $project->youtube_url);
        $this->assertSame('Podcast Sendiri', $project->video_title);
        $this->assertNotNull($project->source_file_path);
        Storage::disk('local')->assertExists($project->source_file_path);
    }

    public function test_upload_rejects_a_non_video_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/projects/upload', $this->settings + [
            'video' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('video');

        $this->assertSame(0, ClipProject::count());
    }

    public function test_metadata_step_fails_before_the_wizard_goes_further(): void
    {
        $user = User::factory()->create();
        $this->ownChannel($user, self::CHANNEL);
        $this->fakeChannelLookups('UCsomeoneElse00000000000'); // someone else's video

        $this->actingAs($user)->getJson('/api/meta?url='.urlencode('https://youtu.be/dQw4w9WgXcQ'))
            ->assertUnprocessable()
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'verifikasi'));
    }

    public function test_autopilot_only_accepts_the_verified_channel(): void
    {
        $user = User::factory()->create();
        $this->ownChannel($user, self::CHANNEL);
        // The handle in the source resolves to whatever Google reports for it
        Http::fake(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/channels')
            ? Http::response(['items' => [[
                'id' => (string) $request['forHandle'] === '@sendiri' ? self::CHANNEL : 'UCsomeoneElse00000000000',
            ]]])
            : Http::response(['error' => 'unexpected call'], 500));

        $this->actingAs($user)->postJson('/api/autopilot/sources', ['type' => 'channel', 'value' => '@oranglain'])
            ->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->actingAs($user)->postJson('/api/autopilot/sources', ['type' => 'channel', 'value' => '@sendiri'])
            ->assertCreated();
    }

    public function test_autopilot_rejects_keyword_sources(): void
    {
        $user = $this->verifiedUser(self::CHANNEL);

        $this->actingAs($user)->postJson('/api/autopilot/sources', ['type' => 'keyword', 'value' => 'podcast'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_discovery_refuses_a_source_whose_channel_is_no_longer_verified(): void
    {
        $user = User::factory()->create();
        $this->ownChannel($user, self::CHANNEL);
        $source = VideoSource::create([
            'user_id' => $user->id, 'type' => 'channel', 'value' => 'https://www.youtube.com/@oranglain',
            'settings' => [],
        ]);
        $this->fakeChannelLookups('UCsomeoneElse00000000000');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('belum/tidak lagi terverifikasi');
        app(DiscoveryService::class)->run($source);
    }

    public function test_settings_page_explains_why_and_disconnect_takes_effect(): void
    {
        $user = $this->verifiedUser(self::CHANNEL);
        $account = YoutubeAccount::sole();

        $this->actingAs($user)->get('/settings/youtube')
            ->assertOk()
            ->assertSee('Kenapa harus verifikasi channel?')
            ->assertSee('youtube.readonly')
            ->assertSee(self::CHANNEL);

        $this->actingAs($user)->post('/settings/youtube/'.$account->id.'/disconnect')
            ->assertRedirect('/settings/youtube');
        $this->assertSame(0, YoutubeAccount::count());

        // Without the proof, the same link is refused again
        $this->actingAs($user)->postJson('/api/projects', $this->settings + [
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertUnprocessable()->assertJsonValidationErrors('youtube_url');
    }

    public function test_connect_refuses_a_grant_without_readonly_scope(): void
    {
        config([
            'services.youtube.client_id' => 'cid',
            'services.youtube.client_secret' => 'cs',
            'services.youtube.redirect_uri' => 'https://example.test/youtube/callback',
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload',
            ]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/youtube/connect');
        $this->actingAs($user)->get('/youtube/callback?state='.session('youtube_oauth_state').'&code=c1')
            ->assertRedirect('/settings/youtube')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'youtube.readonly'));

        $this->assertSame(0, YoutubeAccount::count());
    }
}
