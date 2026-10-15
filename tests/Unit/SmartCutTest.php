<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Services\SmartCut;
use App\Support\ClipSettings;
use Tests\TestCase;

class SmartCutTest extends TestCase
{
    public function test_pauses_keep_some_air_next_to_speech_but_go_entirely_at_the_edges(): void
    {
        $log = "[silencedetect] silence_start: 0\n[silencedetect] silence_end: 0.8 | silence_duration: 0.8\n"
            ."[silencedetect] silence_start: 4.2\n[silencedetect] silence_end: 5.0 | silence_duration: 0.8\n"
            .'[silencedetect] silence_start: 9.5';

        $this->assertSame([[0.0, 0.7], [4.3, 4.9], [9.6, 10.0]], array_map(
            fn ($s) => array_map(fn ($v) => round($v, 3), $s),
            SmartCut::parseSilences($log, 10.0)
        ));
    }

    public function test_keep_is_what_the_cuts_leave_without_tiny_scraps(): void
    {
        $this->assertSame([[0.7, 4.3], [4.9, 9.6]], SmartCut::keep([[0.0, 0.7], [4.3, 4.9], [9.6, 10.0]], 10.0));
        // 0.1s between two cuts is dropped, overlapping cuts merge
        $this->assertSame([[0.0, 2.0], [3.5, 6.0]], SmartCut::keep([[2.0, 3.0], [3.1, 3.5], [3.2, 3.4]], 6.0));
        $this->assertSame([[0.0, 6.0]], SmartCut::keep([], 6.0));
    }

    public function test_filler_words_are_cut_but_lookalikes_stay(): void
    {
        $words = [
            ['text' => 'jadi', 'start' => 100.0, 'end' => 100.4],
            ['text' => 'eh,', 'start' => 100.4, 'end' => 100.7],
            ['text' => 'Hmm...', 'start' => 101.0, 'end' => 101.5],
            ['text' => 'ehh', 'start' => 102.0, 'end' => 102.2], // not in the list
            ['text' => 'emas', 'start' => 103.0, 'end' => 103.4],
        ];

        $this->assertEqualsWithDelta([[0.4, 0.7], [1.0, 1.5]], SmartCut::fillers($words, 100.0, 30.0), 0.0001);
    }

    public function test_time_moves_onto_the_cut_timeline(): void
    {
        $pieces = [[0.0, 2.0], [3.0, 5.0]];

        $this->assertSame(1.5, SmartCut::mapTime($pieces, 1.5));
        $this->assertSame(2.0, SmartCut::mapTime($pieces, 2.5)); // inside the cut: where the next piece starts
        $this->assertSame(3.0, SmartCut::mapTime($pieces, 4.0));
        $this->assertSame(4.0, SmartCut::outputDuration($pieces));
    }

    public function test_segments_are_remapped_and_cut_out_words_dropped(): void
    {
        $segments = [['start' => 10.0, 'end' => 15.0, 'text' => 'satu eh dua', 'words' => [
            ['text' => 'satu', 'start' => 10.5, 'end' => 11.0],
            ['text' => 'eh', 'start' => 12.2, 'end' => 12.8],   // inside the cut 2..3
            ['text' => 'dua', 'start' => 13.5, 'end' => 14.0],
        ]]];

        $out = SmartCut::remapSegments($segments, [[0.0, 2.0], [3.0, 5.0]], 10.0);

        $this->assertSame(['satu', 'dua'], array_column($out[0]['words'], 'text'));
        $this->assertSame([10.5, 12.5], array_column($out[0]['words'], 'start'));
        $this->assertSame([10.0, 14.0], [$out[0]['start'], $out[0]['end']]);
    }

    public function test_zoom_changes_at_cuts_and_sentence_ends_but_not_too_often(): void
    {
        $pieces = [[0.0, 3.0], [3.5, 9.0], [9.4, 20.0]]; // cuts at 3.0 and 8.5 on the cut timeline
        $words = [
            ['text' => 'Halo.', 'start' => 0.5, 'end' => 1.0],    // too soon after the start
            ['text' => 'oke.', 'start' => 5.0, 'end' => 5.5],     // only 2.5s after the 3.0 cut: allowed
            ['text' => 'lanjut', 'start' => 15.0, 'end' => 15.4],
            ['text' => 'terus', 'start' => 16.0, 'end' => 16.4],
        ];

        // in at 3.0, out at 5.5, in at 8.5 (cut), out at 15.0 (nothing for 6s: next word)
        $this->assertSame([[3.0, 5.5], [8.5, 15.0]], SmartCut::zoomPlan($pieces, $words, 19.6));
    }

    public function test_edit_options_default_on_and_are_validated(): void
    {
        $this->assertSame(['jump_cut' => true, 'zoom' => true, 'progress' => true, 'title' => false], ClipSettings::normalize([])['edit']);
        $this->assertFalse(ClipSettings::normalize(['edit' => ['zoom' => false]])['edit']['zoom']);
        $this->assertFalse(validator(['edit' => ['jump_cut' => 'ya']], ClipSettings::rules())->passes());
    }

    public function test_ffmpeg_cuts_pieces_and_keeps_sound_and_picture_together(): void
    {
        $dir = sys_get_temp_dir().'/clipper-smartcut-'.getmypid();
        @mkdir($dir);
        $src = $dir.'/src.mp4';
        // 6s: tone, 2s silence, tone, with a moving test picture
        exec(sprintf('%s -v error -y -f lavfi -i testsrc=size=640x360:rate=25:duration=6 -f lavfi -i %s -shortest -pix_fmt yuv420p -c:a aac %s 2>&1',
            escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')),
            escapeshellarg("aevalsrc='if(between(t,2,4),0,0.5*sin(440*2*PI*t))':d=6"),
            escapeshellarg($src)), $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $pieces = (new SmartCut)->pieces($src, 0.0, 6.0, []);
        $this->assertCount(2, $pieces);
        $this->assertEqualsWithDelta(4.2, SmartCut::outputDuration($pieces), 0.1); // 2s pause, 0.1s kept each side

        $out = $dir.'/out.mp4';
        (new FFmpegService)->applyLayout($src, 'gaussian', $out, 0.0, 6.0, null, $pieces, [[1.0, 2.5]]);

        $probe = fn ($stream) => (float) shell_exec(sprintf('ffprobe -v error -select_streams %s -show_entries stream=duration -of csv=p=0 %s', $stream, escapeshellarg($out)));
        $this->assertEqualsWithDelta(4.2, $probe('v:0'), 0.1);
        $this->assertEqualsWithDelta($probe('v:0'), $probe('a:0'), 0.06);
        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_quiet_voice_is_brought_to_tiktok_loudness(): void
    {
        $dir = sys_get_temp_dir().'/clipper-loud-'.getmypid();
        @mkdir($dir);
        $src = $dir.'/src.mp4';
        exec(sprintf('%s -v error -y -f lavfi -i color=c=black:s=320x240:r=25:d=4 -f lavfi -i %s -shortest -pix_fmt yuv420p -c:a aac %s 2>&1',
            escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')),
            escapeshellarg("aevalsrc='0.02*sin(300*2*PI*t)*(1+sin(3*t))':d=4"),
            escapeshellarg($src)), $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $this->assertTrue((new FFmpegService)->normalizeVoice($src, $dir.'/out.mp4'));

        $lufs = fn ($f) => (float) (preg_match('/I:\s+(-?[\d.]+) LUFS/', (string) shell_exec(sprintf('ffmpeg -nostats -i %s -af ebur128 -f null - 2>&1 | tail -12', escapeshellarg($f))), $m) ? $m[1] : 0);
        $this->assertLessThan(-30, $lufs($src));
        $this->assertEqualsWithDelta(-14, $lufs($dir.'/out.mp4'), 2.5);
        exec('rm -rf '.escapeshellarg($dir));
    }
}
