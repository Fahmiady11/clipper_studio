<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\User;
use App\Support\ClipSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GameplayLayoutSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/clipper-gameplay-feature-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir);
        config(['services.gameplay.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function create(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->postJson('/api/projects', $overrides + [
            'youtube_url' => 'https://youtu.be/abcdefghijk',
            'layout_type' => 'gameplay',
        ] + ClipSettings::normalize([]));
    }

    public function test_layout_is_refused_while_the_footage_folder_is_empty(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->create($user)->assertUnprocessable()->assertJsonValidationErrors('layout_type');

        $this->assertSame(0, ClipProject::count());
    }

    public function test_layout_is_accepted_once_footage_exists_and_is_stored_on_the_project(): void
    {
        Queue::fake();
        touch($this->dir.'/parkour.mp4');
        $user = User::factory()->create();

        $this->create($user)->assertSuccessful();

        $this->assertSame('gameplay', ClipProject::first()->layout_type);
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

    public function test_studio_page_tells_the_ui_whether_footage_is_ready(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertSee('GAMEPLAY_READY = false', false);

        touch($this->dir.'/parkour.mp4');
        $this->actingAs($user)->get('/')->assertSee('GAMEPLAY_READY = true', false);
    }
}
