<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\GeminiService;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoryClearTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Storage::fake();
        $this->user = User::factory()->create();
    }

    private function project(array $attrs = []): ClipProject
    {
        $project = ClipProject::create($attrs + [
            'user_id' => $this->user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 'T', 'reason' => 'R', 'viral_potential' => 'tinggi', 'status' => 'done',
            'output_path' => "clips/{$project->user_id}/{$project->id}/clip.mp4",
        ]);
        Storage::put("clips/{$project->user_id}/{$project->id}/clip.mp4", 'mp4');

        return $project;
    }

    public function test_clear_all_removes_studio_projects_and_their_files(): void
    {
        $a = $this->project();
        $b = $this->project(['status' => 'processing']);
        $source  = VideoSource::create(['user_id' => $this->user->id, 'type' => 'channel', 'value' => '@chan', 'settings' => []]);
        $auto    = $this->project(['video_source_id' => $source->id]);
        $foreign = $this->project(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->user)->deleteJson('/api/projects')->assertOk()->assertJson(['deleted' => 2]);

        $this->assertModelMissing($a);
        $this->assertModelMissing($b);
        $this->assertSame(0, GeneratedClip::whereIn('clip_project_id', [$a->id, $b->id])->count());
        Storage::assertMissing("clips/{$a->user_id}/{$a->id}/clip.mp4");

        // Autopilot review clips and other users' projects are untouched
        $this->assertModelExists($auto);
        $this->assertModelExists($foreign);
        Storage::assertExists("clips/{$auto->user_id}/{$auto->id}/clip.mp4");
    }

    public function test_history_lists_only_studio_projects(): void
    {
        $studio = $this->project();
        $source = VideoSource::create(['user_id' => $this->user->id, 'type' => 'channel', 'value' => '@chan', 'settings' => []]);
        $this->project(['video_source_id' => $source->id]);

        $this->actingAs($this->user)->getJson('/api/projects')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.project_id', $studio->id);
    }

    public function test_delete_one_project_removes_its_files(): void
    {
        $project = $this->project();

        $this->actingAs($this->user)->deleteJson("/api/projects/{$project->id}")->assertOk();

        $this->assertModelMissing($project);
        Storage::assertMissing("clips/{$project->user_id}/{$project->id}/clip.mp4");
    }

    public function test_job_for_a_deleted_project_exits_quietly(): void
    {
        $ytdlp = $this->mock(YtDlpService::class);
        $ytdlp->shouldNotReceive('download');

        (new AnalyzeVideoJob('00000000-0000-0000-0000-000000000000'))->handle(
            $ytdlp, $this->mock(TranscriptService::class), $this->mock(GeminiService::class)
        );

        $this->assertTrue(true);
    }
}
