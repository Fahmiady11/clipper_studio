<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_signed_in_user_is_sent_from_login_and_register_to_studio(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/login')->assertRedirect('/');
        $this->get('/register')->assertRedirect('/');
    }

    public function test_login_ignores_intended_api_url(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);

        // A <video> pointing at /api bounced to login and was remembered as "intended"
        $this->withSession(['url.intended' => url('/api/clips/abc/download')])
            ->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_keeps_intended_page(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);

        $this->withSession(['url.intended' => url('/autopilot')])
            ->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertRedirect(url('/autopilot'));
    }

    public function test_login_is_throttled_after_five_wrong_passwords(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => 'Email atau password salah.']);
        }

        // Even the right password is refused during the cool-down
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('email'));
    }

    public function test_logout_signs_out_and_says_so(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Kamu sudah keluar.');

        $this->assertGuest();
        $this->get('/autopilot')->assertRedirect(route('login'));
    }

    public function test_logout_without_session_still_lands_on_login(): void
    {
        $this->post('/logout')->assertRedirect(route('login'));
    }

    public function test_studio_pages_are_not_cached_by_the_browser(): void
    {
        $this->actingAs(User::factory()->create());

        $cache = $this->get('/autopilot')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_expired_csrf_token_redirects_with_message_instead_of_page_expired(): void
    {
        Route::post('/_expired-form', fn () => abort(419))->middleware('web');

        $this->from('/login')->post('/_expired-form', ['email' => 'a@b.c', 'password' => 'x'])
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Sesi kedaluwarsa. Silakan coba lagi.')
            ->assertSessionHasInput('email', 'a@b.c')
            ->assertSessionMissing('_old_input.password');

        $this->postJson('/_expired-form')
            ->assertStatus(419)
            ->assertJson(['message' => 'Sesi kedaluwarsa. Muat ulang halaman.']);
    }

    public function test_api_returns_401_json_when_session_is_gone(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
    }
}
