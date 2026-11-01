<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where a clip is on its way to one platform. YouTube and TikTok still keep this on the clip itself. */
class ClipPublication extends Model
{
    // Autopilot could not book a slot; error says why. Nothing was sent
    public const SKIPPED = 'skipped';

    public const BUSY = ['queued', 'uploading', 'processing'];

    protected $fillable = ['generated_clip_id', 'platform', 'target_id', 'status', 'external_id', 'container_id', 'url', 'error', 'uploaded_at', 'checked_at'];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(GeneratedClip::class, 'generated_clip_id');
    }
}
