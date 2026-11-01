<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Services\GameplayFootage;
use App\Services\VideoLayoutService;
use Tests\TestCase;

class GameplayLayoutTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/clipper-gameplay-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function ffmpegAvailable(): bool
    {
        exec(escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')).' -version 2>&1', $o, $code);

        return $code === 0;
    }

    private function color(string $file, string $color, int $w, int $h, int $seconds, bool $audio = false): void
    {
        $bin = escapeshellarg(config('services.ffmpeg.path', 'ffmpeg'));
        $a = $audio ? '-f lavfi -i sine=f=300:d='.$seconds.' -c:a aac' : '-an';
        exec(sprintf('%s -v error -y -f lavfi -i color=c=%s:s=%dx%d:r=30:d=%d %s -c:v libx264 -pix_fmt yuv420p %s 2>&1', $bin, $color, $w, $h, $seconds, $a, escapeshellarg($file)), $o, $code);
        $this->assertSame(0, $code, implode("\n", $o));
    }

    public function test_footage_folder_lists_only_video_files(): void
    {
        touch($this->dir.'/a.mp4');
        touch($this->dir.'/b.MOV');
        touch($this->dir.'/notes.txt');
        touch($this->dir.'/.hidden.mp4');
        mkdir($this->dir.'/sub.mp4');

        $footage = new GameplayFootage($this->dir);

        $this->assertSame([$this->dir.'/a.mp4', $this->dir.'/b.MOV'], $footage->files());
        $this->assertTrue($footage->available());
    }

    public function test_empty_or_missing_folder_means_the_layout_is_unavailable(): void
    {
        $this->assertFalse((new GameplayFootage($this->dir))->available());
        $this->assertFalse((new GameplayFootage($this->dir.'/nope'))->available());
        $this->assertNull((new GameplayFootage($this->dir))->pick(30.0));
    }

    public function test_pick_chooses_among_the_clips_and_leaves_room_for_the_clip_length(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }
        $this->color($this->dir.'/a.mp4', 'red', 160, 90, 20);
        $this->color($this->dir.'/b.mp4', 'blue', 160, 90, 20);

        $footage = new GameplayFootage($this->dir);
        $seen = [];
        $starts = [];
        for ($i = 0; $i < 30; $i++) {
            $pick = $footage->pick(8.0);
            $seen[basename($pick['path'])] = true;
            $starts[] = $pick['start'];
            $this->assertLessThanOrEqual(12.0, $pick['start']); // 20s of footage, 8s needed
        }

        $this->assertCount(2, $seen);
        $this->assertGreaterThan(1, count(array_unique($starts)), 'the offset should vary');
    }

    public function test_footage_shorter_than_the_clip_still_gets_an_offset_inside_it(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }
        $this->color($this->dir.'/short.mp4', 'red', 160, 90, 3);

        $pick = (new GameplayFootage($this->dir))->pick(40.0);

        $this->assertGreaterThanOrEqual(0.0, $pick['start']);
        $this->assertLessThanOrEqual(3.0, $pick['start']);
    }

    public function test_top_half_is_a_wide_face_following_crop_and_bottom_fills_the_other_half(): void
    {
        $layout = new VideoLayoutService;

        $top = $layout->layoutFilter('split_gameplay', '[lay]', [[0.0, 5.0, 0.3, 0.7]]);
        $this->assertStringContainsString("crop=w='min(iw,ih*9/8)'", $top);
        $this->assertStringContainsString($layout->trackExpression([[0.0, 5.0, 0.3, 0.7]]), $top);
        $this->assertStringContainsString('scale=1080:960', $top);

        $bottom = $layout->gameplayFilter(1, 30.0, '[gp]');
        $this->assertSame('[1:v]scale=1080:960:force_original_aspect_ratio=increase:flags=bicubic,crop=1080:960,fps=30,setsar=1,format=yuv420p[gp]', $bottom);

        $this->assertSame('[a][b]vstack=inputs=2:shortest=1[out]', $layout->stackSplit('[a]', '[b]', '[out]'));
    }

    public function test_split_render_puts_the_speaker_on_top_and_looping_muted_footage_below(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }
        // Speaker: 6s of green with sound. Footage: only 2s of red with a sound track, so it must loop
        $this->color($this->dir.'/src.mp4', 'green', 640, 360, 6, true);
        $this->color($this->dir.'/game.mp4', 'red', 360, 640, 2, true);

        $out = $this->dir.'/out.mp4';
        (new FFmpegService)->applyLayout($this->dir.'/src.mp4', 'gameplay', $out, 0.0, 6.0, null, null, [], [
            'gameplay' => ['path' => $this->dir.'/game.mp4', 'start' => 0.5],
        ]);

        $probe = fn ($args) => trim((string) shell_exec(sprintf('ffprobe -v error %s -of csv=p=0 %s', $args, escapeshellarg($out))));
        $this->assertSame('1080,1920', $probe('-select_streams v -show_entries stream=width,height'));
        $this->assertEqualsWithDelta(6.0, (float) $probe('-show_entries format=duration'), 0.2);
        $this->assertSame('1', (string) count(array_filter(explode("\n", $probe('-select_streams a -show_entries stream=index')))), 'one audio track: the speaker');

        // y=480 is in the top half (green), y=1440 in the bottom half (red); the loop keeps it red at 5s
        $px = fn ($t, $y) => array_values(unpack('C3', (string) shell_exec(sprintf(
            'ffmpeg -v error -ss %s -i %s -frames:v 1 -vf crop=16:16:532:%d,scale=1:1 -f rawvideo -pix_fmt rgb24 -', $t, escapeshellarg($out), $y))));
        foreach ([1.0, 5.0] as $t) {
            [$r, $g, $b] = $px($t, 480);
            $this->assertGreaterThan($r + 60, $g, "top half is the speaker at {$t}s");
            [$r, $g, $b] = $px($t, 1440);
            $this->assertGreaterThan($g + 100, $r, "bottom half is the footage at {$t}s");
        }
    }

    public function test_split_render_zooms_only_the_speaker_half(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }
        $this->color($this->dir.'/src.mp4', 'green', 640, 360, 4, true);
        $this->color($this->dir.'/game.mp4', 'red', 360, 640, 4);

        $out = $this->dir.'/out.mp4';
        (new FFmpegService)->applyLayout($this->dir.'/src.mp4', 'gameplay', $out, 0.0, 4.0, null, [[0.0, 2.0], [2.5, 4.5]], [[1.0, 3.0]], [
            'gameplay' => ['path' => $this->dir.'/game.mp4', 'start' => 0.0],
        ]);

        $this->assertFileExists($out);
        $size = trim((string) shell_exec(sprintf('ffprobe -v error -select_streams v -show_entries stream=width,height -of csv=p=0 %s', escapeshellarg($out))));
        $this->assertSame('1080,1920', $size);
    }

    public function test_layout_without_footage_falls_back_to_a_plain_reframe(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }
        $this->color($this->dir.'/src.mp4', 'green', 640, 360, 2, true);

        $out = $this->dir.'/out.mp4';
        (new FFmpegService)->applyLayout($this->dir.'/src.mp4', 'gameplay', $out, 0.0, 2.0);

        $px = array_values(unpack('C3', (string) shell_exec(sprintf(
            'ffmpeg -v error -ss 0.5 -i %s -frames:v 1 -vf crop=16:16:532:1440,scale=1:1 -f rawvideo -pix_fmt rgb24 -', escapeshellarg($out)))));
        $this->assertGreaterThan($px[0] + 60, $px[1], 'the bottom half is the speaker too, not black');
    }
}
