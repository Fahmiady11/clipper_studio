<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Support\JobCancelled;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetches the video a render reads once the project's full download is gone:
 * the copy in video_cache when there is one, otherwise only the clip's stretch
 * of it, since a 60 s clip from a 45 min source needs about 2% of the bytes.
 */
class ClipSource
{
    /** Seconds kept either side of the clip, so a frame or two of rounding never runs off the file */
    public const MARGIN = 3.0;

    /**
     * Download the whole video instead once the clips still waiting on this
     * project add up to this share of it: the full file is cached and shared
     * by all of them, sections are fetched again for every clip. A section
     * comes over one ffmpeg connection, about 2.5x slower per second of video
     * than the 4-fragment full download, so past ~40% the full file is quicker.
     */
    public const FULL_SHARE = 0.4;

    public function __construct(private YtDlpService $ytdlp) {}

    /**
     * @return array{path: string, offset: float, section: bool} offset is the source time at the file's 0 s;
     *                                                           seek the file at t - offset for source time t
     */
    public function fetch(GeneratedClip $clip, string $tempDir): array
    {
        $project = $clip->clipProject;
        $full = $tempDir.'/video.mp4';
        $log = Log::channel('clipper_jobs');
        @mkdir($tempDir, 0755, true);

        $reason = $this->ytdlp->isCached($project->youtube_url) ? 'cached' : ($this->fullIsCheaper($clip) ? 'clips_cover_much' : null);
        if ($reason === null) {
            $from = max(0.0, (float) $clip->start_seconds - self::MARGIN);
            $to = (float) $clip->end_seconds + self::MARGIN;
            $section = $tempDir.'/section_'.$clip->id.'.mp4';
            try {
                $this->ytdlp->downloadSection($project->youtube_url, $section, $from, $to);
                $log->info('Source section downloaded', ['from' => $from, 'to' => $to, 'size_mb' => round(filesize($section) / 1048576, 1)]);

                return ['path' => $section, 'offset' => $from, 'section' => true];
            } catch (JobCancelled $e) {
                throw $e;
            } catch (RuntimeException $e) {
                @unlink($section);
                $reason = 'section_failed';
                $log->warning('Section download failed, downloading the whole video', ['error' => mb_substr($e->getMessage(), -500)]);
            }
        }

        $log->info('Source video missing, re-downloading', ['project_id' => $project->id, 'reason' => $reason]);
        $this->ytdlp->download($project->youtube_url, $full);

        return ['path' => $full, 'offset' => 0.0, 'section' => false];
    }

    private function fullIsCheaper(GeneratedClip $clip): bool
    {
        $duration = (float) $clip->clipProject->duration_seconds;
        if ($duration <= 0) {
            return false; // length unknown: a section can't be the bigger download
        }

        // This clip is among them (it is processing); hook-test arms share one cut, count it once
        $needed = GeneratedClip::where('clip_project_id', $clip->clip_project_id)->awaitingRender()
            ->get(['start_seconds', 'end_seconds'])
            ->unique(fn ($c) => $c->start_seconds.'-'.$c->end_seconds)
            ->sum(fn ($c) => $c->durationSeconds() + 2 * self::MARGIN);

        return $needed >= self::FULL_SHARE * $duration;
    }
}
