<?php

namespace App\Jobs;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\Transcript;
use App\Services\GeminiService;
use App\Services\MusicService;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use App\Support\Cancellation;
use App\Support\HookText;
use App\Support\JobCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries       = 2;   // 2 allows recovery from worker restart mid-job (stale reserved attempt)
    public int $timeout     = 3600; // 1 hour — large videos (700MB+) take 15-30 min to download
    public int $backoff     = 30;  // wait 30s before retry (give Gemini 503 time to recover)

    // Stages match the frontend STAGES array (index 1–6, 0 = dispatched)
    private const STAGES = [
        1 => 'Job di-dispatch ke queue',
        2 => 'Mengunduh video via yt-dlp…',
        3 => 'Mengambil transcript YouTube…',
        4 => 'Mengirim transcript ke Gemini…',
        5 => 'Menyusun segmen subtitle…',
        6 => 'Menyimpan hasil ke database…',
    ];

    public function __construct(public string $projectId) {}

    /**
     * Never run the same project twice in parallel: a stale copy handed out
     * again while the original is still working is dropped instead of restarting.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('analyze:' . $this->projectId))->expireAfter($this->timeout)->dontRelease()];
    }

    public function handle(YtDlpService $ytdlp, TranscriptService $transcriptSvc, GeminiService $gemini): void
    {
        $project = ClipProject::with(['subtitleSetting', 'hookSetting'])->find($this->projectId);
        if (!$project || $project->cancelled_at) {
            return; // cancelled or deleted from history while still waiting in the queue
        }

        try {
            Cancellation::watch(
                fn() => $this->isCancelled(),
                fn() => $this->analyze($project, $ytdlp, $transcriptSvc, $gemini)
            );
        } catch (JobCancelled) {
            // The cancel endpoint already marked the project; drop the partial download
            foreach (glob(storage_path('app/temp/' . $this->projectId . '/video.*')) ?: [] as $partial) {
                @unlink($partial);
            }
            Log::channel('clipper_jobs')->info('AnalyzeVideoJob cancelled', ['project_id' => $this->projectId]);
        }
    }

    private function analyze(ClipProject $project, YtDlpService $ytdlp, TranscriptService $transcriptSvc, GeminiService $gemini): void
    {
        $log = Log::channel('clipper_jobs');

        $this->stage($project, 1);
        $log->info('AnalyzeVideoJob started', [
            'project_id' => $project->id,
            'url'        => $project->youtube_url,
            'layout'     => $project->layout_type,
            'clip_count' => $project->clip_count,
        ]);

        // Stage 2 — download
        $this->stage($project, 2);
        $tempDir   = storage_path('app/temp/' . $this->projectId);
        @mkdir($tempDir, 0755, true);
        $videoPath = $tempDir . '/video.mp4';

        $log->info('Downloading video', ['dest' => $videoPath]);
        $ytdlp->download($project->youtube_url, $videoPath);
        $log->info('Download complete', ['size_mb' => round(filesize($videoPath) / 1048576, 1)]);

        // Stage 3 — transcript (skip re-fetch if already cached from a prior attempt)
        $this->stage($project, 3);
        $existing = Transcript::where('clip_project_id', $project->id)->first();
        if ($existing && !empty($existing->content)) {
            $log->info('Transcript loaded from cache (retry)', [
                'language' => $existing->language,
                'segments' => count($existing->content),
            ]);
            $transcriptData = ['language' => $existing->language, 'segments' => $existing->content];
        } else {
            $log->info('Fetching transcript');
            $transcriptData = $transcriptSvc->fetch($project->youtube_url, $videoPath);

            if (empty($transcriptData['segments'])) {
                throw new \RuntimeException('Transcript kosong — yt-dlp gagal ambil subtitle (rate-limit/no captions). Coba ulang.');
            }

            Transcript::updateOrCreate(
                ['clip_project_id' => $project->id],
                ['language' => $transcriptData['language'], 'content' => $transcriptData['segments']]
            );
            $log->info('Transcript saved', [
                'language' => $transcriptData['language'],
                'segments' => count($transcriptData['segments']),
            ]);
        }

        // Stage 4 — Gemini
        $this->stage($project, 4);
        $durationMode = $project->duration_mode;
        $minDuration  = $project->min_duration ?? 30;
        $maxDuration  = $project->max_duration ?? 90;

        $log->info('Calling Gemini', [
            'clip_count'    => $project->clip_count,
            'duration_mode' => $durationMode,
        ]);

        $clips = $gemini->analyzeWithTranscript(
            $transcriptData,
            $project->clip_count,
            $project->layout_type,
            $durationMode,
            $minDuration,
            $maxDuration
        );
        $log->info('Gemini returned', ['count' => count($clips)]);

        // Stage 5 — subtitle segments (already in Gemini response, just log)
        $this->stage($project, 5);

        // Stage 6 — save to DB
        $this->stage($project, 6);
        $auto = $project->video_source_id !== null;
        foreach ($clips as $clip) {
            $generated = GeneratedClip::create([
                'clip_project_id' => $project->id,
                'ranking'         => $clip['ranking'],
                'start_seconds'   => $clip['start_seconds'],
                'end_seconds'     => $clip['end_seconds'],
                'topic'           => $clip['topic'] ?? 'Clip #' . $clip['ranking'],
                'reason'          => $clip['reason'] ?? '',
                'viral_potential' => $clip['viral_potential'] ?? 'sedang',
                'hook_text'       => HookText::clean($clip['hook_text'] ?? null, shorten: true),
                'subtitle_json'   => $clip['subtitle_segments'] ?? [],
                'music_mood'      => MusicService::normalizeMood($clip['music_mood'] ?? null),
                'status'          => 'pending',
                'review_status'   => $auto ? 'pending' : null,
            ]);
            // Autopilot: render every clip straight away; they land in the review queue
            if ($auto) {
                RenderClipJob::dispatch($generated->id);
            }
            $log->debug('GeneratedClip saved', ['ranking' => $clip['ranking']]);
        }

        // whereNull: a cancel that landed during the save wins over "done"
        ClipProject::whereKey($project->id)->whereNull('cancelled_at')->update(['status' => 'done', 'progress_stage' => 6]);
        $log->info('AnalyzeVideoJob done', ['project_id' => $project->id]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('AnalyzeVideoJob failed', [
            'project_id' => $this->projectId,
            'error'      => $e->getMessage(),
            'trace'      => $e->getTraceAsString(),
        ]);

        ClipProject::where('id', $this->projectId)->whereNull('cancelled_at')->update([
            'status'           => 'failed',
            'error_msg'        => $e->getMessage(),
            'progress_message' => mb_substr('Gagal: ' . $e->getMessage(), 0, 119),
        ]);
    }

    private function isCancelled(): bool
    {
        // A project deleted from history counts as cancelled too
        return !ClipProject::whereKey($this->projectId)->whereNull('cancelled_at')->exists();
    }

    private function stage(ClipProject $project, int $n): void
    {
        Cancellation::throwIfCancelled(); // stage boundaries are the cancel checkpoints

        $tries = 0;
        do {
            try {
                $project->update([
                    'progress_stage'   => $n,
                    'progress_message' => self::STAGES[$n] ?? '',
                ]);
                return;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($tries++ >= 5 || !str_contains($e->getMessage(), 'database is locked')) {
                    throw $e;
                }
                usleep(200_000 * $tries); // 0.2s, 0.4s, 0.6s…
            }
        } while (true);
    }
}
