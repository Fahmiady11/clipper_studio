<?php

namespace App\Http\Controllers;

use App\Models\ClipProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AgentClipController extends Controller
{
    public function index(string $projectId): JsonResponse
    {
        $project = ClipProject::with(['generatedClips' => fn ($q) => $q->orderBy('ranking')])
            ->where('user_id', Auth::id())
            ->findOrFail($projectId);

        return response()->json([
            'project_id' => $project->id,
            'status' => $project->status,
            'clips' => $project->generatedClips->map(fn ($c) => [
                'id' => $c->id,
                'ranking' => $c->ranking,
                'start_seconds' => $c->start_seconds,
                'end_seconds' => $c->end_seconds,
                'topic' => $c->topic,
                'reason' => $c->reason,
                'viral_score' => $c->viral_score,
                'hook_text' => $c->hook_text_override ?? $c->hook_text,
                'status' => $c->status,
                'error_msg' => $c->status === 'failed' ? $c->error_msg : null,
                'download_url' => $c->status === 'done' ? url("/api/v1/clips/{$c->id}/download") : null,
                'youtube_status' => $c->youtube_status,
                'youtube_url' => $c->youtube_video_id ? 'https://youtube.com/shorts/'.$c->youtube_video_id : null,
                'tiktok_status' => $c->tiktok_status,
            ])->values(),
        ]);
    }

    public function accounts(): JsonResponse
    {
        $tiktok = app(TikTokController::class)->accounts()->getData(true);
        $youtube = app(YouTubeController::class)->accounts()->getData(true);

        return response()->json([
            'youtube' => $youtube['accounts'],
            'tiktok' => $tiktok['accounts'],
        ]);
    }
}
