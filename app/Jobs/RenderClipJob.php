<?php

namespace App\Jobs;

use App\Models\GeneratedClip;
use App\Services\FaceTracker;
use App\Services\FFmpegService;
use App\Services\GeminiService;
use App\Services\HookRenderer;
use App\Services\MusicService;
use App\Services\SmartCut;
use App\Services\StockFootage;
use App\Services\YtDlpService;
use App\Support\Cancellation;
use App\Support\JobCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RenderClipJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    private const BROLL_SECONDS = 2.2; // length of one stock-footage cut-in

    public int $timeout = 1800; // two libx264 "slow" passes on long clips can take >10 min

    public function __construct(public string $clipId) {}

    /**
     * Never run the same clip twice in parallel: a stale copy handed out
     * again while the original is still working is dropped instead of restarting.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('render:'.$this->clipId))->expireAfter($this->timeout)->dontRelease()];
    }

    public function handle(FFmpegService $ffmpeg, MusicService $music, YtDlpService $ytdlp, HookRenderer $hooks): void
    {
        $clip = GeneratedClip::with(['clipProject.subtitleSetting', 'clipProject.hookSetting', 'clipProject.transcript'])->find($this->clipId);
        if (! $clip || $clip->cancelled_at || $clip->review_status === 'rejected') {
            return; // cancelled, deleted from history or rejected while still waiting in the queue
        }

        $tempDir = storage_path('app/temp/'.$clip->clip_project_id);

        try {
            Cancellation::watch(
                // Deleted (history cleared) counts as cancelled too
                fn () => ! GeneratedClip::whereKey($this->clipId)->whereNull('cancelled_at')->exists(),
                fn () => $this->render($clip, $ffmpeg, $music, $ytdlp, $hooks)
            );
        } catch (JobCancelled) {
            // The cancel endpoint already reset the clip; just drop the half-made files
            $this->cleanupTemp($tempDir, $this->clipId);
            Log::channel('clipper_jobs')->info('RenderClipJob cancelled', ['clip_id' => $this->clipId]);
        }
    }

    private function render(GeneratedClip $clip, FFmpegService $ffmpeg, MusicService $music, YtDlpService $ytdlp, HookRenderer $hooks): void
    {
        $log = Log::channel('clipper_jobs');
        $project = $clip->clipProject;

        $clip->update(['status' => 'processing']);

        $log->info('RenderClipJob started', [
            'clip_id' => $this->clipId,
            'project_id' => $project->id,
            'layout' => $project->layout_type,
            'start' => $clip->start_seconds,
            'end' => $clip->end_seconds,
        ]);

        $tempDir = storage_path('app/temp/'.$project->id);
        $srcVideo = $tempDir.'/video.mp4';

        if (! file_exists($srcVideo)) {
            // Source removed by clipper:cleanup — fetch it again (served from video_cache when still there)
            $log->info('Source video missing, re-downloading', ['project_id' => $project->id]);
            @mkdir($tempDir, 0755, true);
            $ytdlp->download($project->youtube_url, $srcVideo);
        }

        $step2 = $tempDir.'/layout_'.$this->clipId.'.mp4';

        // 1+2. Cut + layout are part of the single re-encode pass below. A
        // stream-copy cut snaps to the previous keyframe, so the clip started up
        // to a few seconds early and subtitles landed late by a varying amount.
        // Reframe follows the faces (and the speaker on a two-shot) when the tracker is available
        $track = null;
        if ($project->layout_type === 'reframe') {
            $track = app(FaceTracker::class)->plan($srcVideo, (float) $clip->start_seconds, (float) $clip->end_seconds);
            $log->info('Face tracking', ['pieces' => $track ? count($track) : 0]);
        }

        // Transcript lines of this clip: they drive the cuts, the zoom and the subtitles
        $clipStart = (float) $clip->start_seconds;
        $rawSegments = $project->transcript?->content ?? $clip->subtitle_json ?? [];
        $clipSegments = array_values(array_filter($rawSegments, fn ($s) => ($s['end'] ?? 0) > $clip->start_seconds &&
            ($s['start'] ?? 0) < $clip->end_seconds
        ));

        // Jump cuts drop pauses and filler words; punch-ins change at cuts and sentence ends
        $pieces = [[0.0, (float) $clip->end_seconds - $clipStart]];
        if ($project->jump_cut) {
            $words = array_merge([], ...array_map(fn ($s) => $s['words'] ?? [], $clipSegments));
            $pieces = app(SmartCut::class)->pieces($srcVideo, $clipStart, (float) $clip->end_seconds, $words);
            $clipSegments = SmartCut::remapSegments($clipSegments, $pieces, $clipStart);
        }
        $clipDuration = SmartCut::outputDuration($pieces);
        $zoom = [];
        if ($project->auto_zoom) {
            $words = array_map(fn ($w) => ['text' => $w['text'] ?? '', 'start' => $w['start'] - $clipStart, 'end' => ($w['end'] ?? $w['start']) - $clipStart],
                array_merge([], ...array_map(fn ($s) => $s['words'] ?? [], $clipSegments)));
            $zoom = SmartCut::zoomPlan($pieces, $words, $clipDuration);
        }
        $log->info('Smart cut', ['pieces' => count($pieces), 'duration' => round($clipDuration, 2), 'zoom' => count($zoom)]);

        // 3. Resolve hook first — its duration drives subtitle suppression
        $hook = $project->hookSetting;
        $hookText = null;
        $hookOverlay = null;
        $hookDuration = 0.0;
        if ($hook && $hook->enabled) {
            // A per-clip edit from the result screen beats the project/AI text
            $text = $clip->hook_text_override
                ?: ($hook->is_ai_generated ? ($clip->hook_text ?? $hook->hook_text) : $hook->hook_text);
            if ($text) {
                $hookText = $text;
                $hookDuration = (float) $hook->duration_seconds;
                $image = $tempDir.'/hook_'.$this->clipId.'.png';
                $hookOverlay = ['image' => $image] + $hooks->render($text, [
                    'text_color' => $hook->text_color,
                    'background_style' => $hook->background_style,
                    'position' => $hook->position,
                    // Hook shares the subtitle font and highlight so the clip has one look
                    'font_family' => $project->subtitleSetting?->font_family ?? 'Montserrat',
                    'accent_color' => $project->subtitleSetting?->highlight_color ?? '#facc15',
                ], $image);
            }
        }

        // 4. Build subtitle ASS using real transcript timestamps (Fix 4)
        $assPath = null;
        $subtitle = $project->subtitleSetting;
        if ($subtitle && $subtitle->enabled) {
            // DB transcript segments (real YouTube caption timestamps) rather than
            // Gemini's subtitle_json (AI approximation, drifts); on the cut timeline.
            if (! empty($clipSegments)) {
                $log->info('Building subtitles', [
                    'font' => $subtitle->font_family,
                    'position' => $subtitle->position,
                    'source' => $project->transcript ? 'transcript' : 'subtitle_json',
                    'segments' => count($clipSegments),
                ]);
                $assPath = $tempDir.'/sub_'.$this->clipId.'.ass';
                $assContent = $ffmpeg->buildAssFile($clipSegments, [
                    'font_family' => $subtitle->font_family,
                    'font_size' => $subtitle->font_size,
                    'text_color' => $subtitle->text_color,
                    'highlight_color' => $subtitle->highlight_color,
                    'position' => $subtitle->position,
                    'position_y' => $subtitle->position_y,
                    'background_style' => $subtitle->background_style,
                    'style' => $subtitle->style,
                ], $clipStart, $hookDuration);
                file_put_contents($assPath, $assContent);
            } else {
                $log->debug('Subtitles skipped — no segments in clip range', [
                    'enabled' => $subtitle->enabled,
                    'has_json' => ! empty($clip->subtitle_json),
                ]);
            }
        } else {
            $log->debug('Subtitles skipped', ['enabled' => $subtitle?->enabled]);
        }

        // Emoji pop in over keywords of the subtitle words just built
        $emojis = $assPath && $subtitle->emoji
            ? $ffmpeg->emojiOverlays($clipDuration)
            : [];

        // 5. One pass from the source: cut + layout + jump cuts + zoom, with the
        // subtitles, hook and emoji drawn on top (a single encode, audio kept)
        // Title held at the top once the hook is done; progress bar in the highlight colour
        $titlePath = null;
        if ($project->top_title && trim((string) $clip->topic) !== '' && $clipDuration - $hookDuration > 1) {
            $titlePath = $tempDir.'/title_'.$this->clipId.'.ass';
            file_put_contents($titlePath, $ffmpeg->buildTitleAss((string) $clip->topic, $hookDuration, $clipDuration, $subtitle?->font_family ?? 'Montserrat'));
        }
        $progress = $project->progress_bar
            ? ['color' => $subtitle?->highlight_color ?? '#facc15', 'duration' => $clipDuration]
            : null;

        // B-roll: stock footage over concrete things being talked about, after the hook
        $broll = [];
        $stock = app(StockFootage::class);
        if ($project->broll && $stock->configured()) {
            $words = array_map(fn ($w) => ['text' => $w['text'] ?? '', 'start' => $w['start'] - $clipStart],
                array_merge([], ...array_map(fn ($s) => $s['words'] ?? [], $clipSegments)));
            $used = [];
            foreach (app(GeminiService::class)->brollCues($words, $clipDuration, $hookDuration + 1.0) as $cue) {
                $footage = $stock->clip($cue['query'], $used);
                if ($footage) {
                    $used[] = $footage['id'];
                    $broll[] = ['path' => $footage['path'], 'start' => $cue['time'], 'duration' => min(self::BROLL_SECONDS, $clipDuration - $cue['time'] - 0.5)];
                }
            }
            $log->info('B-roll', ['cues' => array_map(fn ($b) => round($b['start'], 1), $broll)]);
        }

        $log->info('Rendering', ['hook' => $hookText, 'hook_dur' => $hookDuration, 'subs' => (bool) $assPath, 'emoji' => count($emojis), 'title' => (bool) $titlePath, 'progress' => (bool) $progress]);
        $ffmpeg->applyLayout($srcVideo, $project->layout_type, $step2, $clipStart, (float) $clip->end_seconds, $track, $pieces, $zoom, [
            'ass' => $assPath, 'hook' => $hookOverlay, 'hook_duration' => $hookDuration, 'emojis' => $emojis,
            'title' => $titlePath, 'progress' => $progress, 'broll' => $broll,
        ]);
        $current = $step2;
        Cancellation::throwIfCancelled();

        // 6. Background music (ducked under the voice)
        $musicTrack = null;
        if ($project->music_enabled) {
            $mood = $project->music_mood ?? $clip->music_mood;
            $track = $music->pickTrack($mood, $clip->id);
            if ($track) {
                $log->info('Mixing music', ['mood' => $mood, 'track' => basename($track), 'volume' => $project->music_volume]);
                $withMusic = $tempDir.'/music_'.$this->clipId.'.mp4';
                $ffmpeg->mixMusic($current, $track, (int) $project->music_volume, $withMusic);
                $current = $withMusic;
                $musicTrack = $music->relative($track); // "mood/file.mp3" — keys the credit row
            } else {
                $log->warning('Music enabled but library is empty — skipped', ['path' => config('services.music.path')]);
            }
        }
        // Without music nothing levelled the voice yet
        if (! $musicTrack) {
            $levelled = $tempDir.'/loud_'.$this->clipId.'.mp4';
            if ($ffmpeg->normalizeVoice($current, $levelled)) {
                $current = $levelled;
            }
        }

        // 7. Move to output
        Cancellation::throwIfCancelled();
        $outDir = 'clips/'.$project->user_id.'/'.$project->id;
        Storage::makeDirectory($outDir);
        $outPath = $outDir.'/'.$this->clipId.'.mp4';
        Storage::put($outPath, file_get_contents($current));

        $this->cleanupTemp($tempDir, $this->clipId);

        // whereNull: a cancel that landed during the final copy wins over "done"
        GeneratedClip::whereKey($this->clipId)->whereNull('cancelled_at')
            ->update(['output_path' => $outPath, 'status' => 'done', 'music_track' => $musicTrack]);

        $log->info('RenderClipJob done', [
            'clip_id' => $this->clipId,
            'output' => $outPath,
            'size_mb' => round(Storage::size($outPath) / 1048576, 2),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('RenderClipJob failed', [
            'clip_id' => $this->clipId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        GeneratedClip::where('id', $this->clipId)->whereNull('cancelled_at')->update([
            'status' => 'failed',
            'error_msg' => $e->getMessage(),
        ]);
    }

    private function cleanupTemp(string $dir, string $clipId): void
    {
        foreach (['cut_', 'layout_', 'subtitled_', 'hook_', 'final_', 'sub_', 'music_', 'loud_', 'title_'] as $prefix) {
            foreach (['.mp4', '.ass', '.png'] as $ext) {
                $f = $dir.'/'.$prefix.$clipId.$ext;
                if (file_exists($f)) {
                    @unlink($f);
                }
            }
        }
    }
}
