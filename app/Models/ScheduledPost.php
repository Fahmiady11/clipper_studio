<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPost extends Model
{
    public const PLATFORMS = ['tiktok', 'youtube', 'facebook'];

    // Where a user whose token expired or was revoked connects again
    private const CONNECT_URLS = ['tiktok' => '/tiktok/connect', 'youtube' => '/youtube/connect', 'facebook' => '/facebook/connect'];

    // scheduled: waiting for its time. publishing: claimed by the scheduler. queued: handed to the
    // upload job, whose result lives on the clip. failed: could not be handed over (see error).
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['user_id', 'generated_clip_id', 'platform', 'account_id', 'scheduled_at', 'status', 'options', 'error', 'handed_off_at'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'handed_off_at' => 'datetime',
            'options' => 'array',
        ];
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(GeneratedClip::class, 'generated_clip_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Still waiting for its time, so it can be moved or cancelled. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /** The connect link when the error is a lost session ("Hubungkan ulang …"), else null. */
    public static function reconnectUrl(string $platform, ?string $error): ?string
    {
        return $error && stripos($error, 'hubungkan ulang') !== false ? (self::CONNECT_URLS[$platform] ?? null) : null;
    }
}
