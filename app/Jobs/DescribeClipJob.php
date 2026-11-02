<?php

namespace App\Jobs;

use App\Models\GeneratedClip;
use App\Services\ClipPerformanceSummary;
use App\Services\GeminiService;
use App\Services\MusicService;
use App\Services\RenderQuota;
use App\Support\HookText;
use App\Support\TranscriptText;
use App\Support\ViralityScore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Title, hook and score for a clip the user cut from the transcript, so it
 * renders like an AI clip. Runs before RenderClipJob in a chain and never
 * throws: without an AI answer the clip keeps its placeholder title and
 * renders anyway (an exception would stop the chain and strand the render).
 */
class DescribeClipJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    private const POTENTIAL = ['tinggi', 'sedang', 'rendah'];

    public function __construct(public string $clipId) {}

    public function handle(GeminiService $gemini, ClipPerformanceSummary $performance): void
    {
        $clip = GeneratedClip::with('clipProject.transcript')->find($this->clipId);
        if (! $clip) {
            return; // deleted from history while waiting
        }
        $project = $clip->clipProject;
        $text = TranscriptText::between($project->transcript?->content ?? [], (float) $clip->start_seconds, (float) $clip->end_seconds);

        try {
            $d = $gemini->describeClip($text, $project->transcript?->language ?? 'id', $project->instruction, $performance->hookInsight($project->user_id));
        } catch (Throwable $e) {
            Log::channel('clipper_jobs')->warning('DescribeClipJob: no AI answer, keeping placeholder title', ['clip_id' => $clip->id, 'error' => $e->getMessage()]);

            return;
        }

        $potential = is_string($d['viral_potential'] ?? null) ? mb_strtolower($d['viral_potential']) : null;
        $clip->update([
            'topic' => mb_substr(trim((string) $d['topic']), 0, 200),
            'reason' => mb_substr(trim(is_string($d['reason'] ?? null) ? $d['reason'] : ''), 0, 500),
            'viral_score' => ViralityScore::score($d['viral_score'] ?? null),
            'viral_reasons' => ViralityScore::reasons($d['viral_reasons'] ?? null),
            'viral_potential' => in_array($potential, self::POTENTIAL, true) ? $potential : 'sedang',
            'hook_text' => HookText::clean(is_string($d['hook_text'] ?? null) ? $d['hook_text'] : null, shorten: true),
            'music_mood' => MusicService::normalizeMood(is_string($d['music_mood'] ?? null) ? $d['music_mood'] : null),
        ]);
    }

    /** Killed (timeout) before the chain reached the render: free the clip and its quota. */
    public function failed(Throwable $e): void
    {
        GeneratedClip::whereKey($this->clipId)->where('status', 'processing')->whereNull('cancelled_at')
            ->update(['status' => 'failed', 'error_msg' => 'Gagal menyiapkan clip: '.mb_substr($e->getMessage(), 0, 200)]);
        app(RenderQuota::class)->release($this->clipId);
    }
}
