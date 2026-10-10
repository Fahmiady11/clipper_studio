<?php

namespace App\Services;

use App\Models\MusicTrack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Background-music library on disk: one folder per mood, e.g.
 * storage/app/music/energetic/track.mp3. Only use tracks you have the
 * rights to publish (royalty-free / licensed).
 */
class MusicService
{
    public const MOODS = ['energetic', 'chill', 'inspiring', 'dramatic', 'funny', 'sad'];

    public const EXTENSIONS = ['mp3', 'm4a', 'aac', 'wav', 'ogg'];

    /** Served Content-Type per extension; never sniffed from the (user-uploaded) bytes. */
    public const MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
    ];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? config('services.music.path', storage_path('app/music')), '/');
    }

    public static function normalizeMood(?string $mood): ?string
    {
        $mood = strtolower(trim((string) $mood));

        return in_array($mood, self::MOODS, true) ? $mood : null;
    }

    /**
     * Pick a track for the mood; falls back to the whole library when that
     * mood's folder is empty. $seed (e.g. the clip id) keeps the choice stable
     * across re-renders while spreading tracks over different clips.
     */
    public function pickTrack(?string $mood, string $seed): ?string
    {
        $tracks = $mood ? $this->tracksIn($this->root.'/'.$mood) : [];
        if (empty($tracks)) {
            $tracks = [];
            foreach (self::MOODS as $m) {
                array_push($tracks, ...$this->tracksIn($this->root.'/'.$m));
            }
            array_push($tracks, ...$this->tracksIn($this->root));
        }
        if (empty($tracks)) {
            return null;
        }

        sort($tracks);

        return $tracks[crc32($seed) % count($tracks)];
    }

    /** Library root path, e.g. storage/app/music. */
    public function root(): string
    {
        return $this->root;
    }

    /** Path stored on a clip/credit row: "chill/song.mp3" (or "song.mp3" at the root). */
    public function relative(string $absolute): string
    {
        // Accept the root as configured or resolved (/var vs /private/var on macOS)
        foreach (array_filter([$this->root, realpath($this->root)]) as $root) {
            if (str_starts_with($absolute, $root.'/')) {
                return substr($absolute, strlen($root) + 1);
            }
        }

        return ltrim(basename($absolute), '/');
    }

    /**
     * Every track on disk with its credit row (if any), grouped mood first.
     *
     * @return list<array{path: string, mood: ?string, name: string, size: int, license: ?string, credit: ?string}>
     */
    public function library(): array
    {
        $credits = MusicTrack::all()->keyBy('path');
        $out = [];
        foreach ([...self::MOODS, null] as $mood) {
            foreach ($this->tracksIn($mood ? "{$this->root}/{$mood}" : $this->root) as $file) {
                $rel = $this->relative($file);
                $row = $credits->get($rel);
                $out[] = [
                    'path' => $rel, 'mood' => $mood, 'name' => basename($file), 'size' => filesize($file),
                    'license' => $row?->license, 'credit' => $row?->credit,
                ];
            }
        }

        return $out;
    }

    /** Store an uploaded file in the mood's folder (never overwriting) and return its relative path. */
    public function store(UploadedFile $file, ?string $mood): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'track';
        $dir = $mood ? "{$this->root}/{$mood}" : $this->root;
        @mkdir($dir, 0755, true);

        $name = "{$base}.{$ext}";
        for ($n = 2; file_exists("{$dir}/{$name}"); $n++) {
            $name = "{$base}-{$n}.{$ext}";
        }
        $file->move($dir, $name);

        return $this->relative("{$dir}/{$name}");
    }

    /** Move a track to another mood folder; its credit row follows. Returns the new relative path. */
    public function move(string $path, ?string $mood): string
    {
        $from = $this->absolute($path);
        $dir = $mood ? "{$this->root}/{$mood}" : $this->root;
        @mkdir($dir, 0755, true);

        $name = basename($from);
        $info = pathinfo($name);
        for ($n = 2; file_exists("{$dir}/{$name}") && "{$dir}/{$name}" !== $from; $n++) {
            $name = "{$info['filename']}-{$n}.{$info['extension']}";
        }
        rename($from, "{$dir}/{$name}");

        $to = $this->relative("{$dir}/{$name}");
        MusicTrack::where('path', $path)->update(['path' => $to]);

        return $to;
    }

    public function delete(string $path): void
    {
        @unlink($this->absolute($path));
        MusicTrack::where('path', $path)->delete();
    }

    /** Credit to print in captions for a clip's track, when it has one. */
    public function creditFor(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $credit = MusicTrack::where('path', $path)->value('credit');

        return $credit ? trim($credit) : null;
    }

    /**
     * Resolve a relative library path to an existing file inside the library.
     *
     * @throws InvalidArgumentException for anything outside the library (e.g. "../.env")
     */
    public function absolute(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Lagu tidak ditemukan di library.');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $real = realpath($this->root.'/'.$path);
        $root = realpath($this->root);

        if (! $real || ! $root || ! str_starts_with($real, $root.'/') || ! in_array($ext, self::EXTENSIONS, true) || ! is_file($real)) {
            throw new InvalidArgumentException('Lagu tidak ditemukan di library.');
        }

        return $real;
    }

    /** True when ffprobe finds an audio stream (rejects renamed non-audio files). */
    public function isAudio(string $file): bool
    {
        $probe = preg_replace('/ffmpeg(\.exe)?$/', 'ffprobe$1', config('services.ffmpeg.path', 'ffmpeg'));
        exec(sprintf('%s -v error -select_streams a -show_entries stream=codec_type -of csv=p=0 %s 2>/dev/null',
            $probe, escapeshellarg($file)), $out, $code);

        return $code === 0 && in_array('audio', array_map('trim', $out), true);
    }

    private function tracksIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, self::EXTENSIONS, true) && is_file("{$dir}/{$f}")) {
                $files[] = "{$dir}/{$f}";
            }
        }

        return $files;
    }
}
