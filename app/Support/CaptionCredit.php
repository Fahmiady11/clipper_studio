<?php

namespace App\Support;

use App\Models\ClipProject;

/**
 * Credits printed under a clip's caption: the CC BY music track and, for clips
 * cut from another creator's video, "Sumber: <channel>" with the video link.
 * A credit is never trimmed: when a platform limit bites, the body gives way.
 */
class CaptionCredit
{
    private const CHANNEL_MAX = 100;

    // Below this many characters the body is not worth keeping next to the hashtags
    private const MIN_BODY = 40;

    /** "Sumber: channel" plus the video link, or null when the project has no known source channel. */
    public static function source(?ClipProject $project): ?string
    {
        $channel = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', (string) $project?->source_channel));
        if ($channel === '') {
            return null;
        }
        $line = 'Sumber: '.mb_substr($channel, 0, self::CHANNEL_MAX);

        return $project->youtube_url ? $line."\n".$project->youtube_url : $line;
    }

    /** The credit lines for a clip: music first, then the source. Null when there is nothing to credit. */
    public static function block(?string $music, ?ClipProject $project): ?string
    {
        $lines = array_filter([$music ? "🎵 {$music}" : null, self::source($project)]);

        return $lines ? implode("\n", $lines) : null;
    }

    public static function append(string $caption, ?string $block): string
    {
        return $block ? rtrim($caption)."\n\n".$block : $caption;
    }

    /**
     * Body, credit and hashtags as one post text within $max characters (or bytes).
     * Over the limit the body is cut at a word first; the hashtags go only when
     * even a short body would not fit beside them. The credit always stays.
     */
    public static function compose(string $body, ?string $credit, string $tags, int $max, bool $bytes = false): string
    {
        $len = fn (string $s) => $bytes ? strlen($s) : mb_strlen($s);
        $join = fn (string $b, string $t) => trim(implode("\n\n", array_filter([trim($b), $credit, trim($t)], fn ($p) => $p !== null && $p !== '')));

        $full = $join($body, $tags);
        if ($len($full) <= $max) {
            return $full;
        }

        foreach ([$tags, ''] as $keep) {
            $room = $max - $len($join('', $keep)) - 2; // 2: blank line between the body and the rest
            if ($room >= self::MIN_BODY || ($keep === '' && $room > 0)) {
                return $join(self::cut(trim($body), $room, $bytes), $keep);
            }
        }

        $credit = $join('', '');

        return $bytes ? mb_strcut($credit, 0, $max) : mb_substr($credit, 0, $max);
    }

    /** Cut at a word boundary with an ellipsis, never inside a character. */
    private static function cut(string $text, int $room, bool $bytes): string
    {
        if (($bytes ? strlen($text) : mb_strlen($text)) <= $room) {
            return $text;
        }
        $cut = $bytes ? mb_strcut($text, 0, max($room - 3, 0)) : mb_substr($text, 0, max($room - 1, 0));
        $space = mb_strrpos($cut, ' ');

        return rtrim($space > 0 ? mb_substr($cut, 0, $space) : $cut).'…';
    }
}
