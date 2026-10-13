<?php

namespace Tests\Unit;

use App\Services\FaceTracker;
use App\Services\FFmpegService;
use App\Services\VideoLayoutService;
use Tests\TestCase;

class FaceTrackerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/clipper-facetrack-'.getmypid();
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** A stand-in "python" that ignores its arguments and prints $stdout. */
    private function fakePython(string $stdout, int $exit = 0): string
    {
        $bin = $this->dir.'/python';
        file_put_contents($bin, "#!/bin/sh\nprintf '%s' ".escapeshellarg($stdout)."\nexit {$exit}\n");
        chmod($bin, 0755);

        return $bin;
    }

    public function test_disabled_without_python(): void
    {
        config(['services.facetrack.python' => '']);

        $this->assertNull((new FaceTracker)->plan('/nope.mp4', 0, 10));
    }

    public function test_falls_back_to_centre_crop_when_the_script_fails(): void
    {
        config(['services.facetrack.python' => $this->fakePython('Traceback: no module named cv2', 1)]);

        $this->assertNull((new FaceTracker)->plan('/nope.mp4', 0, 10));
    }

    public function test_no_faces_keeps_centre_crop(): void
    {
        config(['services.facetrack.python' => $this->fakePython('{"crop_w":0.3164,"faces":false,"segments":[]}')]);

        $this->assertNull((new FaceTracker)->plan('/v.mp4', 0, 10));
    }

    public function test_returns_the_planned_pieces(): void
    {
        config(['services.facetrack.python' => $this->fakePython(
            '{"crop_w":0.3164,"faces":true,"segments":[[0,2.5,0.3,0.3],[2.5,4,0.7,0.65]]}'
        )]);

        $this->assertSame([[0.0, 2.5, 0.3, 0.3], [2.5, 4.0, 0.7, 0.65]], (new FaceTracker)->plan('/v.mp4', 0, 4));
    }

    public function test_expression_holds_pans_and_clamps(): void
    {
        $expr = (new VideoLayoutService)->trackExpression([[0, 2, 0.3, 0.3], [2, 4, 0.5, 0.7], [4, 6, 0.9, 0.9]]);

        $this->assertSame(
            'clip((gte(t,0.000)*lt(t,2.000)*0.3000+gte(t,2.000)*lt(t,4.000)*(0.5000+0.10000*(t-2.000))'
            .'+gte(t,4.000)*lt(t,86400.000)*0.9000)*iw-ow/2,0,iw-ow)',
            $expr
        );
    }

    public function test_ffmpeg_renders_a_moving_crop(): void
    {
        // Real ffmpeg run: proves the expression survives filtergraph quoting,
        // and that a 60fps source comes out at 30fps
        $src = $this->dir.'/src.mp4';
        exec(sprintf('%s -v error -y -f lavfi -i testsrc=size=640x360:rate=60:duration=3 -pix_fmt yuv420p %s 2>&1',
            escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')), escapeshellarg($src)), $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }
        $out = $this->dir.'/out.mp4';

        (new FFmpegService)->applyLayout($src, 'reframe', $out, 0.0, 3.0, [[0, 1, 0.2, 0.2], [1, 3, 0.4, 0.8]]);

        $size = trim((string) shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height,avg_frame_rate -of csv=p=0 '.escapeshellarg($out)));
        $this->assertSame('1080,1920,30/1', $size);
    }
}
