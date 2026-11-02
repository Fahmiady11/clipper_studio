<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ClipProject;
use App\Models\StylePreset;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\ProjectCreator;
use App\Support\ClipSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SfxSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sfx_is_off_unless_asked_for_and_survives_a_settings_round_trip(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $creator = app(ProjectCreator::class);

        $default = $creator->create($user->id, 'https://youtu.be/abcdefghijk', ClipSettings::normalize([]));
        $this->assertFalse($default->fresh()->sfx);

        $on = $creator->create($user->id, 'https://youtu.be/abcdefghijk', ClipSettings::normalize(['edit' => ['sfx' => true]]));
        $this->assertTrue($on->fresh()->sfx);
        $this->assertTrue($creator->settingsFrom($on->fresh())['edit']['sfx']);
    }

    public function test_migration_adds_the_column_defaulting_to_off_for_existing_rows(): void
    {
        $this->assertTrue(Schema::hasColumn('clip_projects', 'sfx'));

        $user = User::factory()->create();
        $row = ClipProject::create(['user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefghijk', 'status' => 'done']);

        $this->assertFalse($row->fresh()->sfx);
    }

    public function test_studio_request_accepts_the_sfx_toggle(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/projects', [
            'youtube_url' => 'https://youtu.be/abcdefghijk',
            'edit' => ['sfx' => true],
        ] + ClipSettings::normalize([]))->assertSuccessful();

        $this->assertTrue($user->hasMany(ClipProject::class)->first()->sfx);
    }

    public function test_sfx_must_be_a_boolean(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/projects', [
            'youtube_url' => 'https://youtu.be/abcdefghijk',
            'edit' => ['sfx' => 'banyak'],
        ] + ClipSettings::normalize([]))->assertUnprocessable()->assertJsonValidationErrors('edit.sfx');
    }

    public function test_sfx_style_is_halus_unless_asked_and_survives_a_settings_round_trip(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $creator = app(ProjectCreator::class);

        $default = $creator->create($user->id, 'https://youtu.be/abcdefghijk', ClipSettings::normalize(['edit' => ['sfx' => true]]));
        $this->assertSame('subtle', $default->fresh()->sfx_style);

        $energetic = $creator->create($user->id, 'https://youtu.be/abcdefghijk', ClipSettings::normalize(['edit' => ['sfx' => true, 'sfx_style' => 'energetic']]));
        $this->assertSame('energetic', $energetic->fresh()->sfx_style);
        $this->assertSame('energetic', $creator->settingsFrom($energetic->fresh())['edit']['sfx_style']);

        // Rows from before the column: Halus
        $old = ClipProject::create(['user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefghijk', 'status' => 'done']);
        $this->assertSame('subtle', $old->fresh()->sfx_style);
    }

    public function test_studio_and_agent_requests_take_the_style_and_reject_others(): void
    {
        Queue::fake();
        $user = User::factory()->create(['approved_at' => now()]);
        $body = fn (array $edit) => ['youtube_url' => 'https://youtu.be/abcdefghijk', 'edit' => $edit] + ClipSettings::normalize([]);

        $this->actingAs($user)->postJson('/api/projects', $body(['sfx' => true, 'sfx_style' => 'energetic']))->assertSuccessful();
        $this->assertSame('energetic', ClipProject::firstOrFail()->sfx_style);

        $this->actingAs($user)->postJson('/api/projects', $body(['sfx' => true, 'sfx_style' => 'keras']))
            ->assertUnprocessable()->assertJsonValidationErrors('edit.sfx_style');

        [, $token] = ApiToken::issue($user, 'test');
        $api = $this->withHeader('Authorization', 'Bearer '.$token);
        ClipProject::query()->delete();
        $api->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk', 'edit' => ['sfx' => true, 'sfx_style' => 'energetic']])->assertCreated();
        $this->assertSame('energetic', ClipProject::firstOrFail()->sfx_style);

        $tools = collect($api->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->keyBy('name');
        $this->assertSame(['subtle', 'energetic'], $tools['create_project']['inputSchema']['properties']['edit']['properties']['sfx_style']['enum']);
    }

    public function test_only_the_energik_built_in_sounds_energetic(): void
    {
        $styles = StylePreset::whereNull('user_id')->orderBy('id')->get()->mapWithKeys(fn ($p) => [$p->slug => StylePreset::normalize($p->settings)['edit']['sfx_style']]);

        $this->assertSame(['podcast-bersih' => 'subtle', 'energik' => 'energetic', 'minimalis' => 'subtle', 'brand-kit' => 'subtle'], $styles->all());
        $this->assertContains('Efek suara energik', StylePreset::where('slug', 'energik')->first()->summary());
    }

    public function test_autopilot_source_settings_keep_the_style(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/autopilot/sources', [
            'type' => 'keyword', 'value' => 'podcast',
            'settings' => ClipSettings::normalize(['edit' => ['sfx' => true, 'sfx_style' => 'energetic']]),
        ])->assertCreated();

        $this->assertSame('energetic', VideoSource::firstOrFail()->settings['edit']['sfx_style']);
    }
}
