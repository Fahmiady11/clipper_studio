<?php

namespace Tests\Feature;

use App\Models\DataDeletionRequest;
use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Models\User;
use App\Support\SignedRequest;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MetaDataDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.facebook.app_secret' => 'fb-test-secret',
            'services.threads.app_secret' => 'th-test-secret',
        ]);
    }

    private function login(User $user, string $provider, string $platformUserId): MetaAccount
    {
        $account = MetaAccount::create([
            'user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $platformUserId, 'name' => 'Rani', 'access_token' => 'token',
        ]);
        foreach ($provider === 'threads' ? ['threads'] : ['facebook', 'instagram'] as $platform) {
            MetaTarget::create([
                'user_id' => $user->id, 'meta_account_id' => $account->id, 'platform' => $platform,
                'external_id' => "{$platform}-{$platformUserId}", 'name' => 'Target', 'access_token' => 'page-token',
            ]);
        }

        return $account;
    }

    private function signed(string $userId, string $secret = 'fb-test-secret'): string
    {
        return SignedRequest::make(['user_id' => $userId, 'issued_at' => time()], $secret);
    }

    public function test_facebook_deletion_request_removes_that_login_everywhere_and_returns_a_status_link(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->login($first, 'facebook', '1001');
        $this->login($second, 'facebook', '1001'); // the same person connected by two Clipper users
        $kept = $this->login($first, 'facebook', '2002');
        $threads = $this->login($first, 'threads', '1001'); // a Threads id may collide; it is another provider

        $res = $this->post('/meta/data-deletion', ['signed_request' => $this->signed('1001')])
            ->assertOk()->assertJsonStructure(['url', 'confirmation_code']);

        $code = $res->json('confirmation_code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{16}$/', $code);
        $this->assertSame(route('data-deletion.status', $code), $res->json('url'));
        $this->assertSame(0, MetaAccount::where('provider', 'facebook')->where('provider_user_id', '1001')->count());
        $this->assertSame(0, MetaTarget::where('external_id', 'facebook-1001')->count());
        $this->assertNotNull($kept->fresh());
        $this->assertNotNull($threads->fresh());
        $this->assertSame(2, User::count()); // the Clipper accounts themselves stay

        $deletion = DataDeletionRequest::sole();
        $this->assertSame(['facebook', 'completed', 2], [$deletion->provider, $deletion->status, $deletion->connections_removed]);

        $this->get($res->json('url'))->assertOk()->assertSee($code)->assertSee('Completed')->assertSee('Facebook Page');
    }

    public function test_a_request_for_someone_we_hold_nothing_about_still_gets_a_code(): void
    {
        $res = $this->post('/meta/data-deletion', ['signed_request' => $this->signed('999')])->assertOk();

        $this->get($res->json('url'))->assertOk()->assertSee('nothing to delete');
    }

    public function test_forged_or_malformed_requests_are_rejected_and_delete_nothing(): void
    {
        $this->login(User::factory()->create(), 'facebook', '1001');
        $forged = $this->signed('1001', 'not-the-secret');
        [$sig] = explode('.', $this->signed('1001'));
        $swapped = $sig.'.'.explode('.', $this->signed('2002'))[1];
        $wrongAlgo = SignedRequest::make(['user_id' => '1001', 'algorithm' => 'HMAC-SHA1'], 'fb-test-secret');

        foreach ([$forged, $swapped, $wrongAlgo, 'garbage', ''] as $bad) {
            $this->post('/meta/data-deletion', ['signed_request' => $bad])->assertStatus(400);
            $this->post('/meta/deauthorize', ['signed_request' => $bad])->assertStatus(400);
        }
        $this->post('/meta/data-deletion')->assertStatus(400);

        $this->assertSame(1, MetaAccount::count());
        $this->assertSame(0, DataDeletionRequest::count());
    }

    public function test_no_app_secret_configured_means_nothing_is_trusted(): void
    {
        $this->login(User::factory()->create(), 'facebook', '1001');
        $request = $this->signed('1001');
        config(['services.facebook.app_secret' => null]);

        $this->post('/meta/data-deletion', ['signed_request' => $request])->assertStatus(400);

        $this->assertSame(1, MetaAccount::count());
    }

    public function test_facebook_deauthorize_removes_the_login(): void
    {
        $this->login(User::factory()->create(), 'facebook', '1001');

        $this->post('/meta/deauthorize', ['signed_request' => $this->signed('1001')])->assertOk();

        $this->assertSame(0, MetaAccount::count());
        $this->assertSame(0, MetaTarget::count());
        $this->assertSame(0, DataDeletionRequest::count());
    }

    public function test_signed_request_in_the_query_string_is_accepted(): void
    {
        $this->login(User::factory()->create(), 'facebook', '1001');

        $this->post('/meta/deauthorize?signed_request='.urlencode($this->signed('1001')))->assertOk();

        $this->assertSame(0, MetaAccount::count());
    }

    public function test_threads_callbacks_use_the_threads_secret_and_only_touch_threads_logins(): void
    {
        $user = User::factory()->create();
        $facebook = $this->login($user, 'facebook', '5005');
        $this->login($user, 'threads', '5005');

        // Signed with the Facebook secret: not a Threads request
        $this->post('/threads/delete', ['signed_request' => $this->signed('5005')])->assertStatus(400);
        $this->assertSame(2, MetaAccount::count());

        $res = $this->post('/threads/delete', ['signed_request' => $this->signed('5005', 'th-test-secret')])
            ->assertOk()->assertJsonStructure(['url', 'confirmation_code']);

        $this->assertSame(0, MetaAccount::where('provider', 'threads')->count());
        $this->assertSame(0, MetaTarget::where('platform', 'threads')->count());
        $this->assertNotNull($facebook->fresh());
        $this->get($res->json('url'))->assertOk()->assertSee('Threads');
    }

    public function test_threads_uninstall_removes_the_login(): void
    {
        $this->login(User::factory()->create(), 'threads', '7007');

        $this->post('/threads/uninstall', ['signed_request' => $this->signed('7007', 'th-test-secret')])->assertOk();

        $this->assertSame(0, MetaAccount::count());
    }

    public function test_status_page_for_an_unknown_code_is_not_found(): void
    {
        $this->get('/data-deletion/ABCDEFGH12345678')->assertNotFound();
    }

    public function test_callbacks_skip_csrf_because_meta_posts_them(): void
    {
        foreach (['meta.data-deletion', 'meta.deauthorize', 'threads.data-deletion', 'threads.uninstall'] as $name) {
            $this->assertContains(ValidateCsrfToken::class, Route::getRoutes()->getByName($name)->excludedMiddleware(), $name);
        }
    }

    public function test_signed_request_round_trip_and_padding(): void
    {
        $payload = ['user_id' => '42', 'issued_at' => 1700000000, 'name' => str_repeat('x', 7)];
        $signed = SignedRequest::make($payload, 's3cret');

        $this->assertStringNotContainsString('=', $signed);
        $this->assertSame('42', SignedRequest::parse($signed, 's3cret')['user_id']);
        $this->assertNull(SignedRequest::parse($signed, 'other'));
        $this->assertNull(SignedRequest::parse($signed, null));
    }
}
