<?php

namespace App\Support;

/**
 * Readable text out of a stored transcript (caption lines, optionally with
 * word timing). Sound tags like "[tertawa]" are dropped: they are not speech.
 */
final class TranscriptText
{
    /** @return list<array{start: float, end: float, text: string}> lines in time order */
    public static function lines(array $segments): array
    {
        $lines = [];
        foreach ($segments as $seg) {
            $text = self::clean((string) ($seg['text'] ?? ''));
            if ($text !== '' && isset($seg['start'], $seg['end'])) {
                $lines[] = ['start' => round((float) $seg['start'], 2), 'end' => round((float) $seg['end'], 2), 'text' => $text];
            }
        }
        usort($lines, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $lines;
    }

    /** What is said between $start and $end: timed words when there are any, else the lines that overlap. */
    public static function between(array $segments, float $start, float $end): string
    {
        $parts = [];
        foreach ($segments as $seg) {
            if ((float) ($seg['end'] ?? 0) <= $start || (float) ($seg['start'] ?? 0) >= $end) {
                continue;
            }
            if (empty($seg['words'])) {
                $parts[] = [(float) $seg['start'], self::clean((string) ($seg['text'] ?? ''))];

                continue;
            }
            foreach ($seg['words'] as $w) {
                $at = (float) ($w['start'] ?? -1);
                if ($at >= $start && $at < $end) {
                    $parts[] = [$at, self::clean((string) ($w['text'] ?? ''))];
                }
            }
        }
        usort($parts, fn ($a, $b) => $a[0] <=> $b[0]);

        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_column($parts, 1))));
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/\[[^\]]*\]/u', '', $text)));
    }
}
