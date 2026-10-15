<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GeneratedClip extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'clip_project_id',
        'ranking',
        'start_seconds',
        'end_seconds',
        'topic',
        'reason',
        'viral_potential',
        'hook_text',
        'hook_text_override',
        'subtitle_json',
        'caption',
        'hashtags_json',
        'broll_json',
        'music_mood',
        'music_track',
        'tiktok_account_id',
        'tiktok_publish_id',
        'tiktok_status',
        'tiktok_error',
        'output_path',
        'status',
        'review_status',
        'error_msg',
        'cancelled_at',
    ];

    protected $casts = [
        'ranking' => 'integer',
        'start_seconds' => 'float',
        'end_seconds' => 'float',
        'subtitle_json' => 'array',
        'hashtags_json' => 'array',
        'broll_json' => 'array',
        'cancelled_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function clipProject(): BelongsTo
    {
        return $this->belongsTo(ClipProject::class);
    }

    public function durationSeconds(): float
    {
        return $this->end_seconds - $this->start_seconds;
    }
}
