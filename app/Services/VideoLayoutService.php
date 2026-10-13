<?php

namespace App\Services;

class VideoLayoutService
{
    /**
     * Returns filter_complex graph for the given layout mode.
     * $outputLabel: stream label for the final video output, e.g. '[out]' or '[pre_text]'.
     */
    /**
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>|null  $track  FaceTracker pieces;
     *                                                                           reframe follows them instead of cropping the centre
     */
    public function layoutFilter(string $layoutMode, string $outputLabel = '[out]', ?array $track = null): string
    {
        $cropX = $track ? $this->trackExpression($track) : '(iw-ow)/2';

        return match ($layoutMode) {
            'auto_reframe' => "[0:v]crop=w=ih*9/16:h=ih:x='{$cropX}':y=0,scale=1080:1920:flags=lanczos,unsharp=5:5:0.6{$outputLabel}",
            'auto_split' => '[0:v]split=2[s1][s2];'.
                '[s1]crop=in_w:trunc(in_h*0.55):0:0,scale=1080:960:flags=lanczos[top];'.
                '[s2]crop=in_w:trunc(in_h*0.55):0:trunc(in_h*0.45),scale=1080:960:flags=lanczos[bot];'.
                "[top][bot]vstack=inputs=2{$outputLabel}",
            default => // gaussian_blur, auto_magic
            '[0:v]split=2[s1][s2];'.
                '[s1]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:3,eq=saturation=1.5:brightness=-0.1[bg];'.
                '[s2]scale=1080:1920:force_original_aspect_ratio=decrease:flags=lanczos[fg];'.
                "[bg][fg]overlay=(W-w)/2:(H-h)/2{$outputLabel}",
        };
    }

    /**
     * Crop x per frame: the crop centre is piecewise linear in t, one term per
     * piece (gte*lt picks the active one), clamped to stay inside the frame.
     */
    public function trackExpression(array $track): string
    {
        $terms = [];
        $last = count($track) - 1;
        foreach (array_values($track) as $i => [$t0, $t1, $x0, $x1]) {
            $t1 = $i === $last ? 86400.0 : $t1; // the last piece holds to the end of the clip
            $span = sprintf('gte(t,%.3f)*lt(t,%.3f)', $t0, $t1);
            $value = abs($x1 - $x0) < 1e-4 || $i === $last
                ? sprintf('%.4f', $x0)
                : sprintf('(%.4f+%.5f*(t-%.3f))', $x0, ($x1 - $x0) / max($track[$i][1] - $t0, 1e-3), $t0);
            $terms[] = $span.'*'.$value;
        }

        return 'clip(('.implode('+', $terms).')*iw-ow/2,0,iw-ow)';
    }

    public function fontsDir(): string
    {
        return resource_path('fonts');
    }

    public function resolveFontByFamily(string $family): string
    {
        $map = [
            'Montserrat' => 'Montserrat-Bold.ttf',
            'Roboto' => 'Roboto-Bold.ttf',
            'Oswald' => 'Oswald-Bold.ttf',
            'Bebas Neue' => 'BebasNeue-Regular.ttf',
            'Arial' => 'Arial-Bold.ttf',
        ];

        $file = $map[$family] ?? 'Montserrat-Bold.ttf';
        $path = $this->fontsDir().'/'.$file;

        if (file_exists($path)) {
            return $path;
        }

        return $this->resolveFontPath();
    }

    private function resolveFontPath(): string
    {
        $bundled = $this->fontsDir().'/Montserrat-Bold.ttf';
        if (file_exists($bundled)) {
            return $bundled;
        }

        $candidates = [
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/Library/Fonts/Arial.ttf',
            '/Library/Fonts/Arial Unicode.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];
        foreach ($candidates as $f) {
            if (file_exists($f)) {
                return $f;
            }
        }
        throw new \RuntimeException('No system font found');
    }
}
