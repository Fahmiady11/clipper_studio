<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\GameplayClip;
use App\Models\User;
use App\Support\ClipSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GameplayLayoutSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function footageFor(User $user): void
    {
        Storage::disk('local')->put("gameplay/{$user->id}/a.mp4", 'x');
        GameplayClip::create([
            'user_id' => $user->id, 'name' => 'a.mp4', 'path' => "gameplay/{$user->id}/a.mp4",
            'size_bytes' => 1, 'duration' => 20, 'width' => 160, 'height' => 90,
        ]);
    }

    private function create(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->postJson('/api/projects', $overrides + [
            'youtube_url' => 'https://youtu.be/abcdefghijk',
            'layout_type' => 'gameplay',
        ] + ClipSettings::normalize([]));
    }

    public function test_layout_is_refused_while_the_user_has_no_footage(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->create($user)->assertUnprocessable()->assertJsonValidationErrors('layout_type');

        $this->assertSame(0, ClipProject::count());
    }

    public function test_layout_is_accepted_once_the_user_has_footage_and_is_stored_on_the_project(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->footageFor($user);

        $this->create($user)->assertSuccessful();

        $this->assertSame('gameplay', ClipProject::first()->layout_type);
    }

    public function test_someone_elses_footage_does_not_unlock_the_layout(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $this->footageFor($owner);
        $user = User::factory()->create();

        $this->create($user)->assertUnprocessable()->assertJsonValidationErrors('layout_type');
    }

    public function test_the_refusal_points_to_the_upload_page(): void
    {
        $user = User::factory()->create();

        $this->create($user)->assertJsonPath('errors.layout_type.0', fn ($m) => str_contains($m, 'Footage gameplay'));
    }

    public function test_other_layouts_do_not_need_footage(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->create($user, ['layout_type' => 'reframe'])->assertSuccessful();
        $this->create($user, ['layout_type' => 'gaussian'])->assertSuccessful();
    }

    public function test_unknown_layouts_are_still_rejected(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['layout_type' => 'pip'])->assertUnprocessable()->assertJsonValidationErrors('layout_type');
    }

    public function test_autopilot_settings_use_the_same_per_user_check(): void
    {
        $user = User::factory()->create();
        $payload = ['url' => 'https://www.youtube.com/@channel', 'settings' => ['layout_type' => 'gameplay']];

        $rules = ClipSettings::rules('settings.');
        $this->actingAs($user);
        $this->assertTrue(validator($payload, $rules)->errors()->has('settings.layout_type'));

        $this->footageFor($user);
        $this->assertFalse(validator($payload, $rules)->errors()->has('settings.layout_type'));
    }

    public function test_studio_page_tells_the_ui_whether_this_user_has_footage(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->footageFor($other);

        $this->actingAs($user)->get('/')->assertSee('GAMEPLAY_READY = false', false)
            ->assertSee(route('gameplay.page'), false);

        $this->footageFor($user);
        $this->actingAs($user)->get('/')->assertSee('GAMEPLAY_READY = true', false);
    }

    public function test_autopilot_page_tells_the_ui_whether_this_user_has_footage(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/autopilot')->assertSee('GAMEPLAY_READY = false', false)
            ->assertSee(route('gameplay.page'), false);

        $this->footageFor($user);
        $this->actingAs($user)->get('/autopilot')->assertSee('GAMEPLAY_READY = true', false);
    }
}
