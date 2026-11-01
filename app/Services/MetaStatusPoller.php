<?php

namespace App\Services;

use App\Models\ClipPublication;
use App\Models\MetaTarget;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Follows Meta uploads until they are published or failed, so calendar and
 * autopilot posts settle without anyone polling from the browser. A Facebook
 * Reel is asked for its status; an Instagram or Threads container is asked
 * whether Meta has fetched the video, and is published once it has.
 * Same back-off as YouTubeStatusPoller.
 */
class MetaStatusPoller
{
    public function __construct(private MetaPublishing $publishing) {}

    /** @return array{checked: int, settled: int} */
    public function run(): array
    {
        $due = ClipPublication::where('status', 'processing')
            ->whereIn('platform', MetaPublishing::PLATFORMS)
            ->where(fn ($q) => $q->whereNotNull('external_id')->orWhereNotNull('container_id'))
            ->get()
            ->filter(fn (ClipPublication $p) => $this->isDue($p));

        $targets = MetaTarget::with('account')->whereIn('id', $due->pluck('target_id')->unique())->get()->keyBy('id');
        $checked = $settled = 0;
        foreach ($due as $publication) {
            $checked++;
            $settled += $this->check($publication, $targets[$publication->target_id] ?? null);
        }

        return ['checked' => $checked, 'settled' => $settled];
    }

    /** Every run for the first hour, then every 15 and 60 minutes. */
    public function isDue(ClipPublication $publication): bool
    {
        $last = $publication->checked_at;
        if (! $last) {
            return true;
        }
        $ageMinutes = ($publication->uploaded_at ?? $publication->updated_at)->diffInMinutes(now());
        $every = match (true) {
            $ageMinutes < 60 => 0,
            $ageMinutes < 360 => 15,
            default => 60,
        };

        return $last->copy()->addMinutes($every)->lte(now());
    }

    /** @return int 1 when it reached published or failed */
    private function check(ClipPublication $publication, ?MetaTarget $target): int
    {
        $publisher = $this->publishing->publisher($publication->platform);
        $name = $publisher->name();
        try {
            if (! $target) {
                throw new \RuntimeException(MetaPublishing::targetNoun($publication->platform).' sudah diputus.');
            }
            $status = $publisher->advance($target, $publication);
        } catch (Throwable $e) {
            // Status unknown, not failed: the post may well be up. Say why and try again later
            $publication->update([
                'checked_at' => now(),
                'error' => mb_substr("Status {$name} belum bisa dicek: ".$e->getMessage(), 0, 1000),
            ]);
            Log::channel('clipper_jobs')->warning('Meta status poll failed', ['publication_id' => $publication->id, 'error' => $e->getMessage()]);

            return $this->giveUpIfOld($publication, $publisher);
        }

        $publication->update(array_filter([
            'external_id' => $status['external_id'] ?? null,
            'url' => $status['url'] ?? null,
        ]) + [
            'status' => $status['status'],
            'error' => $status['reason'],
            'checked_at' => now(),
        ]);
        if ($status['status'] === 'processing') {
            return $this->giveUpIfOld($publication, $publisher);
        }
        Log::channel('clipper_jobs')->info('Meta upload settled', ['publication_id' => $publication->id, 'status' => $status['status']]);

        return 1;
    }

    private function giveUpIfOld(ClipPublication $publication, MetaPublisher $publisher): int
    {
        if (now()->lt(($publication->uploaded_at ?? $publication->created_at)->copy()->addHours($publisher->giveUpHours()))) {
            return 0;
        }
        $end = $publisher->givenUp();
        $publication->update(['status' => $end['status'], 'error' => $end['reason']]);

        return 1;
    }
}
