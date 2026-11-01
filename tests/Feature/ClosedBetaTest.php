<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\InviteCode;
use App\Models\RenderUsage;
use App\Models\User;
use App\Notifications\JobFailed;
use App\Services\JobWatchdog;
use App\Services\LoadMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClosedBetaTest extends TestCase
{
    use RefreshDatabase;

    private function signUp(array $extra = [])
    {
        return $this->post('/register', $extra + [
            'name' => 'Orang', 'email' => 'baru@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
        ]);
    }

    public function test_sign_up_needs_a_valid_invite_and_approves_on_the_spot(): void
    {
        User::factory()->admin()->create();
        $invite = InviteCode::generate();

        $this->get('/register')->assertOk()->assertSee('Kode undangan');
        $this->signUp()->assertSessionHasErrors('invite');
        $this->signUp(['invite' => 'NOPE-0000'])->assertSessionHasErrors('invite');
        $this->assertDatabaseMissing('users', ['email' => 'baru@example.com']);

        $this->signUp(['invite' => strtolower($invite->code)])->assertRedirect(route('home'));
        $user = User::where('email', 'baru@example.com')->first();
        $this->assertTrue($user->isApproved());
        $this->assertSame($invite->id, $user->invite_code_id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_invite_is_used_up_or_expired(): void
    {
        User::factory()->admin()->create();
        $once = InviteCode::generate(1);
        $old = InviteCode::generate(5, 1);
        $old->update(['expires_at' => now()->subMinute()]);

        $this->signUp(['invite' => $once->code])->assertRedirect(route('home'));
        auth()->logout();
        $this->signUp(['invite' => $once->code, 'email' => 'dua@example.com'])->assertSessionHasErrors('invite');
        $this->signUp(['invite' => $old->code, 'email' => 'tiga@example.com'])->assertSessionHasErrors('invite');
        $this->assertSame(1, $once->fresh()->uses);
    }

    public function test_sign_up_stays_closed_without_invites_and_open_registration_needs_no_code(): void
    {
        User::factory()->admin()->create();
        $this->get('/register')->assertRedirect(route('login'));

        config(['auth.registration_enabled' => true]);
        $this->signUp()->assertRedirect(route('login')); // no code, waits for approval as before
        $this->assertFalse(User::where('email', 'baru@example.com')->first()->isApproved());
    }

    public function test_watchdog_fails_stuck_jobs_releases_quota_and_notifies(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $project = ClipProject::create(['user_id' => $user->id, 'youtube_url' => 'https://youtu.be/x', 'layout_type' => 'reframe', 'status' => 'done']);
        $stuck = GeneratedClip::create(['clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30, 'topic' => 't', 'reason' => '', 'status' => 'processing']);
        $live = GeneratedClip::create(['clip_project_id' => $project->id, 'ranking' => 2, 'start_seconds' => 0, 'end_seconds' => 30, 'topic' => 'u', 'reason' => '', 'status' => 'processing']);
        RenderUsage::create(['user_id' => $user->id, 'generated_clip_id' => $stuck->id, 'period' => now()->format('Y-m'), 'seconds' => 30]);
        GeneratedClip::whereKey($stuck->id)->toBase()->update(['updated_at' => now()->subSeconds((new RenderClipJob(''))->timeout + 600)]);

        $this->assertSame(['projects' => 0, 'clips' => 1], app(JobWatchdog::class)->run());

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('processing', $live->fresh()->status);
        $this->assertSame(0, RenderUsage::where('generated_clip_id', $stuck->id)->count());
        Notification::assertSentTo($user, JobFailed::class);
    }

    public function test_status_page_is_public_and_reports_load(): void
    {
        config(['services.clipper.queue_busy' => 1, 'services.clipper.queue_overloaded' => 3]);
        $this->assertSame('ok', app(LoadMonitor::class)->level(0.1, 0));
        $this->assertSame('busy', app(LoadMonitor::class)->level(0.1, 1));
        $this->assertSame('overloaded', app(LoadMonitor::class)->level(0.1, 3));
        $this->assertSame('overloaded', app(LoadMonitor::class)->level(9.0, 0));

        $this->get('/status')->assertOk()->assertSee('jobs waiting');
    }
}
