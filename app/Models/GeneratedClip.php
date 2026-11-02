<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class GeneratedClip extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** Shortest and longest cut a user may set (trim or a clip from the transcript). */
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 300;

    protected $fillable = [
        'id',
        'clip_project_id',
        'variant_of_id',
        'ranking',
        'start_seconds',
        'end_seconds',
        'topic',
        'reason',
        'viral_potential',
        'viral_score',
        'viral_reasons',
        'hook_text',
        'hook_text_override',
        'hook_angle',
        'subtitle_json',
        'caption',
        'hashtags_json',
        'broll_json',
        'music_mood',
        'music_track',
        'tiktok_account_id',
        'tiktok_publish_id',
        'tiktok_status',
        'tiktok_error',
        'tiktok_uploaded_at',
        'tiktok_checked_at',
        'youtube_account_id',
        'youtube_video_id',
        'youtube_status',
        'youtube_error',
        'youtube_autopost_error',
        'youtube_uploaded_at',
        'youtube_checked_at',
        'youtube_privacy',
        'output_path',
        'status',
        'review_status',
        'manual',
        'error_msg',
        'cancelled_at',
    ];

    protected $casts = [
        'ranking' => 'integer',
        'start_seconds' => 'float',
        'end_seconds' => 'float',
        'viral_score' => 'integer',
        'viral_reasons' => 'array',
        'subtitle_json' => 'array',
        'hashtags_json' => 'array',
        'broll_json' => 'array',
        'manual' => 'boolean',
        'cancelled_at' => 'datetime',
        'youtube_uploaded_at' => 'datetime',
        'youtube_checked_at' => 'datetime',
        'tiktok_uploaded_at' => 'datetime',
        'tiktok_checked_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function clipProject(): BelongsTo
    {
        return $this->belongsTo(ClipProject::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(ClipStat::class);
    }

    /** Hook A/B variants: same cut as this clip, another hook text. */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'variant_of_id')->orderBy('created_at')->orderBy('id');
    }

    public function variantOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'variant_of_id');
    }

    public function hookTest(): HasOne
    {
        return $this->hasOne(HookTest::class);
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ClipPublication::class);
    }

    public function publication(string $platform): ?ClipPublication
    {
        return $this->relationLoaded('publications')
            ? $this->publications->firstWhere('platform', $platform)
            : $this->publications()->where('platform', $platform)->first();
    }

    /**
     * What the upload job and the platform reported for this clip.
     * status: queued, uploading, processing, inbox (TikTok), unknown (TikTok never gave a final state), published, failed; null when nothing was sent.
     * note: something to know about a published post, e.g. Meta never confirmed it.
     *
     * @return array{status: ?string, error: ?string, url: ?string, privacy: ?string, note: ?string}
     */
    public function delivery(string $platform): array
    {
        if ($platform === 'tiktok') {
            return ['status' => $this->tiktok_status, 'error' => $this->tiktok_error, 'url' => null, 'privacy' => null, 'note' => null];
        }
        if ($platform === 'youtube') {
            return [
                'status' => $this->youtube_status,
                'error' => $this->youtube_error,
                'url' => $this->youtube_video_id ? 'https://youtube.com/shorts/'.$this->youtube_video_id : null,
                'privacy' => $this->youtube_privacy, // private while the API project is unaudited
                'note' => null,
            ];
        }
        $publication = $this->publication($platform);
        $sent = $publication && $publication->status !== ClipPublication::SKIPPED;
        $published = $sent && $publication->status === 'published';

        return [
            'status' => $sent ? $publication->status : null,
            'error' => $sent && ! $published ? $publication->error : null,
            'url' => $sent ? $publication->url : null,
            'privacy' => null,
            'note' => $published ? $publication->error : null,
        ];
    }

    public function durationSeconds(): float
    {
        return $this->end_seconds - $this->start_seconds;
    }
}
