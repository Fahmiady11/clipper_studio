<?php

namespace App\Support;

use App\Models\GeneratedClip;

/**
 * Clip length limits written in the user's instruction ("maks 30 detik",
 * "di bawah 1 menit", "minimal 20 detik", "15-45 detik", "under 1 minute"),
 * and fitting an AI clip into them. The AI is told the limits too, but it
 * does not always keep to them, so the bounds are corrected afterwards.
 */
final class InstructionDuration
{
    /** Seconds a clip may be off a limit (the breath kept after the last word). */
    public const TOLERANCE = 0.5;

    private const FLOOR = 5.0;     // shortest clip worth keeping (same as ClipBoundaries)

    private const CEILING = 300.0; // longest clip a user may cut

    private const TAIL = 0.35;     // breath kept after the last word

    private const STRETCH = 20.0;  // how far past the minimum a sentence end is looked for

    private const NUM = '(\d+(?:[.,]\d+)?)';

    private const UNIT = '(detik|dtk|det|seconds?|secs?|s|menit|mnt|minutes?|mins?|m)\b';

    private const MAX_WORDS = 'maks(?:imal|imum)?|max(?:imal|imum)?|paling\s+(?:lama|panjang)|(?:tidak|tak|gak|ga|nggak|enggak)\s+lebih\s+(?:dari|dr)|kurang\s+dari|lebih\s+pendek\s+dari|di\s*bawah|under|below|less\s+than|shorter\s+than|at\s+most|no\s+(?:more|longer)\s+than|up\s+to|<=?';

    private const MIN_WORDS = 'min(?:imal|imum)?|paling\s+(?:sedikit|singkat|pendek)|setidaknya|sekurang-?kurangnya|lebih\s+(?:dari|panjang\s+dari)|di\s*atas|at\s+least|more\s+than|longer\s+than|over|>=?';

    private const WORD_START = '(?<![\p{L}\p{N}])'; // "min" in "admin" is not a limit

    private const ABOUT_WORDS = 'sekitar|kira-?kira|kurang\s+lebih|kurleb|around|about|approximately|roughly|~|±';

    /**
     * @return array{min: ?float, max: ?float}|null null when the text sets no usable length
     */
    public static function parse(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $t = ' '.str_replace(['–', '—'], '-', mb_strtolower($text)).' ';
        $n = self::NUM;
        $u = self::UNIT;
        $min = $max = [];

        // Ranges first: "15-45 detik", "15 sampai 45 detik", "antara 15 dan 45 detik", "between 15 and 45 seconds"
        $t = preg_replace_callback('/'.self::WORD_START."(?:antara\\s+|between\\s+)?{$n}\\s*(?:{$u})?\\s*(?:-|sampai|s\\/d|sd|hingga|to|until|dan|and)\\s*{$n}\\s*{$u}/u", function ($m) use (&$min, &$max) {
            $unit = $m[2] !== '' ? $m[2] : $m[4]; // "1-2 menit": the first number takes the second's unit
            $min[] = self::seconds($m[1], $unit);
            $max[] = self::seconds($m[3], $m[4]);

            return ' ';
        }, $t);
        // "sekitar 30 detik": a fifth either way
        $t = preg_replace_callback('/'.self::WORD_START.'(?:'.self::ABOUT_WORDS.")\\s*{$n}\\s*{$u}/u", function ($m) use (&$min, &$max) {
            $s = self::seconds($m[1], $m[2]);
            $min[] = $s * 0.8;
            $max[] = $s * 1.2;

            return ' ';
        }, $t);
        $t = preg_replace_callback('/'.self::WORD_START.'(?:'.self::MAX_WORDS.")\\s*[:.]?\\s*{$n}\\s*{$u}/u", function ($m) use (&$max) {
            $max[] = self::seconds($m[1], $m[2]);

            return ' ';
        }, $t);
        preg_replace_callback('/'.self::WORD_START.'(?:'.self::MIN_WORDS.")\\s*[:.]?\\s*{$n}\\s*{$u}/u", function ($m) use (&$min) {
            $min[] = self::seconds($m[1], $m[2]);

            return ' ';
        }, $t);

        // Several limits of a kind: the tightest wins
        $lo = $min ? min(self::CEILING, max(GeneratedClip::MIN_SECONDS, max($min))) : null;
        $hi = $max ? min(self::CEILING, max(self::FLOOR, min($max))) : null;
        if ($lo === null && $hi === null) {
            return null;
        }
        if ($lo !== null && $hi !== null && $lo > $hi) {
            return null; // contradicting each other: trust neither
        }

        return ['min' => $lo === null ? null : round($lo, 1), 'max' => $hi === null ? null : round($hi, 1)];
    }

    /** Words for the UI: "maks 30 detik", "15–45 detik". */
    public static function label(array $limits): string
    {
        $fmt = fn (float $s) => $s >= 60 && fmod($s, 60) == 0 ? ($s / 60).' menit' : rtrim(rtrim(number_format($s, 1, ',', ''), '0'), ',').' detik';

        return match (true) {
            $limits['min'] !== null && $limits['max'] !== null => $fmt($limits['min']).' sampai '.$fmt($limits['max']),
            $limits['max'] !== null => 'maks '.$fmt($limits['max']),
            default => 'minimal '.$fmt($limits['min']),
        };
    }

    /**
     * The length range to give the AI for these limits, filling an open end
     * from the project's own range.
     *
     * @return array{0: int, 1: int}
     */
    public static function promptRange(array $limits, int $min, int $max): array
    {
        $lo = $limits['min'] ?? min($min, max(self::FLOOR, $limits['max'] / 2));
        $hi = $limits['max'] ?? max($max, $lo * 2);

        return [(int) floor($lo), (int) ceil(min($hi, self::CEILING))];
    }

    /**
     * Move a clip's end so its length fits $limits: too long ends at the last
     * sentence (else word) that still fits, too short runs on to the next
     * sentence end past the minimum. The start stays: it carries the hook.
     *
     * @param  array<int, array>  $segments  transcript, absolute times
     * @return array{0: float, 1: float}|null null when the clip cannot be made to fit
     */
    public static function fit(float $start, float $end, array $segments, array $limits, ?float $videoEnd = null): ?array
    {
        $min = $limits['min'] ?? null;
        $max = $limits['max'] ?? null;
        $words = array_values(array_filter(ClipBoundaries::words($segments), fn ($w) => $w['start'] >= $start - 0.01));

        if ($max !== null && $end - $start > $max + self::TOLERANCE) {
            $end = self::cutBefore($start, $start + $max, $words, max($min ?? 0, min(self::FLOOR, $max)));
        }
        if ($min !== null && $end - $start < $min - self::TOLERANCE) {
            $end = self::runPast($start, $start + $min, $max === null ? null : $start + $max, $words, $videoEnd);
            if ($end === null) {
                return null;
            }
        }

        $length = $end - $start;
        if ($length < max(GeneratedClip::MIN_SECONDS, ($min ?? 0) - self::TOLERANCE) || ($max !== null && $length > $max + self::TOLERANCE)) {
            return null;
        }

        return [round($start, 2), round($end, 2)];
    }

    /** The latest end at or before $limit, on a sentence end when one leaves at least $floor seconds. */
    private static function cutBefore(float $start, float $limit, array $words, float $floor): float
    {
        $sentence = $word = null;
        foreach ($words as $i => $w) {
            $next = $words[$i + 1] ?? null;
            $cut = min($w['end'] + self::TAIL, $next && $next['start'] >= $w['end'] ? $next['start'] : INF);
            if ($w['end'] > $limit || $cut - $start < $floor) {
                continue;
            }
            $cut = min($cut, $limit);
            $word = $cut;
            if (! $next || ClipBoundaries::isBreak($w, $next)) {
                $sentence = $cut;
            }
        }

        return $sentence ?? $word ?? $limit; // no timed words: a plain cut
    }

    /** The first end past $target, on a sentence end when one comes soon enough; null when the speech runs out. */
    private static function runPast(float $start, float $target, ?float $limit, array $words, ?float $videoEnd): ?float
    {
        $word = null;
        foreach ($words as $i => $w) {
            if ($target > $w['end'] + self::TAIL) {
                continue;
            }
            $next = $words[$i + 1] ?? null;
            $cut = min($w['end'] + self::TAIL, $next && $next['start'] >= $w['end'] ? $next['start'] : INF);
            if (($limit !== null && $cut > $limit + self::TOLERANCE) || $w['end'] > $target + self::STRETCH) {
                break;
            }
            $word ??= $cut;
            if (! $next || ClipBoundaries::isBreak($w, $next)) {
                return $cut;
            }
        }
        if ($word !== null) {
            return $word;
        }
        // No timed words at all: plain extension while the video lasts
        $plain = $limit !== null ? min($target, $limit) : $target;

        return ! $words && ($videoEnd === null || $plain <= $videoEnd) ? $plain : null;
    }

    private static function seconds(string $number, string $unit): float
    {
        $value = (float) str_replace(',', '.', $number);

        return str_starts_with($unit, 'm') ? $value * 60 : $value;
    }
}
