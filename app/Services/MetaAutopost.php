<?php

namespace App\Services;

use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Autopilot to a Meta platform. The account is a MetaTarget of that platform;
 * a clip autopilot could not book keeps the reason as a "skipped" publication.
 */
abstract class MetaAutopost implements AutopostPlatform
{
    public function __construct(protected MetaPublishing $publishing) {}

    abstract protected function platform(): string;

    abstract public function isConfigured(): bool;

    abstract public function missingAccount(): string;

    public function name(): string
    {
        return $this->publishing->publisher($this->platform())->name();
    }

    public function accounts(int $userId): Collection
    {
        return MetaTarget::ownedBy($userId, $this->platform())->orderBy('name')->get(['id', 'name', 'username'])
            ->map(fn (MetaTarget $t) => ['id' => $t->id, 'name' => $t->username ? "{$t->name} (@{$t->username})" : $t->name]);
    }

    public function account(PlatformSetting $settings): ?Model
    {
        return app(ConnectedAccounts::class)->default($settings->user_id, $this->platform());
    }

    public function refusal(GeneratedClip $clip): ?string
    {
        return $this->publishing->publisher($this->platform())->refusal($clip);
    }

    public function recordSkip(GeneratedClip $clip, ?string $reason): void
    {
        $platform = $this->platform();
        if ($reason === null) {
            ClipPublication::where(['generated_clip_id' => $clip->id, 'platform' => $platform, 'status' => ClipPublication::SKIPPED])->delete();

            return;
        }
        // Never over a real upload: a clip already sent keeps its status
        $existing = $clip->publication($platform);
        if (! $existing || $existing->status === ClipPublication::SKIPPED) {
            ClipPublication::updateOrCreate(['generated_clip_id' => $clip->id, 'platform' => $platform], [
                'status' => ClipPublication::SKIPPED, 'error' => mb_substr($reason, 0, 1000),
            ]);
        }
    }

    public function skipReason(GeneratedClip $clip): ?string
    {
        $publication = $clip->publication($this->platform());

        return $publication?->status === ClipPublication::SKIPPED ? $publication->error : null;
    }
}
