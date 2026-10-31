<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClipStat extends Model
{
    protected $fillable = [
        'generated_clip_id',
        'platform',
        'views',
        'likes',
        'comments',
        'shares',
        'collected_at',
    ];

    protected $casts = [
        'views' => 'integer',
        'likes' => 'integer',
        'comments' => 'integer',
        'shares' => 'integer',
        'collected_at' => 'datetime',
    ];

    public function generatedClip(): BelongsTo
    {
        return $this->belongsTo(GeneratedClip::class);
    }
}
