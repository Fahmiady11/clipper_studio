<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YoutubeAutopostSetting extends Model
{
    public const DEFAULT_SLOTS = ['09:00', '15:00', '20:00'];

    protected $fillable = ['user_id', 'youtube_account_id', 'enabled', 'max_per_day', 'slot_times', 'timezone'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_per_day' => 'integer',
            'slot_times' => 'array',
        ];
    }

    /** The saved row, or unsaved defaults for a user who never opened the panel. */
    public static function for(int $userId): self
    {
        return self::firstOrNew(['user_id' => $userId], [
            'enabled' => false,
            'max_per_day' => 3,
            'slot_times' => self::DEFAULT_SLOTS,
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(YoutubeAccount::class, 'youtube_account_id');
    }
}
