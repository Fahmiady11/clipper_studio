<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveredVideo extends Model
{
    protected $fillable = [
        'user_id',
        'video_source_id',
        'video_id',
        'clip_project_id',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(VideoSource::class, 'video_source_id');
    }
}
