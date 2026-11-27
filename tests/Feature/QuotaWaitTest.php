<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\DiscoveredVideo;
use App\Models\GeneratedClip;
use App\Models\Plan;
use App\Models\RenderUsage;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\DiscoveryService;
use App\Services\GeminiService;
use App\Services\RenderQuota;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class QuotaWaitTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTA_MSG = 'Kuota render bulan ini habis (30 dari 30 menit terpakai). Upgrade paket di halaman Paket & kuota, atau tunggu reset tanggal 1.';

    private function source(User $user): VideoSource
    {
        return VideoSource::create([
            'user_id' => $user->id, 'type' => 'channel', 'value' => '@chan',
            'max_per_run' => 5, 'min_video_seconds' => 300, 'max_video_seconds' => 7200,
            'settings' => ['layout_type' => 'reframe', 'clip_count' => 1],
        ]);
    }

    private function project(User $user, array $attrs = []): ClipProject
    {
        return ClipProject::create($attrs + [
            'user_id' => $user->id, 'video_source_id' => $this->source($user)->id, 'youtube_url' => 'https://youtu.be/x',
            'layout_type' => 'reframe', 'status' => 'done',
        ]);
    }

    /** An autopilot clip refused for quota at $blockedAt */
    private function waitingClip(User $user, int $seconds = 60, string $blockedAt = '-1 hour', array $attrs = [], ?ClipProject $project = null): GeneratedClip
    {
        return GeneratedClip::create($attrs + [
            'clip_project_id' => ($project ?? $this->project($user))->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => $seconds,
            'topic' => 't', 'reason' => '', 'status' => 'pending', 'review_status' => 'pending',
            'error_msg' => self::QUOTA_MSG, 'quota_blocked_at' => now()->modify($blockedAt),
        ]);
    }

    private function useMinutes(User $user, int $minutes, ?string $period = null): void
    {
        RenderUsage::create(['user_id' => $user->id, 'generated_clip_id' => 'old', 'period' => $period ?? now()->format('Y-m'), 'status' => 'charged', 'seconds' => $minutes * 60]);
    }

    private function dispatchedClipIds(): array
    {
        return Queue::pushed(RenderClipJob::class)->map(fn ($job) => $job->clipId)->values()->all();
    }

    public function test_autopilot_clip_over_quota_waits_instead_of_spinning(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->useMinutes($user, 30); // free = 30 min
        $project = $this->project($user, ['clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'processing']);

        $this->mock(YtDlpService::class)->shouldReceive('getMetadata')
            ->andReturn(['title' => 'V', 'duration_seconds' => 1800, 'thumbnail_url' => null, 'channel' => 'C', 'language' => 'id']);
        $this->mock(TranscriptService::class)->shouldReceive('fetch')
            ->andReturn(['language' => 'id', 'segments' => [['start' => 0, 'end' => 5, 'text' => 'halo']]]);
        $this->mock(GeminiService::class)->shouldReceive('analyzeWithTranscript')->andReturn([
            ['ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30, 'topic' => 'T', 'reason' => 'R'],
        ]);

        $this->app->call([new AnalyzeVideoJob($project->id), 'handle']);
        exec('rm -rf '.escapeshellarg(storage_path('app/temp/'.$project->id)));

        $clip = GeneratedClip::where('clip_project_id', $project->id)->firstOrFail();
        $this->assertSame('pending', $clip->status);
        $this->assertNotNull($clip->quota_blocked_at);
        $this->assertStringContainsString('Kuota render bulan ini habis', $clip->error_msg);
        Queue::assertNotPushed(RenderClipJob::class);

        // The review card gets the waiting state and the message, not "rendering"
        $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()
            ->assertJsonPath('data.0.waiting_quota', true)
            ->assertJsonPath('data.0.error_msg', $clip->error_msg);

        // "Hapus semua" may clear it: no job holds its row
        $this->actingAs($user)->postJson('/api/autopilot/review/clean-pending')->assertOk()->assertJsonPath('removed', 1);
    }

    public function test_plan_upgrade_resumes_oldest_first_and_stops_when_quota_runs_out(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->useMinutes($user, 295); // pro = 300 min: 300 s left after the upgrade
        $newest = $this->waitingClip($user, 120, '-1 hour');
        $oldest = $this->waitingClip($user, 120, '-3 hours');
        $middle = $this->waitingClip($user, 120, '-2 hours');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post("/admin/users/{$user->id}/plan", ['plan_id' => Plan::where('slug', 'pro')->value('id')])->assertRedirect();

        $this->assertSame([$oldest->id, $middle->id], $this->dispatchedClipIds());
        Queue::assertPushedOn('render', RenderClipJob::class);
        foreach ([$oldest, $middle] as $clip) {
            $clip->refresh();
            $this->assertNull($clip->quota_blocked_at);
            $this->assertNull($clip->error_msg);
            $this->assertSame('pending', $clip->status); // RenderClipJob sets processing when it starts
            $this->assertTrue(RenderUsage::where('generated_clip_id', $clip->id)->where('status', 'reserved')->exists());
        }
        $this->assertNotNull($newest->fresh()->quota_blocked_at);
        $this->assertFalse(RenderUsage::where('generated_clip_id', $newest->id)->exists());
    }

    public function test_released_reservation_resumes_a_waiting_clip(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->useMinutes($user, 29);
        $quota = app(RenderQuota::class);
        $rendering = $this->waitingClip($user, 60, attrs: ['quota_blocked_at' => null, 'error_msg' => null, 'status' => 'processing']);
        $quota->reserve($user, $rendering); // the last free minute
        $waiting = $this->waitingClip($user, 60);

        $this->assertSame(0, $quota->resumeWaiting($user));
        Queue::assertNotPushed(RenderClipJob::class);

        $quota->release($rendering->id); // e.g. the render failed or was cancelled

        $this->assertSame([$waiting->id], $this->dispatchedClipIds());
        $this->assertNull($waiting->fresh()->quota_blocked_at);
    }

    public function test_schedule_resumes_clips_blocked_last_month(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->useMinutes($user, 30);
        $clip = $this->waitingClip($user, 60);

        $this->artisan('clipper:resume-quota')->assertSuccessful();
        Queue::assertNotPushed(RenderClipJob::class);

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());
        $this->artisan('clipper:resume-quota')->expectsOutputToContain('Queued 1')->assertSuccessful();

        $this->assertSame([$clip->id], $this->dispatchedClipIds());
        $this->assertSame(now()->format('Y-m'), RenderUsage::where('generated_clip_id', $clip->id)->value('period'));
    }

    public function test_resume_never_dispatches_a_clip_twice(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->waitingClip($user, 60);
        $quota = app(RenderQuota::class);

        // Another trigger holds the user's lock: this one waits, then gives up instead of racing it
        Sleep::fake(syncWithCarbon: true);
        $lock = Cache::lock('render-quota-resume:'.$user->id, 120);
        $this->assertTrue($lock->get());
        $this->assertSame(0, $quota->resumeWaiting($user));
        Queue::assertNotPushed(RenderClipJob::class);
        $lock->release();

        $this->assertSame(1, $quota->resumeWaiting($user));
        $this->assertSame(0, $quota->resumeWaiting($user));
        $this->assertSame(0, $quota->resumeAll());
        $this->assertSame([$clip->id], $this->dispatchedClipIds());
    }

    public function test_rejected_and_cancelled_clips_are_not_queued(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $rejected = $this->waitingClip($user, attrs: ['review_status' => 'rejected']);
        $cancelled = $this->waitingClip($user, attrs: ['cancelled_at' => now()]);
        $projectGone = $this->waitingClip($user, project: $this->project($user, ['cancelled_at' => now()]));
        $ok = $this->waitingClip($user, blockedAt: '-1 minute');

        $this->assertSame(1, app(RenderQuota::class)->resumeWaiting($user));

        $this->assertSame([$ok->id], $this->dispatchedClipIds());
        foreach ([$rejected, $cancelled, $projectGone] as $clip) {
            $this->assertNull($clip->fresh()->quota_blocked_at); // no longer waits, so no later trigger picks it up
            $this->assertFalse(RenderUsage::where('generated_clip_id', $clip->id)->exists());
        }
    }

    public function test_admin_is_never_blocked(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->useMinutes($admin, 30);
        $clip = $this->waitingClip($admin, 600);

        $this->assertSame(1, app(RenderQuota::class)->resumeWaiting($admin));
        $this->assertSame([$clip->id], $this->dispatchedClipIds());
    }

    public function test_discovery_pauses_while_quota_is_used_up_and_resumes_after(): void
    {
        Queue::fake();
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('listVideos')->once()->andReturn([['id' => 'vidNew1aaaa', 'title' => 'Baru', 'channel' => 'C', 'duration' => 1200]]);
        $user = User::factory()->create();
        $this->useMinutes($user, 30);
        $source = $this->source($user);

        $this->assertSame([], app(DiscoveryService::class)->run($source));
        $this->assertSame(0, ClipProject::count());
        $this->assertSame(0, DiscoveredVideo::count()); // left unseen, so it is picked up later
        Queue::assertNotPushed(AnalyzeVideoJob::class);
        $this->actingAs($user)->getJson('/api/autopilot/sources')->assertOk()->assertJsonPath('0.quota_paused', true);

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());
        $started = app(DiscoveryService::class)->run($source->fresh());

        $this->assertCount(1, $started);
        $this->assertTrue(DiscoveredVideo::where('video_id', 'vidNew1aaaa')->exists());
        Queue::assertPushed(AnalyzeVideoJob::class, 1);
        $this->actingAs($user)->getJson('/api/autopilot/sources')->assertOk()->assertJsonPath('0.quota_paused', false);
    }

    public function test_discovery_keeps_running_for_admins(): void
    {
        Queue::fake();
        $this->mock(YtDlpService::class)->shouldReceive('listVideos')->andReturn([['id' => 'vidNew1aaaa', 'title' => 'Baru', 'channel' => 'C', 'duration' => 1200]]);
        $admin = User::factory()->admin()->create();
        $this->useMinutes($admin, 30);

        $this->assertCount(1, app(DiscoveryService::class)->run($this->source($admin)));
    }

    public function test_migration_marks_clips_stuck_before_the_waiting_state_existed(): void
    {
        $user = User::factory()->create();
        $stuck = $this->waitingClip($user, attrs: ['quota_blocked_at' => null]);
        $reserved = $this->waitingClip($user, attrs: ['quota_blocked_at' => null]);
        RenderUsage::create(['user_id' => $user->id, 'generated_clip_id' => $reserved->id, 'period' => now()->format('Y-m'), 'seconds' => 60]);
        $rejected = $this->waitingClip($user, attrs: ['quota_blocked_at' => null, 'review_status' => 'rejected']);
        $otherError = $this->waitingClip($user, attrs: ['quota_blocked_at' => null, 'error_msg' => 'Sumber video tidak bisa diunduh.']);

        (require database_path('migrations/2026_11_27_000002_mark_quota_stuck_clips_waiting.php'))->up();

        $this->assertEquals($stuck->created_at, $stuck->fresh()->quota_blocked_at); // keeps the order they were made in
        foreach ([$reserved, $rejected, $otherError] as $clip) {
            $this->assertNull($clip->fresh()->quota_blocked_at);
        }
    }
}
