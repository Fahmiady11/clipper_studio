<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Http\Requests\StoreProjectRequest;
use App\Jobs\AnalyzeVideoJob;
use App\Models\ClipProject;
use App\Services\ProjectCreator;
use App\Support\YoutubeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProjectController extends Controller
{
    use EnsuresQueueWorker;

    public function store(StoreProjectRequest $request, ProjectCreator $creator): JsonResponse
    {
        $data = $request->validated();
        // Only ever store/hand yt-dlp a URL we rebuilt from the video id
        $project = $creator->create(Auth::id(), YoutubeUrl::canonical($data['youtube_url']), $data);
        $this->ensureQueueWorkerRunning();

        Log::channel('clipper_api')->info('Project created, AnalyzeVideoJob dispatched', [
            'project_id' => $project->id,
            'user_id' => Auth::id(),
            'url' => $project->youtube_url,
            'layout' => $project->layout_type,
            'clip_count' => $project->clip_count,
        ]);

        return response()->json([
            'project_id' => $project->id,
            'status' => $project->status,
        ], 201);
    }

    public function index(): JsonResponse
    {
        // Studio history only; autopilot projects live in the review queue on /autopilot
        $projects = ClipProject::where('user_id', Auth::id())
            ->whereNull('video_source_id')
            ->with(['generatedClips' => fn ($q) => $q->where('status', 'done')->orderBy('ranking')])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($projects->map(fn ($p) => [
            'project_id' => $p->id,
            'title' => $p->video_title,
            'thumbnail_url' => $p->thumbnail_url,
            'layout_type' => $p->layout_type,
            'status' => $p->status,
            'created_at' => $p->created_at->toISOString(),
            'rendered_clip_id' => $p->generatedClips->first()?->id,
        ]));
    }

    public function destroy(string $projectId): JsonResponse
    {
        $project = ClipProject::where('id', $projectId)
            ->where('user_id', Auth::id())
            ->whereNull('video_source_id')
            ->firstOrFail();

        $this->deleteProject($project);

        return response()->json(['success' => true]);
    }

    /** Clear the whole studio history (autopilot projects are left alone). */
    public function destroyAll(): JsonResponse
    {
        $projects = ClipProject::where('user_id', Auth::id())->whereNull('video_source_id')->get();
        $projects->each(fn (ClipProject $p) => $this->deleteProject($p));

        Log::channel('clipper_api')->info('History cleared', ['user_id' => Auth::id(), 'projects' => $projects->count()]);

        return response()->json(['deleted' => $projects->count()]);
    }

    private function deleteProject(ClipProject $project): void
    {
        // Running analyse/render jobs see the rows vanish, kill yt-dlp/ffmpeg and exit
        $project->subtitleSetting()->delete();
        $project->hookSetting()->delete();
        $project->transcript()->delete();
        $project->generatedClips()->delete();
        $project->delete();

        // Rendered MP4s and the per-project source copy (video_cache keeps its own)
        Storage::deleteDirectory("clips/{$project->user_id}/{$project->id}");
        File::deleteDirectory(storage_path('app/temp/'.$project->id));
    }

    public function cancel(string $projectId): JsonResponse
    {
        $project = ClipProject::where('user_id', Auth::id())->findOrFail($projectId);

        abort_unless(in_array($project->status, ['draft', 'processing'], true), 409, 'Analisis sudah selesai.');

        // The job polls cancelled_at, stops yt-dlp/ffmpeg mid-run and exits quietly
        $project->update([
            'status' => 'failed',
            'cancelled_at' => now(),
            'error_msg' => 'Dibatalkan oleh pengguna.',
            'progress_message' => 'Dibatalkan',
        ]);

        return response()->json(['project_id' => $project->id, 'status' => $project->status]);
    }

    /**
     * Run the analysis again on the same project after it failed or was cancelled.
     * The download and transcript are cached, so this mostly repeats the AI step.
     */
    public function retry(string $projectId): JsonResponse
    {
        $project = ClipProject::where('user_id', Auth::id())->findOrFail($projectId);

        abort_unless($project->status === 'failed', 409, 'Project tidak dalam status gagal.');

        $project->generatedClips()->delete(); // a half-saved set from the failed run
        $project->update([
            'status' => 'processing',
            'progress_stage' => 0,
            'progress_message' => 'Diulang — menunggu antrian…',
            'error_msg' => null,
            'cancelled_at' => null,
        ]);
        AnalyzeVideoJob::dispatch($project->id);
        $this->ensureQueueWorkerRunning();

        return response()->json(['project_id' => $project->id, 'status' => 'processing']);
    }

    public function status(string $projectId): JsonResponse
    {
        $project = ClipProject::with('generatedClips')
            ->where('user_id', Auth::id())
            ->findOrFail($projectId);

        $clips = $project->generatedClips->map(fn ($c) => [
            'id' => $c->id,
            'ranking' => $c->ranking,
            'start_seconds' => $c->start_seconds,
            'end_seconds' => $c->end_seconds,
            'topic' => $c->topic,
            'reason' => $c->reason,
            'viral_potential' => $c->viral_potential,
            'hook_text' => $c->hook_text,
            'status' => $c->status,
        ]);

        return response()->json([
            'project_id' => $project->id,
            'status' => $project->status,
            'progress_stage' => $project->progress_stage,
            'progress_message' => $project->progress_message,
            'error_msg' => $project->error_msg,
            'cancelled' => $project->cancelled_at !== null,
            'clips' => $clips,
        ]);
    }
}
