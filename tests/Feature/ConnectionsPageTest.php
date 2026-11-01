<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\YoutubeAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesInstagramFixtures;
use Tests\TestCase;

class ConnectionsPageTest extends TestCase
{
    use MakesInstagramFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    private function tiktok(User $user, string $name = 'tiktokku'): TiktokAccount
    {
        return TiktokAccount::create(['user_id' => $user->id, 'open_id' => 'o'.$user->id.$name, 'display_name' => $name, 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/settings/connections')->assertRedirect('/login');
    }

    public function test_every_platform_is_listed_with_its_connected_accounts(): void
    {
        $user = User::factory()->create();
        $this->configureInstagram();
        config(['services.tiktok.client_key' => 'k', 'services.tiktok.client_secret' => 's', 'services.tiktok.redirect_uri' => 'https://example.test/tiktok/callback']);
        $this->tiktok($user);
        $this->igAccount($user);
        YoutubeAccount::create(['user_id' => $user->id, 'channel_id' => 'UC1', 'title' => 'Podcast Klip', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour()]);

        $this->actingAs($user)->get('/settings/connections')->assertOk()
            ->assertSeeInOrder(['TikTok', 'tiktokku', 'Instagram Reels', '@brandku', 'YouTube Shorts', 'Podcast Klip'])
            ->assertSee(route('tiktok.connect'), false)
            ->assertSee(route('instagram.connect'), false);
    }

    public function test_a_platform_without_credentials_says_so_and_offers_no_dead_connect_button(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/connections')->assertOk()
            ->assertSee('Kredensial Instagram Reels belum diisi admin')
            ->assertDontSee(route('instagram.connect'), false)
            ->assertDontSee(route('tiktok.connect'), false);
    }

    public function test_an_empty_configured_platform_tells_the_user_what_to_do(): void
    {
        $this->configureInstagram();

        $this->actingAs(User::factory()->create())->get('/settings/connections')->assertOk()
            ->assertSee('Belum ada akun Instagram Reels. Hubungkan satu')
            ->assertSee('Hubungkan akun')
            ->assertSee('Business atau Creator');
    }

    public function test_it_shows_only_the_signed_in_users_accounts(): void
    {
        $this->configureInstagram();
        $other = User::factory()->create();
        $this->igAccount($other, ['display_name' => '@orang_lain']);
        $this->tiktok($other, 'tiktok_orang');

        $this->actingAs(User::factory()->create())->get('/settings/connections')->assertOk()
            ->assertDontSee('@orang_lain')->assertDontSee('tiktok_orang');
    }

    public function test_flash_messages_from_a_finished_connect_are_shown(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['status' => 'Instagram @brandku terhubung.'])->get('/settings/connections')
            ->assertSee('Instagram @brandku terhubung.');
        $this->actingAs($user)->withSession(['error' => 'Izin Instagram dibatalkan.'])->get('/settings/connections')
            ->assertSee('Izin Instagram dibatalkan.');
    }

    public function test_youtube_is_managed_on_its_own_page_because_disconnecting_withdraws_verification(): void
    {
        $user = User::factory()->create();
        $channel = YoutubeAccount::create(['user_id' => $user->id, 'channel_id' => 'UC1', 'title' => 'Podcast Klip', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour()]);

        $this->actingAs($user)->get('/settings/connections')
            ->assertSee(route('youtube.settings'), false)
            ->assertDontSee("/settings/connections/youtube/{$channel->id}", false);

        $this->actingAs($user)->delete("/settings/connections/youtube/{$channel->id}")->assertNotFound();
        $this->assertModelExists($channel);
    }

    public function test_disconnecting_removes_the_account_and_says_so(): void
    {
        $user = User::factory()->create();
        $tiktok = $this->tiktok($user);
        $instagram = $this->igAccount($user);

        $this->actingAs($user)->delete("/settings/connections/tiktok/{$tiktok->id}")
            ->assertRedirect(route('connections.index'))->assertSessionHas('status', 'Akun TikTok diputuskan.');
        $this->actingAs($user)->delete("/settings/connections/instagram/{$instagram->id}")
            ->assertRedirect(route('connections.index'))->assertSessionHas('status', 'Akun Instagram Reels diputuskan.');

        $this->assertModelMissing($tiktok);
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_nobody_can_disconnect_someone_elses_account(): void
    {
        $theirs = $this->igAccount(User::factory()->create());

        $this->actingAs(User::factory()->create())->delete("/settings/connections/instagram/{$theirs->id}")->assertNotFound();

        $this->assertModelExists($theirs);
    }

    public function test_an_unknown_platform_in_the_url_is_a_404(): void
    {
        $this->actingAs(User::factory()->create())->delete('/settings/connections/myspace/1')->assertNotFound();
    }

    public function test_the_account_menu_links_to_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/music')->assertOk()
            ->assertSee(route('connections.index'), false)->assertSee('Akun terhubung');
    }
}
