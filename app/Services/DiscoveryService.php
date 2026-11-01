<?php

namespace App\Services;

use App\Models\ClipProject;
use App\Models\DiscoveredVideo;
use App\Models\VideoSource;
use App\Support\ClipSettings;
use App\Support\YoutubeUrl;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Autopilot step 1: find new videos for a source and start a project for each
 * (analysis → auto-render → review queue).
 */
class DiscoveryService
{
    private const LIST_LIMIT = 20;

    public function __construct(
        private YtDlpService $ytdlp,
        private ProjectCreator $creator,
        private YouTubeService $youtube,
    ) {}

    /** @return ClipProject[] projects started in this run */
    public function run(VideoSource $source): array
    {
        $log = Log::channel('clipper_jobs');

        // Autopilot only monitors channels the user proved by OAuth. A channel
        // connected then disconnected fails here until it is verified again.
        $ownedChannelId = $this->youtube->verifiedChannelIdForSource($source->user_id, (string) $source->value);
        if (! $ownedChannelId) {
            throw new RuntimeException('Channel sumber ini belum/tidak lagi terverifikasi sebagai milikmu. Hubungkan channel di /settings/youtube, lalu jalankan ulang.');
        }

        $candidates = $this->ytdlp->listVideos('channel', YoutubeUrl::channel($source->value), self::LIST_LIMIT);
        $seen = DiscoveredVideo::where('user_id', $source->user_id)
            ->whereIn('video_id', array_column($candidates, 'id'))
            ->pluck('video_id')
            ->all();

        $started = [];
        foreach ($candidates as $video) {
            if (count($started) >= $source->max_per_run) {
                break;
            }
            if (in_array($video['id'], $seen, true) || ! $this->durationOk($source, $video['duration'])) {
                continue;
            }

            // The id comes from yt-dlp's listing output: rebuild the URL through the same
            // strict check as user links instead of trusting whatever came back
            $url = YoutubeUrl::canonical('https://www.youtube.com/watch?v='.$video['id']);
            if (! $url) {
                continue;
            }
            // The source channel was verified above; every video listed under it
            // shares that ownership, so no per-video lookup is needed.
            $project = $this->creator->create(
                $source->user_id,
                $url,
                ClipSettings::forAutopilot($source->settings),
                $source->id,
                verifiedChannelId: $ownedChannelId,
            );

            DiscoveredVideo::create([
                'user_id' => $source->user_id,
                'video_source_id' => $source->id,
                'video_id' => $video['id'],
                'clip_project_id' => $project->id,
            ]);
            $project->update(['video_title' => $video['title']]);

            $log->info('Autopilot: project started', ['source_id' => $source->id, 'video' => $video['id'], 'project_id' => $project->id]);
            $started[] = $project;
        }

        $source->update(['last_run_at' => now(), 'last_error' => null]);

        return $started;
    }

    private function durationOk(VideoSource $source, ?int $duration): bool
    {
        // Unknown length (some search results) is accepted; min/max still guard the rest
        return $duration === null
            || ($duration >= $source->min_video_seconds && $duration <= $source->max_video_seconds);
    }
}
