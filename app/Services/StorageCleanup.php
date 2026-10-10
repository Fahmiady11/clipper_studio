<?php

namespace App\Services;

use App\Models\ClipProject;
use App\Models\GeneratedClip;

/**
 * Frees disk used by downloaded source videos. Rendered clips are kept.
 * - storage/app/temp/<project>: removed once the project is idle and older
 *   than the retention window (RenderClipJob re-downloads if needed later).
 * - storage/app/video_cache/*.mp4: removed when untouched for the window.
 */
class StorageCleanup
{
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

        return $stats;
    }

    /** Anything still analysing or rendering (or queued to render by autopilot) keeps its source video. */
    private function isBusy(string $dirName): bool
    {
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
