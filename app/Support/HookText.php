<?php

namespace App\Support;

/**
 * Hook text as shown on screen. Words wrapped in *asterisks* are emphasised
 * (drawn in the highlight colour) by HookRenderer and the studio preview.
 */
final class HookText
{
    /** AI hooks longer than this are cut back (the prompt asks for ≤ 5 words / 30 chars). */
    public const AI_MAX_WORDS = 5;
    public const AI_MAX_CHARS = 36;

    // Words a cut hook must not end on ("Tour paling aneh di…" → "Tour paling aneh…")
    private const DANGLING = ['di', 'ke', 'dari', 'yang', 'dan', 'atau', 'untuk', 'dengan', 'pada', 'si', 'para', 'buat',
        'sama', 'karena', 'kalau', 'tapi', 'the', 'a', 'an', 'of', 'in', 'on', 'to', 'for', 'and', 'or', 'with', 'at'];

    /**
     * Tidy hook text for rendering. With $shorten (AI output), an over-long hook
     * is cut at a clause/word boundary instead of mid-word.
     */
    public static function clean(?string $text, bool $shorten = false): ?string
    {
        if ($text === null) {
            return null;
        }

        // Emoji/symbols have no glyph in the bundled fonts (they render as boxes)
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\p{P}\p{Zs}\p{Sc}\p{Sm}]/u', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        // The model sometimes wraps the whole hook in quotes
        $text = trim(preg_replace('/^["“”\'‘’]+|["“”\'‘’]+$/u', '', $text));
        // Drop a stray trailing full stop; ?, ! and … carry meaning
        $text = trim(preg_replace('/(?<!\.)\.$/u', '', $text));

        if ($shorten && (self::wordCount($text) > self::AI_MAX_WORDS || mb_strlen(self::plain($text)) > self::AI_MAX_CHARS)) {
            $text = self::shorten($text, self::AI_MAX_CHARS, self::AI_MAX_WORDS);
        }

        return $text === '' ? null : $text;
    }

    /** Text without emphasis markers (for captions, logs, width maths). */
    public static function plain(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('*', '', $text)));
    }

    /**
     * Split into words, each flagged when it sits inside *…*.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function tokens(string $text): array
    {
        $tokens = [];
        $inside = false;

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $opens  = str_starts_with($word, '*');
            $closes = mb_strlen($word) > 1 && str_ends_with($word, '*');
            $clean  = str_replace('*', '', $word);

            if ($clean !== '') {
                $tokens[] = [$clean, $inside || $opens];
            }
            if ($opens && !$closes) {
                $inside = true;
            } elseif ($closes) {
                $inside = false;
            }
        }

        return $tokens;
    }

    private static function capitalised(string $word): bool
    {
        return (bool) preg_match('/^\*?\p{Lu}/u', $word);
    }

    public static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', self::plain($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    private static function shorten(string $text, int $max, int $maxWords): string
    {
        $words = preg_split('/\s+/u', $text);
        $kept  = [];
        $best  = null; // longest prefix that ends a clause (?, !, ,, :, —)

        foreach ($words as $word) {
            $candidate = implode(' ', [...$kept, $word]);
            if (count($kept) >= $maxWords || mb_strlen(self::plain($candidate)) > $max) {
                break;
            }
            $kept = [...$kept, $word];
            if (preg_match('/[?!,:;—]\*?$/u', $word)) {
                $best = $kept;
            }
        }

        if ($best && count($best) >= 2) {
            $cut = $best;
        } else {
            $cut  = $kept;
            $next = $words[count($kept)] ?? '';
            // Don't split a name ("New | York"): drop its first half too
            if ($cut && self::capitalised($next) && self::capitalised(end($cut))) {
                array_pop($cut);
            }
            while (count($cut) > 2 && in_array(mb_strtolower(trim(end($cut), '*')), self::DANGLING, true)) {
                array_pop($cut);
            }
        }
        $out = rtrim(implode(' ', $cut ?: [$words[0]]), ' ,:;—');

        // Close an emphasis left open by the cut
        if (substr_count($out, '*') % 2 === 1) {
            $out .= '*';
        }

        return preg_match('/[?!]$/u', $out) ? $out : $out . '…';
    }
}
