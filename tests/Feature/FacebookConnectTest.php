<?php

namespace Tests\Feature;

use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.facebook.app_id' => 'app-1',
            'services.facebook.app_secret' => 'secret',
            'services.facebook.redirect_uri' => 'https://clip.test/facebook/callback',
            'services.facebook.graph_version' => 'v26.0',
            'services.facebook.config_id' => null,
        ]);
    }

    private function login(User $user, array $attrs = []): MetaAccount
    {
        return MetaAccount::create($attrs + [
            'user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => 'fb'.$user->id,
            'name' => 'Rina', 'access_token' => 'user-long', 'scopes' => 'pages_show_list,pages_read_engagement,pages_manage_posts',
        ]);
    }

    private function fakePages(): void
    {
        Http::fake(['graph.facebook.com/v26.0/me/accounts*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Toko Rina', 'access_token' => 'page-111', 'tasks' => ['CREATE_CONTENT', 'MANAGE'], 'picture' => ['data' => ['url' => 'https://cdn.test/111.jpg']]],
            ['id' => '222', 'name' => 'Komunitas', 'access_token' => 'page-222', 'tasks' => ['ANALYZE']],
        ]])]);
    }

    public function test_connect_redirects_to_the_dialog_by_scope_or_business_config(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->get('/facebook/connect');
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v26.0/dialog/oauth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['app-1', 'https://clip.test/facebook/callback', 'code'], [$q['client_id'], $q['redirect_uri'], $q['response_type']]);
        $this->assertSame(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'], explode(',', $q['scope']));
        $this->assertStringNotContainsString('publish_video', $url);
        $this->assertSame(session('facebook_oauth_state'), $q['state']);

        config(['services.facebook.config_id' => 'cfg-9']);
        parse_str(parse_url($this->actingAs($user)->get('/facebook/connect')->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('cfg-9', $q['config_id']);
        $this->assertArrayNotHasKey('scope', $q);

        config(['services.facebook.app_id' => null]);
        $this->actingAs($user)->get('/facebook/connect')->assertRedirect('/settings/meta?facebook=not_configured');
    }

    public function test_callback_stores_a_long_lived_login_and_rejects_a_bad_state(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'graph.facebook.com/v26.0/oauth/access_token*' => fn (Request $r) => Http::response(
                ($r->data()['grant_type'] ?? null) === 'fb_exchange_token'
                    ? ['access_token' => 'user-long', 'token_type' => 'bearer', 'expires_in' => 5184000]
                    : ['access_token' => 'user-short', 'expires_in' => 3600]
            ),
            'graph.facebook.com/v26.0/me/permissions*' => Http::response(['data' => [
                ['permission' => 'pages_show_list', 'status' => 'granted'],
                ['permission' => 'pages_manage_posts', 'status' => 'declined'],
            ]]),
            'graph.facebook.com/v26.0/me?*' => Http::response(['id' => '9001', 'name' => 'Rina']),
        ]);

        $this->actingAs($user)->withSession(['facebook_oauth_state' => 'abc'])
            ->get('/facebook/callback?state=wrong&code=x')->assertRedirect('/settings/meta?facebook=invalid_state');
        $this->actingAs($user)->withSession(['facebook_oauth_state' => 'abc'])
            ->get('/facebook/callback?state=abc&error=access_denied')->assertRedirect('/settings/meta?facebook=denied');

        $res = $this->actingAs($user)->withSession(['facebook_oauth_state' => 'abc'])->get('/facebook/callback?state=abc&code=the-code');
        $account = MetaAccount::sole();
        $res->assertRedirect('/settings/meta?facebook=connected&account='.$account->id);

        $this->assertSame(['9001', 'Rina', 'user-long', 'pages_show_list'], [$account->provider_user_id, $account->name, $account->access_token, $account->scopes]);
        $this->assertTrue($account->token_expires_at->between(now()->addDays(59), now()->addDays(61)));
        // Encrypted at rest
        $this->assertNotSame('user-long', DB::table('meta_accounts')->value('access_token'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/access_token') && ($r->data()['code'] ?? null) === 'the-code');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me?') && $r->data()['appsecret_proof'] === hash_hmac('sha256', 'user-long', 'secret'));

        // The declined permission is flagged on the settings page
        $this->actingAs($user)->getJson('/api/meta/accounts')->assertOk()
            ->assertJsonPath('accounts.0.missing_scopes', ['pages_read_engagement', 'pages_manage_posts'])
            ->assertJsonMissingPath('accounts.0.access_token');
    }

    public function test_picking_pages_keeps_their_page_tokens_and_syncs_the_choice(): void
    {
        $user = User::factory()->create();
        $account = $this->login($user);
        $this->fakePages();

        $this->actingAs($user)->getJson("/api/meta/accounts/{$account->id}/pages")->assertOk()
            ->assertJsonPath('pages.0', ['id' => '111', 'name' => 'Toko Rina', 'picture_url' => 'https://cdn.test/111.jpg', 'can_publish' => true, 'connected' => false, 'instagram' => null])
            ->assertJsonPath('pages.1.can_publish', false)
            ->assertJsonMissingPath('pages.0.access_token');

        // No content rights on that Page
        $this->actingAs($user)->putJson("/api/meta/accounts/{$account->id}/pages", ['page_ids' => ['222']])
            ->assertUnprocessable()->assertJsonPath('message', 'Kamu tidak punya izin membuat konten di Page Komunitas.');
        // Not one of this login's Pages
        $this->actingAs($user)->putJson("/api/meta/accounts/{$account->id}/pages", ['page_ids' => ['999']])->assertUnprocessable();

        $this->actingAs($user)->putJson("/api/meta/accounts/{$account->id}/pages", ['page_ids' => ['111']])->assertOk()->assertJson(['connected' => 1]);
        $page = MetaTarget::sole();
        $this->assertSame(['facebook', '111', 'Toko Rina', 'page-111'], [$page->platform, $page->external_id, $page->name, $page->access_token]);
        $this->assertSame('page-111', $page->token());

        $this->actingAs($user)->getJson('/api/facebook/pages')->assertOk()
            ->assertJson(['configured' => true])->assertJsonPath('accounts.0.name', 'Toko Rina')
            ->assertJsonMissingPath('accounts.0.access_token');

        // Unticking it removes it
        $this->actingAs($user)->putJson("/api/meta/accounts/{$account->id}/pages", ['page_ids' => []])->assertOk()->assertJson(['connected' => 0]);
        $this->assertSame(0, MetaTarget::count());

        // Someone else's login
        $this->actingAs(User::factory()->create())->getJson("/api/meta/accounts/{$account->id}/pages")->assertNotFound();
    }

    public function test_an_expired_login_says_to_reconnect(): void
    {
        $user = User::factory()->create();
        $account = $this->login($user);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463]], 400)]);

        $this->actingAs($user)->getJson("/api/meta/accounts/{$account->id}/pages")->assertStatus(502)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Hubungkan ulang Facebook'));
    }

    public function test_disconnect_a_page_or_the_whole_login(): void
    {
        $user = User::factory()->create();
        $account = $this->login($user);
        $a = MetaTarget::create(['user_id' => $user->id, 'meta_account_id' => $account->id, 'platform' => 'facebook', 'external_id' => '1', 'name' => 'A', 'access_token' => 't']);
        MetaTarget::create(['user_id' => $user->id, 'meta_account_id' => $account->id, 'platform' => 'facebook', 'external_id' => '2', 'name' => 'B', 'access_token' => 't']);

        $this->actingAs(User::factory()->create())->deleteJson("/api/meta/targets/{$a->id}")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/meta/targets/{$a->id}")->assertOk();
        $this->assertSame(['B'], MetaTarget::pluck('name')->all());

        $this->actingAs($user)->deleteJson("/api/meta/accounts/{$account->id}")->assertOk();
        $this->assertSame([0, 0], [MetaAccount::count(), MetaTarget::count()]);
    }

    public function test_settings_page_renders_and_deleting_the_user_drops_the_tokens(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $user->forceFill(['approved_at' => now()])->save();
        $account = $this->login($user);
        MetaTarget::create(['user_id' => $user->id, 'meta_account_id' => $account->id, 'platform' => 'facebook', 'external_id' => '1', 'access_token' => 't']);

        $this->actingAs($user)->get('/settings/meta')->assertOk()->assertSee('Akun Meta');

        $user->delete();
        $this->assertSame([0, 0], [MetaAccount::count(), MetaTarget::count()]);
    }
}
