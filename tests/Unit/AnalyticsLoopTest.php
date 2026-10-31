<?php

namespace Tests\Unit;

use App\Models\ClipProject;
use App\Models\ClipStat;
use App\Models\GeneratedClip;
use App\Models\User;
use App\Services\ClipPerformanceSummary;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AnalyticsLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_totals_use_the_latest_snapshot_per_clip(): void
    {
        $user = User::factory()->create();
        $first = $this->clip($user, ['topic' => 'First']);
        $second = $this->clip($user, ['topic' => 'Second']);

        $this->stat($first, now()->subHours(2), 100, 10);
        $this->stat($first, now()->subHour(), 500, 50);
        $this->stat($second, now()->subHour(), 300, 25);

        $totals = app(ClipPerformanceSummary::class)->totalsForClips([$first->id, $second->id]);

        $this->assertSame(500, $totals[$first->id]['views']);
        $this->assertSame(50, $totals[$first->id]['likes']);
        $this->assertSame(300, $totals[$second->id]['views']);
    }

    public function test_user_summary_orders_by_views_and_exposes_top_clips(): void
    {
        $user = User::factory()->create();
        $best = $this->clip($user, ['topic' => 'Best']);
        $second = $this->clip($user, ['topic' => 'Second']);

        $this->stat($best, now(), 900, 80);
        $this->stat($second, now(), 400, 120);

        $summary = app(ClipPerformanceSummary::class)->forUser($user->id);

        $this->assertSame(2, $summary['clip_count']);
        $this->assertSame(1300, $summary['total_views']);
        $this->assertSame($best->id, $summary['best_clip']['id']);
        $this->assertSame($best->id, $summary['top_clips'][0]['id']);
    }

    public function test_prompt_insight_is_silent_until_enough_clips_have_stats(): void
    {
        $user = User::factory()->create();
        foreach ([7, 8, 9, 10] as $score) {
            $clip = $this->clip($user, ['viral_score' => $score]);
            $this->stat($clip, now(), $score * 100);
        }

        $this->assertNull(app(ClipPerformanceSummary::class)->promptInsight());

        $fifth = $this->clip($user, ['viral_score' => 7]);
        $this->stat($fifth, now(), 500);

        $insight = app(ClipPerformanceSummary::class)->promptInsight();

        $this->assertStringContainsString('skor 7-8 = 667 views', $insight);
        $this->assertStringContainsString('skor 9-10 = 950 views', $insight);
    }

    public function test_performance_context_is_inserted_as_optional_gemini_prompt_block(): void
    {
        $method = new ReflectionMethod(GeminiService::class, 'buildPromptFromTranscript');
        $transcript = ['language' => 'id', 'segments' => [['start' => 0, 'end' => 5, 'text' => 'halo']]];

        $withContext = $method->invoke(app(GeminiService::class), $transcript, 1, 'reframe', 'auto', 30, 90, 'skor 7-8 = 1.000 views');
        $withoutContext = $method->invoke(app(GeminiService::class), $transcript, 1, 'reframe', 'auto', 30, 90);

        $this->assertStringContainsString('== DATA PERFORMA HISTORIS (opsional) ==', $withContext);
        $this->assertStringContainsString('skor 7-8 = 1.000 views', $withContext);
        $this->assertStringNotContainsString('DATA PERFORMA HISTORIS', $withoutContext);
    }

    private function clip(User $user, array $attrs = []): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => $user->id,
            'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe',
            'clip_count' => 1,
            'duration_mode' => 'auto',
            'status' => 'done',
        ]);

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id,
            'ranking' => 1,
            'start_seconds' => 0,
            'end_seconds' => 20,
            'topic' => 'Test clip',
            'reason' => 'A useful moment',
            'viral_score' => 8,
            'status' => 'done',
            'review_status' => 'approved',
        ]);
    }

    private function stat(GeneratedClip $clip, $collectedAt, int $views, int $likes = 0): ClipStat
    {
        return ClipStat::create([
            'generated_clip_id' => $clip->id,
            'platform' => 'tiktok',
            'views' => $views,
            'likes' => $likes,
            'comments' => 0,
            'shares' => 0,
            'collected_at' => $collectedAt,
        ]);
    }
}
