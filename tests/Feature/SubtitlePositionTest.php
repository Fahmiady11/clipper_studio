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
        $lines = array_filter(explode("\n", $ass), fn ($l) => str_starts_with($l, 'Dialogue:'));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringContainsString('{\an5\pos(540,576)}', $line); // 30% of 1920
        }
    }

    public function test_preset_position_has_no_override_and_out_of_range_is_clamped(): void
    {
        $this->assertStringNotContainsString('\pos(', $this->ass(null));
        $this->assertStringContainsString('\pos(540,1824)', $this->ass(99)); // clamped to 95%
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
