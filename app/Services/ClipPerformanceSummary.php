<?php

namespace App\Services;

use App\Models\ClipStat;
use App\Models\GeneratedClip;
use App\Models\HookTest;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Aggregates the latest lifetime snapshot per clip/platform. Keeping this
 * platform-aware lets TikTok totals and future Instagram/YouTube totals coexist.
 */
class ClipPerformanceSummary
{
    private const MIN_CLIPS_FOR_PROMPT = 5;

    private const MIN_HOOK_TESTS_FOR_PROMPT = 2;

    /**
     * Latest snapshot per platform, summed for a total across platforms.
     *
     * @param  iterable<int, string>  $clipIds
     * @return array<string, array{views: int, likes: int, comments: int, shares: int}>
     */
    public function totalsForClips(iterable $clipIds): array
    {
        $ids = collect($clipIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return $this->latestSnapshotsFor($ids)
            ->groupBy('generated_clip_id')
            ->map(fn (Collection $snapshots) => [
                'views' => (int) $snapshots->sum('views'),
                'likes' => (int) $snapshots->sum('likes'),
                'comments' => (int) $snapshots->sum('comments'),
                'shares' => (int) $snapshots->sum('shares'),
            ])
            ->all();
    }

    /**
     * @return array{clip_count: int, total_views: int, total_likes: int, best_clip: ?array<string, mixed>, top_clips: list<array<string, mixed>>}
     */
    public function forUser(int $userId): array
    {
        $clips = GeneratedClip::query()
            ->whereHas('clipProject', fn ($query) => $query->where('user_id', $userId))
            ->whereNull('variant_of_id') // hook A/B variants repeat the original's topic
            ->whereHas('stats', fn ($query) => $query->where('platform', 'tiktok'))
            ->get(['id', 'topic', 'viral_score']);

        $totals = $this->totalsForClips($clips->pluck('id'));
        $rows = $clips
            ->filter(fn (GeneratedClip $clip) => isset($totals[$clip->id]))
            ->map(fn (GeneratedClip $clip) => [
                'id' => $clip->id,
                'topic' => $clip->topic,
                'views' => $totals[$clip->id]['views'],
                'likes' => $totals[$clip->id]['likes'],
            ])
            ->sort(function (array $a, array $b): int {
                return [$b['views'], $b['likes']] <=> [$a['views'], $a['likes']];
            })
            ->values();

        return [
            'clip_count' => $rows->count(),
            'total_views' => (int) $rows->sum('views'),
            'total_likes' => (int) $rows->sum('likes'),
            'best_clip' => $rows->first(),
            'top_clips' => $rows->take(3)->all(),
        ];
    }

    /** Aggregate prompt hint; return null until the evidence base is meaningful. */
    public function promptInsight(): ?string
    {
        try {
            $clips = GeneratedClip::query()
                ->whereNotNull('viral_score')
                ->whereHas('stats')
                ->get(['id', 'viral_score']);

            if ($clips->count() < self::MIN_CLIPS_FOR_PROMPT) {
                return null;
            }

            $totals = $this->totalsForClips($clips->pluck('id'));
            $buckets = $clips
                ->filter(fn (GeneratedClip $clip) => isset($totals[$clip->id]))
                ->groupBy(fn (GeneratedClip $clip) => self::bucket($clip->viral_score))
                ->map(fn (Collection $bucketClips) => [
                    'clips' => $bucketClips->count(),
                    'avg_views' => (int) round(
                        $bucketClips->avg(fn (GeneratedClip $clip) => $totals[$clip->id]['views'])
                    ),
                ])
                ->sortBy('clips')
                ->all();

            $parts = collect($buckets)
                ->map(fn (array $bucket, string $label) => "skor {$label} = {$bucket['avg_views']} views rata-rata ({$bucket['clips']} klip)")
                ->implode('; ');

            return 'Data performa TikTok historis: '.$parts.
                '. Gunakan pola ini sebagai sinyal tambahan, tetap prioritaskan sinyal konten dari transcript.';
        } catch (Throwable) {
            // Analytics must never prevent a normal analysis when tables are unavailable.
            return null;
        }
    }

    /**
     * Hook angles ordered by how often they won this user's A/B tests (most wins first;
     * untested angles keep the default order).
     *
     * @return list<string>
     */
    public function rankedHookAngles(int $userId): array
    {
        $wins = $this->hookWins($userId);

        return collect(HookTest::ANGLES)
            ->sortByDesc(fn (string $angle) => $wins[$angle] ?? 0) // stable: ties keep ANGLES order
            ->values()
            ->all();
    }

    /** Short prompt line about which hook styles won this user's tests; null until two tests were decided. */
    public function hookInsight(int $userId): ?string
    {
        try {
            $wins = $this->hookWins($userId);
            $total = array_sum($wins);
            if ($total < self::MIN_HOOK_TESTS_FOR_PROMPT) {
                return null;
            }
            arsort($wins);
            $parts = collect($wins)->map(fn (int $n, string $angle) => HookTest::angleLabel($angle)." ({$n}x)")->implode(', ');

            return "Hasil tes A/B hook akun ini ({$total} tes): gaya yang paling sering menang = {$parts}. ".
                'Condongkan hook ke gaya yang menang, tapi tetap pas dengan isi clip.';
        } catch (Throwable) {
            return null; // like promptInsight: analytics never blocks a render or an analysis
        }
    }

    /** @return array<string, int> wins per angle over decided tests */
    private function hookWins(int $userId): array
    {
        return HookTest::query()
            ->where('user_id', $userId)
            ->where('status', HookTest::STATUS_DECIDED)
            ->whereNotNull('winner_angle')
            ->selectRaw('winner_angle, count(*) as n')
            ->groupBy('winner_angle')
            ->pluck('n', 'winner_angle')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @param  Collection<int, int|string>  $ids */
    private function latestSnapshotsFor(Collection $ids): Collection
    {
        return ClipStat::query()
            ->whereIn('generated_clip_id', $ids)
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ClipStat $stat) => $stat->generated_clip_id.'|'.$stat->platform)
            ->map->first()
            ->values();
    }

    private static function bucket(int $score): string
    {
        return match (true) {
            $score >= 9 => '9-10',
            $score >= 7 => '7-8',
            $score >= 5 => '5-6',
            default => '0-4',
        };
    }
}
