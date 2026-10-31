<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Models\ClipProject;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViralScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_job_maps_and_persists_scores_then_lists_clips_by_score(): void
    {
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id,
            'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'gaussian',
            'clip_count' => 2,
            'duration_mode' => 'auto',
            'status' => 'processing',
        ]);

        $this->mock(YtDlpService::class)->shouldReceive('download')->andReturnUsing(function ($url, $path) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, 'video');
        });
        $this->mock(TranscriptService::class)->shouldReceive('fetch')
            ->andReturn(['language' => 'id', 'segments' => [['start' => 0, 'end' => 5, 'text' => 'halo']]]);
        $this->mock(GeminiService::class)->shouldReceive('analyzeWithTranscript')->andReturn([
            [
                'ranking' => 1,
                'start_seconds' => 0,
                'end_seconds' => 30,
                'topic' => 'Good hook',
                'reason' => 'Strong opening',
                'viral_score' => '7.2',
                'viral_reasons' => ['hook', 'pacing'],
                'music_mood' => 'chill',
            ],
            [
                'ranking' => 2,
                'start_seconds' => 60,
                'end_seconds' => 90,
                'topic' => 'Better value',
                'reason' => 'Actionable tip',
                'viral_score' => 11,
                'viral_reasons' => ['value'],
                'music_mood' => 'inspiring',
            ],
        ]);

        $this->app->call([new AnalyzeVideoJob($project->id), 'handle']);

        $clips = $project->generatedClips;
        $this->assertSame([2, 1], $clips->pluck('ranking')->all());
        $this->assertSame([10, 7], $clips->pluck('viral_score')->all());
        $this->assertSame([['value'], ['hook', 'flow']], $clips->pluck('viral_reasons')->all());

        $this->actingAs($user)->getJson("/api/projects/{$project->id}/status")
            ->assertOk()
            ->assertJsonPath('clips.0.viral_score', 10)
            ->assertJsonPath('clips.0.viral_reasons', ['value'])
            ->assertJsonPath('clips.1.viral_score', 7)
            ->assertJsonPath('clips.1.viral_reasons', ['hook', 'flow']);

        @unlink(storage_path('app/temp/'.$project->id.'/video.mp4'));
        @rmdir(storage_path('app/temp/'.$project->id));
    }
}
