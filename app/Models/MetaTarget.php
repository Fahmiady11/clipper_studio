<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place clips are published to under a Meta login: a Facebook Page now,
 * an Instagram professional account or a Threads profile later. Scheduled
 * posts and autopost settings of those platforms point at this id.
 */
class MetaTarget extends Model
{
    public const PLATFORMS = ['facebook', 'instagram', 'threads'];

    protected $fillable = ['user_id', 'meta_account_id', 'platform', 'external_id', 'name', 'username', 'picture_url', 'access_token', 'details'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'details' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAccount::class, 'meta_account_id');
    }

    public function scopeOwnedBy(Builder $query, int $userId, string $platform): Builder
    {
        return $query->where('user_id', $userId)->where('platform', $platform);
    }

    /** The token calls for this target are made with: its own (a Page token), else its login's. */
    public function token(): string
    {
        return $this->access_token ?: $this->account->access_token;
    }
}
