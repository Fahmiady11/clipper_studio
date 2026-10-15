<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClipTrimTest extends TestCase
{
    use RefreshDatabase;

    private function clip(User $user, array $attrs = []): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefghijk', 'duration_seconds' => 600,
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        Transcript::create(['clip_project_id' => $project->id, 'language' => 'id', 'content' => [
            ['start' => 50.0, 'end' => 53.0, 'text' => 'jauh sebelum', 'words' => [['start' => 50.0, 'end' => 50.5, 'text' => 'jauh']]],
            ['start' => 99.0, 'end' => 103.0, 'text' => 'halo [tertawa] semua', 'words' => [
                ['start' => 99.0, 'end' => 99.5, 'text' => 'halo'], ['start' => 99.5, 'end' => 100.0, 'text' => '[tertawa]'], ['start' => 100.2, 'end' => 100.8, 'text' => 'semua'],
            ]],
            ['start' => 125.0, 'end' => 128.0, 'text' => 'baris tanpa timing'],
        ]]);

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 100.0, 'end_seconds' => 130.0,
            'topic' => 't', 'reason' => 'r', 'status' => 'done',
        ]);
    }

    public function test_transcript_lists_words_around_the_clip_without_sound_tags(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $res = $this->actingAs($user)->getJson("/api/clips/{$clip->id}/transcript")->assertOk();

        $this->assertSame(['halo', 'semua', 'baris tanpa timing'], array_column($res->json('words'), 'text')); // 80..150 only
        $this->assertEquals(100.0, $res->json('start_seconds'));
        $this->actingAs(User::factory()->create())->getJson("/api/clips/{$clip->id}/transcript")->assertForbidden();
    }

    public function test_new_bounds_drop_the_old_render(): void
    {
        Storage::fake();
        Storage::put('clips/old.mp4', 'x');
        $user = User::factory()->create();
        $clip = $this->clip($user, ['output_path' => 'clips/old.mp4']);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}/bounds", ['start_seconds' => 98.85, 'end_seconds' => 131.2])
            ->assertOk()->assertJson(['status' => 'pending']);

        $clip->refresh();
        $this->assertEquals([98.85, 131.2], [(float) $clip->start_seconds, (float) $clip->end_seconds]);
        $this->assertSame('pending', $clip->status);
        $this->assertNull($clip->output_path);
        Storage::assertMissing('clips/old.mp4');
    }

    public function test_bad_bounds_and_busy_clips_are_refused(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);
        $patch = fn ($s, $e) => $this->actingAs($user)->patchJson("/api/clips/{$clip->id}/bounds", ['start_seconds' => $s, 'end_seconds' => $e]);

        $patch(100, 101)->assertStatus(422);      // under 3s
        $patch(100, 90)->assertStatus(422);       // end before start
        $patch(590, 640)->assertStatus(422);      // past the video
        $clip->update(['status' => 'processing']);
        $patch(100, 120)->assertStatus(409);
        $this->actingAs(User::factory()->create())->patchJson("/api/clips/{$clip->id}/bounds", ['start_seconds' => 100, 'end_seconds' => 120])->assertForbidden();
    }
}
