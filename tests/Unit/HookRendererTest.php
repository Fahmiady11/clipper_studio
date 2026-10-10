<?php

namespace Tests\Unit;

use App\Services\HookRenderer;
use App\Services\VideoLayoutService;
use Tests\TestCase;

class HookRendererTest extends TestCase
{
    private HookRenderer $renderer;

    private string $font;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new HookRenderer(new VideoLayoutService);
        $this->font = $this->renderer->fontPath('Montserrat');
    }

    private function width(int $px, array $words): float
    {
        $box = imagettfbbox($px * 0.75, 0, $this->font, implode(' ', $words));

        return $box[2] - $box[0];
    }

    private function words(string $text): array
    {
        return explode(' ', $text);
    }

    public function test_hooks_use_the_heavy_weight(): void
    {
        $this->assertStringEndsWith('Montserrat-ExtraBold.ttf', $this->font);
        $this->assertFileExists($this->font);
    }

    public function test_short_hook_uses_the_largest_size(): void
    {
        $this->assertSame(104, $this->renderer->fit($this->words('Berhenti jadi korban!'), $this->font)['size']);
    }

    public function test_long_hook_shrinks_to_fit_four_lines_inside_the_frame(): void
    {
        $text = 'Lupakan angka 21 hari. Ini alasannya kenapa kamu selalu gagal membangun kebiasaan baru yang benar';
        $fit = $this->renderer->fit($this->words($text), $this->font);

        $this->assertLessThan(104, $fit['size']);
        $this->assertLessThanOrEqual(4, count($fit['lines']));
        $this->assertSame($text, implode(' ', array_merge(...$fit['lines'])), 'no words lost or reordered');
        foreach ($fit['lines'] as $line) {
            $this->assertLessThanOrEqual(900, $this->width($fit['size'], $line));
        }
    }

    public function test_lines_are_balanced_not_greedy(): void
    {
        // Greedy wrapping would leave one word alone on the last line
        $fit = $this->renderer->fit($this->words('Kebiasaan gagal? Coba aturan 2 menit ini sekarang juga!'), $this->font);
        $widths = array_map(fn ($l) => $this->width($fit['size'], $l), $fit['lines']);

        $this->assertGreaterThan(0.5, min($widths) / max($widths));
    }

    public function test_renders_cropped_png_with_placement_on_the_frame(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'hook').'.png';

        $place = $this->renderer->render('Gaji 50 juta, tetap *bokek*', [
            'background_style' => 'full', 'text_color' => '#ffffff', 'accent_color' => '#facc15',
            'position' => 'center', 'font_family' => 'Montserrat',
        ], $out);

        [$w, $h] = getimagesize($out);
        $this->assertSame([$place['w'], $place['h']], [$w, $h]);
        $this->assertLessThan(1080 * 1920 / 4, $w * $h, 'cropped to the text block');
        $this->assertEqualsWithDelta(540, $place['x'] + $w / 2, 2, 'centred horizontally');
        $this->assertEqualsWithDelta(960, $place['y'] + $h / 2, 40, 'centred vertically');

        $img = imagecreatefrompng($out);
        $pixels = ['white' => false, 'yellow' => false];
        for ($x = 0; $x < $w; $x += 3) {
            for ($y = 0; $y < $h; $y += 3) {
                $c = imagecolorat($img, $x, $y);
                if ((($c >> 24) & 0x7F) !== 0) {
                    continue;
                }
                [$r, $g, $b] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
                $pixels['white'] = $pixels['white'] || ($r > 245 && $g > 245 && $b > 245);
                $pixels['yellow'] = $pixels['yellow'] || ($r > 230 && $g > 190 && $b < 60);
            }
        }
        $this->assertTrue($pixels['white'], 'white label boxes');
        $this->assertTrue($pixels['yellow'], 'yellow marker behind the *emphasised* word');
        $this->assertSame(127, (imagecolorat($img, 0, 0) >> 24) & 0x7F, 'transparent margin');

        @unlink($out);
    }
}
