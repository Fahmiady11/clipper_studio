<?php

namespace App\Services;

use App\Models\AutopostSetting;
use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Autopilot to Facebook Page Reels: the platform side of Autopost. */
class FacebookAutopost implements AutopostPlatform
{
    public function __construct(private MetaGraph $graph, private FacebookReels $reels) {}

    public function name(): string
    {
        return 'Facebook';
    }

    public function isConfigured(): bool
    {
        return $this->graph->isConfigured();
    }

    public function accounts(int $userId): Collection
    {
        return MetaTarget::ownedBy($userId, 'facebook')->orderBy('name')->get(['id', 'name'])
            ->map(fn (MetaTarget $t) => ['id' => $t->id, 'name' => $t->name]);
    }

    public function account(AutopostSetting $settings): ?Model
    {
        return $settings->account_id ? MetaTarget::ownedBy($settings->user_id, 'facebook')->find($settings->account_id) : null;
    }

    public function missingAccount(): string
    {
        return 'Page Facebook untuk autopilot belum dipilih atau sudah diputus. Pilih Page di pengaturan Facebook.';
    }

    public function refusal(GeneratedClip $clip): ?string
    {
        return $this->reels->refusal($clip);
    }

    /** The Reel description: AI caption with music credit, then the hashtags. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags): array
    {
        return [
            'description' => mb_substr(trim($caption."\n\n".implode(' ', $hashtags)), 0, 5000),
            'autopilot' => true,
        ];
    }

    public function recordSkip(GeneratedClip $clip, ?string $reason): void
    {
        $skipped = ['generated_clip_id' => $clip->id, 'platform' => 'facebook', 'status' => ClipPublication::SKIPPED];
        if ($reason === null) {
            ClipPublication::where($skipped)->delete();

            return;
        }
        // Never over a real upload: a clip already sent keeps its status
        $existing = $clip->publication('facebook');
        if (! $existing || $existing->status === ClipPublication::SKIPPED) {
            ClipPublication::updateOrCreate(['generated_clip_id' => $clip->id, 'platform' => 'facebook'], [
                'status' => ClipPublication::SKIPPED, 'error' => mb_substr($reason, 0, 1000),
            ]);
        }
    }

    public function skipReason(GeneratedClip $clip): ?string
    {
        $publication = $clip->publication('facebook');

        return $publication?->status === ClipPublication::SKIPPED ? $publication->error : null;
    }
}
