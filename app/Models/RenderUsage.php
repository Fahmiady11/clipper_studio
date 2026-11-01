<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenderUsage extends Model
{
    protected $fillable = ['user_id', 'generated_clip_id', 'period', 'status', 'seconds', 'cpu_seconds', 'cost_usd'];
}
