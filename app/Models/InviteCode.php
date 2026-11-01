<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InviteCode extends Model
{
    protected $fillable = ['code', 'note', 'max_uses', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function generate(int $maxUses = 1, ?int $days = null, ?string $note = null): self
    {
        return self::create([
            'code' => Str::upper(Str::random(4).'-'.Str::random(4)),
            'note' => $note,
            'max_uses' => $maxUses,
            'expires_at' => $days ? now()->addDays($days) : null,
        ]);
    }

    /** Takes one use atomically; false when the code is unknown, used up or expired. */
    public static function redeem(string $code): ?self
    {
        $invite = self::where('code', Str::upper(trim($code)))->first();
        if (! $invite) {
            return null;
        }

        $taken = self::whereKey($invite->id)
            ->whereColumn('uses', '<', 'max_uses')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->increment('uses');

        return $taken ? $invite : null;
    }
}
