<?php

namespace App\Support;

/**
 * Plans the sound effects of a clip: a whoosh on every jump cut and zoom
 * punch-in, a pop when an emoji appears, a ding on a highlighted keyword.
 * The WAVs in resources/sfx are synthesised by resources/sfx/generate.sh.
 *
 * All times are on the output clip's timeline. Effects never crowd each other:
 * a global minimum gap, a longer one per sound, and the stronger cue wins a clash.
 */
final class SfxCues
{
    public const WHOOSH = 'whoosh';

    public const POP = 'pop';

    public const DING = 'ding';

    /** Level of each sound under the voice (voice sits at -14 LUFS), 0..1 */
    public const GAIN = [self::WHOOSH => 0.32, self::POP => 0.40, self::DING => 0.30];

    private const MIN_GAP = 0.8;

    /** Same sound closer than this reads as a stutter */
    private const SOUND_GAP = [self::WHOOSH => 1.8, self::POP => 3.0, self::DING => 4.0];

    /** Who wins when two cues land within MIN_GAP: the emoji pop, then the keyword ding */
    private const PRIORITY = [self::POP => 3, self::DING => 2, self::WHOOSH => 1];

    /** The whoosh swells for this long before it peaks; it starts early so the peak lands on the cut */
    private const WHOOSH_LEAD = 0.3;

    private const EDGE = 0.25; // seconds kept free at either end of the clip

    /**
     * @param  list<float>  $cuts  jump cut times
     * @param  list<array{0: float, 1: float}>  $zoom  punched-in spans; their start is the punch
     * @param  list<float>  $emojis  emoji start times
     * @param  array<int, array{text: string, start: float}>  $words  clip-relative; only those on screen highlighted
     * @return list<array{sound: string, at: float, gain: float}> sorted by time
     */
    public static function plan(array $cuts, array $zoom, array $emojis, array $words, float $duration, float $notBefore = 0.0): array
    {
        $candidates = [];
        foreach ($cuts as $t) {
            $candidates[] = [self::WHOOSH, (float) $t];
        }
        foreach ($zoom as $span) {
            $candidates[] = [self::WHOOSH, (float) $span[0]];
        }
        foreach ($emojis as $t) {
            $candidates[] = [self::POP, (float) $t];
        }
        foreach ($words as $w) {
            if (self::isKeyword((string) ($w['text'] ?? ''))) {
                $candidates[] = [self::DING, (float) $w['start']];
            }
        }

        // Strongest cue first, so a clash is settled before the weaker ones are tried
        usort($candidates, fn ($a, $b) => self::PRIORITY[$b[0]] <=> self::PRIORITY[$a[0]] ?: $a[1] <=> $b[1]);

        $kept = [];
        foreach ($candidates as [$sound, $t]) {
            if ($t < max(self::EDGE, $notBefore) || $t > $duration - self::EDGE) {
                continue;
            }
            foreach ($kept as $k) {
                $gap = abs($k['t'] - $t);
                if ($gap < self::MIN_GAP || ($k['sound'] === $sound && $gap < self::SOUND_GAP[$sound])) {
                    continue 2;
                }
            }
            $kept[] = ['sound' => $sound, 't' => $t];
        }

        usort($kept, fn ($a, $b) => $a['t'] <=> $b['t']);

        return array_map(fn ($k) => [
            'sound' => $k['sound'],
            'at' => round(max(0.0, $k['t'] - ($k['sound'] === self::WHOOSH ? self::WHOOSH_LEAD : 0.0)), 3),
            'gain' => self::GAIN[$k['sound']],
        ], $kept);
    }

    /** Where the pieces are joined on the cut clip's timeline. */
    public static function cutTimes(array $pieces): array
    {
        $cuts = [];
        $at = 0.0;
        foreach (array_slice($pieces, 0, -1) as [$a, $b]) {
            $at += $b - $a;
            $cuts[] = $at;
        }

        return $cuts;
    }

    /** The words the "word" subtitle style colours: numbers and long words (same rule as FFmpegService). */
    public static function isKeyword(string $word): bool
    {
        return (bool) preg_match('/\d/u', $word) || mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $word)) >= 7;
    }

    public static function path(string $sound): string
    {
        return resource_path('sfx/'.$sound.'.wav');
    }
}
