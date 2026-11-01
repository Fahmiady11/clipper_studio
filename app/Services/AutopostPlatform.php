<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** What Autopost needs to know about one platform it books slots on. */
interface AutopostPlatform
{
    /** In messages: "Tidak dijadwalkan ke {name}: …" */
    public function name(): string;

    public function isConfigured(): bool;

    /** @return Collection<int, array{id: int, name: ?string}> the user's accounts to pick from */
    public function accounts(int $userId): Collection;

    /** The account the settings point at, null when none was picked or it was disconnected. */
    public function account(PlatformSetting $settings): ?Model;

    public function missingAccount(): string;

    /** Why this clip can't go to the platform, or null. */
    public function refusal(GeneratedClip $clip): ?string;

    /** ScheduledPost options, as the calendar would fill them in. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags): array;

    /** Keep (or with null, clear) why autopilot could not book the clip. */
    public function recordSkip(GeneratedClip $clip, ?string $reason): void;

    public function skipReason(GeneratedClip $clip): ?string;
}
