<?php

namespace App\Services;

use App\Models\ClipStat;
use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly lifetime metrics for clips that TikTok has finished publishing.
 * The Display API query endpoint works by video ID, so it can reuse the
 * publish ID stored while posting; snapshots are kept as append-only history.
 */
class TikTokStatsService
{
    private const API = 'https://open.tiktokapis.com/v2';

    private const REQUIRED_SCOPE = 'video.list';

    private const METRIC_FIELDS = 'id,like_count,comment_count,share_count,view_count';

    private const QUERY_BATCH_SIZE = 20;

    public function collectPublishedStats(): int
    {
        $clips = GeneratedClip::query()
            ->where('tiktok_status', 'published')
            ->whereNotNull('tiktok_publish_id')
            ->whereNotNull('tiktok_account_id')
            ->with('clipProject:id,user_id')
            ->get(['id', 'clip_project_id', 'tiktok_publish_id', 'tiktok_account_id']);

        if ($clips->isEmpty()) {
            return 0;
        }

        $accounts = TiktokAccount::whereIn('id', $clips->pluck('tiktok_account_id')->unique())->get()->keyBy('id');
        $collected = 0;

        foreach ($clips->groupBy('tiktok_account_id') as $accountId => $accountClips) {
            $account = $accounts->get($accountId);
            if (! $account) {
                $this->warn('TikTok account missing for published clip stats', ['account_id' => $accountId]);

                continue;
            }

            if (! $account->hasScope(self::REQUIRED_SCOPE)) {
                $this->warn('TikTok account lacks stats scope', [
                    'account_id' => $account->id,
                    'scope' => self::REQUIRED_SCOPE,
                ]);

                continue;
            }

            foreach ($accountClips->chunk(self::QUERY_BATCH_SIZE) as $batch) {
                // The API is scoped to one authorized owner; drop any stale pairing instead of guessing.
                $batch = $batch->filter(fn (GeneratedClip $clip) => $clip->clipProject?->user_id === $account->user_id);
                if ($batch->isEmpty()) {
                    continue;
                }

                try {
                    $collected += $this->collectBatch($account, $batch);
                } catch (Throwable $e) {
                    // A missing scope/approval, expired session or TikTok outage must not break the scheduler.
                    $this->warn('TikTok stats refresh failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return $collected;
    }

    /** @param  Collection<int, GeneratedClip>  $clips */
    private function collectBatch(TiktokAccount $account, Collection $clips): int
    {
        $json = $this->queryVideos($account, $clips->pluck('tiktok_publish_id')->all());
        $videos = collect($json['data']['videos'] ?? [])->keyBy('id');

        return $clips->reduce(function (int $carry, GeneratedClip $clip) use ($videos): int {
            $video = $videos->get($clip->tiktok_publish_id);
            if (! $video) {
                return $carry;
            }

            ClipStat::create([
                'generated_clip_id' => $clip->id,
                'platform' => 'tiktok',
                'views' => max(0, (int) ($video['view_count'] ?? 0)),
                'likes' => max(0, (int) ($video['like_count'] ?? 0)),
                'comments' => max(0, (int) ($video['comment_count'] ?? 0)),
                'shares' => max(0, (int) ($video['share_count'] ?? 0)),
                'collected_at' => now(),
            ]);

            return $carry + 1;
        }, 0);
    }

    /**
     * @param  list<string>  $videoIds
     * @return array<string, mixed>
     */
    private function queryVideos(TiktokAccount $account, array $videoIds): array
    {
        $token = app(TikTokService::class)->freshToken($account);
        $response = Http::withToken($token)
            ->timeout(60)
            ->post(self::API.'/video/query/?'.http_build_query(['fields' => self::METRIC_FIELDS]), [
                'filters' => ['video_ids' => array_values($videoIds)],
            ]);

        return $this->unwrap($response, 'video/query');
    }

    /** @return array<string, mixed> */
    private function unwrap(Response $response, string $endpoint): array
    {
        $json = $response->json() ?? [];
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        if (! $response->successful() || (($error['code'] ?? 'ok') !== 'ok')) {
            $message = $error['message'] ?? $response->body();
            throw new \RuntimeException(
                "TikTok {$endpoint} gagal ({$response->status()}): ".($error['code'] ?? '').' '.$message
            );
        }

        return is_array($json) ? $json : [];
    }

    private function warn(string $message, array $context = []): void
    {
        Log::channel('clipper_api')->warning($message, $context);
    }
}
