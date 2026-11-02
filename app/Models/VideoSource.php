<?php

namespace App\Models;

use App\Support\ClipSettings;
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
        'style_preset_id',
        'last_run_at',
        'last_error',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'interval_hours' => 'integer',
        'max_per_run' => 'integer',
        'min_video_seconds' => 'integer',
        'max_video_seconds' => 'integer',
        'style_preset_id' => 'integer',
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

    public function stylePreset(): BelongsTo
    {
        return $this->belongsTo(StylePreset::class);
    }

    /**
     * Settings for this source's next project. A linked preset gives its
     * current look; the source's own settings keep count, length, instruction
     * and the look it falls back to when the preset is deleted.
     */
    public function clipSettings(): array
    {
        $preset = $this->style_preset_id ? StylePreset::findVisible($this->style_preset_id, $this->user_id) : null;
        $kit = $preset ? BrandKit::where('user_id', $this->user_id)->first() : null;

        return ClipSettings::forAutopilot($preset ? array_replace_recursive($this->settings ?? [], $preset->look($kit)) : $this->settings);
    }

    public function isDue(): bool
    {
        return $this->enabled
            && (! $this->last_run_at || $this->last_run_at->addHours($this->interval_hours)->isPast());
    }
}
