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
        config(['services.ffmpeg.threads' => '3']);
        (new FFmpegService)->burnSubtitles('in.mp4', 'sub.ass', 'out.mp4');

        $this->assertStringContainsString('-c:v libx264 -preset medium -crf 18 -pix_fmt yuv420p -threads 3 ', $this->args());
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
