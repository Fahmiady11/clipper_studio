<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One user's settings for one publishing platform, managed on Settings → Akun terhubung:
 * account_id is the default account (Studio, calendar and autopilot publish there),
 * enabled switches autopost on (for TikTok: send to the inbox when a clip is approved),
 * max_per_day and slot_times are the autopost slots. account_id may point at a
 * disconnected account; ConnectedAccounts::default() then falls back to the first one.
 * tiktok_mode is read on the tiktok row only: 'inbox' (draft in the TikTok app) or 'direct'.
 */
class PlatformSetting extends Model
{
    public const DEFAULT_SLOTS = ['09:00', '15:00', '20:00'];

    public const TIKTOK_MODES = ['inbox', 'direct'];

    protected $fillable = ['user_id', 'platform', 'account_id', 'enabled', 'max_per_day', 'slot_times', 'timezone', 'tiktok_mode'];

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
            'tiktok_mode' => 'inbox',
        ]);
    }

    /** Anything but 'direct' is inbox, so an unknown or empty value never posts publicly. */
    public function tiktokMode(): string
    {
        return $this->tiktok_mode === 'direct' ? 'direct' : 'inbox';
    }
}
