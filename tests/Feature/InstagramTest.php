<?php

namespace Tests\Feature;

use App\Jobs\UploadToInstagramJob;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesInstagramFixtures;
use Tests\TestCase;

class InstagramTest extends TestCase
{
    use MakesInstagramFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureInstagram();
    }

    private function fakeOAuth(?array $short = null): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response($short ?? [
                'access_token' => 'short', 'user_id' => 1784,
                'permissions' => 'instagram_business_basic,instagram_business_content_publish',
            ]),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-lived', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            'graph.instagram.com/v21.0/me*' => Http::response(['user_id' => '1784', 'username' => 'brandku', 'profile_picture_url' => 'https://cdn.example/p.jpg']),
        ]);
    }

    private function startOAuth(User $user): string
    {
        $res = $this->actingAs($user)->get('/instagram/connect');
        $res->assertRedirect();
        parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);

        return $q['state'];
    }

    public function test_connect_sends_the_user_to_instagram_with_the_publish_scope_and_a_state(): void
    {
        $res = $this->actingAs(User::factory()->create())->get('/instagram/connect');

        $location = $res->headers->get('Location');
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('ig-app', $q['client_id']);
        $this->assertSame('https://example.test/instagram/callback', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertStringContainsString('instagram_business_content_publish', $q['scope']);
        $this->assertSame(40, strlen($q['state']));
        $this->assertSame($q['state'], session('instagram_oauth_state'));
    }

    public function test_connect_without_credentials_explains_what_is_missing_instead_of_redirecting_away(): void
    {
        config(['services.instagram.app_secret' => null]);

        $this->actingAs(User::factory()->create())->get('/instagram/connect')
            ->assertRedirect(route('connections.index'))
            ->assertSessionHas('error');
    }

    public function test_callback_stores_a_long_lived_encrypted_token_for_the_professional_account(): void
    {
        $user = User::factory()->create();
        $this->fakeOAuth();
        $state = $this->startOAuth($user);

        $this->actingAs($user)->get("/instagram/callback?state={$state}&code=abc")
            ->assertRedirect(route('connections.index'))
            ->assertSessionHas('status');

        $account = SocialAccount::sole();
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('instagram', $account->platform);
        $this->assertSame('1784', $account->external_id);
        $this->assertSame('@brandku', $account->display_name);
        $this->assertSame('long-lived', $account->access_token);
        $this->assertEqualsWithDelta(now()->addSeconds(5183944)->timestamp, $account->access_expires_at->timestamp, 5);
        // At rest the token is ciphertext, and it never leaves in JSON
        $this->assertNotSame('long-lived', DB::table('social_accounts')->value('access_token'));
        $this->assertArrayNotHasKey('access_token', $account->toArray());

        Http::assertSent(fn ($r) => $r->url() === 'https://api.instagram.com/oauth/access_token'
            && $r['code'] === 'abc' && $r['client_secret'] === 'ig-secret' && $r['grant_type'] === 'authorization_code');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.instagram.com/access_token')
            && $r['grant_type'] === 'ig_exchange_token' && $r['access_token'] === 'short');
    }

    public function test_connecting_the_same_account_twice_keeps_one_row_with_the_new_token(): void
    {
        $user = User::factory()->create();
        $this->igAccount($user, ['access_token' => 'stale']);
        $this->fakeOAuth();

        $this->actingAs($user)->get('/instagram/callback?state='.$this->startOAuth($user).'&code=abc');

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('long-lived', SocialAccount::sole()->access_token);
    }

    public function test_callback_with_the_wrong_state_connects_nothing(): void
    {
        $user = User::factory()->create();
        $this->startOAuth($user);
        Http::fake();

        $this->actingAs($user)->get('/instagram/callback?state=forged&code=abc')
            ->assertRedirect(route('connections.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, SocialAccount::count());
        Http::assertNothingSent();
    }

    public function test_callback_without_a_started_flow_is_refused(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())->get('/instagram/callback?state=x&code=abc')->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_the_user_denying_access_is_reported_without_calling_instagram(): void
    {
        $user = User::factory()->create();
        $state = $this->startOAuth($user);
        Http::fake();

        $this->actingAs($user)->get("/instagram/callback?state={$state}&error=access_denied")
            ->assertSessionHas('error', 'Izin Instagram dibatalkan. Akun belum terhubung.');

        Http::assertNothingSent();
    }

    public function test_instagram_rejecting_the_code_shows_its_reason(): void
    {
        $user = User::factory()->create();
        Http::fake(['api.instagram.com/*' => Http::response(['error_type' => 'OAuthException', 'code' => 400, 'error_message' => 'Invalid authorization code'], 400)]);

        $this->actingAs($user)->get('/instagram/callback?state='.$this->startOAuth($user).'&code=bad')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Invalid authorization code'));

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_an_unreachable_instagram_during_connect_is_reported_without_curl_noise(): void
    {
        $user = User::factory()->create();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->actingAs($user)->get('/instagram/callback?state='.$this->startOAuth($user).'&code=abc')
            ->assertSessionHas('error', 'Tidak bisa terhubung ke Instagram. Coba lagi beberapa menit lagi.');
    }

    public function test_an_account_that_did_not_grant_publishing_is_not_connected(): void
    {
        $user = User::factory()->create();
        $this->fakeOAuth(['access_token' => 'short', 'user_id' => 1784, 'permissions' => 'instagram_business_basic']);

        $this->actingAs($user)->get('/instagram/callback?state='.$this->startOAuth($user).'&code=abc')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'instagram_business_content_publish'));

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_newer_api_versions_that_wrap_the_token_in_data_still_work(): void
    {
        $user = User::factory()->create();
        $this->fakeOAuth(['data' => [['access_token' => 'short', 'user_id' => 1784, 'permissions' => 'instagram_business_basic,instagram_business_content_publish']]]);

        $this->actingAs($user)->get('/instagram/callback?state='.$this->startOAuth($user).'&code=abc')->assertSessionHas('status');

        $this->assertSame(1, SocialAccount::count());
    }

    public function test_accounts_lists_only_the_users_own_accounts_without_tokens(): void
    {
        $mine = User::factory()->create();
        $this->igAccount($mine);
        $this->igAccount(User::factory()->create(), ['display_name' => '@other']);
        SocialAccount::create(['user_id' => $mine->id, 'platform' => 'other', 'external_id' => 'z', 'access_token' => 't']);

        $res = $this->actingAs($mine)->getJson('/api/instagram/accounts')->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.name', '@brandku');

        $this->assertStringNotContainsString('ig-token', $res->getContent());
    }

    public function test_disconnect_removes_own_account_and_never_someone_elses(): void
    {
        $mine = User::factory()->create();
        $account = $this->igAccount($mine);
        $theirs = $this->igAccount(User::factory()->create());

        $this->actingAs($mine)->deleteJson("/api/instagram/accounts/{$theirs->id}")->assertNotFound();
        $this->assertModelExists($theirs);

        $this->actingAs($mine)->deleteJson("/api/instagram/accounts/{$account->id}")->assertOk();
        $this->assertModelMissing($account);
    }

    public function test_publish_queues_the_upload_and_reports_it_as_queued(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $this->igAccount($user);
        $clip = $this->doneClip($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/instagram", [
            'account_id' => $account->id, 'caption' => '  Halo dunia #klip  ', 'share_to_feed' => false,
        ])->assertOk()->assertJson(['clip_id' => $clip->id, 'status' => 'queued', 'error' => null, 'url' => null]);

        Queue::assertPushed(UploadToInstagramJob::class, fn ($job) => $job->clipId === $clip->id
            && $job->accountId === $account->id
            && $job->options === ['caption' => 'Halo dunia #klip', 'share_to_feed' => false]);
        $this->assertSame($account->id, $clip->fresh()->instagram_account_id);
    }

    public function test_pressing_publish_again_while_it_is_in_flight_does_not_queue_a_second_upload(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $this->igAccount($user);
        $clip = $this->doneClip($user, ['instagram_status' => 'processing']);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/instagram", ['account_id' => $account->id])
            ->assertOk()->assertJson(['status' => 'processing']);

        Queue::assertNothingPushed();
    }

    public function test_a_failed_upload_can_be_sent_again(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $this->igAccount($user);
        $clip = $this->doneClip($user, ['instagram_status' => 'failed', 'instagram_error' => 'boom']);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/instagram", ['account_id' => $account->id])
            ->assertOk()->assertJson(['status' => 'queued', 'error' => null]);

        Queue::assertPushed(UploadToInstagramJob::class, 1);
    }

    public function test_publish_refuses_unfinished_clips_foreign_clips_and_foreign_accounts(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $this->igAccount($user);
        $stranger = User::factory()->create();

        $rendering = $this->doneClip($user, ['status' => 'processing']);
        $this->actingAs($user)->postJson("/api/clips/{$rendering->id}/instagram", ['account_id' => $account->id])
            ->assertStatus(422)->assertJsonPath('message', 'Clip belum selesai dirender.');

        $clip = $this->doneClip($user);
        $this->actingAs($stranger)->postJson("/api/clips/{$clip->id}/instagram", ['account_id' => $account->id])->assertForbidden();

        $strangersAccount = $this->igAccount($stranger, ['external_id' => '999']);
        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/instagram", ['account_id' => $strangersAccount->id])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Akun Instagram tidak ditemukan'));

        Queue::assertNothingPushed();
    }

    public function test_a_caption_over_the_instagram_limit_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = $this->igAccount($user);
        $clip = $this->doneClip($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/instagram", ['account_id' => $account->id, 'caption' => str_repeat('a', 2201)])
            ->assertStatus(422)->assertJsonValidationErrors('caption');
    }

    public function test_publish_is_rate_limited_per_minute(): void
    {
        $user = User::factory()->create();
        $clip = $this->doneClip($user);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson("/api/clips/{$clip->id}/instagram", [])->assertStatus(422);
        }
        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/instagram", [])->assertStatus(429);
    }

    public function test_status_reports_where_the_clip_stands_and_hides_other_users_clips(): void
    {
        $user = User::factory()->create();
        $clip = $this->doneClip($user);

        $this->actingAs($user)->getJson("/api/clips/{$clip->id}/instagram-status")
            ->assertOk()->assertJson(['clip_id' => $clip->id, 'status' => null, 'error' => null, 'url' => null]);

        $clip->update(['instagram_status' => 'published', 'instagram_url' => 'https://www.instagram.com/reel/C1/']);
        $this->actingAs($user)->getJson("/api/clips/{$clip->id}/instagram-status")
            ->assertJson(['status' => 'published', 'url' => 'https://www.instagram.com/reel/C1/']);

        $clip->update(['instagram_status' => 'failed', 'instagram_error' => 'Instagram menolak video ini: format.']);
        $this->actingAs($user)->getJson("/api/clips/{$clip->id}/instagram-status")
            ->assertJson(['status' => 'failed', 'error' => 'Instagram menolak video ini: format.']);

        $this->actingAs(User::factory()->create())->getJson("/api/clips/{$clip->id}/instagram-status")->assertForbidden();
    }

    public function test_guests_cannot_reach_any_instagram_endpoint(): void
    {
        $this->getJson('/api/instagram/accounts')->assertUnauthorized();
        $this->get('/instagram/connect')->assertRedirect('/login');
    }
}
