<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ClipProject extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'video_source_id',
        'youtube_url',
        'video_title',
        'duration_seconds',
        'thumbnail_url',
        'layout_type',
        'jump_cut',
        'auto_zoom',
        'progress_bar',
        'top_title',
        'broll',
        'clip_count',
        'duration_mode',
        'min_duration',
        'max_duration',
        'music_enabled',
        'music_mood',
        'music_volume',
        'status',
        'progress_stage',
        'progress_message',
        'error_msg',
        'cancelled_at',
    ];

    protected $casts = [
        'clip_count' => 'integer',
        'duration_seconds' => 'integer',
        'min_duration' => 'integer',
        'max_duration' => 'integer',
        'music_enabled' => 'boolean',
        'jump_cut' => 'boolean',
        'auto_zoom' => 'boolean',
        'progress_bar' => 'boolean',
        'top_title' => 'boolean',
        'broll' => 'boolean',
        'music_volume' => 'integer',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoSource(): BelongsTo
    {
        return $this->belongsTo(VideoSource::class);
    }

    public function subtitleSetting(): HasOne
    {
        return $this->hasOne(SubtitleSetting::class);
    }

    public function hookSetting(): HasOne
    {
        return $this->hasOne(HookSetting::class);
    }

    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class);
    }

    public function generatedClips(): HasMany
    {
        return $this->hasMany(GeneratedClip::class)->orderBy('ranking');
    }
}
