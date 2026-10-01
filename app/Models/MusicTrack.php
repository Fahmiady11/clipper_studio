<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MusicTrack extends Model
{
    public const LICENSES = ['cc-by', 'free'];

    protected $fillable = ['path', 'license', 'credit'];
}
