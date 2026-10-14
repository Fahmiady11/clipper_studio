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

    private const REVIEW_PER_PAGE = 9;

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

    /**
     * Autopilot clips, a page at a time: waiting for review first (oldest first),
     * then the most recent decisions. ?status=pending|approved|rejected narrows it.
     */
    public function review(Request $request, MusicService $music): JsonResponse
    {
        $status = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ])['status'] ?? null;

        $base = GeneratedClip::query()
            ->whereNotNull('review_status')
            ->whereHas('clipProject', fn ($q) => $q->where('user_id', Auth::id()));

        $counts = (clone $base)->selectRaw('review_status, count(*) as n')->groupBy('review_status')
            ->pluck('n', 'review_status')->map(fn ($n) => (int) $n);

        $page = (clone $base)
            ->when($status, fn ($q) => $q->where('review_status', $status))
            ->with('clipProject.videoSource')
            ->latest() // newest clips on page 1
            ->orderBy('ranking')
            ->orderBy('id') // stable order, so no clip shows on two pages
            ->paginate(self::REVIEW_PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(function (GeneratedClip $c) use ($music) {
                $credit = $music->creditFor($c->music_track);

                return [
                    'id' => $c->id,
                    // null until generated (GET /api/clips/{id}/caption); copied by hand since TikTok drafts carry no caption
                    'caption' => $c->caption ? $music->captionWithCredit($c->caption, $credit) : null,
                    'hashtags' => $c->hashtags_json ?? [],
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
                    'preview_url' => $c->status === 'done' ? url("/api/clips/{$c->id}/download?inline=1") : null,
                    'tiktok_status' => $c->tiktok_status,
                    'tiktok_error' => $c->tiktok_error,
                    'music_credit' => $credit,
                ];
            }),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'pending' => $counts['pending'] ?? 0,
            'counts' => [
                'pending' => $counts['pending'] ?? 0,
                'approved' => $counts['approved'] ?? 0,
                'rejected' => $counts['rejected'] ?? 0,
            ],
        ]);
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

    /**
     * Clear approved clips that are done with TikTok (sent, posted or failed):
     * the file and the row go, and an autopilot project left without clips is
     * deleted along with its source video. Clips still uploading are kept.
     */
    public function cleanApproved(StorageCleanup $cleanup): JsonResponse
    {
        $clips = GeneratedClip::with('clipProject')
            ->where('review_status', 'approved')
            ->where(fn ($q) => $q->whereNull('tiktok_status')->orWhereNotIn('tiktok_status', ['queued', 'uploading', 'processing']))
            ->whereHas('clipProject', fn ($q) => $q->where('user_id', Auth::id()))
            ->get();

        foreach ($clips as $clip) {
            if ($clip->output_path) {
                Storage::delete($clip->output_path);
            }
            $clip->delete();
        }

        foreach ($clips->pluck('clipProject')->unique('id') as $project) {
            $cleanup->releaseSource($project);
            if ($project->video_source_id && ! $project->generatedClips()->exists()) {
                $project->delete();
            }
        }

        return response()->json(['removed' => $clips->count()]);
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
