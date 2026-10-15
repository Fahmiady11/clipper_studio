<?php

namespace App\Support;

/**
 * Moves the AI's clip start/end onto real speech boundaries. Gemini reads
 * caption lines and picks times that can be a second or two off, so clips
 * began mid-word or ended mid-sentence. The start snaps to a nearby word that
 * opens a sentence (or follows a pause), the end to a word that closes one.
 */
final class ClipBoundaries
{
    private const START_BACK = 2.0;  // how far a start may move earlier

    private const START_AHEAD = 1.5; // ... or later

    private const END_BACK = 1.5;

    private const END_AHEAD = 2.5;

    private const PAUSE = 0.5;       // a gap this long between words is a natural break

    private const PREFER_BREAK = 1.5; // seconds of distance a sentence boundary is worth

    private const LEAD = 0.15;       // breath kept before the first word

    private const TAIL = 0.35;       // ... and after the last

    private const MIN_LENGTH = 5.0;

    /**
     * @param  array<int, array{start: float, end: float, text: string, words?: array}>  $segments  transcript, absolute times
     * @return array{0: float, 1: float}
     */
    public static function snap(float $start, float $end, array $segments): array
    {
        $words = self::words($segments);
        if (count($words) < 2) {
            return [$start, $end];
        }

        $newStart = self::snapStart($start, $words) ?? $start;
        $newEnd = self::snapEnd($end, $words) ?? $end;

        return $newEnd - $newStart >= self::MIN_LENGTH
            // rounded inward, so a boundary never takes a sliver of the neighbouring word
            ? [ceil($newStart * 100) / 100, floor($newEnd * 100) / 100]
            : [$start, $end];
    }

    /** Timed words, or caption lines standing in for words when there is no word timing. */
    private static function words(array $segments): array
    {
        $words = [];
        foreach ($segments as $seg) {
            foreach (! empty($seg['words']) ? $seg['words'] : [$seg] as $w) {
                if (isset($w['start'], $w['end']) && trim((string) ($w['text'] ?? '')) !== '') {
                    $words[] = ['start' => (float) $w['start'], 'end' => (float) $w['end'], 'text' => trim((string) $w['text'])];
                }
            }
        }
        usort($words, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $words;
    }

    private static function snapStart(float $start, array $words): ?float
    {
        $best = null;
        $bestScore = INF;
        foreach ($words as $i => $w) {
            if ($w['start'] < $start - self::START_BACK || $w['start'] > $start + self::START_AHEAD) {
                continue;
            }
            $prev = $words[$i - 1] ?? null;
            $opens = ! $prev || self::isBreak($prev, $w);
            $score = abs($w['start'] - $start) - ($opens ? self::PREFER_BREAK : 0);
            if ($score < $bestScore) {
                $bestScore = $score;
                // a breath before the word, but never into the previous one
                $best = $w['start'] - self::LEAD;
                if ($prev && $prev['end'] <= $w['start']) {
                    $best = max($best, $prev['end']);
                }
            }
        }

        return $best;
    }

    private static function snapEnd(float $end, array $words): ?float
    {
        $best = null;
        $bestScore = INF;
        foreach ($words as $i => $w) {
            if ($w['end'] < $end - self::END_BACK || $w['end'] > $end + self::END_AHEAD) {
                continue;
            }
            $next = $words[$i + 1] ?? null;
            $closes = ! $next || self::isBreak($w, $next);
            $score = abs($w['end'] - $end) - ($closes ? self::PREFER_BREAK : 0);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $w['end'] + self::TAIL;
                if ($next && $next['start'] >= $w['end']) {
                    $best = min($best, $next['start']);
                }
            }
        }

        return $best;
    }

    /**
     * A sentence ends between $a and $b: punctuation, a pause, or (ASR
     * captions rarely punctuate) $b opening with a capital after a lowercase word.
     */
    private static function isBreak(array $a, array $b): bool
    {
        return preg_match('/[.?!…]["\')]*$/u', $a['text'])
            || $b['start'] - $a['end'] >= self::PAUSE
            || (preg_match('/^\p{Lu}\p{Ll}/u', $b['text']) && preg_match('/\p{Ll}[^\p{L}]*$/u', $a['text']));
    }
}
