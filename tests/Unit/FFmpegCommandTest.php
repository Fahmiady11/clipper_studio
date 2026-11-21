<?php

namespace Tests\Unit;

use App\Exceptions\FFmpegFailed;
use App\Services\FFmpegService;
use RuntimeException;
use Tests\TestCase;

/** The commands FFmpegService builds, recorded by a stand-in ffmpeg that exits with a chosen code */
class FFmpegCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/clipper-fakeff-'.getmypid();
        @mkdir($this->dir);
        $this->fakeExit(0);
        // hasAudio() asks ffprobe for an audio stream index
        file_put_contents($this->dir.'/ffprobe', "#!/bin/sh\necho 1\n");
        chmod($this->dir.'/ffprobe', 0755);
        config(['services.ffmpeg.path' => $this->dir.'/ffmpeg']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function fakeExit(int $code): void
    {
        file_put_contents($this->dir.'/ffmpeg', "#!/bin/sh\nprintf '%s\\n' \"\$*\" > ".escapeshellarg($this->dir.'/args')."\necho 'fake ffmpeg output'\nexit {$code}\n");
        chmod($this->dir.'/ffmpeg', 0755);
    }

    private function args(): string
    {
        return (string) file_get_contents($this->dir.'/args');
    }

    public function test_voice_normalizing_names_a_stereo_layout_for_the_aac_encoder(): void
    {
        $this->assertTrue((new FFmpegService)->normalizeVoice('in.mp4', 'out.mp4'));

        $this->assertStringContainsString('loudnorm=I=-14:TP=-1.5:LRA=11,aresample=48000,aformat=channel_layouts=stereo', $this->args());
        $this->assertStringContainsString('-c:a aac', $this->args());
    }

    public function test_encodes_use_the_configured_thread_count(): void
    {
        config(['services.ffmpeg.threads' => '3', 'services.ffmpeg.preset' => 'veryfast', 'services.ffmpeg.crf' => '20']);
        (new FFmpegService)->burnSubtitles('in.mp4', 'sub.ass', 'out.mp4');

        $this->assertStringContainsString('-c:v libx264 -preset veryfast -crf 20 -maxrate 8M -bufsize 16M -pix_fmt yuv420p -threads 3 ', $this->args());
    }

    public function test_the_render_pass_encodes_with_the_configured_preset_and_crf(): void
    {
        config(['services.ffmpeg.preset' => 'faster', 'services.ffmpeg.crf' => '21', 'services.ffmpeg.threads' => null]);
        (new FFmpegService)->applyLayout('in.mp4', 'reframe', 'out.mp4', 5.0, 35.0, overlay: ['ass' => null, 'hook' => null, 'emojis' => []]);

        $this->assertStringContainsString(' -fpsmax 30 -c:v libx264 -preset faster -crf 21 -maxrate 8M -bufsize 16M -pix_fmt yuv420p -c:a aac', $this->args());
        $this->assertSame(1, substr_count($this->args(), 'libx264'));
    }

    public function test_without_a_preset_or_crf_the_final_encode_is_veryfast_crf_20(): void
    {
        config(['services.ffmpeg.preset' => null, 'services.ffmpeg.crf' => null]);
        (new FFmpegService)->burnSubtitles('in.mp4', 'sub.ass', 'out.mp4');

        $this->assertStringContainsString('-preset veryfast -crf 20 ', $this->args());
    }

    public function test_an_unknown_preset_or_out_of_range_crf_falls_back_to_the_defaults(): void
    {
        config(['services.ffmpeg.preset' => 'fast; rm -rf /', 'services.ffmpeg.crf' => '99']);
        (new FFmpegService)->burnSubtitles('in.mp4', 'sub.ass', 'out.mp4');

        $this->assertStringContainsString('-preset veryfast -crf 20 ', $this->args());
        $this->assertStringNotContainsString('rm -rf', $this->args());
    }

    public function test_a_layout_pass_without_overlays_stays_a_fast_intermediate(): void
    {
        config(['services.ffmpeg.preset' => 'slow', 'services.ffmpeg.crf' => '17']);
        (new FFmpegService)->applyLayout('in.mp4', 'reframe', 'out.mp4');

        $this->assertStringContainsString('-preset veryfast -crf 14 ', $this->args());
    }

    public function test_without_a_thread_count_ffmpeg_picks_its_own(): void
    {
        config(['services.ffmpeg.threads' => null]);
        (new FFmpegService)->burnSubtitles('in.mp4', 'sub.ass', 'out.mp4');

        $this->assertStringNotContainsString('-threads', $this->args());
    }

    public function test_a_rejected_command_is_an_ffmpeg_failure_with_its_output(): void
    {
        $this->fakeExit(1);

        try {
            (new FFmpegService)->normalizeVoice('in.mp4', 'out.mp4');
            $this->fail('expected FFmpegFailed');
        } catch (FFmpegFailed $e) {
            $this->assertStringContainsString("FFmpeg normalizeVoice failed:\nfake ffmpeg output", $e->getMessage());
        }
    }

    public function test_a_killed_ffmpeg_is_not_treated_as_a_certain_failure(): void
    {
        $this->fakeExit(137); // what the shell reports after SIGKILL, e.g. the OOM killer

        try {
            (new FFmpegService)->normalizeVoice('in.mp4', 'out.mp4');
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(FFmpegFailed::class, $e);
        }
    }
}
