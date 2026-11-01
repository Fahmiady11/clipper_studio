<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GameplayClip extends Model
{
    protected $fillable = ['user_id', 'name', 'path', 'thumb_path', 'size_bytes', 'duration', 'width', 'height'];

    protected $casts = [
        'size_bytes' => 'integer',
        'duration' => 'float',
        'width' => 'integer',
        'height' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $clip) {
            Storage::disk('local')->delete(array_filter([$clip->path, $clip->thumb_path]));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->path);
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk('local')->exists($this->path);
    }
}
