<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\User;
use App\Services\ProjectCreator;
use App\Support\ClipSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmartCutSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_options_are_stored_on_the_project_and_read_back(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $creator = app(ProjectCreator::class);

        $default = $creator->create($user->id, 'https://youtu.be/abcdefghijk', ClipSettings::normalize([]));
        $this->assertTrue($default->jump_cut);
        $this->assertTrue($default->auto_zoom);

        $off = $creator->create($user->id, 'https://youtu.be/abcdefghijk', ClipSettings::normalize(['edit' => ['jump_cut' => false]]));
        $this->assertFalse($off->fresh()->jump_cut);
        $this->assertSame(['jump_cut' => false, 'zoom' => true, 'progress' => true, 'title' => false], $creator->settingsFrom($off->fresh())['edit']);
    }

    public function test_studio_request_accepts_edit_options(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/projects', [
            'youtube_url' => 'https://youtu.be/abcdefghijk',
            'edit' => ['jump_cut' => false, 'zoom' => false],
        ] + ClipSettings::normalize([]))->assertSuccessful();

        $project = $user->hasMany(ClipProject::class)->first();
        $this->assertFalse($project->jump_cut);
        $this->assertFalse($project->auto_zoom);
    }
}
