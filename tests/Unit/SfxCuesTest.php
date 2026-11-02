<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Support\SfxCues;
use Tests\TestCase;

class SfxCuesTest extends TestCase
{
    private function sounds(array $cues): array
    {
        return array_column($cues, 'sound');
    }

    public function test_broll_cut_ins_get_a_whoosh_that_peaks_on_the_cut(): void
    {
        $cues = SfxCues::plan([10.0], [], [], 30.0);

        $this->assertSame(['whoosh'], $this->sounds($cues));
        // The whoosh swells first, so it starts before the cut-in
        $this->assertSame([10.0 - SfxCues::WHOOSH_LEAD], array_column($cues, 'at'));
    }

    public function test_emoji_get_a_pop_on_the_frame_they_appear(): void
    {
        $cues = SfxCues::plan([], [6.0], [], 30.0);

        $this->assertSame([['sound' => 'pop', 'at' => 6.0, 'gain' => SfxCues::GAIN['subtle']['pop']]], $cues);
    }

    public function test_cuts_zooms_and_plain_speech_stay_silent(): void
    {
        // Nothing seen and no punchline: no sound at all (jump cuts and zooms are not inputs any more)
        $this->assertSame([], SfxCues::plan([], [], [], 60.0, 3.0));
    }

    public function test_punchlines_get_a_ding_one_when_subtle_two_when_energetic(): void
    {
        $this->assertSame([5.0], array_column(SfxCues::plan([], [], [5.0, 15.0, 25.0], 30.0), 'at'));
        $this->assertSame([5.0, 15.0], array_column(SfxCues::plan([], [], [5.0, 15.0, 25.0], 30.0, null, 'energetic'), 'at'));
    }

    public function test_a_clip_full_of_moments_stays_within_its_budget(): void
    {
        $emoji = array_map(fn ($i) => 1.0 + $i, range(0, 50));

        foreach (['subtle' => [4, 4.0], 'energetic' => [7, 2.5]] as $style => [$max, $gap]) {
            $times = array_column(SfxCues::plan([], $emoji, [], 60.0, null, $style), 'at');
            $this->assertCount($max, $times, $style);
            foreach ($times as $i => $t) {
                if ($i > 0) {
                    $this->assertGreaterThanOrEqual($gap - 0.001, $t - $times[$i - 1], $style);
                }
            }
        }
    }

    public function test_the_broll_whoosh_wins_a_clash_with_a_pop_or_ding(): void
    {
        $cues = SfxCues::plan([5.0], [5.5], [6.0], 20.0);

        $this->assertSame(['whoosh'], $this->sounds($cues));
    }

    public function test_only_the_energetic_style_marks_the_hook_leaving(): void
    {
        $this->assertSame([], SfxCues::plan([], [], [], 20.0, 3.0));
        $this->assertSame(['whoosh'], $this->sounds(SfxCues::plan([], [], [], 20.0, 3.0, 'energetic')));
        $this->assertSame(SfxCues::GAIN['energetic']['whoosh'], SfxCues::plan([], [], [], 20.0, 3.0, 'energetic')[0]['gain']);
    }

    public function test_nothing_plays_in_the_clip_edges_and_unknown_styles_are_subtle(): void
    {
        $this->assertSame([], SfxCues::plan([0.1], [11.9], [], 12.0));
        $this->assertSame(SfxCues::GAIN['subtle']['pop'], SfxCues::plan([], [4.0], [], 12.0, null, 'loud')[0]['gain']);
    }

    public function test_bundled_effects_exist_and_are_short(): void
    {
        foreach (['whoosh', 'pop', 'ding'] as $sound) {
            $this->assertFileExists(SfxCues::path($sound));
            $this->assertLessThan(150_000, filesize(SfxCues::path($sound)), "$sound.wav should stay small");
        }
    }

    public function test_sfx_command_delays_each_effect_and_keeps_the_video_stream(): void
    {
        $cues = [
            ['sound' => 'whoosh', 'at' => 1.7, 'gain' => 0.32],
            ['sound' => 'pop', 'at' => 4.0, 'gain' => 0.4],
            ['sound' => 'whoosh', 'at' => 9.5, 'gain' => 0.32],
        ];

        $cmd = (new FFmpegService)->sfxCommand('/tmp/in.mp4', $cues, 12.0, true, '/tmp/out.mp4');

        $this->assertStringContainsString('whoosh.wav', $cmd);
        $this->assertStringContainsString('pop.wav', $cmd);
        $this->assertStringNotContainsString('ding.wav', $cmd);
        $this->assertStringContainsString('adelay=1700|1700,volume=0.320', $cmd);
        $this->assertStringContainsString('adelay=4000|4000,volume=0.400', $cmd);
        $this->assertStringContainsString('adelay=9500|9500', $cmd);
        $this->assertStringContainsString('asplit=2', $cmd); // two whooshes from one input
        $this->assertStringContainsString('amix=inputs=4:duration=first:normalize=0', $cmd);
        $this->assertStringContainsString('-map 0:v', $cmd);
        $this->assertStringContainsString('-c:v copy', $cmd);
        $this->assertStringNotContainsString('anullsrc', $cmd);
    }

    public function test_sfx_command_makes_silence_to_lay_effects_on_a_clip_without_audio(): void
    {
        $cmd = (new FFmpegService)->sfxCommand('/tmp/in.mp4', [['sound' => 'ding', 'at' => 2.0, 'gain' => 0.3]], 6.0, false, '/tmp/out.mp4');

        $this->assertStringContainsString('anullsrc', $cmd);
        $this->assertStringContainsString('[2:a]', $cmd); // the silence is input 2, after video and ding
    }

    public function test_mix_sfx_adds_the_effects_without_changing_length_or_video(): void
    {
        $bin = config('services.ffmpeg.path', 'ffmpeg');
        $dir = sys_get_temp_dir().'/clipper-sfx-'.getmypid();
        @mkdir($dir);
        exec(sprintf('%s -v error -y -f lavfi -i color=c=black:s=320x240:d=6:r=30 -f lavfi -i anullsrc=r=48000:cl=stereo -t 6 -c:v libx264 -pix_fmt yuv420p -c:a aac %s 2>&1', $bin, escapeshellarg($dir.'/in.mp4')), $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }

        (new FFmpegService)->mixSfx($dir.'/in.mp4', [
            ['sound' => 'pop', 'at' => 2.0, 'gain' => 0.4],
            ['sound' => 'ding', 'at' => 4.0, 'gain' => 0.3],
        ], $dir.'/out.mp4');

        $probe = fn ($args) => trim((string) shell_exec(sprintf('ffprobe -v error %s -of csv=p=0 %s', $args, escapeshellarg($dir.'/out.mp4'))));
        $this->assertEqualsWithDelta(6.0, (float) $probe('-show_entries format=duration'), 0.15);
        $this->assertSame('h264', $probe('-select_streams v -show_entries stream=codec_name'));
        $loud = fn ($at) => (float) preg_replace('/.*max_volume: (-?[\d.]+) dB.*/s', '$1', (string) shell_exec(sprintf(
            '%s -hide_banner -ss %s -t 0.2 -i %s -af volumedetect -f null - 2>&1', $bin, $at, escapeshellarg($dir.'/out.mp4'))));
        $this->assertGreaterThan(-30, $loud('2.0'));  // the pop is there...
        $this->assertLessThan(-60, $loud('3.0'));     // ...and the gap between effects stays silent
        exec('rm -rf '.escapeshellarg($dir));
    }
}
