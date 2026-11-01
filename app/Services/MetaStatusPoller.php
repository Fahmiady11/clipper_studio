<?php

namespace App\Services;

use App\Models\ClipPublication;
use App\Models\MetaTarget;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Follows Meta uploads (Facebook Reels now; Instagram and Threads containers
 * plug in through checkers()) until they are published or failed, so calendar
 * and autopilot posts settle without anyone polling from the browser.
 * Same back-off as YouTubeStatusPoller.
 */
class MetaStatusPoller
{
    // A Reel still processing after this is reported as failed with a pointer to the Page
    public const GIVE_UP_HOURS = 48;

    public function __construct(private FacebookReels $reels) {}

    /** @return array<string, callable(MetaTarget, string): array{status: string, reason: ?string, url: ?string}> */
    private function checkers(): array
    {
        return [
            'facebook' => fn (MetaTarget $target, string $id) => $this->reels->status($target, $id),
        ];
    }

    /** @return array{checked: int, settled: int} */
    public function run(): array
    {
        $checkers = $this->checkers();
        $due = ClipPublication::where('status', 'processing')
            ->whereIn('platform', array_keys($checkers))
            ->whereNotNull('external_id')
            ->get()
            ->filter(fn (ClipPublication $p) => $this->isDue($p));

        $targets = MetaTarget::with('account')->whereIn('id', $due->pluck('target_id')->unique())->get()->keyBy('id');
        $checked = $settled = 0;
        foreach ($due as $publication) {
            $checked++;
            $settled += $this->check($publication, $targets[$publication->target_id] ?? null, $checkers[$publication->platform]);
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
    private function check(ClipPublication $publication, ?MetaTarget $target, callable $checker): int
    {
        $name = ucfirst($publication->platform);
        try {
            if (! $target) {
                throw new \RuntimeException("Akun {$name} sudah diputus.");
            }
            $status = $checker($target, $publication->external_id);
        } catch (Throwable $e) {
            // Status unknown, not failed: the Reel may well be up. Say why and try again later
            $publication->update([
                'checked_at' => now(),
                'error' => mb_substr("Status {$name} belum bisa dicek: ".$e->getMessage(), 0, 1000),
            ]);
            Log::channel('clipper_jobs')->warning('Meta status poll failed', ['publication_id' => $publication->id, 'error' => $e->getMessage()]);

            return $this->giveUpIfOld($publication);
        }

        $publication->update([
            'status' => $status['status'],
            'error' => $status['reason'],
            'url' => $status['url'] ?? $publication->url,
            'checked_at' => now(),
        ]);
        if ($status['status'] === 'processing') {
            return $this->giveUpIfOld($publication);
        }
        Log::channel('clipper_jobs')->info('Meta upload settled', ['publication_id' => $publication->id, 'status' => $status['status']]);

        return 1;
    }

    private function giveUpIfOld(ClipPublication $publication): int
    {
        if (now()->lt(($publication->uploaded_at ?? $publication->created_at)->copy()->addHours(self::GIVE_UP_HOURS))) {
            return 0;
        }
        $publication->update([
            'status' => 'failed',
            'error' => 'Status '.ucfirst($publication->platform).' tidak berubah dalam '.self::GIVE_UP_HOURS.' jam. Cek langsung di Page, Reel mungkin sudah tayang.',
        ]);

        return 1;
    }
}
