<?php

namespace App\Models;

use App\Exceptions\TokenLimitReached;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    public const PREFIX = 'clp_';

    public const MAX_PER_USER = 10;

    // read: list/poll/download. render: create projects, render, cancel, captions. publish: YouTube/TikTok upload.
    // render and publish imply read (a token that can start work must be able to poll it).
    public const ABILITIES = ['read', 'render', 'publish'];

    protected $fillable = ['user_id', 'name', 'token_hash', 'abilities', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'abilities' => 'array'];
    }

    /** Only the SHA-256 is stored, so the plain token is shown once, at creation. */
    public static function issue(User $user, string $name, ?array $abilities = null): array
    {
        $plain = self::PREFIX.Str::random(40);
        $token = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => self::hash($plain),
            'abilities' => self::normalize($abilities ?? self::ABILITIES),
        ]);

        return [$token, $plain];
    }

    /** Same as issue() but refuses past MAX_PER_USER; the user row lock makes count + insert atomic. */
    public static function issueLimited(User $user, string $name, array $abilities): array
    {
        return DB::transaction(function () use ($user, $name, $abilities) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if (self::where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
                throw new TokenLimitReached('Maksimal '.self::MAX_PER_USER.' token. Hapus yang tidak dipakai dulu.');
            }

            return self::issue($user, $name, $abilities);
        });
    }

    /** Known abilities only, in canonical order, with read added whenever render or publish is present. */
    public static function normalize(array $abilities): array
    {
        if (array_intersect(['render', 'publish'], $abilities)) {
            $abilities[] = 'read';
        }

        return array_values(array_intersect(self::ABILITIES, $abilities));
    }

    public function can(string $ability): bool
    {
        $have = $this->abilities ?? [];
        if ($ability === 'read') {
            return (bool) array_intersect(['read', 'render', 'publish'], $have);
        }

        return in_array($ability, $have, true);
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
