<?php

namespace App\Support;

/**
 * Measures text the way libass lays it out, so shapes drawn behind a subtitle
 * (e.g. a rounded box) can be sized to fit it. libass scales a font so that
 * OS/2 winAscent + winDescent equals the ASS font size; GD measures in points
 * at 96 dpi, hence the 0.75 factor.
 */
class FontMetrics
{
    /** @var array<string, float> font path => em size per ASS font-size unit */
    private static array $emScale = [];

    public static function textWidth(string $fontPath, string $text, float $assFontSize): float
    {
        $emPx = $assFontSize * self::emScale($fontPath);
        $box = imagettfbbox($emPx * 0.75, 0, $fontPath, $text);

        return $box === false ? 0.0 : (float) ($box[2] - $box[0]);
    }

    private static function emScale(string $fontPath): float
    {
        return self::$emScale[$fontPath] ??= self::readEmScale($fontPath);
    }

    private static function readEmScale(string $fontPath): float
    {
        $data = @file_get_contents($fontPath);
        if ($data === false || strlen($data) < 12) {
            return 1.0;
        }

        $tables = [];
        $numTables = unpack('n', $data, 4)[1];
        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + $i * 16;
            $tables[substr($data, $rec, 4)] = unpack('N', $data, $rec + 8)[1];
        }
        if (! isset($tables['head'], $tables['OS/2'])) {
            return 1.0;
        }

        $unitsPerEm = unpack('n', $data, $tables['head'] + 18)[1];
        $win = unpack('nascent/ndescent', $data, $tables['OS/2'] + 74);
        $height = $win['ascent'] + $win['descent'];

        return $height > 0 ? $unitsPerEm / $height : 1.0;
    }
}
