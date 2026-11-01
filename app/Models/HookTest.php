<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A/B test of hook texts on one clip: the variants are clips of their own (variant_of_id → the original). */
class HookTest extends Model
{
    public const ANGLES = ['curiosity_gap', 'question', 'bold_claim'];

    // draft: variants made, nothing posted. testing: variants scheduled to TikTok, stats still coming in.
    // decided: a winner was picked. inconclusive: the window passed without a clear difference.
    public const STATUS_DRAFT = 'draft';

    public const STATUS_TESTING = 'testing';

    public const STATUS_DECIDED = 'decided';

    public const STATUS_INCONCLUSIVE = 'inconclusive';

    protected $fillable = [
        'user_id', 'generated_clip_id', 'variant_count', 'status', 'account_id', 'gap_hours', 'started_at',
        'ends_by', 'decided_at', 'winner_clip_id', 'winner_angle', 'results', 'note',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ends_by' => 'datetime',
            'decided_at' => 'datetime',
            'results' => 'array',
        ];
    }

    public static function angleLabel(string $angle): string
    {
        return match ($angle) {
            'question' => 'pertanyaan tajam',
            'bold_claim' => 'klaim berani',
            'curiosity_gap' => 'celah rasa penasaran',
            'original' => 'hook asli',
            default => $angle,
        };
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(GeneratedClip::class, 'generated_clip_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(GeneratedClip::class, 'winner_clip_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DECIDED, self::STATUS_INCONCLUSIVE], true);
    }
}
