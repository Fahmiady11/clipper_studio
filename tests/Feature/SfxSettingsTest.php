<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\User;
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
}
