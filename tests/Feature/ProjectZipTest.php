<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectZipTest extends TestCase
{
    use RefreshDatabase;

    public function test_rendered_clips_download_as_one_zip_with_captions(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefghijk', 'video_title' => 'Podcast Seru',
            'layout_type' => 'reframe', 'clip_count' => 3, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $mk = fn ($rank, $status, $path = null, $caption = null) => GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => $rank, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => "Topik ke-{$rank}: seru!", 'reason' => 'r', 'status' => $status, 'output_path' => $path,
            'caption' => $caption, 'hashtags_json' => $caption ? ['#fyp'] : null,
        ]);
        Storage::put('clips/a.mp4', 'video-a');
        Storage::put('clips/b.mp4', 'video-b');
        $mk(1, 'done', 'clips/a.mp4', 'Caption satu');
        $mk(2, 'done', 'clips/b.mp4');
        $mk(3, 'pending');

        $res = $this->actingAs($user)->get("/api/projects/{$project->id}/download")->assertOk();
        $this->assertStringContainsString('clips-podcast-seru.zip', $res->headers->get('Content-Disposition'));

        $zip = new \ZipArchive;
        $zip->open($res->getFile()->getPathname());
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $this->assertSame(['01 - topik ke 1 seru.mp4', '02 - topik ke 2 seru.mp4', 'captions.txt'], $names);
        $this->assertSame('video-b', $zip->getFromName('02 - topik ke 2 seru.mp4'));
        $this->assertStringContainsString("Caption satu\n\n#fyp", $zip->getFromName('captions.txt'));
        $zip->close();

        $this->actingAs(User::factory()->create())->get("/api/projects/{$project->id}/download")->assertNotFound();
    }
}
