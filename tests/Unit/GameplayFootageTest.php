<?php

namespace Tests\Unit;

use App\Models\GameplayClip;
use App\Models\User;
use App\Services\FFmpegService;
use App\Services\GameplayFootage;
use App\Support\InvalidFootage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GameplayFootageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir().'/clipper-footage-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function bin(): string
    {
        return escapeshellarg(config('services.ffmpeg.path', 'ffmpeg'));
    }

    private function needFfmpeg(): void
    {
        exec($this->bin().' -version 2>&1', $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }
    }

    /** A real video of $seconds, uploaded under $name. */
    private function video(string $name = 'parkour.mp4', int $seconds = 5, string $color = 'red'): UploadedFile
    {
        $path = $this->dir.'/'.uniqid().'-'.$name;
        exec(sprintf('%s -v error -y -f lavfi -i color=c=%s:s=160x90:r=30:d=%d -c:v libx264 -pix_fmt yuv420p %s 2>&1', $this->bin(), $color, $seconds, escapeshellarg($path)), $o, $code);
        $this->assertSame(0, $code, implode("\n", $o));

        return new UploadedFile($path, $name, null, null, true);
    }

    /** A clip row whose file exists on the fake disk (no real video needed). */
    private function clip(User $user, float $duration = 20.0, string $name = 'a'): GameplayClip
    {
        Storage::disk('local')->put("gameplay/{$user->id}/{$name}.mp4", 'x');

        return GameplayClip::create([
            'user_id' => $user->id, 'name' => "{$name}.mp4", 'path' => "gameplay/{$user->id}/{$name}.mp4",
            'size_bytes' => 1, 'duration' => $duration, 'width' => 160, 'height' => 90,
        ]);
    }

    public function test_a_user_only_ever_gets_their_own_footage(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $this->clip($other, 30.0, 'theirs');

        $this->assertFalse(GameplayFootage::for($mine->id)->available());
        $this->assertNull(GameplayFootage::for($mine->id)->pick(10.0));

        $own = $this->clip($mine, 30.0, 'mine');
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($own->absolutePath(), GameplayFootage::for($mine->id)->pick(10.0)['path']);
        }
        $this->assertSame(1, GameplayFootage::for($other->id)->clips()->count());
    }

    public function test_nobody_signed_in_has_no_footage(): void
    {
        $this->clip(User::factory()->create());

        $this->assertFalse(GameplayFootage::for(null)->available());
        $this->assertNull(GameplayFootage::for(null)->pick(5.0));
    }

    public function test_a_clip_whose_file_is_gone_does_not_count(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);
        $this->assertTrue(GameplayFootage::for($user->id)->available());

        Storage::disk('local')->delete($clip->path);

        $this->assertFalse(GameplayFootage::for($user->id)->available());
        $this->assertNull(GameplayFootage::for($user->id)->pick(5.0));
    }

    public function test_pick_chooses_among_the_clips_and_leaves_room_for_the_clip_length(): void
    {
        $user = User::factory()->create();
        $this->clip($user, 20.0, 'a');
        $this->clip($user, 20.0, 'b');

        $seen = [];
        $starts = [];
        for ($i = 0; $i < 40; $i++) {
            $pick = GameplayFootage::for($user->id)->pick(8.0);
            $seen[basename($pick['path'])] = true;
            $starts[] = $pick['start'];
            $this->assertLessThanOrEqual(12.0, $pick['start']); // 20s of footage, 8s needed
        }

        $this->assertCount(2, $seen);
        $this->assertGreaterThan(1, count(array_unique($starts)), 'the offset should vary');
    }

    public function test_footage_shorter_than_the_clip_still_gets_an_offset_inside_it(): void
    {
        $user = User::factory()->create();
        $this->clip($user, 3.0);

        $pick = GameplayFootage::for($user->id)->pick(40.0);

        $this->assertGreaterThanOrEqual(0.0, $pick['start']);
        $this->assertLessThanOrEqual(3.0, $pick['start']);
    }

    public function test_a_real_video_is_stored_under_the_users_folder_with_its_facts_and_a_thumbnail(): void
    {
        $this->needFfmpeg();
        $user = User::factory()->create();

        $clip = GameplayFootage::for($user->id)->store($this->video('Parkour Run.mp4', 6), new FFmpegService);

        $this->assertSame($user->id, $clip->user_id);
        $this->assertStringStartsWith("gameplay/{$user->id}/", $clip->path);
        $this->assertSame('Parkour Run.mp4', $clip->name);
        $this->assertEqualsWithDelta(6.0, $clip->duration, 0.2);
        $this->assertSame([160, 90], [$clip->width, $clip->height]);
        $this->assertGreaterThan(0, $clip->size_bytes);
        Storage::disk('local')->assertExists($clip->path);
        $this->assertNotNull($clip->thumb_path);
        Storage::disk('local')->assertExists($clip->thumb_path);
    }

    public function test_audio_only_files_and_garbage_are_refused(): void
    {
        $this->needFfmpeg();
        $user = User::factory()->create();
        $footage = GameplayFootage::for($user->id);

        $audio = $this->dir.'/song.mp4';
        exec(sprintf('%s -v error -y -f lavfi -i sine=f=440:d=6 -c:a aac -f mp4 %s 2>&1', $this->bin(), escapeshellarg($audio)));
        $garbage = $this->dir.'/garbage.mp4';
        file_put_contents($garbage, random_bytes(4096));
        $text = $this->dir.'/notes.mp4';
        file_put_contents($text, "ini bukan video\n");

        foreach ([$audio, $garbage, $text] as $path) {
            try {
                $footage->store(new UploadedFile($path, basename($path), null, null, true), new FFmpegService);
                $this->fail(basename($path).' should have been refused');
            } catch (InvalidFootage $e) {
                $this->assertStringContainsString('bukan video', $e->getMessage());
            }
        }
        $this->assertSame(0, GameplayClip::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_still_image_or_cover_art_is_not_a_video(): void
    {
        $this->needFfmpeg();
        $user = User::factory()->create();

        $jpg = $this->dir.'/still.mp4';
        exec(sprintf('%s -v error -y -f lavfi -i color=c=blue:s=160x90 -frames:v 1 -f image2 -c:v mjpeg %s 2>&1', $this->bin(), escapeshellarg($jpg)));
        $cover = $this->dir.'/cover.mp4';
        exec(sprintf('%s -v error -y -f lavfi -i sine=f=440:d=6 -f lavfi -i color=c=blue:s=160x90:d=1 -map 0 -map 1 -c:a aac -c:v mjpeg -disposition:v attached_pic -f mp4 %s 2>&1', $this->bin(), escapeshellarg($cover)));

        foreach ([$jpg, $cover] as $path) {
            $this->expectedRefusal(fn () => GameplayFootage::for($user->id)->store(new UploadedFile($path, 'x.mp4', null, null, true), new FFmpegService));
        }
    }

    public function test_a_clip_shorter_than_three_seconds_is_refused(): void
    {
        $this->needFfmpeg();
        $user = User::factory()->create();

        try {
            GameplayFootage::for($user->id)->store($this->video('short.mp4', 2), new FFmpegService);
            $this->fail('too short');
        } catch (InvalidFootage $e) {
            $this->assertStringContainsString('minimal 3 detik', $e->getMessage());
        }
    }

    public function test_the_eleventh_file_is_refused_and_each_user_has_their_own_allowance(): void
    {
        $this->needFfmpeg();
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach (range(1, GameplayFootage::MAX_FILES) as $i) {
            $this->clip($user, 10.0, "c{$i}");
        }

        $this->assertSame(0, GameplayFootage::for($user->id)->remaining());
        try {
            GameplayFootage::for($user->id)->store($this->video(), new FFmpegService);
            $this->fail('over the limit');
        } catch (InvalidFootage $e) {
            $this->assertStringContainsString('Batas '.GameplayFootage::MAX_FILES, $e->getMessage());
        }

        $this->assertSame(GameplayFootage::MAX_FILES, GameplayFootage::for($other->id)->remaining());
        $this->assertSame($other->id, GameplayFootage::for($other->id)->store($this->video(), new FFmpegService)->user_id);
    }

    public function test_deleting_a_clip_removes_its_files(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);
        Storage::disk('local')->put("gameplay/{$user->id}/a.jpg", 'x');
        $clip->update(['thumb_path' => "gameplay/{$user->id}/a.jpg"]);

        $clip->delete();

        Storage::disk('local')->assertMissing("gameplay/{$user->id}/a.mp4");
        Storage::disk('local')->assertMissing("gameplay/{$user->id}/a.jpg");
    }

    public function test_probe_video_reads_size_and_length_and_ignores_non_video(): void
    {
        $this->needFfmpeg();
        $ffmpeg = new FFmpegService;
        $video = $this->video('v.mp4', 4);

        $info = $ffmpeg->probeVideo($video->getRealPath());

        $this->assertSame(160, $info['width']);
        $this->assertEqualsWithDelta(4.0, $info['duration'], 0.2);
        $this->assertNull($ffmpeg->probeVideo($this->dir.'/missing.mp4'));
    }

    private function expectedRefusal(callable $store): void
    {
        try {
            $store();
            $this->fail('should have been refused');
        } catch (InvalidFootage) {
            $this->addToAssertionCount(1);
        }
    }
}
