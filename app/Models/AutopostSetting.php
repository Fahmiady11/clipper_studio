<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Autopilot upload slots of one user on one platform. account_id is that platform's account (see Autopost). */
class AutopostSetting extends Model
{
    public const DEFAULT_SLOTS = ['09:00', '15:00', '20:00'];

    protected $fillable = ['user_id', 'platform', 'account_id', 'enabled', 'max_per_day', 'slot_times', 'timezone'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_per_day' => 'integer',
            'account_id' => 'integer',
            'slot_times' => 'array',
        ];
    }

    /** The saved row, or unsaved defaults for a user who never opened the panel. */
    public static function for(int $userId, string $platform): self
    {
        return self::firstOrNew(['user_id' => $userId, 'platform' => $platform], [
            'enabled' => false,
            'max_per_day' => 3,
            'slot_times' => self::DEFAULT_SLOTS,
            'timezone' => 'Asia/Jakarta',
        ]);
    }
}
