<?php

namespace App\Services;

/**
 * Looping "satisfying"/gameplay footage for the split-screen layout, from a
 * folder the user fills themselves (storage/app/gameplay/*.mp4). Nothing is
 * downloaded: only use clips you have the rights to publish.
 */
class GameplayFootage
{
    public const EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', 'mkv'];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? config('services.gameplay.path', storage_path('app/gameplay')), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<string> absolute paths, sorted */
    public function files(): array
    {
        $files = [];
        foreach (is_dir($this->root) ? (scandir($this->root) ?: []) : [] as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if ($f[0] !== '.' && in_array($ext, self::EXTENSIONS, true) && is_file($this->root.'/'.$f)) {
                $files[] = $this->root.'/'.$f;
            }
        }
        sort($files);

        return $files;
    }

    public function available(): bool
    {
        return $this->files() !== [];
    }

    /**
     * A random clip and a random offset into it, leaving $needed seconds of
     * footage after the offset when the clip is long enough (shorter clips
     * are looped by the renderer, so any offset works).
     *
     * @return array{path: string, start: float}|null
     */
    public function pick(float $needed, ?FFmpegService $ffmpeg = null): ?array
    {
        $files = $this->files();
        if (! $files) {
            return null;
        }
        $path = $files[array_rand($files)];

        try {
            $length = ($ffmpeg ?? new FFmpegService)->probeDuration($path);
        } catch (\RuntimeException) {
            $length = 0.0; // unreadable duration: start from the top, the loop still fills the length
        }
        $span = $length > $needed ? $length - $needed : $length;
        $start = $span > 0 ? mt_rand(0, (int) floor($span * 1000)) / 1000 : 0.0;

        return ['path' => $path, 'start' => round($start, 3)];
    }
}
