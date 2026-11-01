<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Models\YoutubeAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Follows uploaded videos while YouTube processes them, so calendar and
 * autopilot posts end as published or failed without anyone polling from
 * Studio. One videos.list call (1 quota unit) covers 50 videos of a channel.
 */
class YouTubeStatusPoller
{
    // After this, a video YouTube still calls "uploaded" is taken as done:
    // it is on the channel, and unaudited projects keep it private anyway
    public const GIVE_UP_HOURS = 48;

    private const BATCH = 50;

    public function __construct(private YouTubeService $youtube) {}

    /** @return array{checked: int, settled: int} */
    public function run(): array
    {
        $due = GeneratedClip::where('youtube_status', 'processing')
            ->whereNotNull('youtube_video_id')
            ->whereNotNull('youtube_account_id')
            ->get()
            ->filter(fn (GeneratedClip $clip) => $this->isDue($clip));

        $checked = $settled = 0;
        foreach ($due->groupBy('youtube_account_id') as $accountId => $clips) {
            $account = YoutubeAccount::find($accountId);
            foreach ($clips->chunk(self::BATCH) as $chunk) {
                $checked += $chunk->count();
                $settled += $this->check($account, $chunk);
            }
        }

        return ['checked' => $checked, 'settled' => $settled];
    }

    /** Back off as the upload ages: every run for the first hour, then every 15 and 60 minutes. */
    public function isDue(GeneratedClip $clip): bool
    {
        $since = $clip->youtube_uploaded_at ?? $clip->updated_at;
        $last = $clip->youtube_checked_at;
        if (! $last) {
            return true;
        }
        if ($last->gte($since->copy()->addHours(self::GIVE_UP_HOURS))) {
            return false; // the last check was already past the window: given up
        }
        $ageMinutes = $since->diffInMinutes(now());
        $every = match (true) {
            $ageMinutes < 60 => 0,
            $ageMinutes < 360 => 15,
            default => 60,
        };

        return $last->copy()->addMinutes($every)->lte(now());
    }

    /**
     * @param  Collection<int, GeneratedClip>  $clips
     * @return int how many reached published or failed
     */
    private function check(?YoutubeAccount $account, Collection $clips): int
    {
        try {
            if (! $account) {
                throw new \RuntimeException('Channel YouTube sudah diputus.');
            }
            $statuses = $this->youtube->videoStatuses($account, $clips->pluck('youtube_video_id')->values()->all());
        } catch (Throwable $e) {
            // Status unknown, not failed: the video may well be up. Say why and try again later
            GeneratedClip::whereIn('id', $clips->pluck('id'))->update([
                'youtube_checked_at' => now(),
                'youtube_error' => mb_substr('Status YouTube belum bisa dicek: '.$e->getMessage(), 0, 1000),
            ]);
            Log::channel('clipper_jobs')->warning('YouTube status poll failed', ['account_id' => $account?->id, 'error' => $e->getMessage()]);

            return 0;
        }

        $settled = 0;
        foreach ($clips as $clip) {
            $status = $statuses[$clip->youtube_video_id];
            if ($status['status'] === 'processing' && $this->pastWindow($clip)) {
                $status['status'] = 'published';
            }
            $clip->update([
                'youtube_status' => $status['status'],
                'youtube_privacy' => $status['privacy'],
                'youtube_error' => $status['reason'],
                'youtube_checked_at' => now(),
            ]);
            if ($status['status'] !== 'processing') {
                $settled++;
                Log::channel('clipper_jobs')->info('YouTube video settled', ['clip_id' => $clip->id, 'status' => $status['status'], 'privacy' => $status['privacy']]);
            }
        }

        return $settled;
    }

    private function pastWindow(GeneratedClip $clip): bool
    {
        $since = $clip->youtube_uploaded_at ?? $clip->updated_at;

        return Carbon::now()->gte($since->copy()->addHours(self::GIVE_UP_HOURS));
    }
}
