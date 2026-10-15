<?php

namespace App\Services;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use Illuminate\Support\Str;

/**
 * Frees disk used by downloaded source videos. Rendered clips are kept.
 * - storage/app/temp/<project>: removed once the project is idle and older
 *   than the retention window (RenderClipJob re-downloads if needed later).
 * - storage/app/video_cache/*.mp4: removed when untouched for the window.
 * - storage/app/broll: stock clips and searches unused for BROLL_DAYS.
 */
class StorageCleanup
{
    private const BROLL_DAYS = 30; // B-roll is reused across clips; keep it longer than sources

    /** @return array{temp_dirs: int, cache_files: int, bytes: int} */
    public function run(int $retentionHours): array
    {
        $cutoff = now()->subHours($retentionHours)->getTimestamp();
        $stats = ['temp_dirs' => 0, 'cache_files' => 0, 'bytes' => 0];

        foreach (glob(storage_path('app/temp/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) > $cutoff || $this->isBusy(basename($dir))) {
                continue;
            }
            $stats['bytes'] += $this->removeDir($dir);
            $stats['temp_dirs']++;
        }

        foreach (glob(storage_path('app/video_cache/*.mp4')) ?: [] as $file) {
            // A cache hit hard-links the file into temp/, bumping ctime; mtime is the download time
            if (max(filemtime($file), filectime($file)) > $cutoff) {
                continue;
            }
            $stats['bytes'] += filesize($file);
            @unlink($file);
            $stats['cache_files']++;
        }

        $brollCutoff = now()->subDays(self::BROLL_DAYS)->getTimestamp();
        foreach (glob((config('services.broll_cache') ?: storage_path('app/broll')).'/*') ?: [] as $file) {
            if (filemtime($file) > $brollCutoff) {
                continue; // PexelsService touches a file each time it is reused
            }
            $stats['bytes'] += filesize($file);
            @unlink($file);
            $stats['cache_files']++;
        }

        return $stats;
    }

    /**
     * Free a project's source video as soon as no clip still needs it (e.g. the
     * last unrendered autopilot clip was rejected), instead of waiting for the
     * retention window. Removes temp/<project> and the video_cache copy — they
     * are hard links to one file, so both must go to actually free the space.
     * A later render or a new project with the same URL simply downloads again.
     *
     * @return int bytes freed (0 while the project is still busy)
     */
    public function releaseSource(ClipProject $project): int
    {
        if ($this->isBusy($project->id)) {
            return 0;
        }

        $bytes = 0;
        $dir = storage_path('app/temp/'.$project->id);
        if (is_dir($dir)) {
            $bytes += $this->removeDir($dir);
        }

        $cached = (new YtDlpService)->cachedPathFor($project->youtube_url);
        if ($cached && is_file($cached)) {
            $bytes += filesize($cached);
            @unlink($cached);
        }

        return $bytes;
    }

    /** Anything still analysing or rendering (or queued to render by autopilot) keeps its source video. */
    private function isBusy(string $dirName): bool
    {
        // Project ids are UUIDs; any other folder name is an orphan. Postgres rejects
        // non-UUID input for a uuid column, so don't query with it.
        if (! Str::isUuid($dirName)) {
            return false;
        }
        $project = ClipProject::find($dirName);
        if (! $project) {
            return false; // orphan temp dir
        }
        if ($project->status === 'processing') {
            return true;
        }

        return GeneratedClip::where('clip_project_id', $project->id)
            ->where(fn ($q) => $q->where('status', 'processing')
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('review_status', 'pending')))
            ->exists();
    }

    private function removeDir(string $dir): int
    {
        $bytes = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                $bytes += $item->getSize();
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);

        return $bytes;
    }
}
