<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        // FK cascades drop the child rows without firing their model events, so a
        // user's files go here, after the row is gone. Renders are written to the
        // default disk, the logo and footage to 'local'; the public disk is unused.
        static::deleted(function (self $user) {
            Storage::disk('local')->deleteDirectory("gameplay/{$user->id}");
            Storage::disk('local')->deleteDirectory("brand/{$user->id}");
            Storage::deleteDirectory("clips/{$user->id}");
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'approved_at' => 'datetime',
            'publish_platforms' => 'array',
        ];
    }

    /** Sign-ups can't use the app until the admin approves them. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function brandKit(): HasOne
    {
        return $this->hasOne(BrandKit::class);
    }

    public function clipProjects(): HasMany
    {
        return $this->hasMany(ClipProject::class);
    }

    public function initials(): string
    {
        $words = explode(' ', trim($this->name));
        if (count($words) >= 2) {
            return strtoupper($words[0][0].$words[1][0]);
        }

        return strtoupper(substr($this->name, 0, 2));
    }
}
