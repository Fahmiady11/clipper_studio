<?php

namespace App\Services;

use App\Support\Cancellation;
use RuntimeException;

class YtDlpService
{
    private string $bin;

    private string $cookieArg;

    public function __construct()
    {
        $this->bin = config('services.ytdlp.path', 'yt-dlp');

        // Browser cookies (YTDLP_COOKIES_BROWSER) are always fresh; prefer them.
        // Fall back to cookies file (YTDLP_COOKIES) only when no browser is set.
        $cookieBrowser = config('services.ytdlp.cookies_from_browser', '');
        $cookieFile = config('services.ytdlp.cookies_file', '');

        if ($cookieBrowser) {
            $this->cookieArg = ' --cookies-from-browser '.escapeshellarg($cookieBrowser);
        } elseif ($cookieFile && file_exists($cookieFile)) {
            $this->cookieArg = ' --cookies '.escapeshellarg($cookieFile);
        } else {
            $this->cookieArg = '';
        }
    }

    public function getMetadata(string $url): array
    {
        set_time_limit(0); // yt-dlp exec wall-time counts against PHP limit on macOS
        // "--": whatever follows is a URL, never an option (blocks --exec style injection)
        $cmd = sprintf('%s%s --dump-json --no-playlist -- %s 2>&1', $this->bin, $this->cookieArg, escapeshellarg($url));
        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('yt-dlp metadata failed: '.implode("\n", $output));
        }

        $jsonLine = collect($output)->first(fn ($l) => str_starts_with(trim($l), '{'));
        $json = $jsonLine ? json_decode($jsonLine, true) : null;
        if (! $json) {
            throw new RuntimeException('yt-dlp returned invalid JSON. Output: '.implode("\n", $output));
        }

        return [
            'title' => $json['title'] ?? 'Unknown',
            'duration_seconds' => (int) ($json['duration'] ?? 0),
            'thumbnail_url' => $json['thumbnail'] ?? null,
            'channel' => $json['uploader'] ?? $json['channel'] ?? 'Unknown',
            'language' => $json['language'] ?? 'id',
        ];
    }

    /**
     * List candidate videos without downloading: a channel's latest uploads or
     * the newest search results for a keyword.
     *
     * @return array<int, array{id: string, title: ?string, channel: ?string, duration: ?int}>
     */
    public function listVideos(string $type, string $value, int $limit = 20): array
    {
        set_time_limit(0);

        $target = $type === 'keyword'
            ? "ytsearchdate{$limit}:{$value}"
            : self::channelVideosUrl($value);

        $cmd = sprintf(
            '%s%s --flat-playlist --dump-json --playlist-end %d --no-warnings -- %s 2>&1',
            $this->bin,
            $this->cookieArg,
            $limit,
            escapeshellarg($target)
        );
        exec($cmd, $output, $code);

        $videos = [];
        foreach ($output as $line) {
            $json = str_starts_with(trim($line), '{') ? json_decode($line, true) : null;
            if (! $json || empty($json['id']) || ($json['live_status'] ?? null) === 'is_upcoming') {
                continue;
            }
            $videos[] = [
                'id' => $json['id'],
                'title' => $json['title'] ?? null,
                'channel' => $json['channel'] ?? $json['uploader'] ?? null,
                'duration' => isset($json['duration']) ? (int) $json['duration'] : null,
            ];
        }

        if ($code !== 0 && empty($videos)) {
            throw new RuntimeException('yt-dlp listing failed: '.implode("\n", array_slice($output, -5)));
        }

        return $videos;
    }

    /** Accepts a channel URL or a bare @handle; returns its /videos tab URL. */
    public static function channelVideosUrl(string $value): string
    {
        $value = trim($value);
        if (! str_starts_with($value, 'http')) {
            $value = 'https://www.youtube.com/'.(str_starts_with($value, '@') ? $value : '@'.$value);
        }
        $value = rtrim($value, '/');

        return preg_match('#/(videos|streams|shorts)$#', $value) ? $value : $value.'/videos';
    }

    public function download(string $url, string $outPath): void
    {
        set_time_limit(0);

        $videoId = $this->extractVideoId($url);

        // Serve from cache if the same video was already downloaded
        if ($videoId) {
            $cached = $this->cachedPath($videoId);
            if (file_exists($cached) && filesize($cached) > 1024 * 1024) {
                if (! is_dir(dirname($outPath))) {
                    @mkdir(dirname($outPath), 0755, true);
                }
                // Hard-link to avoid duplicating disk space; fall back to copy
                if (! @link($cached, $outPath)) {
                    copy($cached, $outPath);
                }

                return;
            }
        }

        $dir = dirname($outPath);
        $base = pathinfo($outPath, PATHINFO_FILENAME);

        // Remove stale partial/intermediate files from any previous failed attempt
        foreach (glob("{$dir}/{$base}.f*") ?: [] as $stale) {
            @unlink($stale);
        }
        if (file_exists($outPath)) {
            @unlink($outPath);
        }

        $cmd = sprintf(
            '%s%s'
            // 1080p: the 9:16 reframe crops a 607px-wide strip out of a 1080p
            // frame (only 405px from 720p, which upscaled visibly blurry).
            // Prefer H264, fall back to VP9/AV1 at the same height.
            .' -f "bestvideo[vcodec^=avc1][height<=1080]+bestaudio[ext=m4a]'
            .'/bestvideo[height<=1080]+bestaudio'
            .'/best[height<=1080]/bestvideo+bestaudio/best"'
            .' --concurrent-fragments 4'  // bypass YouTube per-connection throttle
            .' --merge-output-format mp4'
            .' --no-continue'
            .' --force-overwrites'
            .' --no-part'
            .' -o %s'
            .' -- %s'
            .' 2>&1',
            $this->bin,
            $this->cookieArg,
            escapeshellarg($outPath),
            escapeshellarg($url)
        );

        [$code, $output] = Cancellation::exec($cmd);

        if ($code !== 0) {
            throw new RuntimeException('yt-dlp download failed: '.implode("\n", $output));
        }

        if (! file_exists($outPath)) {
            throw new RuntimeException('yt-dlp finished but output file not found: '.$outPath);
        }

        // Store in cache for future projects using the same URL
        if ($videoId) {
            $cached = $this->cachedPath($videoId);
            @mkdir(dirname($cached), 0755, true);
            @link($outPath, $cached) || @copy($outPath, $cached);
        }
    }

    private function cachedPath(string $videoId): string
    {
        // Suffix = download quality; bumping it skips sources cached at the old 720p cap.
        return storage_path('app/video_cache/'.$videoId.'-1080p.mp4');
    }

    private function extractVideoId(string $url): ?string
    {
        if (preg_match('/(?:watch\?v=|youtu\.be\/|shorts\/|embed\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
            return $m[1];
        }

        return null;
    }
}
