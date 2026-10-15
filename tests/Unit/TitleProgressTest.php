<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use Tests\TestCase;

class TitleProgressTest extends TestCase
{
    private function dialogues(string $ass): array
    {
        preg_match_all('/^Dialogue: 1,([^,]+),([^,]+),Default,,0,0,0,,(.*)$/m', $ass, $m, PREG_SET_ORDER);

        return $m;
    }

    public function test_short_title_is_one_line_held_from_the_hook_end(): void
    {
        $lines = $this->dialogues((new FFmpegService)->buildTitleAss('Pilates atau boxing?', 3.0, 45.5, 'Montserrat'));

        $this->assertCount(1, $lines);
        $this->assertSame(['0:00:03.00', '0:00:45.50'], [$lines[0][1], $lines[0][2]]);
        $this->assertStringEndsWith('Pilates atau boxing?', $lines[0][3]);
    }

    public function test_long_title_breaks_near_the_middle_into_two_lines(): void
    {
        $ass = (new FFmpegService)->buildTitleAss('Kenapa orang Indonesia susah banget nabung buat masa depan', 0, 30, 'Montserrat');
        $lines = $this->dialogues($ass);

        $this->assertCount(2, $lines);
        $this->assertSame('Kenapa orang Indonesia susah banget nabung buat masa depan', implode(' ', array_map(fn ($l) => preg_replace('/\{[^}]*\}/', '', $l[3]), $lines)));
        $this->assertSame(2, preg_match_all('/^Dialogue: 0,/m', $ass)); // a box behind each line
    }

    public function test_progress_bar_fills_across_the_top(): void
    {
        $dir = sys_get_temp_dir().'/clipper-progress-'.getmypid();
        @mkdir($dir);
        $src = $dir.'/src.mp4';
        exec(sprintf('%s -v error -y -f lavfi -i color=c=black:s=640x360:r=25:d=4 -pix_fmt yuv420p %s 2>&1',
            escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')), escapeshellarg($src)), $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $out = $dir.'/out.mp4';
        (new FFmpegService)->applyLayout($src, 'gaussian', $out, 0.0, 4.0, null, null, [], ['progress' => ['color' => '#ffffff', 'duration' => 4.0]]);

        // brightness of the top strip's left and right quarters at 1s and 3s
        $lum = fn ($t, $x) => array_sum(unpack('C*', (string) shell_exec(sprintf(
            'ffmpeg -v error -ss %s -i %s -frames:v 1 -vf crop=200:6:%d:3 -f rawvideo -pix_fmt gray -', $t, escapeshellarg($out), $x)))) / 1200;
        $this->assertGreaterThan(200, $lum('1.0', 20));   // filled at the start
        $this->assertLessThan(60, $lum('1.0', 860));      // not yet at the end
        $this->assertGreaterThan(200, $lum('3.9', 860));  // nearly full
        exec('rm -rf '.escapeshellarg($dir));
    }
}
