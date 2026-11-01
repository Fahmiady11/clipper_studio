<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    public const FREE = 'free';

    protected $fillable = ['slug', 'name', 'price_usd_cents', 'render_minutes', 'sort'];

    public static function free(): self
    {
        return static::where('slug', self::FREE)->firstOrFail();
    }

    public function priceUsd(): int
    {
        return intdiv($this->price_usd_cents, 100);
    }
}
