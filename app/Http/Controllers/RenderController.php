<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Jobs\RenderClipJob;
use App\Models\GeneratedClip;
use App\Services\GeminiService;
use App\Services\MusicService;
use App\Support\HookText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RenderController extends Controller
{
    use EnsuresQueueWorker;

    public function render(Request $request, string $clipId): JsonResponse
    {
        $data = $request->validate(['hook_text' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);

        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        // Edited hook from the result screen: store it and render again even if done
        $rerender = array_key_exists('hook_text', $data);
        if ($rerender) {
            abort_if($clip->status === 'processing', 409, 'Clip masih dirender. Tunggu selesai atau batalkan dulu.');
            $clip->hook_text_override = HookText::clean($data['hook_text']);
        }

        // Already done or in-flight — return current status, frontend will poll
        if (! $rerender && in_array($clip->status, ['done', 'processing'])) {
            return response()->json(['clip_id' => $clip->id, 'status' => $clip->status]);
        }

        $clip->fill(['status' => 'processing', 'error_msg' => null, 'cancelled_at' => null])->save();

        RenderClipJob::dispatch($clip->id);
        $this->ensureQueueWorkerRunning();

        return response()->json(['clip_id' => $clip->id, 'status' => 'processing']);
    }

    public function cancel(string $clipId): JsonResponse
    {
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);

        abort_if($clip->clipProject->user_id !== Auth::id(), 403);
        abort_unless($clip->status === 'processing', 409, 'Clip tidak sedang dirender.');

        // Back to "not rendered" so it can be picked again; the job sees
        // cancelled_at, kills ffmpeg and exits without touching the status.
        $clip->update([
            'status' => $clip->output_path ? 'done' : 'pending',
            'error_msg' => null,
            'cancelled_at' => now(),
        ]);

        return response()->json(['clip_id' => $clip->id, 'status' => $clip->status]);
    }

    public function renderStatus(string $clipId): JsonResponse
    {
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);

        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        return response()->json([
            'clip_id' => $clip->id,
            'status' => $clip->status,
            'error_msg' => $clip->error_msg,
            'output_path' => $clip->output_path,
            // Music was requested but the library had nothing to pick from
            'music_missing' => $clip->status === 'done' && $clip->clipProject->music_enabled && ! $clip->music_track,
            'download_url' => $clip->status === 'done'
                ? url("/api/clips/{$clip->id}/download")
                : null,
        ]);
    }

    public function caption(string $clipId, GeminiService $gemini, MusicService $music): JsonResponse
    {
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);

        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        $credit = $music->creditFor($clip->music_track);
        $withCredit = fn (string $caption) => $music->captionWithCredit($caption, $credit);

        // Return cached caption if already generated (an empty one was a failed call: retry)
        if ($clip->caption !== null && $clip->caption !== '') {
            return response()->json([
                'caption' => $withCredit($clip->caption),
                'hashtags' => $clip->hashtags_json ?? [],
                'music_credit' => $credit,
            ]);
        }

        $lang = $clip->clipProject->transcript?->language ?? 'id';
        $segments = $clip->subtitle_json ?? [];

        $result = $gemini->generateCaption(
            $clip->topic,
            HookText::plain($clip->hook_text_override ?? $clip->hook_text ?? ''),
            $segments,
            $lang,
        );

        if ($result['caption'] !== '') {
            $clip->update([
                'caption' => $result['caption'],
                'hashtags_json' => $result['hashtags'],
            ]);
        }

        return response()->json([
            'caption' => $withCredit($result['caption']),
            'hashtags' => $result['hashtags'],
            'music_credit' => $credit,
        ]);
    }
}
