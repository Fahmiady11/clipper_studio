<?php

namespace App\Http\Controllers;

use App\Exceptions\QuotaExceeded;
use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Jobs\DescribeClipJob;
use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Services\RenderQuota;
use App\Support\ClipBoundaries;
use App\Support\InstructionDuration;
use App\Support\TranscriptText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/** Clips the user cuts from the transcript of an analysed studio project, next to the AI's picks. */
class ManualClipController extends Controller
{
    use EnsuresQueueWorker;

    // Each one costs an AI call for its title and hook
    private const MAX_CLIPS_PER_PROJECT = 30;

    /** The whole transcript as caption lines, for picking a range. */
    public function transcript(string $projectId): JsonResponse
    {
        $project = $this->ownedProject($projectId);

        return response()->json([
            'project_id' => $project->id,
            'language' => $project->transcript?->language,
            'duration_seconds' => $project->duration_seconds,
            'lines' => TranscriptText::lines($project->transcript?->content ?? []),
            // Lengths from the instruction, for a hint only: a range picked by hand is kept as picked
            'length_limits' => ($limits = InstructionDuration::parse($project->instruction)) ? $limits + ['label' => InstructionDuration::label($limits)] : null,
        ]);
    }

    public function store(Request $request, string $projectId, RenderQuota $quota): JsonResponse
    {
        $project = $this->ownedProject($projectId);
        abort_unless($project->status === 'done', 409, 'Analisis video ini belum selesai.');
        $segments = $project->transcript?->content ?? [];
        abort_if(! $segments, 422, 'Transkrip video ini tidak tersedia.');
        abort_if($project->generatedClips()->count() >= self::MAX_CLIPS_PER_PROJECT, 422, 'Project ini sudah punya '.self::MAX_CLIPS_PER_PROJECT.' clip. Hapus atau pakai yang ada dulu.');

        $data = $request->validate([
            'start_seconds' => ['required', 'numeric', 'min:0'],
            'end_seconds' => ['required', 'numeric', 'gt:start_seconds'],
            'render' => ['sometimes', 'boolean'],
        ]);

        // The picked lines rarely start or end exactly on a sentence: snap like AI clips do
        [$start, $end] = ClipBoundaries::snap((float) $data['start_seconds'], (float) $data['end_seconds'], $segments);
        $duration = $end - $start;
        abort_if($duration < GeneratedClip::MIN_SECONDS || $duration > GeneratedClip::MAX_SECONDS, 422, 'Durasi clip harus 3 detik sampai 5 menit.');
        abort_if($project->duration_seconds && $end > $project->duration_seconds + 1, 422, 'Akhir clip melewati durasi video.');

        $text = TranscriptText::between($segments, $start, $end);
        abort_if($text === '', 422, 'Tidak ada ucapan di bagian ini. Pilih bagian lain.');

        $clip = GeneratedClip::create([
            'clip_project_id' => $project->id,
            'ranking' => (int) GeneratedClip::where('clip_project_id', $project->id)->max('ranking') + 1,
            'start_seconds' => $start,
            'end_seconds' => $end,
            // Placeholder until DescribeClipJob has the AI's title
            'topic' => Str::words($text, 8, '…'),
            'reason' => '',
            'viral_potential' => 'sedang',
            'subtitle_json' => [],
            'status' => 'pending',
            'manual' => true,
        ]);

        if ($data['render'] ?? true) {
            try {
                $quota->reserve(Auth::user(), $clip);
            } catch (QuotaExceeded $e) {
                $clip->delete();

                return response()->json(['message' => $e->getMessage()], 402);
            }
            $clip->update(['status' => 'processing']);
            // Title and hook first: the render burns the hook into the video
            Bus::chain([new DescribeClipJob($clip->id), new RenderClipJob($clip->id)])->dispatch();
        } else {
            DescribeClipJob::dispatch($clip->id);
        }
        $this->ensureQueueWorkerRunning();

        return response()->json(['clip' => [
            'id' => $clip->id,
            'ranking' => $clip->ranking,
            'start_seconds' => $clip->start_seconds,
            'end_seconds' => $clip->end_seconds,
            'topic' => $clip->topic,
            'reason' => $clip->reason,
            'viral_potential' => $clip->viral_potential,
            'viral_score' => null,
            'viral_reasons' => null,
            'hook_text' => null,
            'manual' => true,
            'status' => $clip->status,
            'error_msg' => null,
        ]], 201);
    }

    /** Studio projects only: autopilot clips go through the review queue instead. */
    private function ownedProject(string $projectId): ClipProject
    {
        return ClipProject::with('transcript')
            ->where('user_id', Auth::id())
            ->whereNull('video_source_id')
            ->findOrFail($projectId);
    }
}
