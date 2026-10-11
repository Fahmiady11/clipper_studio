<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Jobs\DiscoverVideosJob;
use App\Jobs\UploadToTikTokJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use App\Models\VideoSource;
use App\Services\MusicService;
use App\Services\ProjectCreator;
use App\Services\StorageCleanup;
use App\Support\ClipSettings;
use App\Support\YoutubeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AutopilotController extends Controller
{
    use EnsuresQueueWorker;

    public function sources(): JsonResponse
    {
        $sources = VideoSource::where('user_id', Auth::id())
            ->withCount('discoveredVideos')
            ->latest()
            ->get();

        return response()->json($sources->map(fn (VideoSource $s) => [
            'id' => $s->id,
            'type' => $s->type,
            'value' => $s->value,
            'enabled' => $s->enabled,
            'interval_hours' => $s->interval_hours,
            'max_per_run' => $s->max_per_run,
            'min_minutes' => intdiv($s->min_video_seconds, 60),
            'max_minutes' => intdiv($s->max_video_seconds, 60),
            'videos_found' => $s->discovered_videos_count,
            'last_run_at' => $s->last_run_at?->toISOString(),
            'last_error' => $s->last_error,
            'settings' => ClipSettings::forAutopilot($s->settings),
        ]));
    }

    /** Starting look for a new source: the user's latest manual project, else defaults. */
    public function defaults(ProjectCreator $creator): JsonResponse
    {
        return response()->json(ClipSettings::forAutopilot($creator->settingsFrom($this->latestManualProject())));
    }

    public function storeSource(Request $request, ProjectCreator $creator): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:channel,keyword'],
            'value' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($request) {
                if ($request->input('type') === 'channel' && ! YoutubeUrl::channel($value)) {
                    $fail('Channel tidak valid. Pakai @namachannel atau link youtube.com/@namachannel.');
                }
                if ($request->input('type') === 'keyword' && preg_match('/[\r\n]/', $value)) {
                    $fail('Keyword tidak boleh berisi baris baru.');
                }
            }],
            'interval_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'max_per_run' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'min_minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'max_minutes' => ['sometimes', 'integer', 'min:1', 'max:600', 'gte:min_minutes'],
        ] + ClipSettings::rules('settings.'));

        // Look chosen in the form, else the user's latest manual project
        $settings = $data['settings'] ?? $creator->settingsFrom($this->latestManualProject());

        $source = VideoSource::create([
            'user_id' => Auth::id(),
            'type' => $data['type'],
            'value' => $data['type'] === 'channel' ? YoutubeUrl::channel($data['value']) : trim($data['value']),
            'interval_hours' => $data['interval_hours'] ?? 6,
            'max_per_run' => $data['max_per_run'] ?? 1,
            'min_video_seconds' => ($data['min_minutes'] ?? 5) * 60,
            'max_video_seconds' => ($data['max_minutes'] ?? 120) * 60,
            'settings' => ClipSettings::forAutopilot($settings),
        ]);

        return response()->json(['id' => $source->id], 201);
    }

    public function updateSource(Request $request, int $sourceId): JsonResponse
    {
        $source = $this->ownedSource($sourceId);
        $data = $request->validate(['enabled' => ['sometimes', 'boolean']] + ClipSettings::rules('settings.'));
        if (isset($data['settings'])) {
            // Applies to clips made from now on; ones already made keep their look
            $data['settings'] = ClipSettings::forAutopilot(array_replace_recursive($source->settings ?? [], $data['settings']));
        }
        $source->update($data);

        return response()->json(['success' => true]);
    }

    public function destroySource(int $sourceId): JsonResponse
    {
        $this->ownedSource($sourceId)->delete();

        return response()->json(['success' => true]);
    }

    public function runSource(int $sourceId): JsonResponse
    {
        $source = $this->ownedSource($sourceId);
        $source->update(['last_run_at' => now()]);
        DiscoverVideosJob::dispatch($source->id);
        $this->ensureQueueWorkerRunning();

        return response()->json(['success' => true]);
    }

    /** Autopilot clips: waiting for review first, then the most recent decisions. */
    public function review(MusicService $music): JsonResponse
    {
        $base = GeneratedClip::query()
            ->whereNotNull('review_status')
            ->whereHas('clipProject', fn ($q) => $q->where('user_id', Auth::id()))
            ->with('clipProject.videoSource');

        $clips = (clone $base)->where('review_status', 'pending')->oldest()->get()
            ->concat((clone $base)->where('review_status', '!=', 'pending')->latest('updated_at')->limit(20)->get());

        return response()->json($clips->map(fn (GeneratedClip $c) => [
            'id' => $c->id,
            'review_status' => $c->review_status,
            'status' => $c->status,
            'error_msg' => $c->error_msg,
            'topic' => $c->topic,
            'reason' => $c->reason,
            'viral_potential' => $c->viral_potential,
            'duration' => round($c->durationSeconds()),
            'video_title' => $c->clipProject->video_title,
            'youtube_url' => $c->clipProject->youtube_url,
            'source' => $c->clipProject->videoSource?->value,
            'preview_url' => $c->status === 'done' ? url("/api/clips/{$c->id}/download") : null,
            'tiktok_status' => $c->tiktok_status,
            'tiktok_error' => $c->tiktok_error,
            'music_credit' => $music->creditFor($c->music_track),
        ]));
    }

    public function approve(Request $request, string $clipId): JsonResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer']]);
        $clip = $this->ownedReviewClip($clipId);
        $account = TiktokAccount::where('user_id', Auth::id())->findOrFail($data['account_id']);

        abort_if($clip->status !== 'done' || ! $clip->output_path, 422, 'Clip belum selesai dirender.');
        abort_if($clip->review_status === 'rejected', 422, 'Clip sudah ditolak dan filenya dihapus.');
        abort_if(in_array($clip->tiktok_status, ['queued', 'uploading', 'processing'], true), 409, 'Clip sedang dikirim ke TikTok.');
        abort_unless($account->hasScope('video.upload'), 422, TiktokAccount::scopeMessage('video.upload'));

        $clip->update([
            'review_status' => 'approved',
            'tiktok_account_id' => $account->id,
            'tiktok_publish_id' => null,
            'tiktok_status' => 'queued',
            'tiktok_error' => null,
        ]);
        UploadToTikTokJob::dispatch($clip->id, $account->id);
        $this->ensureQueueWorkerRunning();

        return response()->json(['success' => true]);
    }

    public function reject(string $clipId, StorageCleanup $cleanup): JsonResponse
    {
        $clip = $this->ownedReviewClip($clipId);
        if ($clip->output_path) {
            Storage::delete($clip->output_path); // free disk; rejected clips aren't kept
        }
        $clip->update(['review_status' => 'rejected', 'output_path' => null]);
        $cleanup->releaseSource($clip->clipProject); // drop the source video once no clip still needs it

        return response()->json(['success' => true]);
    }

    private function latestManualProject(): ?ClipProject
    {
        return ClipProject::where('user_id', Auth::id())->whereNull('video_source_id')->latest()->first();
    }

    private function ownedSource(int $sourceId): VideoSource
    {
        return VideoSource::where('user_id', Auth::id())->findOrFail($sourceId);
    }

    private function ownedReviewClip(string $clipId): GeneratedClip
    {
        $clip = GeneratedClip::with('clipProject')->whereNotNull('review_status')->findOrFail($clipId);
        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        return $clip;
    }
}
