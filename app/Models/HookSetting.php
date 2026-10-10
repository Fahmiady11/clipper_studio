<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HookSetting extends Model
{
    protected $fillable = [
        'clip_project_id',
        'enabled',
        'hook_text',
        'is_ai_generated',
        'duration_seconds',
        'position',
        'text_color',
        'background_style',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_ai_generated' => 'boolean',
        'duration_seconds' => 'float',
    ];

    public function clipProject(): BelongsTo
    {
        return $this->belongsTo(ClipProject::class);
    }
}
