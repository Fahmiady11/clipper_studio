<?php

namespace Tests\Feature;

use App\Models\SubtitleSetting;
use App\Models\User;
use App\Services\FFmpegService;
use App\Services\ProjectCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubtitlePositionTest extends TestCase
{
    use RefreshDatabase;

    private function ass(?float $y): string
    {
        return app(FFmpegService::class)->buildAssFile(
            [['start' => 0, 'end' => 2, 'text' => 'halo semua apa kabar']],
            ['position' => 'bottom', 'position_y' => $y, 'background_style' => 'semi'],
        );
    }

    public function test_dragged_position_centres_every_line_at_that_height(): void
    {
        $ass = $this->ass(30);
        $lines = array_filter(explode("\n", $ass), fn ($l) => str_starts_with($l, 'Dialogue: 1,')); // text, not box

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringContainsString('{\an5\pos(540,576)}', $line); // 30% of 1920
        }
    }

    public function test_preset_position_centres_lines_in_their_box_and_out_of_range_is_clamped(): void
    {
        // bottom preset: 200px margin, line centred half a 42px line above it,
        // the same centre as its rounded box (y 1664 + 70/2)
        $ass = $this->ass(null);
        $this->assertStringContainsString('\pos(355,1664)', $ass);
        $this->assertMatchesRegularExpression('/^Dialogue: 1,.*\{\\\\an5\\\\pos\(540,1699\)\}/m', $ass);
        $this->assertStringContainsString('\pos(540,1824)', $this->ass(99)); // clamped to 95%
    }

    public function test_background_is_a_rounded_box_drawn_behind_the_text(): void
    {
        $ass = $this->ass(30);

        $this->assertStringContainsString('BorderStyle', $ass);
        $this->assertStringNotContainsString(',0,0,3,', $ass); // no square BorderStyle=3 box
        $this->assertMatchesRegularExpression('/^Dialogue: 0,.*\\\\p1\}m \d+ 0 l .* b /m', $ass);

        $none = app(FFmpegService::class)->buildAssFile(
            [['start' => 0, 'end' => 2, 'text' => 'halo']], ['background_style' => 'none'],
        );
        $this->assertStringNotContainsString('\p1}', $none);
    }

    public function test_project_stores_position_and_rejects_off_screen_values(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $base = [
            'youtube_url' => 'https://youtu.be/abcdefghijk', 'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto',
        ];

        $this->actingAs($user)->postJson('/api/projects', $base + ['subtitle' => ['position_y' => 140]])
            ->assertUnprocessable()->assertJsonValidationErrors('subtitle.position_y');

        $this->actingAs($user)->postJson('/api/projects', $base + ['subtitle' => ['position_y' => 62.5]])->assertCreated();
        $this->assertSame(62.5, SubtitleSetting::first()->position_y);

        // Autopilot templates copy it along with the rest of the look
        $settings = app(ProjectCreator::class)->settingsFrom(SubtitleSetting::first()->clipProject);
        $this->assertSame(62.5, $settings['subtitle']['position_y']);
    }
}
