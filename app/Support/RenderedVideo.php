<?php

namespace App\Support;

use App\Models\GeneratedClip;
use App\Services\FFmpegService;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Length, frame size and file size of a rendered clip, for the platforms' upload rules. */
final class RenderedVideo
{
    public function __construct(
        public readonly float $seconds,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly int $bytes,
    ) {}

    /** A file ffprobe can't read (or a test stub) is judged by its clip length, without a frame size. */
    public static function of(GeneratedClip $clip): self
    {
        $path = Storage::path($clip->output_path);
        $video = null;
        try {
            $video = app(FFmpegService::class)->probeVideo($path);
        } catch (Throwable) {
        }

        return new self(
            $video['duration'] ?? $clip->durationSeconds(),
            $video['width'] ?? null,
            $video['height'] ?? null,
            (int) (@filesize($path) ?: 0),
        );
    }

    public function hasFrame(): bool
    {
        return $this->width !== null && $this->height !== null;
    }

    public function aspect(): float
    {
        return $this->width / max(1, $this->height);
    }

    public function frame(): string
    {
        return "{$this->width}×{$this->height}";
    }

    public function megabytes(): int
    {
        return (int) ceil($this->bytes / 1048576);
    }
}
