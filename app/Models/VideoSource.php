<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoSource extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'value',
        'enabled',
        'interval_hours',
        'max_per_run',
        'min_video_seconds',
        'max_video_seconds',
        'settings',
        'last_run_at',
        'last_error',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'interval_hours' => 'integer',
        'max_per_run' => 'integer',
        'min_video_seconds' => 'integer',
        'max_video_seconds' => 'integer',
        'settings' => 'array',
        'last_run_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discoveredVideos(): HasMany
    {
        return $this->hasMany(DiscoveredVideo::class);
    }

    public function isDue(): bool
    {
        return $this->enabled
            && (! $this->last_run_at || $this->last_run_at->addHours($this->interval_hours)->isPast());
    }
}
