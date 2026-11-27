<?php

namespace App\Jobs;

use App\Exceptions\QuotaExceeded;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\Transcript;
use App\Services\ClipPerformanceSummary;
use App\Services\GeminiService;
use App\Services\JobWatchdog;
use App\Services\MusicService;
use App\Services\RenderQuota;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use App\Support\Cancellation;
use App\Support\ClipBoundaries;
use App\Support\HookText;
use App\Support\InstructionDuration;
use App\Support\JobCancelled;
use App\Support\ViralityScore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;   // 2 allows recovery from worker restart mid-job (stale reserved attempt)

    public int $timeout = 3600; // 1 hour — a long video without captions downloads its audio and goes through Whisper

    public int $backoff = 30;  // wait 30s before retry (give Gemini 503 time to recover)

    // Stages match the frontend STAGES array (index 1–6, 0 = dispatched)
    private const STAGES = [
        1 => 'Job di-dispatch ke queue',
        2 => 'Mengambil info video via yt-dlp…',
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
        return [(new WithoutOverlapping('analyze:'.$this->projectId))->expireAfter($this->timeout)->dontRelease()];
    }

    public function handle(
        YtDlpService $ytdlp,
        TranscriptService $transcriptSvc,
        GeminiService $gemini,
        ?ClipPerformanceSummary $performance = null
    ): void {
        $performance ??= app(ClipPerformanceSummary::class);
        $project = ClipProject::with(['subtitleSetting', 'hookSetting'])->find($this->projectId);
        if (! $project || $project->cancelled_at) {
            return; // cancelled or deleted from history while still waiting in the queue
        }

        try {
            Cancellation::watch(
                fn () => $this->isCancelled(),
                fn () => $this->analyze($project, $ytdlp, $transcriptSvc, $gemini, $performance)
            );
        } catch (JobCancelled) {
            // The cancel endpoint already marked the project; drop the partial download
            foreach (glob(storage_path('app/temp/'.$this->projectId.'/audio.*')) ?: [] as $partial) {
                @unlink($partial);
            }
            Log::channel('clipper_jobs')->info('AnalyzeVideoJob cancelled', ['project_id' => $this->projectId]);
        }
    }

    private function analyze(
        ClipProject $project,
        YtDlpService $ytdlp,
        TranscriptService $transcriptSvc,
        GeminiService $gemini,
        ClipPerformanceSummary $performance
    ): void {
        $log = Log::channel('clipper_jobs');

        $this->stage($project, 1);
        $log->info('AnalyzeVideoJob started', [
            'project_id' => $project->id,
            'url' => $project->youtube_url,
            'layout' => $project->layout_type,
            'clip_count' => $project->clip_count,
        ]);

        // Stage 2 — video info. The video itself is not downloaded here: captions need
        // none of it, Whisper only the sound, and each render fetches what it uses (ClipSource)
        $this->stage($project, 2);
        $tempDir = storage_path('app/temp/'.$this->projectId);
        @mkdir($tempDir, 0755, true);
        $this->storeVideoInfo($project, $ytdlp);

        // Stage 3 — transcript (skip re-fetch if already cached from a prior attempt)
        $this->stage($project, 3);
        $existing = Transcript::where('clip_project_id', $project->id)->first();
        $whisper = false;
        if ($existing && ! empty($existing->content)) {
            $log->info('Transcript loaded from cache (retry)', [
                'language' => $existing->language,
                'segments' => count($existing->content),
            ]);
            $transcriptData = ['language' => $existing->language, 'segments' => $existing->content];
        } else {
            $log->info('Fetching transcript');
            try {
                $transcriptData = $transcriptSvc->fetch($project->youtube_url, function () use ($project, $ytdlp, $tempDir, &$whisper) {
                    $whisper = true;

                    return $this->audioForWhisper($project, $ytdlp, $tempDir);
                });
            } finally {
                // The transcript is all analysis needed it for
                foreach (glob($tempDir.'/audio.*') ?: [] as $audio) {
                    @unlink($audio);
                }
            }

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
        $minDuration = $project->min_duration ?? 30;
        $maxDuration = $project->max_duration ?? 90;
        // "maks 30 detik" in the instruction: the AI gets that range, and the clips are held to it below
        $limits = InstructionDuration::parse($project->instruction);
        if ($limits) {
            $durationMode = 'manual';
            [$minDuration, $maxDuration] = InstructionDuration::promptRange($limits, $minDuration, $maxDuration);
        }

        $performanceHint = $performance->promptInsight();

        $log->info('Calling Gemini', [
            'clip_count' => $project->clip_count,
            'duration_mode' => $durationMode,
            'instruction' => $project->instruction !== null,
            'length_limits' => $limits,
        ]);

        $clips = $gemini->analyzeWithTranscript(
            $transcriptData,
            $project->clip_count,
            $project->layout_type,
            $durationMode,
            $minDuration,
            $maxDuration,
            $performanceHint,
            $performance->hookInsight($project->user_id),
            $project->instruction
        );
        $log->info('Gemini returned', ['count' => count($clips)]);

        // Stage 5 — subtitle segments (already in Gemini response, just log)
        $this->stage($project, 5);

        // Stage 6 — save to DB
        $this->stage($project, 6);
        $auto = $project->video_source_id !== null;
        $kept = [];
        foreach ($clips as $clip) {
            // Start and end on real sentence boundaries, not mid-word
            [$start, $end] = ClipBoundaries::snap((float) $clip['start_seconds'], (float) $clip['end_seconds'], $transcriptData['segments']);
            if ($limits) {
                $fitted = InstructionDuration::fit($start, $end, $transcriptData['segments'], $limits, $project->duration_seconds);
                // A clip stretched to the minimum may now cover most of another one: keep the better-ranked
                $overlaps = $fitted && collect($kept)->contains(fn ($k) => min($k[1], $fitted[1]) - max($k[0], $fitted[0]) > ($fitted[1] - $fitted[0]) / 2);
                if (! $fitted || $overlaps) {
                    $log->info('Clip dropped: does not fit the length in the instruction', ['ranking' => $clip['ranking'] ?? null, 'start' => $start, 'end' => $end, 'limits' => $limits]);

                    continue;
                }
                [$start, $end] = $fitted;
            }
            $kept[] = [$start, $end];
            $generated = GeneratedClip::create([
                'clip_project_id' => $project->id,
                'ranking' => $clip['ranking'],
                'start_seconds' => $start,
                'end_seconds' => $end,
                'topic' => $clip['topic'] ?? 'Clip #'.$clip['ranking'],
                'reason' => $clip['reason'] ?? '',
                'viral_potential' => $clip['viral_potential'] ?? 'sedang',
                'viral_score' => ViralityScore::score($clip['viral_score'] ?? null),
                'viral_reasons' => ViralityScore::reasons($clip['viral_reasons'] ?? null),
                'hook_text' => HookText::clean($clip['hook_text'] ?? null, shorten: true),
                'subtitle_json' => $clip['subtitle_segments'] ?? [],
                // B-roll is no longer requested from the analysis (EditPlanner picks it
                // at render time); keep parsing it so old responses stay usable as fallback.
                'broll_json' => array_values(array_filter((array) ($clip['broll'] ?? []), fn ($b) => is_array($b) && isset($b['time'], $b['query']))),
                'music_mood' => MusicService::normalizeMood($clip['music_mood'] ?? null),
                'status' => 'pending',
                'review_status' => $auto ? 'pending' : null,
            ]);
            // Autopilot: render every clip straight away; they land in the review queue
            if ($auto) {
                try {
                    app(RenderQuota::class)->reserve($project->user, $generated);
                    RenderClipJob::dispatch($generated->id);
                } catch (QuotaExceeded $e) {
                    app(RenderQuota::class)->block($generated, $e); // rendered once there is quota again
                    $log->warning('Autopilot render waits for quota', ['clip_id' => $generated->id, 'user_id' => $project->user_id]);
                }
            }
            $log->debug('GeneratedClip saved', ['ranking' => $clip['ranking']]);
        }

        // whereNull: a cancel that landed during the save wins over "done"
        ClipProject::whereKey($project->id)->whereNull('cancelled_at')->update(['status' => 'done', 'progress_stage' => 6]);
        $log->info('AnalyzeVideoJob done', [
            'project_id' => $project->id,
            'est_ai_cost_usd' => round(config('billing.gemini_usd_per_call') + ($whisper ? ($project->duration_seconds ?? 0) / 3600 * config('billing.groq_usd_per_audio_hour') : 0), 6),
        ]);
    }

    /**
     * Length, title and channel from yt-dlp, without downloading anything: the
     * length lets ClipSource weigh sections against the whole video, bounds
     * manual clips and instruction-length clips. Autopilot already set title
     * and channel from its listing; those are kept. Not worth failing for.
     */
    private function storeVideoInfo(ClipProject $project, YtDlpService $ytdlp): void
    {
        try {
            $meta = $ytdlp->getMetadata($project->youtube_url);
        } catch (\RuntimeException $e) {
            Log::channel('clipper_jobs')->warning('Video info unavailable, continuing without it', ['error' => mb_substr($e->getMessage(), -300)]);

            return;
        }

        // getMetadata says 'Unknown' for a missing title or channel
        $known = fn (?string $v) => $v !== null && $v !== '' && $v !== 'Unknown';
        $thumbnail = $meta['thumbnail_url'] ?? null;
        $project->update(array_filter([
            'duration_seconds' => $meta['duration_seconds'] ?: null,
            'video_title' => $project->video_title ?: ($known($meta['title']) ? mb_substr($meta['title'], 0, 255) : null),
            'source_channel' => $project->source_channel ?: ($known($meta['channel']) ? mb_substr($meta['channel'], 0, 150) : null),
            'thumbnail_url' => $project->thumbnail_url ?: ($thumbnail && strlen($thumbnail) <= 255 ? $thumbnail : null),
        ], fn ($v) => $v !== null));
        Log::channel('clipper_jobs')->info('Video info', ['duration_s' => $project->duration_seconds, 'title' => $project->video_title]);
    }

    /** The sound for Whisper: the full download when video_cache has one, else audio only (a few % of the video's bytes) */
    private function audioForWhisper(ClipProject $project, YtDlpService $ytdlp, string $tempDir): string
    {
        $log = Log::channel('clipper_jobs');
        if ($ytdlp->isCached($project->youtube_url)) {
            $log->info('Whisper reads the cached video');

            return $ytdlp->cachedPathFor($project->youtube_url);
        }

        $project->update(['progress_message' => 'Tidak ada subtitle, mengunduh audio untuk transkripsi…']);
        $log->info('No captions, downloading audio for Whisper');
        $path = $ytdlp->downloadAudio($project->youtube_url, $tempDir);
        $log->info('Audio downloaded', ['size_mb' => round(filesize($path) / 1048576, 1)]);

        return $path;
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('AnalyzeVideoJob failed', [
            'project_id' => $this->projectId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $failed = ClipProject::where('id', $this->projectId)->whereNull('cancelled_at')->update([
            'status' => 'failed',
            'error_msg' => $e->getMessage(),
            'progress_message' => mb_substr('Gagal: '.$e->getMessage(), 0, 119),
        ]);
        if ($failed) {
            app(JobWatchdog::class)->notifyProject(ClipProject::with('user')->find($this->projectId), $e->getMessage());
        }
    }

    private function isCancelled(): bool
    {
        // A project deleted from history counts as cancelled too
        return ! ClipProject::whereKey($this->projectId)->whereNull('cancelled_at')->exists();
    }

    private function stage(ClipProject $project, int $n): void
    {
        Cancellation::throwIfCancelled(); // stage boundaries are the cancel checkpoints

        $tries = 0;
        do {
            try {
                $project->update([
                    'progress_stage' => $n,
                    'progress_message' => self::STAGES[$n] ?? '',
                ]);

                return;
            } catch (QueryException $e) {
                if ($tries++ >= 5 || ! str_contains($e->getMessage(), 'database is locked')) {
                    throw $e;
                }
                usleep(200_000 * $tries); // 0.2s, 0.4s, 0.6s…
            }
        } while (true);
    }
}
