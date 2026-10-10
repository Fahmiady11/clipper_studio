<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiktokAccount extends Model
{
    protected $fillable = [
        'user_id',
        'open_id',
        'display_name',
        'avatar_url',
        'access_token',
        'refresh_token',
        'access_expires_at',
        'refresh_expires_at',
        'scope',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
    ];

    /** Unknown scope (TikTok didn't report it) counts as granted; the API will say otherwise. */
    public function hasScope(string $scope): bool
    {
        return $this->scope === null
            || in_array($scope, preg_split('/[\s,]+/', $this->scope), true);
    }

    public static function scopeMessage(string $scope): string
    {
        return "Akun TikTok ini belum memberi izin {$scope}. Aktifkan scope {$scope} di app TikTok (dan di TIKTOK_SCOPES), lalu hubungkan ulang akun.";
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
