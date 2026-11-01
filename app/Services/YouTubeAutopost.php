<?php

namespace App\Services;

use App\Http\Controllers\YouTubeController;
use App\Models\GeneratedClip;
use App\Models\ScheduledPost;
use App\Models\YoutubeAutopostSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Autopilot to YouTube Shorts: an approved clip becomes a calendar post in the
 * next free daily slot, and ScheduledPublisher uploads it from there.
 */
class YouTubeAutopost
{
    // The scheduler ticks once a minute; the calendar asks for the same lead
    private const MIN_LEAD_MINUTES = 2;

    private const MAX_DAYS = 365;

    private const WAITING = [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_FAILED];

    public function __construct(private ClipCaption $captions, private MusicService $music) {}

    public function isOn(int $userId): bool
    {
        return YoutubeAutopostSetting::where('user_id', $userId)->where('enabled', true)->exists();
    }

    /**
     * Book the clip's YouTube slot. Null when autopost is off.
     *
     * @throws RuntimeException with a message for the user when it is on but the clip can't go
     */
    public function schedule(GeneratedClip $clip): ?ScheduledPost
    {
        $userId = $clip->clipProject->user_id;
        $settings = YoutubeAutopostSetting::where('user_id', $userId)->where('enabled', true)->first();
        if (! $settings) {
            return null;
        }
        $account = $settings->account
            ?? throw new RuntimeException('Channel YouTube untuk autopilot belum dipilih atau sudah diputus. Pilih channel di pengaturan YouTube.');

        // Approving twice keeps the slot it already has
        $existing = $clip->scheduledPosts()->where('platform', 'youtube')
            ->whereIn('status', [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_PUBLISHING])->first();
        if ($existing) {
            return $existing;
        }

        $seconds = $this->renderedSeconds($clip);
        if ($seconds > YouTubeController::MAX_SHORT_SECONDS + 0.5) {
            throw new RuntimeException('Tidak dijadwalkan ke YouTube: Shorts maksimal 3 menit, clip ini '.round($seconds).' detik.');
        }

        $options = $this->options($clip); // may call the AI: outside the lock

        return DB::transaction(function () use ($settings, $account, $clip, $options) {
            // One approval at a time per user, so two clips never take the same slot
            YoutubeAutopostSetting::whereKey($settings->id)->lockForUpdate()->first();

            return ScheduledPost::create([
                'user_id' => $settings->user_id,
                'generated_clip_id' => $clip->id,
                'platform' => 'youtube',
                'account_id' => $account->id,
                'scheduled_at' => $this->nextSlot($settings, $account->id),
                'status' => ScheduledPost::STATUS_SCHEDULED,
                'options' => $options,
            ]);
        });
    }

    /** Drop the clip's posts that have not gone out yet. */
    public function cancelWaiting(GeneratedClip $clip): int
    {
        return $clip->scheduledPosts()->whereIn('status', self::WAITING)->delete();
    }

    /**
     * The earliest slot time, in the user's timezone, on a day still under the
     * daily cap and not already taken on that channel. Calendar posts count too.
     */
    public function nextSlot(YoutubeAutopostSetting $settings, int $accountId): CarbonImmutable
    {
        $tz = $settings->timezone;
        $slots = collect($settings->slot_times)->unique()->sort()->values();
        $earliest = CarbonImmutable::now()->addMinutes(self::MIN_LEAD_MINUTES);
        $today = CarbonImmutable::now($tz)->startOfDay();

        $taken = ScheduledPost::where('user_id', $settings->user_id)
            ->where('platform', 'youtube')
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

        throw new RuntimeException('Tidak ada slot YouTube kosong dalam setahun ke depan.');
    }

    /** Title, description and tags as the calendar fills them in: topic, AI caption, hashtags. */
    private function options(GeneratedClip $clip): array
    {
        $caption = ['caption' => '', 'hashtags' => []];
        try {
            $caption = $this->captions->ensure($clip);
        } catch (Throwable) {
            // Without a caption the description is just #Shorts; the upload still goes
        }
        $body = $this->music->captionWithCredit($caption['caption'], $this->music->creditFor($clip->music_track));
        $hashtags = array_values(array_filter(array_map('strval', $caption['hashtags'] ?? [])));

        return [
            'title' => mb_substr(trim(str_replace(['<', '>'], '', (string) $clip->topic)), 0, 100) ?: 'Clip',
            'description' => mb_substr(trim($body."\n\n".implode(' ', $hashtags)), 0, 5000),
            'privacy' => 'public', // YouTube keeps it private until the API project passes its audit
            'tags' => array_slice(array_values(array_filter(array_map(fn ($h) => ltrim($h, '#'), $hashtags))), 0, 30),
            'autopilot' => true,
        ];
    }

    private function renderedSeconds(GeneratedClip $clip): float
    {
        // Jump cuts make the rendered clip shorter than end - start
        try {
            return app(FFmpegService::class)->probeDuration(Storage::path($clip->output_path));
        } catch (Throwable) {
            return $clip->durationSeconds();
        }
    }
}
