<?php

namespace Tests\Feature;

use App\Models\GameplayClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GameplayCleanupOnUserDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return list<string> the files written for the clip (video and thumbnail) */
    private function clipFor(User $user, string $name): array
    {
        $video = "gameplay/{$user->id}/{$name}.mp4";
        $thumb = "gameplay/{$user->id}/{$name}.jpg";
        Storage::disk('local')->put($video, 'x');
        Storage::disk('local')->put($thumb, 'x');
        GameplayClip::create([
            'user_id' => $user->id, 'name' => "{$name}.mp4", 'path' => $video, 'thumb_path' => $thumb,
            'size_bytes' => 1, 'duration' => 10, 'width' => 160, 'height' => 90,
        ]);

        return [$video, $thumb];
    }

    public function test_deleting_a_user_removes_their_footage_and_thumbnails_but_nobody_elses(): void
    {
        $gone = User::factory()->create();
        $kept = User::factory()->create();
        $goneFiles = [...$this->clipFor($gone, 'one'), ...$this->clipFor($gone, 'two')];
        $keptFiles = $this->clipFor($kept, 'mine');

        $gone->delete();

        foreach ($goneFiles as $file) {
            Storage::disk('local')->assertMissing($file);
        }
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path("gameplay/{$gone->id}"));
        $this->assertSame(0, GameplayClip::where('user_id', $gone->id)->count());
        foreach ($keptFiles as $file) {
            Storage::disk('local')->assertExists($file);
        }
        $this->assertSame(1, GameplayClip::where('user_id', $kept->id)->count());
    }

    public function test_files_without_a_database_row_are_removed_too(): void
    {
        $user = User::factory()->create();
        Storage::disk('local')->put("gameplay/{$user->id}/orphan.mp4", 'x');

        $user->delete();

        Storage::disk('local')->assertMissing("gameplay/{$user->id}/orphan.mp4");
    }

    public function test_rejecting_a_sign_up_in_the_admin_screen_cleans_up_too(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->pending()->create();
        $files = $this->clipFor($pending, 'early');

        $this->actingAs($admin)->delete("/admin/users/{$pending->id}")->assertRedirect();

        $this->assertNull($pending->fresh());
        foreach ($files as $file) {
            Storage::disk('local')->assertMissing($file);
        }
    }
}
