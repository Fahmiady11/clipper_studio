<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Meta login of a user. Facebook Login gives a long-lived user token,
 * from which each Page (and, in Stage 2, the Instagram account linked to it)
 * gets its own target with a Page token. Threads has a login of its own
 * (provider "threads") whose user token its single target uses directly.
 */
class MetaAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'name', 'access_token', 'token_expires_at', 'scopes'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(MetaTarget::class);
    }

    /** @return list<string> */
    public function grantedScopes(): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', (string) $this->scopes)));
    }
}
