<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Models\PlatformSetting;
use App\Models\ScheduledPost;
use App\Support\CaptionCredit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Autopilot uploads: an approved clip becomes a calendar post in the next
 * free daily slot of every platform the user switched on, and
 * ScheduledPublisher uploads it from there. What differs per platform
 * (accounts, limits, post options) sits behind AutopostPlatform.
 */
class Autopost
{
    // The scheduler ticks once a minute; the calendar asks for the same lead
    private const MIN_LEAD_MINUTES = 2;

    private const MAX_DAYS = 365;

    private const WAITING = [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_FAILED];

    public function __construct(
        private ClipCaption $captions,
        private MusicService $music,
        private YouTubeAutopost $youtube,
        private FacebookAutopost $facebook,
        private InstagramAutopost $instagram,
        private ThreadsAutopost $threads,
        private TiktokAutopost $tiktok,
        private ConnectedAccounts $connected,
    ) {}

    /** @return array<string, AutopostPlatform> */
    public function platforms(): array
    {
        return ['tiktok' => $this->tiktok, 'youtube' => $this->youtube, 'facebook' => $this->facebook, 'instagram' => $this->instagram, 'threads' => $this->threads];
    }

    public function platform(string $platform): AutopostPlatform
    {
        return $this->platforms()[$platform] ?? throw new RuntimeException("Platform autopilot tidak dikenal: {$platform}.");
    }

    public function isOn(int $userId): bool
    {
        return PlatformSetting::where('user_id', $userId)->where('enabled', true)
            ->whereIn('platform', array_keys($this->platforms()))->exists();
    }

    /**
     * Book the clip on every platform with autopost on. The approval stands
     * whatever happens; a platform that can't take the clip keeps the reason.
     *
     * @param  array<string, int>  $accountOverrides
     * @return array<string, array{scheduled_at: string}|array{error: string}>
     */
    public function scheduleAll(GeneratedClip $clip, array $accountOverrides = []): array
    {
        $settings = PlatformSetting::where('user_id', $clip->clipProject->user_id)
            ->whereIn('platform', array_keys($this->platforms()))->get()->keyBy('platform');

        // An approve-time TikTok account is a one-post choice, not a new default.
        if ($override = $accountOverrides['tiktok'] ?? null) {
            $settings->put('tiktok', tap(
                $settings->get('tiktok') ?? PlatformSetting::for($clip->clipProject->user_id, 'tiktok'),
                fn (PlatformSetting $setting) => $setting->forceFill(['enabled' => true, 'account_id' => $override])
            ));
        }
        $settings = $settings->filter(fn (PlatformSetting $setting) => $setting->enabled)->values();

        $results = [];
        foreach ($settings as $setting) {
            $platform = $this->platform($setting->platform);
            try {
                $post = $this->schedule($clip, $setting);
                $platform->recordSkip($clip, null);
                $results[$setting->platform] = ['scheduled_at' => $post->scheduled_at->toISOString()];
            } catch (RuntimeException $e) {
                $platform->recordSkip($clip, $e->getMessage());
                $results[$setting->platform] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Book the clip's slot on the settings' platform.
     *
     * @throws RuntimeException with a message for the user when the clip can't go
     */
    public function schedule(GeneratedClip $clip, PlatformSetting $settings): ScheduledPost
    {
        $platform = $this->platform($settings->platform);
        $account = $platform->account($settings) ?? throw new RuntimeException(
            $this->connected->needsDefault($settings->user_id, $settings->platform)
                ? ConnectedAccounts::needsDefaultMessage($settings->platform)
                : $platform->missingAccount()
        );

        // Approving twice keeps the slot it already has
        $existing = $clip->scheduledPosts()->where('platform', $settings->platform)
            ->whereIn('status', [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_PUBLISHING])->first();
        if ($existing) {
            return $existing;
        }

        if ($reason = $platform->refusal($clip)) {
            throw new RuntimeException("Tidak dijadwalkan ke {$platform->name()}: {$reason}");
        }

        [$caption, $hashtags, $credit] = $this->caption($clip); // may call the AI: outside the lock
        $options = $platform->options($clip, $caption, $hashtags, $credit);

        return DB::transaction(function () use ($settings, $account, $clip, $options) {
            // One approval at a time per user and platform, so two clips never take the same slot
            PlatformSetting::whereKey($settings->id)->lockForUpdate()->first();

            return ScheduledPost::create([
                'user_id' => $settings->user_id,
                'generated_clip_id' => $clip->id,
                'platform' => $settings->platform,
                'account_id' => $account->getKey(),
                'scheduled_at' => $this->nextSlot($settings, $account->getKey()),
                'status' => ScheduledPost::STATUS_SCHEDULED,
                'options' => $options,
            ]);
        });
    }

    /** Drop the clip's posts that have not gone out yet, and why autopilot skipped it. */
    public function cancelWaiting(GeneratedClip $clip): int
    {
        foreach ($this->platforms() as $platform) {
            $platform->recordSkip($clip, null);
        }

        return $clip->scheduledPosts()->whereIn('status', self::WAITING)->delete();
    }

    /**
     * The earliest slot time, in the user's timezone, on a day still under the
     * daily cap and not already taken on that account. Calendar posts count too.
     */
    public function nextSlot(PlatformSetting $settings, int $accountId): CarbonImmutable
    {
        $tz = $settings->timezone;
        $slots = collect($settings->slot_times)->unique()->sort()->values();
        $earliest = CarbonImmutable::now()->addMinutes(self::MIN_LEAD_MINUTES);
        $today = CarbonImmutable::now($tz)->startOfDay();

        $taken = ScheduledPost::where('user_id', $settings->user_id)
            ->where('platform', $settings->platform)
            ->where('account_id', $accountId)
            ->where('status', '!=', ScheduledPost::STATUS_FAILED) // a failed post never went up: its slot is free
            ->where('scheduled_at', '>=', $today->utc())
            ->pluck('scheduled_at')
            ->map(fn ($at) => CarbonImmutable::parse($at)->setTimezone($tz));
        $perDay = $taken->countBy(fn (CarbonImmutable $at) => $at->toDateString());
        $minutes = $taken->map(fn (CarbonImmutable $at) => $at->format('Y-m-d H:i'))->flip();

        for ($d = 0; $d <= self::MAX_DAYS; $d++) {
            $day = $today->addDays($d)->toDateString();
            if (($perDay[$day] ?? 0) >= $settings->max_per_day) {
                continue;
            }
            foreach ($slots as $slot) {
                $at = CarbonImmutable::parse("{$day} {$slot}", $tz);
                if ($at->lt($earliest) || isset($minutes[$at->format('Y-m-d H:i')])) {
                    continue;
                }

                return $at->utc();
            }
        }

        throw new RuntimeException('Tidak ada slot '.$this->platform($settings->platform)->name().' kosong dalam setahun ke depan.');
    }

    /** @return array{0: string, 1: list<string>, 2: ?string} AI caption, hashtags, and the credit lines (music, source channel) */
    private function caption(GeneratedClip $clip): array
    {
        $caption = ['caption' => '', 'hashtags' => []];
        try {
            $caption = $this->captions->ensure($clip);
        } catch (Throwable) {
            // Without a caption the post still goes, with the hashtags alone
        }
        $credit = CaptionCredit::block($this->music->creditFor($clip->music_track), $clip->clipProject);

        return [$caption['caption'], array_values(array_filter(array_map('strval', $caption['hashtags'] ?? []))), $credit];
    }
}
