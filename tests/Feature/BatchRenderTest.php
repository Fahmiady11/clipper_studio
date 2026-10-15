<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BatchRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_clip_can_be_queued_and_the_project_status_reports_each(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefghijk',
            'layout_type' => 'reframe', 'clip_count' => 3, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $mk = fn ($rank, $status, $err = null) => GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => $rank, 'start_seconds' => 10 * $rank, 'end_seconds' => 10 * $rank + 30,
            'topic' => 't', 'reason' => 'r', 'status' => $status, 'error_msg' => $err,
        ]);
        $a = $mk(1, 'pending');
        $b = $mk(2, 'failed', 'ffmpeg exploded');
        $mk(3, 'done', 'stale message');

        foreach ([$a, $b] as $clip) {
            $this->actingAs($user)->postJson("/api/clips/{$clip->id}/render")->assertOk()->assertJson(['status' => 'processing']);
        }
        Queue::assertPushed(RenderClipJob::class, 2);

        $b->fresh()->update(['status' => 'failed', 'error_msg' => 'ffmpeg exploded']); // its render failed
        $clips = collect($this->actingAs($user)->getJson("/api/projects/{$project->id}/status")->assertOk()->json('clips'))->keyBy('ranking');
        $this->assertSame('processing', $clips[1]['status']);
        $this->assertSame('ffmpeg exploded', $clips[2]['error_msg']);
        $this->assertNull($clips[3]['error_msg']); // only failed clips carry one
    }
}
