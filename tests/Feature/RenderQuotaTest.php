<?php

namespace Tests\Feature;

use App\Exceptions\QuotaExceeded;
use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\Plan;
use App\Models\RenderUsage;
use App\Models\User;
use App\Services\RenderQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RenderQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function clip(User $user, int $seconds = 60): GeneratedClip
    {
        $project = ClipProject::create(['user_id' => $user->id, 'youtube_url' => 'https://youtu.be/x', 'layout_type' => 'reframe', 'status' => 'done']);

        return GeneratedClip::create(['clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => $seconds, 'topic' => 't', 'reason' => '', 'status' => 'pending']);
    }

    private function fillQuota(User $user, int $minutes): void
    {
        RenderUsage::create(['user_id' => $user->id, 'generated_clip_id' => 'old', 'period' => now()->format('Y-m'), 'status' => 'charged', 'seconds' => $minutes * 60]);
    }

    public function test_render_reserves_quota_and_dispatches(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->clip($user, 90);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/render")->assertOk();

        Queue::assertPushed(RenderClipJob::class);
        $this->assertSame(90, app(RenderQuota::class)->usedSeconds($user));
    }

    public function test_render_refused_with_clear_message_when_quota_is_gone(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->fillQuota($user, 30); // free = 30 min
        $clip = $this->clip($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/render")
            ->assertStatus(402)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Kuota render bulan ini habis') && str_contains($m, '30 dari 30'));

        Queue::assertNothingPushed();
        $this->assertSame('pending', $clip->fresh()->status);
    }

    public function test_pro_plan_has_more_room_and_other_months_do_not_count(): void
    {
        $user = User::factory()->create();
        $quota = app(RenderQuota::class);
        $this->fillQuota($user, 30);
        $this->assertSame(0, $quota->remainingSeconds($user));

        $user->forceFill(['plan_id' => Plan::where('slug', 'pro')->value('id')])->save();
        $this->assertSame(270 * 60, $quota->remainingSeconds($user->fresh()));

        RenderUsage::query()->update(['period' => now()->subMonth()->format('Y-m')]);
        $this->assertSame(300 * 60, $quota->remainingSeconds($user->fresh()));
    }

    public function test_reservations_count_before_the_render_finishes(): void
    {
        $user = User::factory()->create();
        $quota = app(RenderQuota::class);
        $this->fillQuota($user, 29);

        $quota->reserve($user, $this->clip($user, 50));
        $this->expectException(QuotaExceeded::class);
        $quota->reserve($user, $this->clip($user, 50));
    }

    public function test_settle_records_real_length_and_cost_and_release_refunds(): void
    {
        $user = User::factory()->create();
        $quota = app(RenderQuota::class);
        $clip = $this->clip($user, 60);

        $quota->reserve($user, $clip);
        $quota->settle($clip->id, 45, 36.0, 0.01);
        $usage = RenderUsage::where('generated_clip_id', $clip->id)->first();
        $this->assertSame('charged', $usage->status);
        $this->assertSame(45, $usage->seconds);
        $this->assertEqualsWithDelta(0.0105, (float) $usage->cost_usd, 0.0001);

        $other = $this->clip($user, 60);
        $quota->reserve($user, $other);
        $quota->release($other->id);
        $this->assertSame(45, $quota->usedSeconds($user));
    }

    public function test_admin_is_not_limited(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->fillQuota($admin, 30);
        $clip = $this->clip($admin);

        $this->actingAs($admin)->postJson("/api/clips/{$clip->id}/render")->assertOk();
    }

    public function test_pricing_page_shows_usage_and_admin_can_change_plan(): void
    {
        $user = User::factory()->create();
        $this->fillQuota($user, 12);

        $this->actingAs($user)->get('/pricing')->assertOk()->assertSee('12')->assertSee('300');

        $pro = Plan::where('slug', 'pro')->first();
        $this->actingAs($user)->post("/admin/users/{$user->id}/plan", ['plan_id' => $pro->id])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post("/admin/users/{$user->id}/plan", ['plan_id' => $pro->id])->assertRedirect();
        $this->assertSame($pro->id, $user->fresh()->plan_id);
        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }
}
