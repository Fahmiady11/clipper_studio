<?php

namespace App\Support;

use App\Services\SmartCut;

/**
 * The per-clip edit plan: which spoken phrases get B-roll, which get an emoji,
 * and which are punchlines (the speaker stays on screen there). The AI picks
 * phrases by number; this class turns its answer into times, keeps it on the
 * clip (source seconds, so a re-render reuses it) and moves it onto the cut
 * timeline at render time.
 */
final class EditPlan
{
    private const PHRASE_GAP = 0.45; // seconds of silence that end a phrase

    private const PHRASE_WORDS = 10;

    private const BROLL_TAIL = 0.2; // B-roll holds this long past its phrase

    /**
     * Word-timed speech split into phrases at sentence ends, pauses and every PHRASE_WORDS words.
     *
     * @param  array<int, array{text: string, start: float, end?: float}>  $words  in order
     * @return list<array{start: float, end: float, text: string, words: list<array{text: string, start: float, end: float}>}>
     */
    public static function phrases(array $words): array
    {
        $phrases = [];
        $cur = [];
        $flush = function () use (&$cur, &$phrases) {
            if ($cur) {
                $phrases[] = ['start' => $cur[0]['start'], 'end' => end($cur)['end'],
                    'text' => implode(' ', array_column($cur, 'text')), 'words' => $cur];
            }
            $cur = [];
        };
        foreach ($words as $w) {
            $text = trim((string) ($w['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $word = ['text' => $text, 'start' => (float) $w['start'], 'end' => (float) ($w['end'] ?? $w['start'])];
            if ($cur && $word['start'] - end($cur)['end'] >= self::PHRASE_GAP) {
                $flush();
            }
            $cur[] = $word;
            if (preg_match('/[.?!…]$/u', $text) || count($cur) >= self::PHRASE_WORDS) {
                $flush();
            }
        }
        $flush();

        return $phrases;
    }

    /** "#3 [12.4] text" lines, seconds from the clip start, for the prompt. */
    public static function transcript(array $phrases, float $clipStart): string
    {
        return implode("\n", array_map(fn ($p, $i) => sprintf('#%d [%.1f] %s', $i, $p['start'] - $clipStart, $p['text']),
            $phrases, array_keys($phrases)));
    }

    /** Identifies the clip range a stored plan was made for; a trimmed clip needs a new plan. */
    public static function key(float $start, float $end): string
    {
        return sprintf('%.2f-%.2f', $start, $end);
    }

    /**
     * The AI's answer turned into source times. Unknown phrases, emoji without
     * an image and empty queries are dropped.
     *
     * @param  array<string, mixed>  $data  {broll: [{phrase, word, query, alt, must}], emoji: [{phrase, word, emoji}], punchlines: [int]}
     * @return array{key: string, broll: list<array>, emoji: list<array>, punch: list<array>}
     */
    public static function fromAi(array $data, array $phrases, float $clipStart, float $clipEnd): array
    {
        $plan = ['key' => self::key($clipStart, $clipEnd), 'broll' => [], 'emoji' => [], 'punch' => []];

        foreach ((array) ($data['punchlines'] ?? []) as $i) {
            if (is_numeric($i) && isset($phrases[(int) $i])) {
                $p = $phrases[(int) $i];
                $plan['punch'][] = ['start' => $p['start'], 'end' => $p['end']];
            }
        }
        foreach (array_filter((array) ($data['broll'] ?? []), 'is_array') as $b) {
            $p = $phrases[(int) ($b['phrase'] ?? -1)] ?? null;
            $query = trim((string) ($b['query'] ?? ''));
            if ($p && $query !== '') {
                $plan['broll'][] = ['time' => self::anchor($p, (string) ($b['word'] ?? '')), 'end' => $p['end'] + self::BROLL_TAIL,
                    'query' => mb_substr($query, 0, 60), 'alt' => mb_substr(trim((string) ($b['alt'] ?? '')), 0, 40),
                    'must' => array_slice(array_values(array_filter((array) ($b['must'] ?? []), fn ($w) => is_string($w) && trim($w) !== '')), 0, 3)];
            }
        }
        foreach (array_filter((array) ($data['emoji'] ?? []), 'is_array') as $e) {
            $p = $phrases[(int) ($e['phrase'] ?? -1)] ?? null;
            $code = EmojiCues::codeFor((string) ($e['emoji'] ?? ''));
            if ($p && $code) {
                $plan['emoji'][] = ['code' => $code, 'time' => self::anchor($p, (string) ($e['word'] ?? ''))];
            }
        }

        return $plan;
    }

    /**
     * A stored plan moved onto the cut clip's timeline, with the placement rules
     * applied: nothing under the hook, B-roll never over a punchline, emoji never
     * while B-roll covers the speaker.
     *
     * @return array{broll: list<array{time: float, duration: float, query: string, alt: string, must: list<string>}>, emoji: list<array{code: string, start: float}>, punch: list<float>}
     */
    public static function onTimeline(array $plan, array $pieces, float $clipStart, float $duration, float $notBefore): array
    {
        $map = fn ($t) => SmartCut::mapTime($pieces, (float) $t - $clipStart);
        $inClip = fn ($t) => (float) $t >= $clipStart;

        $punches = [];
        foreach ((array) ($plan['punch'] ?? []) as $p) {
            if ($inClip($p['end'] ?? -1)) {
                $punches[] = [$map(max($clipStart, (float) $p['start'])), $map($p['end'])];
            }
        }

        $broll = [];
        foreach ((array) ($plan['broll'] ?? []) as $b) {
            if ($inClip($b['time'] ?? -1)) {
                $at = $map($b['time']);
                $broll[] = ['time' => $at, 'duration' => $map($b['end'] ?? $b['time']) - $at, 'query' => $b['query'] ?? '', 'alt' => $b['alt'] ?? '', 'must' => $b['must'] ?? []];
            }
        }
        $broll = BrollCues::filter($broll, $duration, $notBefore, $punches);

        $emoji = [];
        foreach ((array) ($plan['emoji'] ?? []) as $e) {
            if ($inClip($e['time'] ?? -1)) {
                $emoji[] = ['code' => (string) $e['code'], 'start' => $map($e['time'])];
            }
        }
        $covered = array_map(fn ($b) => [$b['time'] - 0.3, $b['time'] + $b['duration'] + 0.3], $broll);

        return [
            'broll' => $broll,
            'emoji' => EmojiCues::space($emoji, $duration, $notBefore, $covered),
            'punch' => array_values(array_filter(array_map(fn ($p) => round($p[1], 3), $punches), fn ($t) => $t >= $notBefore)),
        ];
    }

    /** When $word is said inside the phrase (its start), else the phrase start. */
    private static function anchor(array $phrase, string $word): float
    {
        $norm = fn (string $s) => mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $s));
        $want = $norm($word);
        if (mb_strlen($want) >= 2) {
            foreach ($phrase['words'] as $w) {
                $got = $norm($w['text']);
                // "masaknya" for "masak": a prefix counts once both sides are 3+ letters
                if ($got === $want || (min(mb_strlen($got), mb_strlen($want)) >= 3 && (str_starts_with($got, $want) || str_starts_with($want, $got)))) {
                    return $w['start'];
                }
            }
        }

        return $phrase['start'];
    }
}
