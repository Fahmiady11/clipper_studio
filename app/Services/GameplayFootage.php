<?php

namespace App\Services;

use App\Models\GameplayClip;
use App\Support\InvalidFootage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Looping "satisfying"/gameplay footage for the split-screen layout. Every
 * user has their own clips (uploaded in Settings) and only ever gets those.
 * Only upload clips you have the rights to publish.
 */
class GameplayFootage
{
    public const EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', 'mkv'];

    public const MAX_FILES = 10;

    public const MAX_KB = 204800; // 200 MB

    public const MIN_SECONDS = 3.0; // shorter loops back so fast it reads as a glitch

    public function __construct(private ?int $userId) {}

    public static function for(?int $userId): self
    {
        return new self($userId);
    }

    /** @return Collection<int, GameplayClip> this user's clips whose file is still on disk */
    public function clips(): Collection
    {
        if (! $this->userId) {
            return collect();
        }

        return GameplayClip::where('user_id', $this->userId)->orderBy('id')->get()
            ->filter(fn (GameplayClip $c) => $c->existsOnDisk())->values();
    }

    public function available(): bool
    {
        return $this->clips()->isNotEmpty();
    }

    public function remaining(): int
    {
        return max(0, self::MAX_FILES - ($this->userId ? GameplayClip::where('user_id', $this->userId)->count() : 0));
    }

    /**
     * A random clip and a random offset into it, leaving $needed seconds of
     * footage after the offset when the clip is long enough (shorter clips
     * are looped by the renderer, so any offset works).
     *
     * @return array{path: string, start: float}|null
     */
    public function pick(float $needed): ?array
    {
        $clips = $this->clips();
        if ($clips->isEmpty()) {
            return null;
        }
        $clip = $clips->random();

        $span = $clip->duration > $needed ? $clip->duration - $needed : $clip->duration;
        $start = $span > 0 ? mt_rand(0, (int) floor($span * 1000)) / 1000 : 0.0;

        return ['path' => $clip->absolutePath(), 'start' => round($start, 3)];
    }

    /**
     * Check the upload is real video and keep it under this user's folder.
     *
     * @throws InvalidFootage
     */
    public function store(UploadedFile $file, FFmpegService $ffmpeg): GameplayClip
    {
        if ($this->remaining() < 1) {
            throw new InvalidFootage('Batas '.self::MAX_FILES.' footage tercapai. Hapus salah satu dulu.');
        }

        $info = $ffmpeg->probeVideo($file->getRealPath());
        if (! $info) {
            throw new InvalidFootage('File ini bukan video yang bisa dibaca. Pakai MP4, MOV, WebM, atau MKV.');
        }
        if ($info['duration'] < self::MIN_SECONDS) {
            throw new InvalidFootage('Footage terlalu pendek, minimal '.(int) self::MIN_SECONDS.' detik.');
        }

        $size = $file->getSize();
        $ext = strtolower($file->getClientOriginalExtension());
        $name = (string) Str::uuid();
        $dir = 'gameplay/'.$this->userId;
        $path = $file->storeAs($dir, $name.'.'.$ext, 'local');

        $thumb = $dir.'/'.$name.'.jpg';
        $made = $ffmpeg->thumbnail(Storage::disk('local')->path($path), min(1.0, $info['duration'] / 2), Storage::disk('local')->path($thumb));

        return GameplayClip::create([
            'user_id' => $this->userId,
            'name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 140, '').'.'.$ext,
            'path' => $path,
            'thumb_path' => $made ? $thumb : null,
            'size_bytes' => $size,
            'duration' => $info['duration'],
            'width' => $info['width'],
            'height' => $info['height'],
        ]);
    }
}
