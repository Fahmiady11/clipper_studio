<?php

namespace App\Services;

use App\Support\HookText;
use GdImage;
use RuntimeException;

/**
 * Draws the opening hook as a transparent PNG, cropped to the text block, that
 * ffmpeg pops onto the clip (FFmpegService::burnSubtitlesAndHook). GD instead
 * of drawtext gives centred multi-line text, rounded boxes, outlines,
 * emphasised words and auto-fit sizing — drawtext could do none of those.
 *
 * Words in *asterisks* are emphasised in the accent colour (a marker box in the
 * Label style). The studio preview (hookLayout() in studio/_script) runs the
 * same layout maths in the browser, so keep the constants in both places in sync.
 */
class HookRenderer
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    private const SUPERSAMPLE = 2;   // draw at 2× and scale down for smooth edges

    private const MAX_WIDTH = 900; // widest text line, px

    private const MAX_LINES = 4;

    private const SIZE_MAX = 104; // font size in px; shrinks until the text fits

    private const SIZE_MIN = 52;

    private const LINE_HEIGHT = 1.2; // × font size

    private const MARGIN = 24;  // transparent border kept around the cropped block

    // Heaviest weight we bundle per family: hooks should hit harder than subtitles
    private const FONTS = [
        'Montserrat' => 'Montserrat-ExtraBold.ttf',
        'Roboto' => 'Roboto-Black.ttf',
        'Oswald' => 'Oswald-Bold.ttf',
        'Bebas Neue' => 'BebasNeue-Regular.ttf',
        'Arial' => 'Arial-Bold.ttf',
    ];

    // Condensed faces look small at the same px size; scale them up
    private const FONT_SCALE = ['Bebas Neue' => 1.3, 'Oswald' => 1.1];

    public function __construct(private VideoLayoutService $layout) {}

    /**
     * @param  array{text_color?: string, accent_color?: string, background_style?: string, position?: string, font_family?: string}  $settings
     * @return array{x: int, y: int, w: int, h: int} where the PNG sits on the 1080×1920 frame
     */
    public function render(string $text, array $settings, string $outPath): array
    {
        $family = $settings['font_family'] ?? 'Montserrat';
        $font = $this->fontPath($family);
        $style = $settings['background_style'] ?? 'full';
        $color = $this->rgb($settings['text_color'] ?? '#ffffff');
        $accent = $this->rgb($settings['accent_color'] ?? '#facc15');

        $text = HookText::clean($text) ?? '';
        if ($family === 'Bebas Neue') {
            $text = mb_strtoupper($text); // all-caps display face, matches the preview
        }
        $tokens = HookText::tokens($text);
        $words = array_column($tokens, 0);

        ['size' => $size, 'lines' => $lines] = $this->fit($words, $font, self::FONT_SCALE[$family] ?? 1.0);

        $s = self::SUPERSAMPLE;
        $canvas = $this->transparentCanvas(self::WIDTH * $s, self::HEIGHT * $s);
        $px = $size * $s;
        $lineH = $px * self::LINE_HEIGHT;
        $capH = $this->capHeight($font, $px);
        $padX = $style === 'full' ? $px * 0.34 : $px * 0.52;
        $padY = $style === 'full' ? 0.0 : $px * 0.38;
        $blockH = count($lines) * $lineH + 2 * $padY;
        $blockY = $this->blockTop($settings['position'] ?? 'center', $blockH / $s) * $s;
        $shadow = $px * 0.08;

        // Lay out every word: each starts at the line's pen origin plus the advance
        // of the text before it, so spacing matches drawing the line in one go
        // (a word's own side bearing — the "j" in "juta" — must not shift it).
        $placed = [];
        $widths = [];
        $i = 0;
        foreach ($lines as $n => $lineWords) {
            $lineBox = $this->bbox($font, $px, implode(' ', $lineWords));
            $widths[$n] = $lineBox[2] - $lineBox[0];
            $origin = (self::WIDTH * $s - $widths[$n]) / 2 - $lineBox[0];
            foreach ($lineWords as $k => $word) {
                $prefix = implode(' ', array_slice($lineWords, 0, $k));
                $advance = $prefix === '' ? 0 : $this->bbox($font, $px, $prefix.' ')[2];
                $wordBox = $this->bbox($font, $px, $word);
                $placed[] = [
                    'line' => $n, 'word' => $word, 'em' => $tokens[$i++][1],
                    'x' => $origin + $advance + $wordBox[0], // left edge of the ink
                    'w' => $wordBox[2] - $wordBox[0],
                ];
            }
        }

        $dark = $this->luminance($color) < 0.45;
        $shadowC = [0, 0, 0, 0.35];

        if ($style === 'semi') {
            // One rounded box behind the whole block; light box for dark text
            $w = max($widths) + 2 * $padX;
            $x = (self::WIDTH * $s - $w) / 2;
            $box = $dark ? [255, 255, 255, 0.9] : [12, 12, 14, 0.66];
            $this->roundedRect($canvas, $x, $blockY + $shadow, $x + $w, $blockY + $blockH + $shadow, $px * 0.4, $shadowC);
            $this->roundedRect($canvas, $x, $blockY, $x + $w, $blockY + $blockH, $px * 0.4, $box);
            $boxRgb = array_slice($box, 0, 3);
        } elseif ($style === 'full') {
            // TikTok "label": a solid box per line in the chosen colour
            foreach ([$shadowC, [...$color, 1.0]] as $pass => $fill) {
                foreach ($widths as $n => $w) {
                    $x = (self::WIDTH * $s - $w) / 2 - $padX;
                    $y1 = $blockY + $n * $lineH + ($pass === 0 ? $shadow : 0);
                    // overlap neighbours slightly so stacked boxes read as one shape
                    $this->roundedRect($canvas, $x, $y1 - $s, $x + $w + 2 * $padX, $y1 + $lineH + $s, $px * 0.22, $fill);
                }
            }
            $boxRgb = $color;
        } else {
            $boxRgb = null;
        }

        // Label style: runs of emphasised words get one marker box in the accent
        // colour — or a dark one when the accent would vanish into the label
        $marker = $this->distinct($accent, $color) ? $accent : $this->contrast($color);
        if ($style === 'full') {
            foreach ($this->emphasisRuns($placed) as [$line, $from, $to]) {
                $y1 = $blockY + $line * $lineH;
                $this->roundedRect($canvas, $from - $px * 0.12, $y1 + $px * 0.1, $to + $px * 0.12,
                    $y1 + $lineH - $px * 0.1, $px * 0.16, [...$marker, 1.0]);
            }
        }

        imagealphablending($canvas, true);
        $plainColor = $boxRgb === null ? $color : ($style === 'full' ? $this->contrast($color) : $color);
        $emColor = match (true) {
            $style === 'full' => $this->contrast($marker),
            $boxRgb !== null && ! $this->readable($accent, $boxRgb) => $plainColor,
            default => $accent,
        };
        $outline = $dark ? [255, 255, 255] : [0, 0, 0];

        foreach ($placed as $p) {
            $baseline = $blockY + $padY + ($p['line'] + 0.5) * $lineH + $capH / 2;
            $fill = $p['em'] ? $emColor : $plainColor;
            if ($style === 'none') {
                $this->drawOutlined($canvas, $font, $px, $p['x'], $baseline, $p['word'], $fill, $outline);
            } else {
                $this->drawText($canvas, $font, $px, $p['x'], $baseline, $p['word'], $fill);
            }
        }

        // Crop to the block (+ margin for outline/shadow) so ffmpeg can scale it around its own centre
        $half = (max($widths) + 2 * $padX) / 2 + ($style === 'none' ? $px * 0.12 : 0);
        $x1 = max(0, (int) floor((self::WIDTH * $s / 2 - $half) / $s) - self::MARGIN);
        $x2 = min(self::WIDTH, (int) ceil((self::WIDTH * $s / 2 + $half) / $s) + self::MARGIN);
        $y1 = max(0, (int) floor(($blockY - $px * 0.12) / $s) - self::MARGIN);
        $y2 = min(self::HEIGHT, (int) ceil(($blockY + $blockH + $shadow + $px * 0.12) / $s) + self::MARGIN);
        $place = ['x' => $x1, 'y' => $y1, 'w' => $x2 - $x1, 'h' => $y2 - $y1];

        $out = $this->transparentCanvas($place['w'], $place['h']);
        imagecopyresampled($out, $canvas, 0, 0, $x1 * $s, $y1 * $s, $place['w'], $place['h'], $place['w'] * $s, $place['h'] * $s);

        if (! imagepng($out, $outPath)) {
            throw new RuntimeException('Gagal menyimpan gambar hook: '.$outPath);
        }

        return $place;
    }

    /**
     * Consecutive emphasised words on one line, as [line, left x, right x].
     *
     * @return list<array{0: int, 1: float, 2: float}>
     */
    private function emphasisRuns(array $placed): array
    {
        $runs = [];
        $prev = null;
        foreach ($placed as $p) {
            if (! $p['em']) {
                $prev = null;

                continue;
            }
            if ($prev !== null && $prev['line'] === $p['line']) {
                $runs[array_key_last($runs)][2] = $p['x'] + $p['w'];
            } else {
                $runs[] = [$p['line'], $p['x'], $p['x'] + $p['w']];
            }
            $prev = $p;
        }

        return $runs;
    }

    public function fontPath(string $family): string
    {
        $path = $this->layout->fontsDir().'/'.(self::FONTS[$family] ?? self::FONTS['Montserrat']);

        return file_exists($path) ? $path : $this->layout->resolveFontByFamily($family);
    }

    /**
     * Largest font size whose balanced wrap fits MAX_WIDTH in MAX_LINES lines.
     *
     * @param  list<string>  $words
     * @return array{size: int, lines: list<list<string>>}
     */
    public function fit(array $words, string $font, float $scale = 1.0): array
    {
        $max = (int) round(self::SIZE_MAX * $scale);
        $min = (int) round(self::SIZE_MIN * $scale);

        for ($size = $max; $size > $min; $size -= 2) {
            $lines = $this->wrap($words, $font, $size);
            if (count($lines) <= self::MAX_LINES && $this->fitsWidth($lines, $font, $size)) {
                return ['size' => $size, 'lines' => $lines];
            }
        }

        return ['size' => $min, 'lines' => $this->wrap($words, $font, $min)];
    }

    /**
     * Greedy wrap at MAX_WIDTH, then narrow the target width as far as the line
     * count allows so lines come out even (no lonely last word).
     *
     * @return list<list<string>>
     */
    private function wrap(array $words, string $font, int $size): array
    {
        $count = count($this->greedy($words, $font, $size, self::MAX_WIDTH));

        $lo = 0;
        $hi = self::MAX_WIDTH;
        while ($hi - $lo > 4) {
            $mid = intdiv($lo + $hi, 2);
            if (count($this->greedy($words, $font, $size, $mid)) <= $count) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        return $this->greedy($words, $font, $size, $hi);
    }

    /** @return list<list<string>> */
    private function greedy(array $words, string $font, int $size, int $maxWidth): array
    {
        $lines = [];
        $line = [];
        foreach ($words as $word) {
            if ($line && $this->textWidth($font, $size, implode(' ', [...$line, $word])) > $maxWidth) {
                $lines[] = $line;
                $line = [$word];
            } else {
                $line[] = $word;
            }
        }
        if ($line) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function fitsWidth(array $lines, string $font, int $size): bool
    {
        foreach ($lines as $line) {
            if ($this->textWidth($font, $size, implode(' ', $line)) > self::MAX_WIDTH) {
                return false;
            }
        }

        return true;
    }

    private function blockTop(string $position, float $blockH): float
    {
        return match ($position) {
            'top' => self::HEIGHT * 0.17,
            'bottom' => self::HEIGHT * 0.74 - $blockH, // stays clear of TikTok's caption/buttons
            default => (self::HEIGHT - $blockH) / 2,
        };
    }

    // ── GD helpers (sizes in px; GD wants points at 96 dpi) ──────────────────

    private function textWidth(string $font, float $px, string $text): float
    {
        $box = $this->bbox($font, $px, $text);

        return $box[2] - $box[0];
    }

    /** @return array{0: float, 2: float} left/right ink edges relative to the pen origin (GD bbox) */
    private function bbox(string $font, float $px, string $text): array
    {
        return imagettfbbox($px * 0.75, 0, $font, $text);
    }

    private function capHeight(string $font, float $px): float
    {
        $box = imagettfbbox($px * 0.75, 0, $font, 'H');

        return $box[1] - $box[7];
    }

    private function drawText(GdImage $img, string $font, float $px, float $x, float $baseline, string $text, array $rgb, float $alpha = 1.0): void
    {
        $box = imagettfbbox($px * 0.75, 0, $font, $text);
        imagettftext($img, $px * 0.75, 0, (int) round($x - $box[0]), (int) round($baseline), $this->color($img, [...$rgb, $alpha]), $font, $text);
    }

    private function drawOutlined(GdImage $img, string $font, float $px, float $x, float $baseline, string $text, array $fill, array $stroke): void
    {
        // Drop shadow, then a solid outline stamped around the glyphs, then the fill
        $r = max(2.0, $px * 0.085);
        $this->drawText($img, $font, $px, $x, $baseline + $px * 0.07 + $r, $text, [0, 0, 0], 0.35);

        foreach ([$r, $r * 0.55] as $radius) {
            for ($a = 0; $a < 360; $a += 15) {
                $this->drawText($img, $font, $px, $x + cos(deg2rad($a)) * $radius, $baseline + sin(deg2rad($a)) * $radius, $text, $stroke);
            }
        }

        $this->drawText($img, $font, $px, $x, $baseline, $text, $fill);
    }

    private function roundedRect(GdImage $img, float $x1, float $y1, float $x2, float $y2, float $r, array $rgba): void
    {
        // Blending off: overlapping pieces overwrite instead of stacking alpha
        imagealphablending($img, false);
        $c = $this->color($img, $rgba);
        [$x1, $y1, $x2, $y2] = array_map(fn ($v) => (int) round($v), [$x1, $y1, $x2, $y2]);
        $r = (int) min(round($r), ($x2 - $x1) / 2, ($y2 - $y1) / 2);

        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $c);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $c);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $c);
        }
    }

    private function transparentCanvas(int $w, int $h): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

        return $img;
    }

    /** @param array{0:int,1:int,2:int,3?:float} $rgba alpha 0 (clear) … 1 (opaque) */
    private function color(GdImage $img, array $rgba): int
    {
        $alpha = 127 - (int) round(127 * ($rgba[3] ?? 1.0));

        return imagecolorallocatealpha($img, $rgba[0], $rgba[1], $rgba[2], $alpha);
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function luminance(array $rgb): float
    {
        return (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) / 255;
    }

    /** Near-black or white, whichever reads on $bg. */
    private function contrast(array $bg): array
    {
        return $this->luminance($bg) < 0.45 ? [255, 255, 255] : [17, 17, 17];
    }

    /** Colours far enough apart to tell a marker box from the label behind it. */
    private function distinct(array $a, array $b): bool
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2) > 120;
    }

    private function readable(array $fg, array $bg): bool
    {
        return abs($this->luminance($fg) - $this->luminance($bg)) > 0.3;
    }
}
