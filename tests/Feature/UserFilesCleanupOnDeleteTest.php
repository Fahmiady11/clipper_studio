<?php

namespace Tests\Feature;

use App\Models\GameplayClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserFilesCleanupOnDeleteTest extends TestCase
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

    /** @return list<string> a logo and two rendered clips, the way BrandKitController and RenderClipJob store them */
    private function brandAndRendersFor(User $user): array
    {
        $files = [
            "brand/{$user->id}/logo-abc12345.png",
            "clips/{$user->id}/project-1/clip-a.mp4",
            "clips/{$user->id}/project-2/clip-b.mp4",
        ];
        foreach ($files as $file) {
            Storage::disk('local')->put($file, 'x');
        }

        return $files;
    }

    public function test_deleting_a_user_also_removes_their_logo_and_rendered_clips_but_nobody_elses(): void
    {
        $gone = User::factory()->create();
        $kept = User::factory()->create();
        $goneFiles = $this->brandAndRendersFor($gone);
        $keptFiles = $this->brandAndRendersFor($kept);

        $gone->delete();

        foreach ($goneFiles as $file) {
            Storage::disk('local')->assertMissing($file);
        }
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path("brand/{$gone->id}"));
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path("clips/{$gone->id}"));
        foreach ($keptFiles as $file) {
            Storage::disk('local')->assertExists($file);
        }
    }

    public function test_a_user_id_that_is_a_prefix_of_another_does_not_take_the_other_s_files(): void
    {
        $a = User::factory()->create(['id' => 7]);
        $b = User::factory()->create(['id' => 70]);
        $files = $this->brandAndRendersFor($b);
        $this->clipFor($b, 'mine');

        $a->delete();

        foreach ($files as $file) {
            Storage::disk('local')->assertExists($file);
        }
        $this->assertSame(1, GameplayClip::where('user_id', $b->id)->count());
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
        $files = [...$this->clipFor($pending, 'early'), ...$this->brandAndRendersFor($pending)];

        $this->actingAs($admin)->delete("/admin/users/{$pending->id}")->assertRedirect();

        $this->assertNull($pending->fresh());
        foreach ($files as $file) {
            Storage::disk('local')->assertMissing($file);
        }
    }
}
