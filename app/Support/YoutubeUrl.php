<?php

namespace App\Support;

/**
 * Strict parsing of user-supplied YouTube links.
 *
 * Links end up as yt-dlp arguments, so anything that is not plainly a YouTube
 * video/channel must be refused: a loose regex let "--exec=… #youtube.com/watch?v=x"
 * through, and yt-dlp reads a leading "--" as an option (escapeshellarg does not
 * help with that). We only ever hand yt-dlp a URL we rebuilt ourselves.
 */
final class YoutubeUrl
{
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'];

    /** The 11-character video id, or null when $url is not a YouTube video link. */
    public static function videoId(?string $url): ?string
    {
        $parts = self::parse($url);
        if (! $parts) {
            return null;
        }
        [$host, $path, $query] = $parts;

        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($path, '/'))[0] ?? '';
        } elseif (in_array($host, self::HOSTS, true)) {
            if ($path === '/watch') {
                parse_str($query, $q);
                $id = is_string($q['v'] ?? null) ? $q['v'] : '';
            } elseif (preg_match('#^/(?:shorts|embed|live|v)/([^/?]+)#', $path, $m)) {
                $id = $m[1];
            } else {
                return null;
            }
        } else {
            return null;
        }

        // D: "$" would otherwise also match before a trailing "\n" (from ?v=…%0A)
        return preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? $id : null;
    }

    /** Canonical https://www.youtube.com/watch?v=… for a video link, else null. */
    public static function canonical(?string $url): ?string
    {
        $id = self::videoId($url);

        return $id ? "https://www.youtube.com/watch?v={$id}" : null;
    }

    /**
     * Channel URL for an autopilot source: "@handle", or a youtube.com link to
     * /@handle, /channel/UC…, /c/name or /user/name. Null for anything else.
     */
    public static function channel(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^@?([A-Za-z0-9._-]{3,100})$/D', $value, $m)) {
            return "https://www.youtube.com/@{$m[1]}";
        }

        $parts = self::parse($value);
        if (! $parts || ! in_array($parts[0], self::HOSTS, true)) {
            return null;
        }
        if (preg_match('#^/(@[A-Za-z0-9._-]{3,100}|channel/UC[A-Za-z0-9_-]{20,30}|c/[A-Za-z0-9._-]{1,100}|user/[A-Za-z0-9._-]{1,100})(?:/(?:videos|streams|shorts))?/?$#D', $parts[1], $m) && ! str_contains($parts[1], '/.')) {
            return "https://www.youtube.com/{$m[1]}";
        }

        return null;
    }

    /** @return array{0: string, 1: string, 2: string}|null host, path, query */
    private static function parse(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 500 || preg_match('/\s/', $url)) {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url; // "youtu.be/…" pasted without a scheme
        }

        $p = parse_url($url);
        if (! $p || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return null;
        }

        return [strtolower($p['host']), $p['path'] ?? '/', $p['query'] ?? ''];
    }
}
