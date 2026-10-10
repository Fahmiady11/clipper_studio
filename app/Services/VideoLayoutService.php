<?php

namespace App\Services;

class VideoLayoutService
{
    /**
     * Returns filter_complex graph for the given layout mode.
     * $outputLabel: stream label for the final video output, e.g. '[out]' or '[pre_text]'.
     */
    public function layoutFilter(string $layoutMode, string $outputLabel = '[out]'): string
    {
        return match ($layoutMode) {
            'auto_reframe' => "[0:v]crop=ih*(9/16):ih:(iw-ih*(9/16))/2:0,scale=1080:1920:flags=lanczos,unsharp=5:5:0.6{$outputLabel}",
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
