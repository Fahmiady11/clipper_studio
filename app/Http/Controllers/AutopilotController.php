<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Jobs\DiscoverVideosJob;
use App\Models\ClipProject;
use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\PlatformSetting;
use App\Models\ScheduledPost;
use App\Models\StylePreset;
use App\Models\TiktokAccount;
use App\Models\VideoSource;
use App\Services\Autopost;
use App\Services\ClipPerformanceSummary;
use App\Services\ConnectedAccounts;
use App\Services\MusicService;
use App\Services\ProjectCreator;
use App\Services\StorageCleanup;
use App\Support\CaptionCredit;
use App\Support\ClipSettings;
use App\Support\YoutubeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AutopilotController extends Controller
{
    use EnsuresQueueWorker;

    private const REVIEW_PER_PAGE = 9;

    public function sources(): JsonResponse
    {
        $sources = VideoSource::where('user_id', Auth::id())
            ->with('stylePreset')
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
            'settings' => $s->clipSettings(),
            'style_preset_id' => $s->stylePreset?->id,
            'style_preset_name' => $s->stylePreset?->name,
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
            'style_preset_id' => ['sometimes', 'nullable', 'integer', StylePreset::existsRule(Auth::id())],
        ] + ClipSettings::rules('settings.'));

        // Look chosen in the form, else the user's latest manual project; a preset decides the look
        $settings = $data['settings'] ?? $creator->settingsFrom($this->latestManualProject());
        $preset = StylePreset::findVisible($data['style_preset_id'] ?? null, Auth::id());
        if ($preset) {
            $settings = array_replace_recursive($settings, $preset->look(Auth::user()->brandKit));
        }

        $source = VideoSource::create([
            'user_id' => Auth::id(),
            'type' => $data['type'],
            'value' => $data['type'] === 'channel' ? YoutubeUrl::channel($data['value']) : trim($data['value']),
            'interval_hours' => $data['interval_hours'] ?? 6,
            'max_per_run' => $data['max_per_run'] ?? 1,
            'min_video_seconds' => ($data['min_minutes'] ?? 5) * 60,
            'max_video_seconds' => ($data['max_minutes'] ?? 120) * 60,
            'settings' => ClipSettings::forAutopilot($settings),
            'style_preset_id' => $preset?->id,
        ]);

        return response()->json(['id' => $source->id], 201);
    }

    public function updateSource(Request $request, int $sourceId): JsonResponse
    {
        $source = $this->ownedSource($sourceId);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'style_preset_id' => ['sometimes', 'nullable', 'integer', StylePreset::existsRule(Auth::id())],
        ] + ClipSettings::rules('settings.'));

        // Applies to clips made from now on; ones already made keep their look
        if (isset($data['settings']) || array_key_exists('style_preset_id', $data)) {
            $kit = Auth::user()->brandKit;
            $settings = $source->settings ?? [];
            $unlinking = array_key_exists('style_preset_id', $data) && $data['style_preset_id'] === null;
            if ($unlinking && $source->stylePreset) {
                $settings = array_replace_recursive($settings, $source->stylePreset->look($kit)); // keep the look it had
            }
            $settings = array_replace_recursive($settings, $data['settings'] ?? []);
            $preset = StylePreset::findVisible(array_key_exists('style_preset_id', $data) ? $data['style_preset_id'] : $source->style_preset_id, Auth::id());
            if ($preset) {
                $settings = array_replace_recursive($settings, $preset->look($kit));
            }
            $data['settings'] = ClipSettings::forAutopilot($settings);
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
    public function review(Request $request, MusicService $music, ClipPerformanceSummary $performance, Autopost $autopost): JsonResponse
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
            ->with('clipProject.videoSource', 'publications')
            ->latest() // newest clips on page 1
            ->orderBy('ranking')
            ->orderBy('id') // stable order, so no clip shows on two pages
            ->paginate(self::REVIEW_PER_PAGE);

        $clipTotals = $performance->totalsForClips($page->getCollection()->pluck('id'));
        // The latest calendar post per clip and autopilot platform
        $posts = ScheduledPost::whereIn('generated_clip_id', $page->getCollection()->pluck('id'))
            ->whereIn('platform', array_keys($autopost->platforms()))
            ->latest('id')
            ->get()
            ->unique(fn (ScheduledPost $p) => $p->generated_clip_id.'|'.$p->platform)
            ->keyBy(fn (ScheduledPost $p) => $p->generated_clip_id.'|'.$p->platform);

        return response()->json([
            'data' => $page->getCollection()->map(function (GeneratedClip $c) use ($music, $clipTotals, $posts, $autopost) {
                $credit = $music->creditFor($c->music_track);
                $totals = $clipTotals[$c->id] ?? null;

                return [
                    'id' => $c->id,
                    // null until generated (GET /api/clips/{id}/caption); copied by hand since TikTok drafts carry no caption
                    'caption' => $c->caption ? CaptionCredit::append($c->caption, CaptionCredit::block($credit, $c->clipProject)) : null,
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
                    // youtube, facebook, instagram, threads: each autopilot platform's line on the card
                    ...collect(array_keys($autopost->platforms()))->mapWithKeys(fn ($platform) => [
                        $platform => $this->autopostState($c, $platform, $posts["{$c->id}|{$platform}"] ?? null, $autopost),
                    ])->all(),
                    'music_credit' => $credit,
                    'views' => $totals['views'] ?? null,
                    'likes' => $totals['likes'] ?? null,
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
            'performance' => $performance->forUser(Auth::id()),
        ]);
    }

    /**
     * Approve a clip: it takes the next free calendar slot of every platform
     * with autopost on, TikTok included, and ScheduledPublisher uploads it when
     * due. account_id picks another TikTok account for this clip only.
     */
    public function approve(Request $request, string $clipId, Autopost $autopost, ConnectedAccounts $connected): JsonResponse
    {
        $data = $request->validate(['account_id' => ['nullable', 'integer']]);
        $clip = $this->ownedReviewClip($clipId);
        $account = isset($data['account_id'])
            ? TiktokAccount::where('user_id', Auth::id())->findOrFail($data['account_id'])
            : $this->tiktokOnApprove($connected);
        $overrides = $account ? ['tiktok' => $account->id] : [];

        abort_if(! $account && ! $autopost->isOn(Auth::id()), 422, 'Belum ada tujuan. Nyalakan TikTok atau upload otomatis di Akun terhubung.');
        abort_if($clip->status !== 'done' || ! $clip->output_path, 422, 'Clip belum selesai dirender.');
        abort_if($clip->review_status === 'rejected', 422, 'Clip sudah ditolak dan filenya dihapus.');
        if ($account) {
            abort_if(in_array($clip->tiktok_status, ['queued', 'uploading', 'processing'], true), 409, 'Clip sedang dikirim ke TikTok.');
            abort_unless($account->hasScope('video.upload'), 422, TiktokAccount::scopeMessage('video.upload'));
        }

        $clip->update(['review_status' => 'approved']);

        // The approval stands either way; a platform that can't take the clip keeps the reason on its card
        $booked = $autopost->scheduleAll($clip, $overrides);

        $result = ['success' => true];
        foreach (array_keys($autopost->platforms()) as $platform) {
            $result[$platform] = $booked[$platform] ?? null; // {scheduled_at} or {error}; null when off
        }

        return response()->json($result);
    }

    /** Back to the queue, as long as nothing has gone out yet. Its waiting calendar slots are freed. */
    public function unapprove(string $clipId, Autopost $autopost): JsonResponse
    {
        $clip = $this->ownedReviewClip($clipId);
        abort_unless($clip->review_status === 'approved', 422, 'Clip ini belum disetujui.');
        abort_if(! in_array($clip->tiktok_status, [null, 'skipped'], true), 422, 'Clip sudah dikirim ke TikTok, approve tidak bisa dibatalkan.');
        $sent = $clip->scheduledPosts()->whereIn('status', [ScheduledPost::STATUS_PUBLISHING, ScheduledPost::STATUS_QUEUED])->first();
        abort_if($clip->youtube_status !== null || $sent?->platform === 'youtube', 422, 'Clip sudah dikirim ke YouTube, approve tidak bisa dibatalkan.');
        $platform = $sent?->platform ?? $clip->publications()->where('status', '!=', ClipPublication::SKIPPED)->value('platform');
        if ($platform !== null) {
            abort(422, 'Clip sudah dikirim ke '.$autopost->platform($platform)->name().', approve tidak bisa dibatalkan.');
        }

        $autopost->cancelWaiting($clip);
        $clip->update(['review_status' => 'pending'] + ($clip->tiktok_status === 'skipped' ? ['tiktok_status' => null, 'tiktok_error' => null] : []));

        return response()->json(['success' => true]);
    }

    /** A platform's switch and slots, shown on Settings → Akun terhubung: GET /api/autopilot/{platform} */
    public function autopostSettings(string $platform, Autopost $autopost, ConnectedAccounts $connected): JsonResponse
    {
        return response()->json($this->presentAutopost(PlatformSetting::for(Auth::id(), $platform), $autopost, $connected));
    }

    /** A platform's switch and slots. account_id, when given, becomes the default. */
    public function updateAutopostSettings(Request $request, string $platform, Autopost $autopost, ConnectedAccounts $connected): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'account_id' => ['nullable', 'integer'],
            'max_per_day' => ['required', 'integer', 'min:1', 'max:10'],
            'slot_times' => ['required', 'array', 'min:1', 'max:10'],
            'slot_times.*' => ['required', 'string', 'distinct', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['required', 'timezone:all'],
        ], [
            'slot_times.*.regex' => 'Jam upload harus format JJ:MM, misalnya 09:00.',
            'slot_times.*.distinct' => 'Ada jam upload yang sama.',
        ]);
        if (isset($data['account_id'])) {
            $connected->setDefault(Auth::id(), $platform, $data['account_id']);
        }
        if ($data['enabled'] && ! $connected->default(Auth::id(), $platform)) {
            throw ValidationException::withMessages(['account_id' => 'Hubungkan akun '.ConnectedAccounts::NAMES[$platform].' dulu.']);
        }

        $settings = PlatformSetting::for(Auth::id(), $platform);
        $settings->fill([
            'enabled' => $data['enabled'],
            'max_per_day' => $data['max_per_day'],
            'slot_times' => collect($data['slot_times'])->sort()->values()->all(),
            'timezone' => $data['timezone'],
        ])->save();

        return response()->json($this->presentAutopost($settings, $autopost, $connected));
    }

    public function reject(string $clipId, StorageCleanup $cleanup, Autopost $autopost): JsonResponse
    {
        $clip = $this->ownedReviewClip($clipId);
        $autopost->cancelWaiting($clip); // the file goes, so nothing can be posted from it
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
     * deleted along with its source video. Clips still uploading, or waiting
     * for their calendar slot, are kept.
     */
    public function cleanApproved(StorageCleanup $cleanup): JsonResponse
    {
        $clips = GeneratedClip::with('clipProject')
            ->where('review_status', 'approved')
            ->where(fn ($q) => $q->whereNull('tiktok_status')->orWhereNotIn('tiktok_status', ['queued', 'uploading', 'processing']))
            ->where(fn ($q) => $q->whereNull('youtube_status')->orWhereNotIn('youtube_status', ['queued', 'uploading']))
            // A container still processing needs the file: Meta is downloading it
            ->whereDoesntHave('publications', fn ($q) => $q->whereIn('status', ClipPublication::BUSY))
            ->whereDoesntHave('scheduledPosts', fn ($q) => $q->whereIn('status', [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_PUBLISHING]))
            ->whereHas('clipProject', fn ($q) => $q->where('user_id', Auth::id()))
            ->get();

        return response()->json(['removed' => $this->removeClips($clips, $cleanup)]);
    }

    /**
     * Delete unreviewed clips that are finished (rendered or failed). Clips
     * still queued or rendering are kept, so the render job never loses its row.
     */
    public function cleanPending(StorageCleanup $cleanup): JsonResponse
    {
        $clips = GeneratedClip::with('clipProject')
            ->where('review_status', 'pending')
            ->whereIn('status', ['done', 'failed'])
            ->whereHas('clipProject', fn ($q) => $q->where('user_id', Auth::id()))
            ->get();

        return response()->json(['removed' => $this->removeClips($clips, $cleanup)]);
    }

    /**
     * Delete the clips and their files; an autopilot project left without
     * clips goes too, along with its source video.
     *
     * @param  Collection<int, GeneratedClip>  $clips
     */
    private function removeClips(Collection $clips, StorageCleanup $cleanup): int
    {
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

        return $clips->count();
    }

    /**
     * Where the clip is on its way to an autopost platform: its calendar post,
     * then what the upload reported once the post was handed over. Without a
     * post, 'skipped' carries why autopilot could not book one.
     */
    private function autopostState(GeneratedClip $clip, string $platform, ?ScheduledPost $post, Autopost $autopost): ?array
    {
        if (! $post) {
            $skipped = $autopost->platform($platform)->skipReason($clip);

            return $skipped
                ? ['post_id' => null, 'status' => 'skipped', 'scheduled_at' => null, 'delivery' => null,
                    'error' => $skipped, 'reconnect_url' => null, 'url' => null, 'privacy' => null, 'note' => null]
                : null;
        }
        $handedOff = $post->status === ScheduledPost::STATUS_QUEUED;
        $delivery = $handedOff ? $clip->delivery($platform) : ['status' => null, 'error' => null, 'url' => null, 'privacy' => null, 'note' => null];
        $error = $post->error ?? $delivery['error'];

        return [
            'post_id' => $post->id,
            'status' => $post->status,
            'scheduled_at' => $post->scheduled_at->toISOString(),
            // queued, uploading, processing, published, failed
            'delivery' => $delivery['status'],
            'error' => $error,
            'reconnect_url' => ScheduledPost::reconnectUrl($platform, $error),
            'url' => $delivery['url'],
            'privacy' => $delivery['privacy'],
            'note' => $delivery['note'],
        ];
    }

    private function presentAutopost(PlatformSetting $settings, Autopost $autopost, ConnectedAccounts $connected): array
    {
        $platform = $settings->platform;
        $accounts = $platform === 'tiktok'
            ? $connected->accounts(Auth::id(), 'tiktok')->map(fn ($a) => ['id' => $a->id, 'name' => $a->display_name])
            : $autopost->platform($platform)->accounts(Auth::id());

        return [
            'platform' => $platform,
            'configured' => $connected->isConfigured($platform),
            'accounts' => $accounts->values(),
            'enabled' => $settings->enabled,
            // The default account: posts go there
            'account_id' => $connected->default(Auth::id(), $platform)?->id,
            'needs_default' => $connected->needsDefault(Auth::id(), $platform),
            'max_per_day' => $settings->max_per_day,
            'slot_times' => $settings->slot_times,
            'timezone' => $settings->timezone,
            'saved' => $settings->exists,
        ];
    }

    /** The default TikTok account when TikTok is switched on for approvals, else null. */
    private function tiktokOnApprove(ConnectedAccounts $connected): ?TiktokAccount
    {
        return $this->tiktokIsOn() ? $connected->default(Auth::id(), 'tiktok') : null;
    }

    private function tiktokIsOn(): bool
    {
        return PlatformSetting::where('user_id', Auth::id())->where('platform', 'tiktok')->where('enabled', true)->exists();
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
