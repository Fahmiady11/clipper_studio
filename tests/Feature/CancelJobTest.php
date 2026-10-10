<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use App\Services\FFmpegService;
use App\Services\GeminiService;
use App\Services\HookRenderer;
use App\Services\MusicService;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CancelJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = User::factory()->create();
    }

    private function project(array $attrs = []): ClipProject
    {
        return ClipProject::create($attrs + [
            'user_id' => $this->user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'processing',
        ]);
    }

    private function clip(ClipProject $project, array $attrs = []): GeneratedClip
    {
        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 'T', 'reason' => 'R', 'viral_potential' => 'tinggi', 'status' => 'processing',
        ]);
    }

    public function test_cancel_analysis_marks_project_cancelled(): void
    {
        $project = $this->project();

        $this->actingAs($this->user)->postJson("/api/projects/{$project->id}/cancel")
            ->assertOk()->assertJson(['status' => 'failed']);

        $project->refresh();
        $this->assertNotNull($project->cancelled_at);
        $this->assertSame('Dibatalkan oleh pengguna.', $project->error_msg);

        $this->actingAs($this->user)->getJson("/api/projects/{$project->id}/status")
            ->assertJson(['cancelled' => true]);
    }

    public function test_cannot_cancel_finished_or_foreign_project(): void
    {
        $done = $this->project(['status' => 'done']);
        $this->actingAs($this->user)->postJson("/api/projects/{$done->id}/cancel")->assertStatus(409);

        $foreign = $this->project(['user_id' => User::factory()->create()->id]);
        $this->actingAs($this->user)->postJson("/api/projects/{$foreign->id}/cancel")->assertNotFound();
    }

    public function test_analyze_job_skips_a_project_cancelled_while_queued(): void
    {
        $project = $this->project(['cancelled_at' => now(), 'status' => 'failed']);

        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldNotReceive('download');

        (new AnalyzeVideoJob($project->id))->handle(
            $ytdlp, $this->mock(TranscriptService::class), $this->mock(GeminiService::class)
        );

        $this->assertSame('failed', $project->fresh()->status);
    }

    public function test_analyze_job_stops_at_next_stage_after_cancel(): void
    {
        $project = $this->project();

        // The download "takes a while"; the user cancels during it
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldReceive('download')->once()->andReturnUsing(function ($url, $path) use ($project) {
            file_put_contents($path, 'partial');
            $project->update(['cancelled_at' => now(), 'status' => 'failed']);
        });
        $transcript = $this->mock(TranscriptService::class);
        $transcript->shouldNotReceive('fetch');

        (new AnalyzeVideoJob($project->id))->handle($ytdlp, $transcript, $this->mock(GeminiService::class));

        $this->assertSame('failed', $project->fresh()->status);
        $this->assertFileDoesNotExist(storage_path("app/temp/{$project->id}/video.mp4"));
        @rmdir(storage_path("app/temp/{$project->id}"));
    }

    public function test_failed_analysis_can_be_retried_on_the_same_project(): void
    {
        Queue::fake();
        $project = $this->project(['status' => 'failed', 'error_msg' => 'Gemini analisa gagal', 'progress_stage' => 4]);

        $this->actingAs($this->user)->postJson("/api/projects/{$project->id}/retry")->assertOk()->assertJson(['status' => 'processing']);

        $project->refresh();
        $this->assertSame('processing', $project->status);
        $this->assertNull($project->error_msg);
        $this->assertSame(0, (int) $project->progress_stage);
        Queue::assertPushed(AnalyzeVideoJob::class, fn ($job) => $job->projectId === $project->id);

        // Only failed projects can be retried
        $this->actingAs($this->user)->postJson("/api/projects/{$project->id}/retry")->assertStatus(409);
    }

    public function test_cancel_render_returns_clip_to_pending(): void
    {
        $clip = $this->clip($this->project(['status' => 'done']));

        $this->actingAs($this->user)->postJson("/api/clips/{$clip->id}/cancel")
            ->assertOk()->assertJson(['status' => 'pending']);

        $this->assertNotNull($clip->fresh()->cancelled_at);

        // Not rendering any more → nothing to cancel
        $this->actingAs($this->user)->postJson("/api/clips/{$clip->id}/cancel")->assertStatus(409);
    }

    public function test_cancelled_render_job_does_not_run(): void
    {
        $clip = $this->clip($this->project(['status' => 'done']), ['status' => 'pending', 'cancelled_at' => now()]);

        $ffmpeg = $this->mock(FFmpegService::class);
        $ffmpeg->shouldNotReceive('applyLayout');

        (new RenderClipJob($clip->id))->handle(
            $ffmpeg, $this->mock(MusicService::class), $this->mock(YtDlpService::class), $this->mock(HookRenderer::class)
        );

        $this->assertSame('pending', $clip->fresh()->status);
    }

    public function test_render_again_with_edited_hook(): void
    {
        Queue::fake();
        $clip = $this->clip($this->project(['status' => 'done']), [
            'status' => 'done', 'output_path' => 'clips/x.mp4', 'cancelled_at' => now(),
        ]);

        // Without hook_text a done clip is just reported back
        $this->actingAs($this->user)->postJson("/api/clips/{$clip->id}/render")->assertJson(['status' => 'done']);
        Queue::assertNothingPushed();

        $this->actingAs($this->user)->postJson("/api/clips/{$clip->id}/render", ['hook_text' => '  Hook baru!  '])
            ->assertJson(['status' => 'processing']);

        $clip->refresh();
        $this->assertSame('Hook baru!', $clip->hook_text_override);
        $this->assertNull($clip->cancelled_at);
        Queue::assertPushed(RenderClipJob::class, fn ($job) => $job->clipId === $clip->id);

        // While it renders, another edit is refused
        $this->actingAs($this->user)->postJson("/api/clips/{$clip->id}/render", ['hook_text' => 'x'])->assertStatus(409);
    }
}
