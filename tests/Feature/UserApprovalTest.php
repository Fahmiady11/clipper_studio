<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email): TestResponse
    {
        return $this->post('/register', [
            'name' => 'Orang', 'email' => $email, 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
        ]);
    }

    public function test_first_account_becomes_admin_and_signs_in(): void
    {
        $this->register('admin@example.com')->assertRedirect(route('home'));

        $admin = User::first();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->isApproved());
        $this->assertAuthenticatedAs($admin);
    }

    public function test_later_sign_up_waits_for_approval_before_login(): void
    {
        User::factory()->admin()->create();
        config(['auth.registration_enabled' => true]);

        $this->register('baru@example.com')->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertGuest();
        $user = User::where('email', 'baru@example.com')->first();
        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->isApproved());

        $this->post('/login', ['email' => 'baru@example.com', 'password' => 'secret-pass'])
            ->assertSessionHasErrors(['email' => 'Akun kamu belum disetujui admin. Coba lagi setelah disetujui.']);
        $this->assertGuest();

        // Wrong password still reads as a plain failed login
        $this->post('/login', ['email' => 'baru@example.com', 'password' => 'wrong-pass'])
            ->assertSessionHasErrors(['email' => 'Email atau password salah.']);

        $user->forceFill(['approved_at' => now()])->save();
        $this->post('/login', ['email' => 'baru@example.com', 'password' => 'secret-pass'])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_approves_rejects_and_revokes(): void
    {
        $admin = User::factory()->admin()->create();
        $a = User::factory()->pending()->create();
        $b = User::factory()->pending()->create();

        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee($a->email)->assertSee($b->email);

        $this->actingAs($admin)->post("/admin/users/{$a->id}/approve")->assertRedirect();
        $this->assertTrue($a->fresh()->isApproved());

        $this->actingAs($admin)->delete("/admin/users/{$b->id}")->assertRedirect();
        $this->assertNull($b->fresh());

        // Approved accounts aren't deleted here (they may have data), only revoked
        $this->actingAs($admin)->delete("/admin/users/{$a->id}")->assertStatus(422);
        $this->actingAs($admin)->post("/admin/users/{$a->id}/revoke")->assertRedirect();
        $this->assertFalse($a->fresh()->isApproved());

        $this->actingAs($admin)->post("/admin/users/{$admin->id}/revoke")->assertStatus(422);
    }

    public function test_only_the_admin_reaches_the_admin_pages(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->pending()->create();

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->post("/admin/users/{$other->id}/approve")->assertForbidden();
        $this->assertFalse($other->fresh()->isApproved());
    }

    public function test_revoked_user_is_signed_out_of_an_open_session(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($user)->getJson('/api/projects')->assertForbidden();
    }

    public function test_admin_creates_an_account_that_can_sign_in_at_once(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/users', ['name' => 'Teman', 'email' => 'teman@example.com', 'password' => 'awal-1234'])
            ->assertRedirect()->assertSessionHas('status');

        $user = User::where('email', 'teman@example.com')->first();
        $this->assertTrue($user->isApproved());
        $this->assertFalse($user->is_admin);

        $this->actingAs($admin)->post('/admin/users', ['name' => 'Lagi', 'email' => 'teman@example.com', 'password' => 'awal-1234'])
            ->assertSessionHasErrorsIn('create', 'email');

        $this->post('/logout');
        $this->post('/login', ['email' => 'teman@example.com', 'password' => 'awal-1234'])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);

        // Not for regular users
        $this->post('/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'awal-1234'])->assertForbidden();
        $this->assertNull(User::where('email', 'x@example.com')->first());
    }
}
