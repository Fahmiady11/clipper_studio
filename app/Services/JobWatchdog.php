<?php

namespace App\Services;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use App\Notifications\JobFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fails work that a dead worker left in 'processing' and tells the user.
 * Jobs that fail normally go through the job's own failed() hook, which calls notify*().
 */
class JobWatchdog
{
    private const GRACE_SECONDS = 300; // past the job's own timeout, so a slow but live job is never reaped

    /** @return array{projects: int, clips: int} */
    public function run(): array
    {
        $reaped = ['projects' => 0, 'clips' => 0];

        $projects = ClipProject::where('status', 'processing')
            ->where('updated_at', '<', now()->subSeconds((new AnalyzeVideoJob(''))->timeout + self::GRACE_SECONDS))
            ->whereNull('cancelled_at')->get();
        foreach ($projects as $project) {
            $reason = 'Analisis melewati batas waktu dan dihentikan.';
            $project->update(['status' => 'failed', 'error_msg' => $reason, 'progress_message' => 'Gagal: '.$reason]);
            $this->notifyProject($project, $reason);
            $reaped['projects']++;
        }

        $clips = GeneratedClip::with('clipProject.user')->where('status', 'processing')
            ->where('updated_at', '<', now()->subSeconds((new RenderClipJob(''))->timeout + self::GRACE_SECONDS))
            ->whereNull('cancelled_at')->get();
        foreach ($clips as $clip) {
            $reason = 'Render melewati batas waktu dan dihentikan.';
            $clip->update(['status' => 'failed', 'error_msg' => $reason]);
            app(RenderQuota::class)->release($clip->id);
            $this->notifyClip($clip, $reason);
            $reaped['clips']++;
        }

        if ($reaped['projects'] || $reaped['clips']) {
            Log::channel('clipper_jobs')->warning('Watchdog failed stuck jobs', $reaped);
        }

        return $reaped;
    }

    public function notifyProject(ClipProject $project, string $reason): void
    {
        $this->send($project->user, 'Analisis video'.($project->video_title ? " “{$project->video_title}”" : ''), $reason);
    }

    public function notifyClip(GeneratedClip $clip, string $reason): void
    {
        $this->send($clip->clipProject?->user, 'Render klip “'.$clip->topic.'”', $reason);
    }

    /** A broken mail server must never turn a failed job into a crashing one. */
    private function send(?User $user, string $what, string $reason): void
    {
        if (! $user) {
            return;
        }
        try {
            $user->notify(new JobFailed($what, $reason));
        } catch (Throwable $e) {
            Log::channel('clipper_jobs')->warning('Failure notification not sent', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
