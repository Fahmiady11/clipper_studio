<?php

namespace Tests\Feature;

use App\Models\HookSetting;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\ProjectCreator;
use App\Support\ClipSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutopilotSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Queue::fake();
        $this->user = User::factory()->create();
    }

    private function source(array $settings = []): VideoSource
    {
        return VideoSource::create([
            'user_id' => $this->user->id, 'type' => 'channel', 'value' => '@chan', 'settings' => $settings,
        ]);
    }

    public function test_new_source_uses_the_look_chosen_in_the_form(): void
    {
        $this->actingAs($this->user)->postJson('/api/autopilot/sources', [
            'type' => 'channel', 'value' => '@chan',
            'settings' => [
                'layout_type' => 'gaussian', 'clip_count' => 5,
                'hook' => ['background_style' => 'none', 'text_color' => '#facc15', 'hook_text' => 'Teks tetap', 'is_ai_generated' => false],
                'subtitle' => ['font_family' => 'Oswald'],
            ],
        ])->assertCreated();

        $s = VideoSource::first()->settings;
        $this->assertSame('gaussian', $s['layout_type']);
        $this->assertSame(5, $s['clip_count']);
        $this->assertSame('none', $s['hook']['background_style']);
        $this->assertSame('Oswald', $s['subtitle']['font_family']);
        // A fixed hook would be wrong for every new video: autopilot hooks are AI-written
        $this->assertTrue($s['hook']['is_ai_generated']);
        $this->assertNull($s['hook']['hook_text']);
        $this->assertSame(42, $s['subtitle']['font_size'], 'missing keys get defaults');
    }

    public function test_new_source_without_look_copies_latest_manual_project_but_keeps_ai_hook(): void
    {
        app(ProjectCreator::class)->create($this->user->id, 'https://youtu.be/abcdefg', [
            'layout_type' => 'gaussian', 'clip_count' => 1,
            'hook' => ['hook_text' => 'Hook manual', 'is_ai_generated' => false, 'background_style' => 'semi'],
        ]);

        $this->actingAs($this->user)->getJson('/api/autopilot/defaults')
            ->assertOk()
            ->assertJsonPath('layout_type', 'gaussian')
            ->assertJsonPath('hook.background_style', 'semi')
            ->assertJsonPath('hook.is_ai_generated', true)
            ->assertJsonPath('hook.hook_text', null);

        $this->actingAs($this->user)->postJson('/api/autopilot/sources', ['type' => 'keyword', 'value' => 'podcast'])->assertCreated();
        $this->assertSame('gaussian', VideoSource::first()->settings['layout_type']);
    }

    public function test_update_merges_partial_look_into_existing(): void
    {
        $source = $this->source(ClipSettings::forAutopilot(['layout_type' => 'gaussian', 'clip_count' => 5]));

        $this->actingAs($this->user)->patchJson("/api/autopilot/sources/{$source->id}", [
            'settings' => ['hook' => ['background_style' => 'semi']],
        ])->assertOk();

        $s = $source->fresh()->settings;
        $this->assertSame('semi', $s['hook']['background_style']);
        $this->assertSame('gaussian', $s['layout_type'], 'untouched keys stay');
        $this->assertSame(5, $s['clip_count']);

        $this->actingAs($this->user)->getJson('/api/autopilot/sources')
            ->assertJsonPath('0.settings.hook.background_style', 'semi');
    }

    public function test_invalid_look_is_rejected(): void
    {
        $source = $this->source();

        $this->actingAs($this->user)->patchJson("/api/autopilot/sources/{$source->id}", [
            'settings' => ['hook' => ['background_style' => 'neon'], 'clip_count' => 4],
        ])->assertUnprocessable()->assertJsonValidationErrors(['settings.hook.background_style', 'settings.clip_count']);
    }

    public function test_discovered_video_project_uses_source_look_with_ai_hook(): void
    {
        // Older sources may still hold a fixed hook text copied from a manual project
        $source = $this->source(['layout_type' => 'gaussian', 'hook' => ['hook_text' => 'Lama', 'is_ai_generated' => false, 'background_style' => 'none']]);
        $project = app(ProjectCreator::class)->create($this->user->id, 'https://youtu.be/abcdefg', ClipSettings::forAutopilot($source->settings), $source->id);

        $this->assertSame('gaussian', $project->layout_type);
        $hook = HookSetting::where('clip_project_id', $project->id)->first();
        $this->assertTrue($hook->is_ai_generated);
        $this->assertNull($hook->hook_text);
        $this->assertSame('none', $hook->background_style);
    }
}
