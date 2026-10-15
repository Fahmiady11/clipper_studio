<?php

namespace App\Support;

use App\Services\SmartCut;

/**
 * Keeps the usable B-roll moments an AI suggested: inside the clip, after
 * the hook, with room to finish, spaced apart, at most a few per clip.
 */
final class BrollCues
{
    public const MIN_GAP = 8.0; // seconds between two cut-ins

    /** One cut-in per 15s of clip, at most three */
    public static function max(float $duration): int
    {
        return max(1, min(3, (int) floor($duration / 15)));
    }

    /**
     * Moments picked during analysis (source time) moved onto the clip's cut
     * timeline, then filtered. Moments outside the (possibly trimmed) clip drop out.
     *
     * @param  array<int, mixed>  $broll  [{time, query}], source seconds
     * @param  list<array{0: float, 1: float}>  $pieces  SmartCut pieces kept
     */
    public static function fromAnalysis(array $broll, array $pieces, float $clipStart, float $duration, float $notBefore): array
    {
        $cues = [];
        foreach (array_filter($broll, 'is_array') as $b) {
            $rel = (float) ($b['time'] ?? -1) - $clipStart;
            if ($rel >= 0) {
                $cues[] = ['time' => SmartCut::mapTime($pieces, $rel), 'query' => $b['query'] ?? ''];
            }
        }

        return self::filter($cues, $duration, $notBefore);
    }

    /**
     * @param  array<int, mixed>  $cues  [{time, query}], clip-relative
     * @return list<array{time: float, query: string}>
     */
    public static function filter(array $cues, float $duration, float $notBefore): array
    {
        $cues = array_values(array_filter($cues, 'is_array'));
        usort($cues, fn ($a, $b) => (float) ($a['time'] ?? 0) <=> (float) ($b['time'] ?? 0));

        $out = [];
        foreach ($cues as $c) {
            $time = (float) ($c['time'] ?? -1);
            $query = trim((string) ($c['query'] ?? ''));
            if ($query === '' || $time < $notBefore || $time > $duration - 3) {
                continue;
            }
            if ($out && $time - end($out)['time'] < self::MIN_GAP) {
                continue;
            }
            $out[] = ['time' => round($time, 2), 'query' => mb_substr($query, 0, 40)];
            if (count($out) >= self::max($duration)) {
                break;
            }
        }

        return $out;
    }
}
