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

    public function test_cuts_and_zoom_punches_get_a_whoosh_that_peaks_on_the_cut(): void
    {
        $cues = SfxCues::plan([4.0], [[10.0, 13.0]], [], [], 30.0);

        $this->assertSame(['whoosh', 'whoosh'], $this->sounds($cues));
        // The whoosh swells for 0.3s first, so it starts before the cut
        $this->assertSame([3.7, 9.7], array_column($cues, 'at'));
    }

    public function test_emoji_get_a_pop_on_the_frame_they_appear(): void
    {
        $cues = SfxCues::plan([], [], [6.0], [], 30.0);

        $this->assertSame([['sound' => 'pop', 'at' => 6.0, 'gain' => SfxCues::GAIN['pop']]], $cues);
    }

    public function test_numbers_and_long_words_get_a_ding_but_short_words_do_not(): void
    {
        $words = [
            ['text' => 'aku', 'start' => 2.0],
            ['text' => '1000', 'start' => 4.0],
            ['text' => 'berhasil,', 'start' => 9.0],
        ];

        $cues = SfxCues::plan([], [], [], $words, 30.0);

        $this->assertSame(['ding', 'ding'], $this->sounds($cues));
        $this->assertSame([4.0, 9.0], array_column($cues, 'at'));
    }

    public function test_dense_cuts_do_not_become_noise(): void
    {
        // A cut every 0.5s for 10 seconds
        $cuts = array_map(fn ($i) => 1.0 + $i * 0.5, range(0, 18));

        $cues = SfxCues::plan($cuts, [], [], [], 12.0);

        $times = array_column($cues, 'at');
        $this->assertLessThan(count($cuts) / 3, count($cues));
        foreach ($times as $i => $t) {
            if ($i > 0) {
                $this->assertGreaterThanOrEqual(1.8 - 0.001, $t - $times[$i - 1], 'two whooshes closer than the per-sound gap');
            }
        }
    }

    public function test_different_sounds_keep_a_minimum_gap_and_the_pop_wins_a_clash(): void
    {
        // Whoosh at 5.0, emoji at 5.4, ding word at 5.6: all within 0.8s of the pop
        $cues = SfxCues::plan([5.0], [], [5.4], [['text' => '2025', 'start' => 5.6]], 20.0);

        $this->assertSame(['pop'], $this->sounds($cues));
    }

    public function test_every_cue_pair_respects_the_global_gap(): void
    {
        $cues = SfxCues::plan(
            [2.0, 3.0, 5.0, 7.0],
            [[3.5, 5.5], [8.0, 9.0]],
            [4.0, 8.2],
            array_map(fn ($i) => ['text' => 'luar biasa'.$i, 'start' => 1.0 + $i * 0.3], range(0, 30)),
            12.0,
        );

        // "at" of a whoosh is 0.3s early, so compare the planned moments
        $moments = array_map(fn ($c) => $c['at'] + ($c['sound'] === 'whoosh' ? 0.3 : 0.0), $cues);
        sort($moments);
        foreach ($moments as $i => $t) {
            if ($i > 0) {
                $this->assertGreaterThanOrEqual(0.8 - 0.001, $t - $moments[$i - 1]);
            }
        }
    }

    public function test_nothing_plays_in_the_clip_edges_or_before_the_hook_ends(): void
    {
        $cues = SfxCues::plan([0.1, 2.0, 11.9], [], [], [], 12.0, 3.0);

        $this->assertSame([], $cues);
    }

    public function test_cut_times_are_where_pieces_are_joined_on_the_output_timeline(): void
    {
        $this->assertSame([], SfxCues::cutTimes([[0.0, 10.0]]));
        $this->assertEqualsWithDelta([3.0, 8.0], SfxCues::cutTimes([[1.0, 4.0], [6.0, 11.0], [15.0, 16.0]]), 0.0001);
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
