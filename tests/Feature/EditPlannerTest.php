<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use App\Services\EditPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EditPlannerTest extends TestCase
{
    use RefreshDatabase;

    private array $segments;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => 'k',
            'services.gemini.models' => ['model-a'],
            'services.gemini.retry_pauses' => [0, 0],
            'services.llm_gateway' => ['url' => null, 'key' => null, 'model' => null],
        ]);
        $words = fn (string $text, float $at) => array_map(fn ($w, $i) => ['text' => $w, 'start' => $at + $i * 0.4, 'end' => $at + $i * 0.4 + 0.35],
            explode(' ', $text), array_keys(explode(' ', $text)));
        $this->segments = [
            ['start' => 100, 'end' => 103, 'text' => 'Pertama kali gue ke gym boxing.', 'words' => $words('Pertama kali gue ke gym boxing.', 100.0)],
            ['start' => 120, 'end' => 123, 'text' => 'Gue dipukul sampai jatuh, ngakak semua.', 'words' => $words('Gue dipukul sampai jatuh, ngakak semua.', 120.0)],
        ];
    }

    private function clip(float $start = 95.0, float $end = 130.0): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => User::factory()->create()->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);

        return GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => $start, 'end_seconds' => $end,
            'topic' => 'Boxing pertama', 'reason' => 'R', 'viral_potential' => 'tinggi', 'status' => 'pending',
        ]);
    }

    private function answer(array $data): void
    {
        Http::fake(['*model-a:*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]]])]);
    }

    public function test_the_plan_is_asked_once_stored_and_reused_until_the_clip_is_trimmed(): void
    {
        $this->answer([
            'broll' => [['phrase' => 0, 'word' => 'gym', 'query' => 'boxing gym training', 'alt' => 'boxing']],
            'emoji' => [['phrase' => 1, 'word' => 'ngakak', 'emoji' => '😂']],
            'punchlines' => [1],
        ]);
        $clip = $this->clip();
        $planner = app(EditPlanner::class);

        [$plan, $asked] = $planner->plan($clip, $this->segments, 3.0);
        $this->assertTrue($asked);
        $this->assertSame('boxing gym training', $plan['broll'][0]['query']);
        $this->assertSame('1f602', $plan['emoji'][0]['code']);
        $this->assertEquals($plan, $clip->fresh()->edit_plan); // JSON turns 120.0 into 120
        Http::assertSent(function ($r) {
            $prompt = $r['contents'][0]['parts'][0]['text'];

            return str_contains($prompt, '#0 [5.0] Pertama kali gue ke gym boxing.')
                && str_contains($prompt, '#1 [25.0] Gue dipukul')
                && str_contains($prompt, 'sebelum detik 4')
                && str_contains($prompt, '🥊');
        });

        [$again, $askedAgain] = $planner->plan($clip->fresh(), $this->segments, 3.0);
        $this->assertFalse($askedAgain);
        $this->assertEquals($plan, $again);
        Http::assertSentCount(1);

        $clip->update(['end_seconds' => 125.0]);
        [, $askedAfterTrim] = $planner->plan($clip->fresh(), $this->segments, 3.0);
        $this->assertTrue($askedAfterTrim);
        Http::assertSentCount(2);
    }

    public function test_no_ai_means_no_plan_and_nothing_stored(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $clip = $this->clip();

        $this->assertSame([null, true], app(EditPlanner::class)->plan($clip, $this->segments, 3.0));
        $this->assertNull($clip->fresh()->edit_plan);
    }

    public function test_a_clip_without_timed_words_is_not_sent_to_the_ai(): void
    {
        Http::fake();

        $this->assertSame([null, false], app(EditPlanner::class)->plan($this->clip(200.0, 230.0), $this->segments, 3.0));
        Http::assertNothingSent();
    }
}
