<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtitleSetting extends Model
{
    protected $fillable = [
        'clip_project_id',
        'enabled',
        'font_family',
        'font_size',
        'text_color',
        'highlight_color',
        'position',
        'position_y',
        'background_style',
    ];

    protected $casts = [
        'enabled'   => 'boolean',
        'position_y' => 'float',
        'font_size' => 'integer',
    ];

    public function clipProject(): BelongsTo
    {
        return $this->belongsTo(ClipProject::class);
    }
}
