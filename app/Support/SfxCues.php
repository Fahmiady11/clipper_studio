<?php

namespace App\Support;

/**
 * Plans the sound effects of a clip. A sound only marks something the viewer
 * sees or should feel: a whoosh as B-roll cuts in, a pop as an emoji appears,
 * a tick on a punchline. Jump cuts and zoom punches stay silent (they are
 * meant to go unnoticed), and so does ordinary speech.
 * The WAVs in resources/sfx are synthesised by resources/sfx/generate.sh.
 *
 * All times are on the output clip's timeline. A per-style budget caps the
 * count and the spacing; the stronger cue wins a clash.
 */
final class SfxCues
{
    public const WHOOSH = 'whoosh';

    public const POP = 'pop';

    public const DING = 'ding';

    public const STYLES = ['subtle', 'energetic'];

    /** Level of each sound, 0..1, per style: subtle peaks ~14 LU under the voice (-14 LUFS), energetic ~10 */
    public const GAIN = [
        'subtle' => [self::WHOOSH => 0.40, self::POP => 0.45, self::DING => 0.30],
        'energetic' => [self::WHOOSH => 0.60, self::POP => 0.65, self::DING => 0.45],
    ];

    /** Most cues per clip, and the least time between any two */
    private const BUDGET = [
        'subtle' => ['max' => 4, 'gap' => 4.0, 'punch' => 1],
        'energetic' => ['max' => 7, 'gap' => 2.5, 'punch' => 2],
    ];

    /** Who wins when two cues clash: the B-roll whoosh (a picture change), then the emoji pop */
    private const PRIORITY = [self::WHOOSH => 3, self::POP => 2, self::DING => 1];

    /** The whoosh swells for this long before it peaks; it starts early so the peak lands on the cut-in */
    public const WHOOSH_LEAD = 0.25;

    private const EDGE = 0.25; // seconds kept free at either end of the clip

    /**
     * @param  list<float>  $broll  times B-roll cuts in
     * @param  list<float>  $emojis  emoji start times
     * @param  list<float>  $punchlines  punchline times (the line's key word)
     * @param  float|null  $hookEnd  when the hook card leaves; energetic style marks it with a whoosh
     * @return list<array{sound: string, at: float, gain: float}> sorted by time
     */
    public static function plan(array $broll, array $emojis, array $punchlines, float $duration, ?float $hookEnd = null, string $style = 'subtle'): array
    {
        $style = in_array($style, self::STYLES, true) ? $style : 'subtle';
        $budget = self::BUDGET[$style];

        $candidates = [];
        foreach ($broll as $t) {
            $candidates[] = [self::WHOOSH, (float) $t];
        }
        foreach ($emojis as $t) {
            $candidates[] = [self::POP, (float) $t];
        }
        foreach (array_slice($punchlines, 0, $budget['punch']) as $t) {
            $candidates[] = [self::DING, (float) $t];
        }
        if ($style === 'energetic' && $hookEnd !== null && $hookEnd > 0) {
            $candidates[] = [self::WHOOSH, (float) $hookEnd];
        }

        // Strongest cue first, so a clash is settled before the weaker ones are tried
        usort($candidates, fn ($a, $b) => self::PRIORITY[$b[0]] <=> self::PRIORITY[$a[0]] ?: $a[1] <=> $b[1]);

        $kept = [];
        foreach ($candidates as [$sound, $t]) {
            if (count($kept) >= $budget['max']) {
                break;
            }
            if ($t < self::EDGE || $t > $duration - self::EDGE) {
                continue;
            }
            foreach ($kept as $k) {
                if (abs($k['t'] - $t) < $budget['gap']) {
                    continue 2;
                }
            }
            $kept[] = ['sound' => $sound, 't' => $t];
        }

        usort($kept, fn ($a, $b) => $a['t'] <=> $b['t']);

        return array_map(fn ($k) => [
            'sound' => $k['sound'],
            'at' => round(max(0.0, $k['t'] - ($k['sound'] === self::WHOOSH ? self::WHOOSH_LEAD : 0.0)), 3),
            'gain' => self::GAIN[$style][$k['sound']],
        ], $kept);
    }

    public static function path(string $sound): string
    {
        return resource_path('sfx/'.$sound.'.wav');
    }
}
