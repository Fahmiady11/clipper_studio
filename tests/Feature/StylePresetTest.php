<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BrandKit;
use App\Models\ClipProject;
use App\Models\StylePreset;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\DiscoveryService;
use App\Services\YtDlpService;
use App\Support\ClipSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StylePresetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function look(array $over = []): array
    {
        return array_replace_recursive(StylePreset::normalize([]), [
            'layout_type' => 'gaussian',
            'edit' => ['zoom' => false, 'sfx' => true],
            'subtitle' => ['font_family' => 'Oswald', 'font_size' => 64, 'style' => 'pop', 'emoji' => false],
            'hook' => ['position' => 'top', 'background_style' => 'none'],
            'music' => ['enabled' => true, 'mood' => 'chill', 'volume' => 30],
        ], $over);
    }

    private function own(User $user, string $name = 'Podcast mingguan', array $over = []): StylePreset
    {
        return StylePreset::create(['user_id' => $user->id, 'name' => $name, 'settings' => $this->look($over)]);
    }

    private function builtin(string $slug): StylePreset
    {
        return StylePreset::where('slug', $slug)->firstOrFail();
    }

    public function test_built_ins_come_first_and_others_presets_stay_hidden(): void
    {
        $user = User::factory()->create();
        $mine = $this->own($user);
        $this->own(User::factory()->create(), 'Punya orang lain');

        $res = $this->actingAs($user)->getJson('/api/presets')->assertOk();

        $this->assertSame(['Podcast bersih', 'Energik', 'Minimalis', 'Brand kit', 'Podcast mingguan'], array_column($res->json('presets'), 'name'));
        $this->assertSame([true, true, true, true, false], array_column($res->json('presets'), 'builtin'));
        $this->assertNull($res->json('default_id'));
        $this->assertSame('Oswald', $res->json('presets.4.settings.subtitle.font_family'));
        $this->assertTrue($res->json('presets.4.settings.hook.is_ai_generated'));
        $this->assertContains('Efek suara', $res->json('presets.4.summary'));
        $this->assertSame($mine->id, $res->json('presets.4.id'));
    }

    public function test_saving_keeps_only_the_look(): void
    {
        $user = User::factory()->create();
        $settings = $this->look() + ['clip_count' => 5, 'instruction' => 'x'];
        $settings['hook']['hook_text'] = 'Teks tetap';

        $this->actingAs($user)->postJson('/api/presets', ['name' => ' Gaya saya ', 'settings' => $settings])
            ->assertCreated()->assertJsonPath('preset.name', 'Gaya saya')->assertJsonPath('preset.builtin', false);

        $stored = StylePreset::where('user_id', $user->id)->firstOrFail()->settings;
        $this->assertSame('gaussian', $stored['layout_type']);
        $this->assertSame(64, $stored['subtitle']['font_size']);
        $this->assertArrayNotHasKey('clip_count', $stored);
        $this->assertArrayNotHasKey('instruction', $stored);
        $this->assertArrayNotHasKey('hook_text', $stored['hook']);

        // Same name twice, a bad value, no name
        $this->actingAs($user)->postJson('/api/presets', ['name' => 'Gaya saya', 'settings' => $settings])->assertJsonValidationErrors('name');
        $this->actingAs($user)->postJson('/api/presets', ['name' => 'Lain', 'settings' => ['subtitle' => ['style' => 'disco']]])->assertJsonValidationErrors('settings.subtitle.style');
        $this->actingAs($user)->postJson('/api/presets', ['settings' => $settings])->assertJsonValidationErrors('name');
    }

    public function test_preset_count_is_capped(): void
    {
        $user = User::factory()->create();
        foreach (range(1, StylePreset::MAX_PER_USER) as $i) {
            $this->own($user, "P{$i}");
        }

        $this->actingAs($user)->postJson('/api/presets', ['name' => 'Satu lagi', 'settings' => $this->look()])->assertUnprocessable();
    }

    public function test_rename_and_delete_only_own_presets(): void
    {
        $user = User::factory()->create();
        $mine = $this->own($user);
        $theirs = $this->own(User::factory()->create(), 'Orang lain');
        $builtin = $this->builtin('energik');

        $this->actingAs($user)->patchJson("/api/presets/{$mine->id}", ['name' => 'Baru'])->assertOk()->assertJsonPath('preset.name', 'Baru');
        $this->actingAs($user)->patchJson("/api/presets/{$builtin->id}", ['name' => 'Punyaku'])->assertForbidden();
        $this->actingAs($user)->patchJson("/api/presets/{$theirs->id}", ['name' => 'Punyaku'])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/presets/{$builtin->id}")->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/presets/{$theirs->id}")->assertNotFound();

        $this->actingAs($user)->deleteJson("/api/presets/{$mine->id}")->assertOk();
        $this->assertModelMissing($mine);
        $this->assertSame('Energik', $builtin->fresh()->name);
    }

    public function test_default_can_be_a_built_in_or_own_and_goes_away_with_the_preset(): void
    {
        $user = User::factory()->create();
        $mine = $this->own($user);
        $theirs = $this->own(User::factory()->create(), 'Orang lain');

        $this->actingAs($user)->putJson('/api/presets/default', ['preset_id' => $this->builtin('minimalis')->id])->assertOk();
        $this->assertSame($this->builtin('minimalis')->id, $user->fresh()->defaultStylePreset()->id);

        $this->actingAs($user)->putJson('/api/presets/default', ['preset_id' => $theirs->id])->assertJsonValidationErrors('preset_id');
        $this->actingAs($user)->putJson('/api/presets/default', ['preset_id' => $mine->id])->assertOk()->assertJsonPath('default_id', $mine->id);

        $this->actingAs($user)->deleteJson("/api/presets/{$mine->id}")->assertOk();
        $this->assertNull($user->fresh()->default_style_preset_id);

        $this->actingAs($user)->putJson('/api/presets/default', ['preset_id' => null])->assertOk()->assertJsonPath('default_id', null);
    }

    public function test_brand_preset_takes_the_kits_font_and_colours(): void
    {
        $user = User::factory()->create();
        BrandKit::create(['user_id' => $user->id, 'font_family' => 'Roboto', 'primary_color' => '#ff0000', 'secondary_color' => '#00ff00', 'hook_style' => 'full']);

        $look = $this->builtin('brand-kit')->look($user->brandKit);

        $this->assertSame(['Roboto', '#ff0000', '#00ff00'], [$look['subtitle']['font_family'], $look['subtitle']['highlight_color'], $look['subtitle']['text_color']]);
        $this->assertSame(['#00ff00', 'full'], [$look['hook']['text_color'], $look['hook']['background_style']]);
        $this->assertTrue($look['watermark']);
        // Without the brand flag the kit changes nothing
        $this->assertSame('Bebas Neue', $this->builtin('energik')->look($user->brandKit)['subtitle']['font_family']);
    }

    public function test_studio_request_with_preset_id_gets_the_look_and_own_values_win(): void
    {
        $user = User::factory()->create();
        $preset = $this->own($user);

        $this->actingAs($user)->postJson('/api/projects', [
            'youtube_url' => 'https://youtu.be/abcdefghijk', 'clip_count' => 1, 'duration_mode' => 'auto', 'layout_type' => 'reframe',
            'preset_id' => $preset->id, 'subtitle' => ['font_size' => 40],
        ])->assertCreated();

        $project = ClipProject::with(['subtitleSetting', 'hookSetting'])->firstOrFail();
        $this->assertSame('reframe', $project->layout_type); // sent explicitly
        $this->assertFalse($project->auto_zoom);
        $this->assertTrue($project->sfx);
        $this->assertSame(['Oswald', 40, 'pop'], [$project->subtitleSetting->font_family, $project->subtitleSetting->font_size, $project->subtitleSetting->style]);
        $this->assertSame(['top', 'none'], [$project->hookSetting->position, $project->hookSetting->background_style]);
        $this->assertTrue($project->hookSetting->is_ai_generated);
        $this->assertSame([true, 'chill', 30], [$project->music_enabled, $project->music_mood, $project->music_volume]);

        $other = $this->own(User::factory()->create(), 'Orang lain');
        $this->actingAs($user)->postJson('/api/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk', 'clip_count' => 1, 'duration_mode' => 'auto', 'layout_type' => 'reframe', 'preset_id' => $other->id])
            ->assertJsonValidationErrors('preset_id');
    }

    public function test_agent_projects_use_preset_id_else_the_default_preset(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        [, $token] = ApiToken::issue($user, 'test');
        $api = $this->withHeader('Authorization', 'Bearer '.$token);

        $api->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk'])->assertCreated();
        $this->assertSame('Montserrat', ClipProject::latest('created_at')->first()->subtitleSetting->font_family); // no default yet: plain defaults

        $user->forceFill(['default_style_preset_id' => $this->own($user)->id])->save();
        ClipProject::query()->delete();
        $api->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk'])->assertCreated();
        $project = ClipProject::firstOrFail();
        $this->assertSame(['gaussian', 3], [$project->layout_type, $project->clip_count]);
        $this->assertSame('Oswald', $project->subtitleSetting->font_family);

        ClipProject::query()->delete();
        $api->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk', 'preset_id' => $this->builtin('energik')->id, 'clip_count' => 1])->assertCreated();
        $project = ClipProject::firstOrFail();
        $this->assertSame(['reframe', 1, 'Bebas Neue'], [$project->layout_type, $project->clip_count, $project->subtitleSetting->font_family]);
        $this->assertSame([true, 'energetic'], [$project->sfx, $project->sfx_style]);

        $api->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk', 'preset_id' => 999999])->assertJsonValidationErrors('preset_id');
        $api->getJson('/api/v1/presets')->assertOk()->assertJsonCount(5, 'presets');
    }

    public function test_autopilot_source_follows_its_preset_until_unlinked(): void
    {
        $user = User::factory()->create();
        $preset = $this->own($user);

        $this->actingAs($user)->postJson('/api/autopilot/sources', [
            'type' => 'keyword', 'value' => 'podcast', 'style_preset_id' => $preset->id,
            'settings' => ClipSettings::normalize(['clip_count' => 5, 'instruction' => 'yang lucu']),
        ])->assertCreated();
        $source = VideoSource::firstOrFail();
        $this->actingAs($user)->getJson('/api/autopilot/sources')->assertOk()
            ->assertJsonPath('0.style_preset_id', $preset->id)->assertJsonPath('0.style_preset_name', 'Podcast mingguan')
            ->assertJsonPath('0.settings.layout_type', 'gaussian')->assertJsonPath('0.settings.clip_count', 5);

        // The preset changes: the next autopilot project takes the new look, keeps count and instruction
        $preset->update(['settings' => $this->look(['subtitle' => ['font_family' => 'Roboto']])]);
        $this->mock(YtDlpService::class)->shouldReceive('listVideos')->andReturn([['id' => 'abcdefghijk', 'title' => 'V', 'duration' => 900]]);
        $project = app(DiscoveryService::class)->run($source->fresh())[0];
        $this->assertSame(['gaussian', 5, 'yang lucu'], [$project->layout_type, $project->clip_count, $project->instruction]);
        $this->assertSame('Roboto', $project->subtitleSetting->font_family);
        $this->assertTrue($project->hookSetting->is_ai_generated);

        // Unlinked: the source keeps the look it had as its own settings
        $this->actingAs($user)->patchJson("/api/autopilot/sources/{$source->id}", ['style_preset_id' => null])->assertOk();
        $source->refresh();
        $this->assertNull($source->style_preset_id);
        $this->assertSame('Roboto', $source->settings['subtitle']['font_family']);

        $other = $this->own(User::factory()->create(), 'Orang lain');
        $this->actingAs($user)->patchJson("/api/autopilot/sources/{$source->id}", ['style_preset_id' => $other->id])->assertJsonValidationErrors('style_preset_id');
    }

    public function test_deleting_a_preset_leaves_its_sources_with_its_look(): void
    {
        $user = User::factory()->create();
        $preset = $this->own($user);
        $source = VideoSource::create(['user_id' => $user->id, 'type' => 'keyword', 'value' => 'x', 'style_preset_id' => $preset->id,
            'settings' => ClipSettings::forAutopilot(['layout_type' => 'reframe'])]);

        $this->actingAs($user)->deleteJson("/api/presets/{$preset->id}")->assertOk();

        $source->refresh();
        $this->assertNull($source->style_preset_id);
        $this->assertSame(['gaussian', 'Oswald'], [$source->settings['layout_type'], $source->settings['subtitle']['font_family']]);
    }

    public function test_settings_page_renders(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)->get('/settings/presets')->assertOk()->assertSee('Preset tampilan');
    }
}
