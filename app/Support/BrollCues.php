<?php

namespace App\Support;

use App\Services\SmartCut;

/**
 * Keeps the usable B-roll moments: inside the clip, after the hook, clear of
 * punchlines, with room to finish, spaced apart, at most a few per clip.
 * Each cut-in lasts as long as its phrase, within MIN..MAX_DURATION.
 */
final class BrollCues
{
    public const MIN_GAP = 10.0; // seconds between two cut-ins

    public const MIN_DURATION = 1.2;

    public const MAX_DURATION = 2.2;

    /** Used when the moment has no phrase end (analysis moments) */
    private const DEFAULT_DURATION = 1.8;

    /** One cut-in per 20s of clip, at most three */
    public static function max(float $duration): int
    {
        return max(1, min(3, (int) floor($duration / 20)));
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
     * @param  array<int, mixed>  $cues  [{time, duration?, query, alt?, must?, phrase?}], clip-relative
     * @param  list<array{0: float, 1: float}>  $punches  punchline spans the speaker must stay visible for
     * @return list<array{time: float, duration: float, query: string, alt: string, must: list<string>, phrase: string}>
     */
    public static function filter(array $cues, float $duration, float $notBefore, array $punches = []): array
    {
        $cues = array_values(array_filter($cues, 'is_array'));
        usort($cues, fn ($a, $b) => (float) ($a['time'] ?? 0) <=> (float) ($b['time'] ?? 0));

        $out = [];
        foreach ($cues as $c) {
            $time = (float) ($c['time'] ?? -1);
            $length = round(max(self::MIN_DURATION, min(self::MAX_DURATION, (float) ($c['duration'] ?? self::DEFAULT_DURATION))), 2);
            $query = trim((string) ($c['query'] ?? ''));
            if ($query === '' || $time < $notBefore || $time + $length > $duration - 1.0) {
                continue;
            }
            foreach ($punches as [$a, $b]) {
                if ($time < $b + 0.3 && $time + $length > $a - 0.3) {
                    continue 2;
                }
            }
            if ($out && $time - end($out)['time'] < self::MIN_GAP) {
                continue;
            }
            $out[] = ['time' => round($time, 2), 'duration' => $length, 'query' => mb_substr($query, 0, 60),
                'alt' => mb_substr(trim((string) ($c['alt'] ?? '')), 0, 40), 'must' => array_values((array) ($c['must'] ?? [])), 'phrase' => (string) ($c['phrase'] ?? '')];
            if (count($out) >= self::max($duration)) {
                break;
            }
        }

        return $out;
    }
}
