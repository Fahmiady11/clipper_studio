<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    public const PREFIX = 'clp_';

    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** Only the SHA-256 is stored, so the plain token is shown once, at creation. */
    public static function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(40);
        $token = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => self::hash($plain),
        ]);

        return [$token, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByPlain(string $plain): ?self
    {
        return self::with('user')->where('token_hash', self::hash($plain))->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
