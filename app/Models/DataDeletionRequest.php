<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A Meta data deletion callback we handled; its confirmation code opens the public status page. */
class DataDeletionRequest extends Model
{
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = ['confirmation_code', 'provider', 'status', 'connections_removed', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public static function newCode(): string
    {
        do {
            $code = strtoupper(Str::random(16));
        } while (self::where('confirmation_code', $code)->exists());

        return $code;
    }
}
