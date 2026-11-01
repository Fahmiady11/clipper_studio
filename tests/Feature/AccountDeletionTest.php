<?php

namespace Tests\Feature;

use App\Jobs\PublishToMetaJob;
use App\Jobs\RenderClipJob;
use App\Jobs\UploadToTikTokJob;
use App\Jobs\UploadToYouTubeJob;
use App\Models\ApiToken;
use App\Models\ClipProject;
use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\YoutubeAccount;
use App\Services\AccountDeletion;
use App\Services\MetaPublishing;
use App\Services\TikTokService;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.tiktok.client_key' => 'tt-key',
            'services.tiktok.client_secret' => 'tt-secret',
            'services.facebook.app_secret' => 'fb-secret',
        ]);
        Storage::fake('local');
    }

    private function fakePlatforms(int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response('', $status),
            'open.tiktokapis.com/v2/oauth/revoke/' => Http::response('', $status),
            'graph.facebook.com/*' => Http::response(['success' => true], $status),
            '*' => Http::response('unexpected', 500),
        ]);
    }

    /** A user with something everywhere: connections on every platform, a schedule, autopilot, a render, files. */
    private function busyUser(string $suffix = 'a'): array
    {
        $user = User::factory()->create();
        $youtube = YoutubeAccount::create([
            'user_id' => $user->id, 'channel_id' => "UC-{$suffix}", 'title' => 'Channel',
            'access_token' => "yt-at-{$suffix}", 'refresh_token' => "yt-rt-{$suffix}", 'access_expires_at' => now()->addHour(),
        ]);
        $tiktok = TiktokAccount::create([
            'user_id' => $user->id, 'open_id' => "open-{$suffix}", 'display_name' => 'Creator',
            'access_token' => "tt-at-{$suffix}", 'refresh_token' => "tt-rt-{$suffix}",
            'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(300),
        ]);
        $facebook = MetaAccount::create([
            'user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => "fb-{$suffix}", 'name' => 'FB', 'access_token' => "fb-at-{$suffix}",
        ]);
        $page = MetaTarget::create([
            'user_id' => $user->id, 'meta_account_id' => $facebook->id, 'platform' => 'facebook', 'external_id' => "page-{$suffix}", 'name' => 'Page', 'access_token' => 'page-token',
        ]);
        $threads = MetaAccount::create([
            'user_id' => $user->id, 'provider' => 'threads', 'provider_user_id' => "th-{$suffix}", 'name' => 'th', 'access_token' => "th-at-{$suffix}",
        ]);
        MetaTarget::create(['user_id' => $user->id, 'meta_account_id' => $threads->id, 'platform' => 'threads', 'external_id' => "th-{$suffix}"]);

        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg', 'video_title' => 'Podcast',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $output = "clips/{$user->id}/{$project->id}/clip.mp4";
        Storage::put($output, 'video');
        $clip = GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 'Momen', 'reason' => 'r', 'status' => 'done', 'output_path' => $output,
        ]);
        ScheduledPost::create([
            'user_id' => $user->id, 'generated_clip_id' => $clip->id, 'platform' => 'youtube', 'account_id' => $youtube->id,
            'scheduled_at' => now()->addHour(), 'status' => 'scheduled', 'options' => [],
        ]);
        VideoSource::create(['user_id' => $user->id, 'type' => 'keyword', 'value' => 'podcast', 'settings' => []]);
        ApiToken::issue($user, 'laptop');
        Storage::disk('local')->put("brand/{$user->id}/logo.png", 'png');

        return compact('user', 'youtube', 'tiktok', 'facebook', 'page', 'threads', 'project', 'clip', 'output');
    }

    private function deleteAs(User $user, array $input = [])
    {
        return $this->actingAs($user)->from(route('account.settings'))->delete(route('account.destroy'), $input + [
            'password' => 'password', 'confirmation' => 'HAPUS',
        ]);
    }

    public function test_settings_page_offers_deletion_and_the_user_menu_links_to_it(): void
    {
        $user = User::factory()->create(['name' => 'Rani']);

        $this->actingAs($user)->get(route('account.settings'))->assertOk()
            ->assertSee('Hapus akun')->assertSee('Rani')->assertSee('name="confirmation"', false)->assertSee(route('account.destroy'));
        $this->actingAs($user)->get('/')->assertSee(route('account.settings'));
    }

    public function test_deleting_removes_everything_revokes_platform_access_and_signs_out(): void
    {
        $this->fakePlatforms();
        $gone = $this->busyUser('a');
        $kept = $this->busyUser('b');

        $this->deleteAs($gone['user'])->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertGuest();

        $id = $gone['user']->id;
        $this->assertNull(User::find($id));
        foreach ([YoutubeAccount::class, TiktokAccount::class, MetaAccount::class, MetaTarget::class, ScheduledPost::class, VideoSource::class, ApiToken::class, ClipProject::class] as $model) {
            $this->assertSame(0, $model::where('user_id', $id)->count(), $model);
        }
        $this->assertNull(GeneratedClip::find($gone['clip']->id));
        Storage::assertMissing($gone['output']);
        Storage::disk('local')->assertMissing("brand/{$id}/logo.png");

        // Google gets the refresh token, TikTok the access token with the app's credentials, Facebook a DELETE
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'yt-rt-a');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://open.tiktokapis.com/v2/oauth/revoke/')
            && $r['token'] === 'tt-at-a' && $r['client_key'] === 'tt-key' && $r['client_secret'] === 'tt-secret');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/me/permissions')
            && str_contains($r->url(), 'access_token=fb-at-a') && str_contains($r->url(), 'appsecret_proof='));
        Http::assertSentCount(3); // nothing for Threads, nothing for the other user

        // The other user is untouched
        $this->assertNotNull(User::find($kept['user']->id));
        $this->assertSame(1, ScheduledPost::where('user_id', $kept['user']->id)->count());
        $this->assertSame(2, MetaAccount::where('user_id', $kept['user']->id)->count());
        Storage::assertExists($kept['output']);
    }

    public function test_platforms_failing_or_unreachable_do_not_block_the_deletion(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $gone = $this->busyUser();

        $this->deleteAs($gone['user'])->assertRedirect(route('login'));

        $this->assertNull(User::find($gone['user']->id));
    }

    public function test_platform_errors_do_not_block_the_deletion(): void
    {
        $this->fakePlatforms(400);
        $gone = $this->busyUser();

        $this->deleteAs($gone['user'])->assertRedirect(route('login'));

        $this->assertNull(User::find($gone['user']->id));
    }

    public function test_an_external_account_another_user_also_connected_is_not_revoked(): void
    {
        $this->fakePlatforms();
        $gone = $this->busyUser('a');
        $other = User::factory()->create();
        YoutubeAccount::create([
            'user_id' => $other->id, 'channel_id' => 'UC-a', 'title' => 'Same channel',
            'access_token' => 'x', 'refresh_token' => 'y', 'access_expires_at' => now()->addHour(),
        ]);

        $this->deleteAs($gone['user'])->assertRedirect(route('login'));

        Http::assertNotSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke');
        $this->assertSame(1, YoutubeAccount::where('user_id', $other->id)->count());
    }

    public function test_wrong_password_or_missing_word_keeps_the_account(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->deleteAs($user, ['password' => 'wrong'])
            ->assertRedirect(route('account.settings'))->assertSessionHasErrorsIn('deleteAccount', ['password']);
        $this->deleteAs($user, ['confirmation' => 'hapus akun'])
            ->assertSessionHasErrorsIn('deleteAccount', ['confirmation']);
        $this->deleteAs($user, ['confirmation' => ''])
            ->assertSessionHasErrorsIn('deleteAccount', ['confirmation']);

        $this->assertNotNull(User::find($user->id));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();
    }

    public function test_the_confirmation_word_ignores_case_and_spaces(): void
    {
        $user = User::factory()->create();

        $this->deleteAs($user, ['confirmation' => ' Hapus '])->assertRedirect(route('login'));

        $this->assertNull(User::find($user->id));
    }

    public function test_the_last_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        $this->actingAs($admin)->get(route('account.settings'))->assertOk()
            ->assertSee('satu-satunya admin')->assertDontSee('name="confirmation"', false);
        $this->deleteAs($admin)->assertRedirect(route('account.settings'))
            ->assertSessionHasErrorsIn('deleteAccount', ['account']);

        $this->assertNotNull(User::find($admin->id));
    }

    public function test_an_admin_can_delete_themselves_while_another_admin_remains(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->deleteAs($admin)->assertRedirect(route('login'));

        $this->assertNull(User::find($admin->id));
        $this->assertTrue(app(AccountDeletion::class)->isLastAdmin(User::sole())); // and now that one has to stay
    }

    public function test_pending_work_is_stopped_before_the_platforms_are_called(): void
    {
        $gone = $this->busyUser();
        $seen = [];
        Http::fake(function () use (&$seen, $gone) {
            $seen[] = [
                'scheduled' => ScheduledPost::where('user_id', $gone['user']->id)->count(),
                'autopilot' => VideoSource::where('user_id', $gone['user']->id)->where('enabled', true)->count(),
                'cancelled' => GeneratedClip::whereKey($gone['clip']->id)->whereNotNull('cancelled_at')->exists(),
            ];

            return Http::response('');
        });

        $this->deleteAs($gone['user'])->assertRedirect(route('login'));

        $this->assertNotEmpty($seen);
        $this->assertSame(['scheduled' => 0, 'autopilot' => 0, 'cancelled' => true], $seen[0]);
    }

    public function test_jobs_still_queued_for_a_deleted_account_exit_quietly(): void
    {
        $this->fakePlatforms();
        $gone = $this->busyUser();
        app(AccountDeletion::class)->delete($gone['user']->fresh());
        $calls = count(Http::recorded());

        (new UploadToYouTubeJob($gone['clip']->id, $gone['youtube']->id, ['title' => 't', 'description' => 'd', 'privacy' => 'private']))->handle(app(YouTubeService::class));
        (new UploadToTikTokJob($gone['clip']->id, $gone['tiktok']->id))->handle(app(TikTokService::class));
        (new PublishToMetaJob($gone['clip']->id, 'facebook', $gone['page']->id, 'caption'))->handle(app(MetaPublishing::class));
        app()->call([new RenderClipJob($gone['clip']->id), 'handle']);

        $this->assertCount($calls, Http::recorded()); // no upload was attempted
        $this->assertSame(0, ClipPublication::count()); // and no failure was written for a clip that is gone
    }
}
