<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Services\HookRenderer;
use App\Services\VideoLayoutService;
use Tests\TestCase;

class BrandKitRenderTest extends TestCase
{
    private FFmpegService $ffmpeg;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ffmpeg = new FFmpegService;
        $this->dir = sys_get_temp_dir().'/brandkit-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_subtitle_ass_uses_kit_font_and_colours(): void
    {
        $ass = $this->ffmpeg->buildAssFile(
            [['start' => 0, 'end' => 2, 'text' => 'halo dunia']],
            ['font_family' => 'Oswald', 'highlight_color' => '#22c55e', 'text_color' => '#eeeeee', 'background_style' => 'none'],
        );

        // ASS colours are &HBBGGRR&: the brand green #22c55e must land as 5EC522
        $this->assertMatchesRegularExpression('/^Style: Default,Oswald,\d+,&H00EEEEEE,&H005EC522,/m', $ass);
        $this->assertMatchesRegularExpression('/^Style: Highlight,Oswald,\d+,&H005EC522,&H00EEEEEE,/m', $ass);
    }

    public function test_subtitle_font_file_resolves_to_bundled_kit_font(): void
    {
        $layout = new VideoLayoutService;

        $this->assertStringEndsWith('Oswald-Bold.ttf', $layout->resolveFontByFamily('Oswald'));
        $this->assertStringEndsWith('BebasNeue-Regular.ttf', $layout->resolveFontByFamily('Bebas Neue'));
    }

    public function test_hook_image_uses_kit_font_and_accent(): void
    {
        $renderer = new HookRenderer(new VideoLayoutService);
        $png = $this->dir.'/hook.png';

        $box = $renderer->render('uang kamu bisa habis', [
            'font_family' => 'Oswald',
            'text_color' => '#ffffff',
            'accent_color' => '#22c55e',
            'background_style' => 'none',
            'position' => 'center',
        ], $png);

        $this->assertFileExists($png);
        $this->assertGreaterThan(0, $box['w']);
        $this->assertStringEndsWith('Oswald-Bold.ttf', $renderer->fontPath('Oswald'));
    }

    public function test_logo_watermark_is_drawn_in_top_right_corner(): void
    {
        if (! is_executable(config('services.ffmpeg.path', 'ffmpeg')) && ! shell_exec('command -v ffmpeg')) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        // 2s test card with audio, then a solid red 200px logo
        $src = $this->dir.'/src.mp4';
        $logo = $this->dir.'/logo.png';
        $out = $this->dir.'/out.mp4';
        $frame = $this->dir.'/frame.png';
        exec('ffmpeg -y -v error -f lavfi -i testsrc=duration=2:size=1280x720:rate=30 -f lavfi -i sine=duration=2 -shortest -c:v libx264 -pix_fmt yuv420p -c:a aac '.escapeshellarg($src).' 2>&1', $o1, $c1);
        $this->assertSame(0, $c1, implode("\n", $o1));
        $img = imagecreatetruecolor(200, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 0, 0));
        imagepng($img, $logo);

        $this->ffmpeg->applyLayout($src, 'gaussian', $out, 0.0, 2.0, null, null, [], [
            'ass' => null, 'hook' => null, 'hook_duration' => 0, 'emojis' => [],
            'title' => null, 'progress' => null, 'broll' => [], 'logo' => $logo,
        ]);
        $this->assertFileExists($out);

        exec('ffmpeg -y -v error -ss 1 -i '.escapeshellarg($out).' -frames:v 1 '.escapeshellarg($frame));
        $pic = imagecreatefrompng($frame);
        // logo is scaled to 180px and sits 40px from the top-right corner: centre ≈ (950, 130)
        [$r, $g, $b] = array_values(imagecolorsforindex($pic, imagecolorat($pic, 950, 130)));
        $this->assertGreaterThan(180, $r);
        $this->assertLessThan(90, $g);
        $this->assertLessThan(90, $b);
        // and the opposite corner is still the video, not red
        [$r2, $g2] = array_values(imagecolorsforindex($pic, imagecolorat($pic, 100, 1800)));
        $this->assertFalse($r2 > 180 && $g2 < 90);
    }
}
